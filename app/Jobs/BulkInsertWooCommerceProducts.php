<?php

namespace App\Jobs;

use App\Models\License;
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

class BulkInsertWooCommerceProducts implements ShouldQueue
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
        $this->onQueue('woocommerce-insert');
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

            if (!$userSetting->enable_new_product) {
                throw new \Exception('دسترسی درج محصول جدید فعال نیست');
            }

            $productsToCreate = [];
            $existingProducts = [];
            $uniqueIds = collect($this->products)->pluck('item_id')->filter()->toArray();

            if (empty($uniqueIds)) {
                Log::error('هیچ شناسه یکتای معتبری برای پردازش وجود ندارد', [
                    'license_id' => $this->license_id,
                    'products' => $this->products
                ]);
                return;
            }

            // بررسی وجود محصولات در ووکامرس
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth(
                $wooCommerceApiKey->api_key,
                $wooCommerceApiKey->api_secret
            )->get($license->website_url . '/wp-json/wc/v3/products/unique', [
                'unique_id' => implode(',', $uniqueIds),
                'per_page' => 100
            ]);

            $responseData = json_decode($response->body(), true);

            // بررسی حالت not_found
            if ($response->status() === 404 && isset($responseData['code']) && $responseData['code'] === 'not_found') {
                Log::info('هیچ محصولی با این شناسه‌های یکتا یافت نشد، در حال درج محصولات جدید...', [
                    'license_id' => $this->license_id,
                    'unique_ids' => $uniqueIds
                ]);

                $productsToCreate = $this->products;
            } else if ($response->successful()) {
                $wooProducts = $responseData['data'] ?? [];
                $productMap = collect($wooProducts)->keyBy('unique_id')->toArray();

                foreach ($this->products as $product) {
                    if (empty($product['item_id'])) {
                        Log::warning('شناسه یکتای محصول خالی است', [
                            'product' => $product
                        ]);
                        continue;
                    }

                    if (isset($productMap[$product['item_id']])) {
                        $existingProducts[] = [
                            'sku' => $product['sku'],
                            'unique_id' => $product['item_id'],
                            'id' => $productMap[$product['item_id']]['product_id']
                        ];
                        Log::info('محصول با این شناسه یکتا قبلاً در ووکامرس وجود دارد:', [
                            'license_id' => $this->license_id,
                            'unique_id' => $product['item_id'],
                            'sku' => $product['sku'],
                            'woo_id' => $productMap[$product['item_id']]['product_id']
                        ]);
                    } else {
                        $productsToCreate[] = $product;
                    }
                }
            } else {
                Log::error('خطا در بررسی وجود محصولات در ووکامرس', [
                    'license_id' => $this->license_id,
                    'response' => $response->body()
                ]);
                return;
            }

            if (!empty($existingProducts)) {
                Log::info('محصولات موجود در ووکامرس:', [
                    'license_id' => $this->license_id,
                    'existing_products' => $existingProducts,
                    'count' => count($existingProducts)
                ]);
            }

            if (!empty($productsToCreate)) {
                Log::info('محصولات جدید در حال درج:', [
                    'license_id' => $this->license_id,
                    'unique_ids' => collect($productsToCreate)->pluck('unique_id')->toArray(),
                    'count' => count($productsToCreate)
                ]);

                // لاگ کردن محتوای درخواست
                Log::info('محتوای درخواست درج محصولات:', [
                    'license_id' => $this->license_id,
                    'request_data' => [
                        'products' => $productsToCreate
                    ],
                    'endpoint' => $license->website_url . '/wp-json/wc/v3/products/unique/batch'
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
                )->post($license->website_url . '/wp-json/wc/v3/products/unique/batch', [
                    'products' => $productsToCreate
                ]);

                $this->handleResponse($response, $productsToCreate, 'insert');
            } else {
                Log::info('هیچ محصول جدیدی برای درج وجود ندارد', [
                    'license_id' => $this->license_id,
                    'total_checked' => count($this->products),
                    'existing_count' => count($existingProducts)
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
     * آماده‌سازی داده‌های محصول برای ووکامرس
     */
    protected function prepareProductData($product, UserSetting $userSetting): array
    {
        // تبدیل به آرایه اگر آبجکت است
        $productData = is_array($product) ? $product : (array)$product;

        $data = [
            'unique_id' => (string)$productData['item_id'],
            'sku' => (string)$productData['barcode'],
            'status' => 'draft',
            'manage_stock' => true,
            'stock_quantity' => (int)($productData['total_count'] ?? 0),
            'stock_status' => ($productData['total_count'] ?? 0) > 0 ? 'instock' : 'outofstock'
        ];

        if ($userSetting->enable_name_update) {
            $data['name'] = $productData['item_name'];
        }

        if ($userSetting->enable_price_update) {
            $regularPrice = $this->calculateFinalPrice(
                (float)($productData['price_amount'] ?? 0),
                0,
                (float)($productData['price_increase_percentage'] ?? 0)
            );

            $salePrice = $this->calculateFinalPrice(
                (float)($productData['price_amount'] ?? 0),
                (float)($productData['discount_percentage'] ?? 0),
                (float)($productData['price_increase_percentage'] ?? 0)
            );

            $data['regular_price'] = (string)$this->convertPriceUnit(
                $regularPrice,
                $userSetting->rain_sale_price_unit,
                $userSetting->woocommerce_price_unit
            );

            if (($productData['discount_percentage'] ?? 0) > 0) {
                $data['sale_price'] = (string)$this->convertPriceUnit(
                    $salePrice,
                    $userSetting->rain_sale_price_unit,
                    $userSetting->woocommerce_price_unit
                );
            }
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

            if (isset($errorData[$operation === 'insert' ? 'create' : 'update']) && is_array($errorData[$operation === 'insert' ? 'create' : 'update'])) {
                foreach ($errorData[$operation === 'insert' ? 'create' : 'update'] as $index => $result) {
                    if (isset($result['error'])) {
                        $productBarcode = $products[$index]['barcode'] ?? 'نامشخص';
                        $errorMessage .= sprintf(
                            "بارکد: %s - کد خطا: %s - پیام: %s | ",
                            $productBarcode,
                            $result['error']['code'] ?? 'نامشخص',
                            $result['error']['message'] ?? 'خطای نامشخص'
                        );
                    }
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

        if (isset($responseData[$operation === 'insert' ? 'create' : 'update']) && is_array($responseData[$operation === 'insert' ? 'create' : 'update'])) {
            foreach ($responseData[$operation === 'insert' ? 'create' : 'update'] as $index => $result) {
                if (isset($result['error'])) {
                    $failedOperations[] = [
                        'barcode' => $products[$index]['barcode'] ?? 'نامشخص',
                        'code' => $result['error']['code'] ?? 'نامشخص',
                        'message' => $result['error']['message'] ?? 'خطای نامشخص'
                    ];
                } else {
                    $successfulOperations[] = [
                        'id' => $result['id'] ?? 'نامشخص',
                        'barcode' => $result['barcode'] ?? 'نامشخص'
                    ];
                }
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
                    'barcode' => $failedOperation['barcode'],
                    'error_code' => $failedOperation['code'],
                    'error_message' => $failedOperation['message']
                ]);
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception)
    {
        Log::error('خطا در پردازش صف درج دسته‌ای محصولات: ' . $exception->getMessage(), [
            'license_id' => $this->license_id
        ]);
    }
}
