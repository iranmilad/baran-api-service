<?php

namespace App\Jobs;

use App\Models\License;
use App\Models\Product;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\WooCommerceApiKey;
use App\Traits\PriceUnitConverter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class BulkUpdateWooCommerceProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, PriceUnitConverter;

    public $tries = 5;
    public $timeout = 180;
    public $maxExceptions = 3;
    public $backoff = [60, 120, 300, 600, 1200];

    protected $products;
    protected $license_id;
    protected $batchSize = 10;

    public function __construct(array $products, int $license_id, int $batchSize = 10)
    {
        $this->products = $products;
        $this->license_id = $license_id;
        $this->batchSize = $batchSize;
        $this->onQueue('woocommerce-update');
    }

    public function handle()
    {
        try {
            $license = License::with(['user', 'userSetting', 'woocommerceApiKey'])->find($this->license_id);

            if (!$license || !$license->isActive()) {
                Log::error('لایسنس معتبر نیست یا منقضی شده است', [
                    'license_id' => $this->license_id
                ]);
                return;
            }

            $userSetting = $license->userSetting;
            if (!$userSetting) {
                throw new \Exception('تنظیمات کاربر یافت نشد');
            }

            $wooCommerceApiKey = $license->woocommerceApiKey;
            if (!$wooCommerceApiKey || !$wooCommerceApiKey->api_key || !$wooCommerceApiKey->api_secret) {
                throw new \Exception('کلیدهای API ووکامرس یافت نشد یا نامعتبر است');
            }

            if (!$this->checkRequiredPermissions($userSetting)) {
                throw new \Exception('دسترسی‌های لازم در تنظیمات کاربر فعال نیست');
            }

            // بررسی وجود محصولات در دیتابیس
            $productsToUpdate = [];
            $productsToInsert = [];

            foreach ($this->products as $product) {
                if (empty($product['item_id'])) {
                    Log::warning('شناسه یکتای محصول خالی است', [
                        'product' => $product
                    ]);
                    continue;
                }

                // بررسی وجود محصول در دیتابیس
                $existingProduct = Product::where('item_id', $product['item_id'])
                    ->where('license_id', $this->license_id)
                    ->first();

                if ($existingProduct) {
                    // محصول وجود دارد، به‌روزرسانی کن
                    $productsToUpdate[] = $this->prepareProductData($product, $userSetting);
                } else {
                    // محصول وجود ندارد، به عنوان محصول جدید درج کن
                    Log::info("محصول با item_id {$product['item_id']} در دیتابیس یافت نشد، به عنوان محصول جدید درج می‌شود");
                    $productsToInsert[] = $product;
                }
            }

            // به‌روزرسانی محصولات موجود
            if (!empty($productsToUpdate)) {
                Log::info('محصولات در حال به‌روزرسانی:', [
                    'license_id' => $this->license_id,
                    'unique_ids' => collect($productsToUpdate)->pluck('unique_id')->toArray(),
                    'barcodes' => collect($productsToUpdate)->pluck('barcode')->toArray(),
                    'count' => count($productsToUpdate)
                ]);

                $response = Http::withOptions([
                    'verify' => false,
                    'timeout' => 180,
                    'connect_timeout' => 60
                ])->retry(3, 300, function ($exception, $request) {
                    return $exception instanceof \Illuminate\Http\Client\ConnectionException ||
                           $exception->response->status() >= 500;
                })->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ])->withBasicAuth(
                    $wooCommerceApiKey->api_key,
                    $wooCommerceApiKey->api_secret
                )->put($license->website_url . '/wp-json/wc/v3/products/unique/batch/update', [
                    'products' => $productsToUpdate
                ]);

                $this->handleResponse($response, $productsToUpdate, 'update');
            }

            // درج محصولات جدید
            if (!empty($productsToInsert)) {
                Log::info('محصولات جدید برای درج:', [
                    'license_id' => $this->license_id,
                    'count' => count($productsToInsert)
                ]);

                // ارسال به صف درج محصولات جدید
                $this->dispatchInsertJobs($productsToInsert, $userSetting);
            }

            if (empty($productsToUpdate) && empty($productsToInsert)) {
                Log::info('هیچ محصولی برای به‌روزرسانی یا درج وجود ندارد', [
                    'license_id' => $this->license_id,
                    'total_checked' => count($this->products)
                ]);
            }

        } catch (\Exception $e) {
            Log::error('خطا در پردازش دسته‌ای محصولات: ' . $e->getMessage(), [
                'license_id' => $this->license_id
            ]);
            throw $e;
        }
    }

    /**
     * بررسی دسترسی‌های لازم در تنظیمات کاربر
     */
    protected function checkRequiredPermissions(UserSetting $userSetting): bool
    {
        return $userSetting->enable_price_update ||
               $userSetting->enable_stock_update ||
               $userSetting->enable_name_update;
    }

    /**
     * آماده‌سازی داده‌های محصول برای ووکامرس
     */
    protected function prepareProductData($product, UserSetting $userSetting): array
    {
        // تبدیل به آرایه اگر آبجکت است
        $productData = is_array($product) ? $product : (array)$product;

        $data = [
            'unique_id' => (string)$productData['item_id'],
            'sku' => (string)($productData['barcode'] ?? $productData['sku'] ?? '')
        ];

        if ($userSetting->enable_name_update) {
            $data['name'] = $productData['name'] ?? $productData['item_name'] ?? '';
        }

        if ($userSetting->enable_price_update) {
            // استفاده از price_amount برای قیمت اصلی
            $regularPrice = (float)($productData['regular_price'] ?? 0);

            // استفاده از price_after_discount برای قیمت با تخفیف
            $salePrice = (float)($productData['sale_price'] ?? 0);

            // تبدیل قیمت‌ها به واحد ووکامرس
            $data['regular_price'] = (string)$this->convertPriceUnit(
                $regularPrice,
                $userSetting->rain_sale_price_unit,
                $userSetting->woocommerce_price_unit
            );

            // اگر قیمت با تخفیف کمتر از قیمت اصلی است، آن را تنظیم می‌کنیم
            if ($salePrice > 0 && $salePrice < $regularPrice) {
                $data['sale_price'] = (string)$this->convertPriceUnit(
                    $salePrice,
                    $userSetting->rain_sale_price_unit,
                    $userSetting->woocommerce_price_unit
                );
            }
        }

        if ($userSetting->enable_stock_update) {
            $data['manage_stock'] = true;
            $data['stock_quantity'] = (int)($productData['stock_quantity'] ?? 0);
            $data['stock_status'] = ($productData['stock_quantity'] ?? 0) > 0 ? 'instock' : 'outofstock';
        }

        return $data;
    }

    /**
     * محاسبه قیمت نهایی با اعمال تخفیف و افزایش قیمت
     */
    protected function calculateFinalPrice(float $basePrice, float $discountPercentage, float $increasePercentage): float
    {
        $price = $basePrice;

        if ($discountPercentage > 0) {
            $price -= ($price * $discountPercentage / 100);
        }

        if ($increasePercentage > 0) {
            $price += ($price * $increasePercentage / 100);
        }

        return round($price, 2);
    }

    /**
     * پردازش پاسخ API
     */
    protected function handleResponse($response, $products, $operation)
    {
        if (!$response->successful()) {
            $errorData = json_decode($response->body(), true);
            $errorMessage = 'خطا در ' . ($operation === 'insert' ? 'درج' : 'به‌روزرسانی') . ' محصولات: ';

            if (isset($errorData['failed']) && is_array($errorData['failed'])) {
                foreach ($errorData['failed'] as $failed) {
                    $errorMessage .= sprintf(
                        "بارکد: %s - پیام: %s | ",
                        $failed['unique_id'] ?? 'نامشخص',
                        $failed['error'] ?? 'خطای نامشخص'
                    );
                }
            } else {
                $errorMessage .= $response->body();
            }

            throw new \Exception($errorMessage);
        }

        // لاگ کردن نتیجه عملیات
        $responseData = json_decode($response->body(), true);
        $successfulOperations = [];
        $failedOperations = [];

        if (isset($responseData['success']) && is_array($responseData['success'])) {
            foreach ($responseData['success'] as $result) {
                $successfulOperations[] = [
                    'product_id' => $result['product_id'] ?? 'نامشخص',
                    'unique_id' => $result['unique_id'] ?? 'نامشخص'
                ];
            }
        }

        if (isset($responseData['failed']) && is_array($responseData['failed'])) {
            foreach ($responseData['failed'] as $result) {
                $failedOperations[] = [
                    'unique_id' => $result['unique_id'] ?? 'نامشخص',
                    'error' => $result['error'] ?? 'خطای نامشخص'
                ];
            }
        }

        Log::info('نتیجه ' . ($operation === 'insert' ? 'درج' : 'به‌روزرسانی') . ' دسته محصولات:', [
            'license_id' => $this->license_id,
            'total_count' => count($products),
            'successful_count' => count($successfulOperations),
            'failed_count' => count($failedOperations),
            'successful_operations' => $successfulOperations,
            'failed_operations' => $failedOperations,
            'response_time' => $response->transferStats->getTransferTime()
        ]);

        // اگر خطایی وجود داشت، آن را به صف خطا ارسال می‌کنیم
        if (!empty($failedOperations)) {
            foreach ($failedOperations as $failedOperation) {
                Log::error('خطا در ' . ($operation === 'insert' ? 'درج' : 'به‌روزرسانی') . ' محصول:', [
                    'license_id' => $this->license_id,
                    'unique_id' => $failedOperation['unique_id'],
                    'error' => $failedOperation['error']
                ]);
            }
        }
    }

    /**
     * ارسال محصولات جدید به صف درج
     */
    protected function dispatchInsertJobs(array $products, UserSetting $userSetting)
    {
        try {
            // آماده‌سازی محصولات برای درج
            $preparedProducts = collect($products)->map(function ($product) use ($userSetting) {
                return $this->prepareProductData($product, $userSetting);
            })->toArray();

            // تقسیم به دسته‌های کوچک
            $chunks = array_chunk($preparedProducts, $this->batchSize);

            foreach ($chunks as $index => $chunk) {
                BulkInsertWooCommerceProducts::dispatch($chunk, $this->license_id, $this->batchSize)
                    ->onQueue('woocommerce-insert')
                    ->delay(now()->addSeconds($index * 15));
            }

            Log::info('محصولات جدید به صف درج ارسال شدند', [
                'license_id' => $this->license_id,
                'count' => count($products),
                'chunks' => count($chunks)
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در ارسال محصولات جدید به صف درج: ' . $e->getMessage(), [
                'license_id' => $this->license_id
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception)
    {
        Log::error('خطا در پردازش صف به‌روزرسانی دسته‌ای محصولات: ' . $exception->getMessage(), [
            'license_id' => $this->license_id
        ]);
    }
}
