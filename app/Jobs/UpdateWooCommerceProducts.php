<?php

namespace App\Jobs;

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
use Automattic\WooCommerce\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class UpdateWooCommerceProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 55; // کاهش تایم‌اوت به 55 ثانیه
    public $maxExceptions = 3;
    public $backoff = [10, 30, 60]; // کاهش به 10، 30 و 60 ثانیه

    protected $products;
    protected $license_id;
    protected $operation;
    protected $barcodes;
    protected $batchSize = 10; // تنظیم اندازه بسته به 10

    public function __construct($license_id, $operation, $barcodes = [], $batchSize = 10)
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
            // لاگ شروع صف
            Log::info('شروع پردازش صف به‌روزرسانی محصولات ووکامرس', [
                'license_id' => $this->license_id,
                'operation' => $this->operation,
                'barcodes_count' => count($this->barcodes),
                'start_time' => date('Y-m-d H:i:s')
            ]);

            $license = License::with(['userSetting', 'woocommerceApiKey'])->find($this->license_id);
            if (!$license || !$license->isActive()) {
                Log::error('لایسنس معتبر نیست', [
                    'license_id' => $this->license_id
                ]);
                return;
            }

            $userSettings = $license->userSetting;
            $wooApiKey = $license->woocommerceApiKey;

            if (!$userSettings || !$wooApiKey) {
                Log::error('تنظیمات یا کلید API ووکامرس یافت نشد', [
                    'license_id' => $license->id
                ]);
                return;
            }

            if (!$wooApiKey) {
                Log::error('کلید API ووکامرس یافت نشد', [
                    'license_id' => $license->id
                ]);
                return;
            }

            $woocommerce = new Client(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                [
                    'version' => 'wc/v3',
                    'verify_ssl' => false,
                    'timeout' => 30 // کاهش timeout
                ]
            );

            // دریافت محصولات از ووکامرس
            $wooProducts = $this->getWooCommerceProducts($woocommerce);

            if (empty($wooProducts)) {
                Log::info('هیچ محصولی در ووکامرس یافت نشد', [
                    'license_id' => $this->license_id
                ]);
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
                Log::info('نزدیک timeout شدیم، تقسیم کار', [
                    'license_id' => $this->license_id,
                    'elapsed_time' => round($elapsedTime, 2),
                    'products_count' => count($wooProducts)
                ]);

                // تقسیم محصولات به batch های کوچکتر
                $productChunks = array_chunk($wooProducts, 15);

                foreach ($productChunks as $index => $chunk) {
                    $chunkBarcodes = array_column($chunk, 'barcode');

                    // ایجاد job جدید برای هر chunk
                    UpdateWooCommerceProducts::dispatch(
                        $this->license_id,
                        'chunk',
                        $chunkBarcodes,
                        15
                    )
                    ->onQueue('bulk-update')
                    ->delay(now()->addSeconds(($index + 1) * 15));

                    Log::info('ارسال chunk جدید', [
                        'license_id' => $this->license_id,
                        'chunk_index' => $index,
                        'chunk_size' => count($chunk),
                        'delay_seconds' => ($index + 1) * 15
                    ]);
                }

                return; // خروج از job فعلی
            }

            // ادامه پردازش عادی
            $this->processProducts($wooProducts, $userSettings, $woocommerce, $startTime, $maxExecutionTime);

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی محصولات در ووکامرس: ' . $e->getMessage(), [
                'license_id' => $this->license_id,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * پردازش محصولات با مدیریت زمان
     */
    private function processProducts($wooProducts, $userSettings, $woocommerce, $startTime, $maxExecutionTime)
    {
        // استخراج بارکدها
        $barcodes = collect($wooProducts)->pluck('barcode')->filter(function($barcode) {
            return !is_null($barcode) && !empty($barcode);
        })->values()->toArray();

        // تقسیم بارکدها به دسته‌های کوچکتر
        $barcodeChunks = array_chunk($barcodes, 20); // کاهش از 100 به 20

        $allProducts = [];
        foreach ($barcodeChunks as $chunkIndex => $chunk) {
            // بررسی زمان قبل از هر chunk
            $elapsedTime = microtime(true) - $startTime;
            if ($elapsedTime > $maxExecutionTime - 10) {
                Log::info('زمان به پایان رسید، ارسال بقیه chunks به job جدید', [
                    'license_id' => $this->license_id,
                    'elapsed_time' => round($elapsedTime, 2),
                    'processed_chunks' => $chunkIndex,
                    'remaining_chunks' => count($barcodeChunks) - $chunkIndex
                ]);

                // ارسال chunks باقی‌مانده
                $remainingChunks = array_slice($barcodeChunks, $chunkIndex);
                $remainingBarcodes = array_merge(...$remainingChunks);

                UpdateWooCommerceProducts::dispatch(
                    $this->license_id,
                    'continuation',
                    $remainingBarcodes,
                    20
                )
                ->onQueue('bulk-update')
                ->delay(now()->addSeconds(10));

                break;
            }

            $rainProducts = $this->getRainProducts($chunk);
            if (!empty($rainProducts)) {
                $allProducts = array_merge($allProducts, $rainProducts);
            }
        }

        if (!empty($allProducts)) {
            $this->updateProductsInWooCommerce($allProducts, $wooProducts, $userSettings, $woocommerce);
        }
    }

    /**
     * به‌روزرسانی محصولات در ووکامرس
     */
    private function updateProductsInWooCommerce($allProducts, $wooProducts, $userSettings, $woocommerce)
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
            $this->updateWooCommerceProducts($woocommerce, $productsToUpdate);
        }
    }

    /**
     * دریافت محصولات از ووکامرس
     */
    protected function getWooCommerceProducts($woocommerce)
    {
        try {
            $response = $woocommerce->get('products/unique');

            if (!isset($response->success) || !$response->success || !isset($response->data)) {
                Log::error('پاسخ نامعتبر از API ووکامرس', [
                    'response' => $response
                ]);
                return [];
            }

            // تبدیل داده‌های stdClass به آرایه
            $products = [];
            foreach ($response->data as $product) {
                $products[] = [
                    'barcode' => $product->barcode ?? null,
                    'product_id' => $product->product_id ?? null,
                    'variation_id' => $product->variation_id ?? null
                ];
            }

            Log::info('تعداد محصولات دریافت شده از ووکامرس', [
                'count' => count($products),
                'license_id' => $this->license_id
            ]);

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
                Log::error('لایسنس یا کاربر یافت نشد', [
                    'license_id' => $this->license_id
                ]);
                return $this->getProductsFromDatabase($barcodes);
            }

            $user = $license->user;
            $userSettings = $license->userSetting;

            // دریافت default_warehouse_code از تنظیمات
            $stockId = $userSettings ? $userSettings->default_warehouse_code : '';

            Log::info('استفاده از default_warehouse_code برای stockId', [
                'license_id' => $this->license_id,
                'stock_id' => $stockId,
                'has_user_settings' => !is_null($userSettings)
            ]);

            if (!$user->api_webservice || !$user->api_username || !$user->api_password) {
                Log::warning('اطلاعات API باران کاربر یافت نشد، استفاده از داده‌های دیتابیس', [
                    'user_id' => $user->id,
                    'license_id' => $license->id
                ]);
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
                Log::warning('خطا در دریافت اطلاعات از API باران، استفاده از داده‌های دیتابیس', [
                    'response' => $response->body(),
                    'user_id' => $user->id,
                    'license_id' => $license->id
                ]);
                return $this->getProductsFromDatabase($barcodes);
            }

            $data = $response->json();
            return $data['GetItemInfosResult'] ?? [];
        } catch (\Exception $e) {
            Log::warning('خطا در دریافت اطلاعات از API باران، استفاده از داده‌های دیتابیس: ' . $e->getMessage(), [
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

            Log::info('اطلاعات محصولات از دیتابیس دریافت شد', [
                'license_id' => $this->license_id,
                'count' => count($result)
            ]);

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
     */
    protected function updateWooCommerceProducts($woocommerce, $products)
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

            // تقسیم محصولات به دسته‌های کوچکتر برای جلوگیری از خطاهای JSON
            $chunks = array_chunk($products, $this->batchSize);
            $totalChunks = count($chunks);

            Log::info('تقسیم محصولات به دسته‌های کوچکتر برای به‌روزرسانی', [
                'total_products' => count($products),
                'batch_size' => $this->batchSize,
                'total_chunks' => $totalChunks,
                'license_id' => $this->license_id
            ]);

            $successfulUpdates = 0;
            $failedUpdates = 0;
            $errors = [];

            foreach ($chunks as $index => $chunk) {
                try {
                    Log::info('به‌روزرسانی دسته محصولات', [
                        'chunk_index' => $index + 1,
                        'total_chunks' => $totalChunks,
                        'chunk_size' => count($chunk),
                        'license_id' => $this->license_id
                    ]);

                    $response = $woocommerce->put('products/unique/batch/update', [
                        'products' => $chunk
                    ]);

                    // بررسی تعداد محصولات به‌روز شده در این دسته
                    $updatedInChunk = isset($response->data) ? count($response->data) : 0;
                    $successfulUpdates += $updatedInChunk;

                    Log::info('دسته محصولات با موفقیت به‌روزرسانی شد', [
                        'chunk_index' => $index + 1,
                        'updated_count' => $updatedInChunk,
                        'license_id' => $this->license_id
                    ]);

                    // اضافه کردن تاخیر بین درخواست‌ها برای جلوگیری از اورلود سرور
                    if ($index < $totalChunks - 1) {
                        sleep(2); // 2 ثانیه تاخیر بین هر درخواست
                    }

                } catch (\Exception $e) {
                    $failedUpdates += count($chunk);
                    $errors[] = [
                        'chunk_index' => $index + 1,
                        'error_message' => $e->getMessage(),
                        'products_count' => count($chunk),
                        'first_few_skus' => array_slice(array_column($chunk, 'sku'), 0, 5) // نمایش 5 بارکد اول برای تشخیص
                    ];

                    Log::error('خطا در به‌روزرسانی دسته محصولات: ' . $e->getMessage(), [
                        'chunk_index' => $index + 1,
                        'products_count' => count($chunk),
                        'license_id' => $this->license_id,
                        'error_code' => $e->getCode()
                    ]);

                    // ادامه اجرا با دسته بعدی، بدون توقف کامل فرآیند
                    continue;
                }
            }

            // گزارش نهایی به‌روزرسانی
            Log::info('پایان فرآیند به‌روزرسانی محصولات', [
                'total_products' => count($products),
                'successful_updates' => $successfulUpdates,
                'failed_updates' => $failedUpdates,
                'total_chunks' => $totalChunks,
                'errors_count' => count($errors),
                'timestamp' => now()->toDateTimeString(),
                'license_id' => $this->license_id
            ]);

            // اگر خطایی رخ داده باشد ولی بعضی موارد با موفقیت انجام شده باشند
            if (!empty($errors) && $successfulUpdates > 0) {
                Log::warning('به‌روزرسانی با برخی خطاها انجام شد', [
                    'errors' => $errors,
                    'license_id' => $this->license_id
                ]);
            }
            // اگر همه موارد با خطا مواجه شدند، استثنا پرتاب می‌کنیم
            else if (count($errors) === $totalChunks) {
                throw new \Exception('تمام دسته‌های به‌روزرسانی با خطا مواجه شدند');
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
