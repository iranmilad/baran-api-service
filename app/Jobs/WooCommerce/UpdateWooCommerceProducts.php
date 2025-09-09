<?php

namespace App\Jobs\WooCommerce;

use App\Models\Product;
use App\Models\License;
use App\Models\UserSetting;
use App\Models\WooCommerceApiKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use App\Traits\WordPress\WordPressMasterTrait;

class UpdateWooCommerceProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WordPressMasterTrait;

    public $tries = 3;
    public $timeout = 55; // کاهش تایم‌اوت به 55 ثانیه
    public $maxExceptions = 3;
    public $backoff = [10, 30, 60]; // کاهش به 10، 30 و 60 ثانیه

    protected $products;
    protected $license_id;
    protected $operation;
    protected $barcodes;
    protected $batchSize = 50; // افزایش به 50

    public function __construct($license_id, $operation, $barcodes = [], $batchSize = 50)
    {
        $this->license_id = $license_id;
        $this->operation = $operation;
        $this->barcodes = $barcodes;
        $this->batchSize = $batchSize;
    }

    public function handle()
    {
        $startTime = microtime(true);
        $maxExecutionTime = 45; // 45 ثانیه - کمتر از timeout

        try {
            Log::info('شروع پردازش به‌روزرسانی محصولات ووکامرس', [
                'license_id' => $this->license_id,
                'operation' => $this->operation,
                'barcodes_count' => count($this->barcodes)
            ]);

            $license = License::with(['userSetting', 'woocommerceApiKey'])->find($this->license_id);
            if (!$license || !$license->isActive()) {
                return;
            }

            $userSettings = $license->userSetting;
            $wooApiKey = $license->woocommerceApiKey;

            if (!$userSettings || !$wooApiKey) {
                return;
            }

            // دریافت محصولات از ووکامرس
            $wooProducts = $this->getWooCommerceProducts($license, $wooApiKey);

            if (empty($wooProducts)) {
                return;
            }

            // فیلتر کردن بر اساس barcodes اگر مشخص شده باشد
            if (!empty($this->barcodes)) {
                $wooProducts = array_filter($wooProducts, function($product) {
                    return in_array($product['barcode'], $this->barcodes);
                });
            }

            // بررسی زمان - اگر نزدیک timeout شدیم، کار را تقسیم کنیم
            $elapsedTime = microtime(true) - $startTime;
            if ($elapsedTime > $maxExecutionTime - 15) {
                // تقسیم محصولات به batch های 50 تایی
                $productChunks = array_chunk($wooProducts, 50);

                foreach ($productChunks as $index => $chunk) {
                    $chunkBarcodes = array_column($chunk, 'barcode');

                    // ایجاد job جدید برای هر chunk
                    UpdateWooCommerceProducts::dispatch(
                        $this->license_id,
                        'chunk',
                        $chunkBarcodes,
                        50
                    )
                    ->onQueue('bulk-update')
                    ->delay(now()->addSeconds($index * 5)); // کاهش delay
                }

                return;
            }

            // ادامه پردازش عادی
            $this->processProducts($wooProducts, $userSettings, $license, $wooApiKey, $startTime, $maxExecutionTime);

            Log::info('پایان پردازش به‌روزرسانی محصولات ووکامرس', [
                'license_id' => $this->license_id,
                'operation' => $this->operation
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی محصولات در ووکامرس: ' . $e->getMessage(), [
                'license_id' => $this->license_id
            ]);
            throw $e;
        }
    }

    /**
     * پردازش محصولات با مدیریت زمان
     *
     * @param array $wooProducts
     * @param \App\Models\UserSetting $userSettings
     * @param \App\Models\License $license
     * @param \App\Models\WooCommerceApiKey $wooApiKey
     * @param float $startTime
     * @param int $maxExecutionTime
     * @return void
     */
    private function processProducts($wooProducts, $userSettings, $license, $wooApiKey, $startTime, $maxExecutionTime)
    {
        // استخراج بارکدها
        $barcodes = collect($wooProducts)->pluck('barcode')->filter(function($barcode) {
            return !is_null($barcode) && !empty($barcode);
        })->values()->toArray();

        // تقسیم بارکدها به دسته‌های 50 تایی
        $barcodeChunks = array_chunk($barcodes, 50);

        $allProducts = [];
        foreach ($barcodeChunks as $chunkIndex => $chunk) {
            // بررسی زمان قبل از هر chunk
            $elapsedTime = microtime(true) - $startTime;
            if ($elapsedTime > $maxExecutionTime - 10) {
                // ارسال chunks باقی‌مانده
                $remainingChunks = array_slice($barcodeChunks, $chunkIndex);
                $remainingBarcodes = array_merge(...$remainingChunks);

                UpdateWooCommerceProducts::dispatch(
                    $this->license_id,
                    'continuation',
                    $remainingBarcodes,
                    50
                )
                ->onQueue('bulk-update')
                ->delay(now()->addSeconds(5));

                break;
            }

            $rainProducts = $this->getRainProducts($chunk);
            if (!empty($rainProducts)) {
                $allProducts = array_merge($allProducts, $rainProducts);
            }
        }

        if (!empty($allProducts)) {
            $this->updateProductsInWooCommerce($allProducts, $wooProducts, $userSettings, $license, $wooApiKey);
        }
    }

    /**
     * به‌روزرسانی محصولات در ووکامرس
     *
     * @param array $allProducts
     * @param array $wooProducts
     * @param \App\Models\UserSetting $userSettings
     * @param \App\Models\License $license
     * @param \App\Models\WooCommerceApiKey $wooApiKey
     * @return void
     */
    private function updateProductsInWooCommerce($allProducts, $wooProducts, $userSettings, $license, $wooApiKey)
    {
        $productsToUpdate = [];
        foreach ($allProducts as $rainProduct) {
            // پیدا کردن محصول ووکامرس متناظر
            $wooProduct = collect($wooProducts)->first(function ($product) use ($rainProduct) {
                return $product['barcode'] === $rainProduct["Barcode"];
            });

            if ($wooProduct) {
                $productsToUpdate[] = $this->prepareProductData([
                    'barcode' => $rainProduct["Barcode"],
                    'unique_id' => $rainProduct["ItemID"],
                    'name' => $rainProduct["Name"],
                    'regular_price' => $rainProduct["Price"],
                    'stock_quantity' => $rainProduct["CurrentUnitCount"],
                    'product_id' => $wooProduct['product_id'],
                    'variation_id' => $wooProduct['variation_id']
                ], $userSettings);
            }
        }

        if (!empty($productsToUpdate)) {
            $this->updateWooCommerceProducts($license, $wooApiKey, $productsToUpdate);
        }
    }

    /**
     * دریافت محصولات از ووکامرس
     *
     * @param \App\Models\License $license
     * @param \App\Models\WooCommerceApiKey $wooApiKey
     * @return array
     */
    protected function getWooCommerceProducts($license, $wooApiKey)
    {
        try {
            $result = $this->getWooCommerceProductsWithUniqueIds(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret
            );

            if (!$result['success']) {
                Log::error('خطا در دریافت محصولات از ووکامرس: ' . $result['message']);
                return [];
            }

            // تبدیل داده‌ها به فرمت مورد نیاز
            $products = [];
            if (isset($result['data'])) {
                foreach ($result['data'] as $product) {
                    $products[] = [
                        'barcode' => $product['barcode'] ?? null,
                        'product_id' => $product['product_id'] ?? null,
                        'variation_id' => $product['variation_id'] ?? null
                    ];
                }
            }

            return $products;
        } catch (\Exception $e) {
            Log::error('خطا در دریافت محصولات از ووکامرس: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * دریافت اطلاعات محصولات از API باران یا دیتابیس
     */
    protected function getRainProducts($barcodes)
    {
        try {
            $license = License::with(['user', 'userSetting'])->find($this->license_id);

            if (!$license || !$license->user) {
                return $this->getProductsFromDatabase($barcodes);
            }

            $user = $license->user;
            $userSettings = $license->userSetting;

            // دریافت default_warehouse_code از تنظیمات
            $stockId = $userSettings ? $userSettings->default_warehouse_code : '';

            if (!$user->api_webservice || !$user->api_username || !$user->api_password) {
                return $this->getProductsFromDatabase($barcodes);
            }

            // آماده‌سازی body درخواست
            $requestBody = ['barcodes' => $barcodes];

            // اضافه کردن stockId فقط در صورت وجود مقدار
            if (!empty($stockId)) {
                $requestBody['stockId'] = $stockId;
            }

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 25, // کاهش از 180 به 25 ثانیه
                'connect_timeout' => 10 // کاهش از 60 به 10 ثانیه
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user->api_username . ':' . $user->api_password)
            ])->post($user->api_webservice."/RainSaleService.svc/GetItemInfos", $requestBody);

            if (!$response->successful()) {
                Log::error('خطا در دریافت اطلاعات از API باران، استفاده از داده‌های دیتابیس', [
                    'license_id' => $this->license_id
                ]);
                return $this->getProductsFromDatabase($barcodes);
            }

            $data = $response->json();
            return $data['GetItemInfosResult'] ?? [];
        } catch (\Exception $e) {
            Log::error('خطا در دریافت اطلاعات از API باران، استفاده از داده‌های دیتابیس: ' . $e->getMessage(), [
                'license_id' => $this->license_id
            ]);
            return $this->getProductsFromDatabase($barcodes);
        }
    }

    /**
     * دریافت اطلاعات محصولات از دیتابیس
     */
    protected function getProductsFromDatabase($barcodes)
    {
        try {
            $products = Product::where('license_id', $this->license_id)
                ->whereIn('barcode', $barcodes)
                ->get();

            $result = [];
            foreach ($products as $product) {
                $result[] = [
                    'Barcode' => $product->barcode,
                    'ItemID' => $product->item_id,
                    'Name' => $product->item_name,
                    'Price' => $product->price_amount,
                    'PriceAfterDiscount' => $product->price_after_discount,
                    'CurrentUnitCount' => $product->total_count,
                    'StockID' => $product->stock_id,
                    'DepartmentName' => $product->department_name
                ];
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('خطا در دریافت اطلاعات از دیتابیس: ' . $e->getMessage(), [
                'license_id' => $this->license_id
            ]);
            return [];
        }
    }

    /**
     * به‌روزرسانی محصولات در ووکامرس
     *
     * @param \App\Models\License $license
     * @param \App\Models\WooCommerceApiKey $wooApiKey
     * @param array $products
     * @return array
     */
    protected function updateWooCommerceProducts($license, $wooApiKey, array $products)
    {
        try {
            // تهیه خلاصه اطلاعات محصولات برای لاگ
            $productsInfo = array_map(function($product) {
                return [
                    'sku' => $product['sku'],
                    'unique_id' => $product['unique_id'],
                    'product_id' => $product['product_id'] ?? null,
                    'variation_id' => $product['variation_id'] ?? null,
                    'name' => $product['name'] ?? null,
                    'price' => $product['regular_price'] ?? null,
                    'stock' => $product['stock_quantity'] ?? null,
                ];
            }, $products);

            // تقسیم محصولات به دسته‌های 50 تایی
            $chunks = array_chunk($products, 50);
            $totalChunks = count($chunks);

            $successfulUpdates = 0;
            $failedUpdates = 0;
            $errors = [];

            foreach ($chunks as $index => $chunk) {
                try {
                    $result = $this->updateWooCommerceBatchProductsByUniqueId(
                        $license->website_url,
                        $wooApiKey->api_key,
                        $wooApiKey->api_secret,
                        $chunk
                    );

                    if ($result['success']) {
                        // بررسی تعداد محصولات به‌روز شده در این دسته
                        $updatedInChunk = isset($result['data']) ? count($result['data']) : 0;
                        $successfulUpdates += $updatedInChunk;
                    } else {
                        $failedUpdates += count($chunk);
                        $errors[] = [
                            'chunk_index' => $index + 1,
                            'error_message' => $result['message'],
                            'products_count' => count($chunk)
                        ];
                    }

                    // کاهش تاخیر بین درخواست‌ها
                    if ($index < $totalChunks - 1) {
                        sleep(1); // کاهش از 2 به 1 ثانیه
                    }

                } catch (\Exception $e) {
                    $failedUpdates += count($chunk);
                    $errors[] = [
                        'chunk_index' => $index + 1,
                        'error_message' => $e->getMessage(),
                        'products_count' => count($chunk)
                    ];

                    Log::error('خطا در به‌روزرسانی دسته محصولات: ' . $e->getMessage(), [
                        'chunk_index' => $index + 1,
                        'products_count' => count($chunk),
                        'license_id' => $this->license_id
                    ]);

                    continue;
                }
            }

            // گزارش نهایی فقط در صورت وجود خطا
            if (!empty($errors)) {
                Log::error('خطاهای رخ داده در به‌روزرسانی محصولات', [
                    'total_products' => count($products),
                    'successful_updates' => $successfulUpdates,
                    'failed_updates' => $failedUpdates,
                    'errors_count' => count($errors),
                    'license_id' => $this->license_id
                ]);
            }

            return [
                'success' => $successfulUpdates > 0,
                'total' => count($products),
                'updated' => $successfulUpdates,
                'failed' => $failedUpdates
            ];

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی محصولات در ووکامرس: ' . $e->getMessage(), [
                'products_count' => count($products),
                'products_skus' => array_column($products, 'sku'),
                'license_id' => $this->license_id,
                'error_code' => $e->getCode(),
                'error_trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    protected function prepareProductData($product, $userSettings): array
    {
        $data = [
            'unique_id' => $product['unique_id'],
            'sku' => $product['barcode']
        ];

        if ($userSettings->enable_name_update) {
            $data['name'] = $product['name'];
        }

        if ($userSettings->enable_price_update) {
            $data['regular_price'] = (string)$product['regular_price'];
            if (isset($product['CurrentDiscount']) && $product['CurrentDiscount'] > 0) {
                $data['sale_price'] = (string)($product['regular_price'] - $product['CurrentDiscount']);
            }
        }

        if ($userSettings->enable_stock_update) {
            $data['stock_quantity'] = $product['stock_quantity'];
            $data['manage_stock'] = true;
            $data['stock_status'] = $product['stock_quantity'] > 0 ? 'instock' : 'outofstock';
        }

        // اضافه کردن product_id و variation_id اگر وجود داشته باشند
        if (isset($product['product_id'])) {
            $data['product_id'] = $product['product_id'];
        }
        if (isset($product['variation_id'])) {
            $data['variation_id'] = $product['variation_id'];
        }


        return $data;
    }

    public function failed(\Throwable $exception)
    {
        Log::error('خطا در پردازش صف به‌روزرسانی محصولات ووکامرس: ' . $exception->getMessage(), [
            'license_id' => $this->license_id,
            'operation' => $this->operation
        ]);
    }
}
