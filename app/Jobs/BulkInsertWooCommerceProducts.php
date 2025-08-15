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

        // فیلدهای یک محصول نمونه را لاگ می‌کنیم برای عیب‌یابی
        if (count($products) > 0) {
            Log::info('نمونه محصول دریافتی در job', [
                'field_keys' => array_keys($products[0]),
                'has_ItemName' => isset($products[0]['ItemName']),
                'has_item_name' => isset($products[0]['item_name']),
                'ItemName_value' => $products[0]['ItemName'] ?? 'not set',
                'license_id' => $license_id
            ]);
        }
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

            // یک بررسی اضافه میکنیم برای تشخیص محصولات بدون نام
            // و ثبت در لاگ جهت عیب‌یابی
            if (count($this->products) > 0) {
                foreach ($this->products as $index => $product) {
                    // Check if we need to recover item name from the original products array
                    if (!isset($product['name']) && !isset($product['ItemName']) && !isset($product['item_name'])) {
                        Log::warning('محصول بدون نام در ابتدای پردازش - بررسی مقادیر تمام فیلدها:', [
                            'product_sku' => $product['sku'] ?? $product['Barcode'] ?? 'unknown',
                            'all_fields' => array_keys($product),
                            'license_id' => $this->license_id
                        ]);
                    }
                }
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

                Log::info('درج محصولات جدید:', [
                    'license_id' => $this->license_id,
                    'unique_ids' => collect($productsToCreate)->pluck('unique_id')->take(10)->toArray(), // نمایش 10 آیتم اول
                    'total_count' => count($productsToCreate),
                    'batch_size' => $this->batchSize
                ]);

                // تقسیم محصولات به دسته‌های کوچکتر برای جلوگیری از خطاهای JSON
                $chunks = array_chunk($productsToCreate, $this->batchSize);
                $totalChunks = count($chunks);

                Log::info('تقسیم محصولات به دسته‌های کوچکتر برای درج', [
                    'total_products' => count($productsToCreate),
                    'batch_size' => $this->batchSize,
                    'total_chunks' => $totalChunks,
                    'license_id' => $this->license_id
                ]);

                $successfulInserts = 0;
                $failedInserts = 0;
                $errors = [];

                foreach ($chunks as $index => $chunk) {
                    try {
                        Log::info('درج دسته محصولات', [
                            'chunk_index' => $index + 1,
                            'total_chunks' => $totalChunks,
                            'chunk_size' => count($chunk),
                            'license_id' => $this->license_id,
                            'sample_product' => count($chunk) > 0 ? [
                                'ItemName' => $chunk[0]['ItemName'] ?? 'not set',
                                'item_name' => $chunk[0]['item_name'] ?? 'not set',
                                'Barcode' => $chunk[0]['Barcode'] ?? 'not set',
                                'barcode' => $chunk[0]['barcode'] ?? 'not set'
                            ] : []
                        ]);

                        try {
                            // اطمینان از وجود فیلدهای اجباری قبل از ارسال به API
                            foreach ($chunk as $index => $product) {
                                // بررسی وجود نام - در هر دو حالت نام‌گذاری فیلدها
                                $hasName = !empty($product['name']) || !empty($product['ItemName']) || !empty($product['item_name']);

                                if (!$hasName) {
                                    // تعیین بارکد برای استفاده در نام پیش‌فرض
                                    $sku = $product['sku'] ?? $product['Barcode'] ?? 'unknown';

                                    Log::warning('محصول بدون نام یافت شد - اضافه کردن نام پیش‌فرض', [
                                        'product_sku' => $sku,
                                        'license_id' => $this->license_id
                                    ]);

                                    // اضافه کردن نام پیش‌فرض برای جلوگیری از خطای API
                                    $chunk[$index]['name'] = 'محصول ' . $sku;
                                } else if (empty($product['name']) && (!empty($product['ItemName']) || !empty($product['item_name']))) {
                                    // اگر نام به فرمت کیمل‌کیس یا اندراسکور وجود دارد اما در فیلد 'name' نیست
                                    $chunk[$index]['name'] = $product['ItemName'] ?? $product['item_name'];
                                }
                            }

                            $response = Http::withOptions([
                                'verify' => false,
                                'timeout' => 180,
                                'connect_timeout' => 60
                            ])->retry(3, 300, function ($exception, $request) {
                                // لاگ کردن خطاهای شبکه برای کمک به تشخیص مشکلات
                                if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                                    Log::error('خطای اتصال به وب‌سرویس ووکامرس (تلاش مجدد):', [
                                        'error' => $exception->getMessage(),
                                        'license_id' => $this->license_id
                                    ]);
                                } elseif (isset($exception->response)) {
                                    Log::error('خطای سرور در وب‌سرویس ووکامرس (تلاش مجدد):', [
                                        'status' => $exception->response->status(),
                                        'body' => $exception->response->body(),
                                        'license_id' => $this->license_id
                                    ]);
                                }

                                return $exception instanceof \Illuminate\Http\Client\ConnectionException ||
                                       (isset($exception->response) && $exception->response->status() >= 500);
                            })->withHeaders([
                                'Content-Type' => 'application/json',
                                'Accept' => 'application/json'
                            ])->withBasicAuth(
                                $wooCommerceApiKey->api_key,
                                $wooCommerceApiKey->api_secret
                            )->post($license->website_url . '/wp-json/wc/v3/products/unique/batch', [
                                'products' => $chunk
                            ]);
                        } catch (\Exception $connectionException) {
                            // ثبت جزئیات خطای اتصال
                            Log::error('خطای اتصال به وب‌سرویس ووکامرس:', [
                                'exception' => get_class($connectionException),
                                'message' => $connectionException->getMessage(),
                                'chunk_index' => $index + 1,
                                'license_id' => $this->license_id,
                                'website_url' => $license->website_url
                            ]);

                            throw $connectionException;
                        }

                        // پردازش پاسخ برای این دسته
                        $chunkResult = $this->processChunkResponse($response, $chunk, 'insert');
                        $successfulInserts += $chunkResult['successful'];
                        $failedInserts += $chunkResult['failed'];

                        if (!empty($chunkResult['errors'])) {
                            $errors = array_merge($errors, $chunkResult['errors']);
                        }

                        // اضافه کردن تاخیر بین درخواست‌ها برای جلوگیری از اورلود سرور
                        if ($index < $totalChunks - 1) {
                            sleep(2); // 2 ثانیه تاخیر بین هر درخواست
                        }

                    } catch (\Exception $e) {
                        $failedInserts += count($chunk);
                        $errors[] = [
                            'chunk_index' => $index + 1,
                            'error_message' => $e->getMessage(),
                            'products_count' => count($chunk),
                            'first_few_skus' => array_slice(array_column($chunk, 'sku'), 0, 5) // نمایش 5 بارکد اول برای تشخیص
                        ];

                        Log::error('خطا در درج دسته محصولات: ' . $e->getMessage(), [
                            'chunk_index' => $index + 1,
                            'products_count' => count($chunk),
                            'license_id' => $this->license_id,
                            'error_code' => $e->getCode()
                        ]);

                        // ادامه اجرا با دسته بعدی، بدون توقف کامل فرآیند
                        continue;
                    }
                }

                // گزارش نهایی درج
                Log::info('پایان فرآیند درج محصولات', [
                    'total_products' => count($productsToCreate),
                    'successful_inserts' => $successfulInserts,
                    'failed_inserts' => $failedInserts,
                    'total_chunks' => $totalChunks,
                    'errors_count' => count($errors),
                    'license_id' => $this->license_id
                ]);

                // اگر خطایی رخ داده باشد، گزارش آن را ثبت می‌کنیم
                if (!empty($errors)) {
                    if ($successfulInserts > 0) {
                        // برخی موارد با موفقیت انجام شده‌اند
                        Log::warning('درج با برخی خطاها انجام شد', [
                            'errors_sample' => array_slice($errors, 0, 5), // نمایش 5 خطای اول
                            'total_errors' => count($errors),
                            'license_id' => $this->license_id
                        ]);
                    } else {
                        // هیچ موردی با موفقیت انجام نشده است
                        Log::error('هیچ یک از محصولات با موفقیت درج نشدند', [
                            'errors_sample' => array_slice($errors, 0, 5), // نمایش 5 خطای اول
                            'total_errors' => count($errors),
                            'license_id' => $this->license_id
                        ]);

                        // به جای پرتاب استثنا، فقط لاگ می‌کنیم و ادامه می‌دهیم
                        // این باعث می‌شود حتی اگر درج با شکست مواجه شود، پردازش ادامه یابد
                        Log::warning('علی‌رغم خطاها، پردازش ادامه می‌یابد', [
                            'license_id' => $this->license_id
                        ]);
                    }
                }
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

        // مپینگ نام‌های مختلف فیلدها (کیمل کیس و آندراسکور)
        $itemId = $productData['item_id'] ?? $productData['ItemId'] ?? null;
        $barcode = $productData['barcode'] ?? $productData['Barcode'] ?? '';
        $itemName = $productData['item_name'] ?? $productData['ItemName'] ?? '';
        $totalCount = $productData['total_count'] ?? $productData['TotalCount'] ?? 0;
        $departmentName = $productData['department_name'] ?? $productData['DepartmentName'] ?? null;
        $priceAmount = $productData['price_amount'] ?? $productData['PriceAmount'] ?? 0;
        $isVariant = $productData['is_variant'] ?? $productData['IsVariant'] ?? false;
        $parentId = $productData['parent_id'] ?? $productData['ParentId'] ?? null;
        $discountPercentage = $productData['discount_percentage'] ?? $productData['DiscountPercentage'] ?? 0;
        $priceIncreasePercentage = $productData['price_increase_percentage'] ?? $productData['PriceIncreasePercentage'] ?? 0;

        $data = [
            'unique_id' => (string)$itemId,
            'sku' => (string)$barcode,
            'status' => 'draft',
            'manage_stock' => true,
            'stock_quantity' => (int)$totalCount,
            'stock_status' => (int)$totalCount > 0 ? 'instock' : 'outofstock'
        ];

        // اضافه کردن اطلاعات واریانت اگر موجود باشد
        if ($isVariant) {
            $data['is_variant'] = true;
            if ($parentId) {
                // اگر parentId یک شناسه یکتا (ItemId) است
                if (is_string($parentId) && strlen($parentId) > 10) {
                    $data['parent_id'] = $parentId;
                    // لاگ برای بررسی و عیب‌یابی
                    Log::info('محصول متغیر با شناسه یکتای والد', [
                        'barcode' => $barcode,
                        'parent_item_id' => $parentId
                    ]);
                } else if (is_numeric($parentId)) {
                    // اگر parentId یک شناسه عددی است (احتمالاً ID داخلی دیتابیس)
                    $data['parent_id'] = (int)$parentId;
                    Log::info('محصول متغیر با شناسه عددی والد', [
                        'barcode' => $barcode,
                        'parent_db_id' => $parentId
                    ]);
                } else {
                    $data['parent_id'] = $parentId;
                }
            } else {
                // محصول متغیر بدون والد، احتمالاً خود محصول مادر است
                Log::info('محصول متغیر مادر (بدون parent_id)', [
                    'barcode' => $barcode,
                    'item_id' => $itemId
                ]);
            }

            // برای محصولات متغیر، نوع محصول در WooCommerce را تنظیم می‌کنیم
            if ($parentId) {
                $data['type'] = 'variation';
            } else {
                $data['type'] = 'variable';
            }
        } else {
            // محصول عادی (غیر متغیر)
            $data['type'] = 'simple';
        }

        // name is a required field for WooCommerce API product creation
        $data['name'] = !empty($itemName) ?
            $itemName :
            'محصول ' . $barcode; // Default name if missing

        // We'll still respect the setting for updates, but for inserts, name is required
        // This just adds a flag to track if name updates are enabled
        $enableNameUpdate = $userSetting->enable_name_update;

        if ($userSetting->enable_price_update || $this->operation === 'insert') {
            $regularPrice = $this->calculateFinalPrice(
                (float)$priceAmount,
                0,
                (float)$priceIncreasePercentage
            );

            $salePrice = $this->calculateFinalPrice(
                (float)$priceAmount,
                (float)$discountPercentage,
                (float)$priceIncreasePercentage
            );

            $data['regular_price'] = (string)$this->convertPriceUnit(
                $regularPrice,
                $userSetting->rain_sale_price_unit,
                $userSetting->woocommerce_price_unit
            );

            if ($discountPercentage > 0) {
                $data['sale_price'] = (string)$this->convertPriceUnit(
                    $salePrice,
                    $userSetting->rain_sale_price_unit,
                    $userSetting->woocommerce_price_unit
                );
            }
        }

        // اضافه کردن دسته‌بندی ووکامرس بر اساس department_name
        if (!empty($departmentName) && !empty($productData['category_id'])) {
            $data['categories'] = [['id' => $productData['category_id']]];
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
    /**
     * پردازش پاسخ برای یک دسته از محصولات
     *
     * @param \Illuminate\Http\Client\Response $response
     * @param array $products
     * @param string $operation
     * @return array
     */
    protected function processChunkResponse($response, $products, $operation)
    {
        $result = [
            'successful' => 0,
            'failed' => 0,
            'errors' => []
        ];

        try {
            // پردازش پاسخ موفق یا ناموفق
            if (!$response->successful()) {
                // لاگ کردن کد وضعیت و متن پاسخ
                Log::error('پاسخ ناموفق از API ووکامرس دریافت شد', [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'license_id' => $this->license_id,
                    'operation' => $operation
                ]);

                // تلاش برای رمزگشایی پاسخ JSON
                $errorData = null;
                try {
                    $errorData = json_decode($response->body(), true);

                    // بررسی خطاهای مربوط به فیلدهای ضروری
                    if ($response->status() === 400 && isset($errorData['message'])) {
                        $errorMessage = $errorData['message'];
                        // چک کردن خطاهای مربوط به فیلدهای اجباری
                        if (strpos($errorMessage, 'یکی از ویژگی‌های لازم') !== false ||
                            strpos($errorMessage, 'required property') !== false) {

                            Log::error('خطای فیلد اجباری در درخواست API:', [
                                'message' => $errorMessage,
                                'products_sample' => array_slice($products, 0, 3),
                                'license_id' => $this->license_id
                            ]);
                        }
                    }
                } catch (\Exception $jsonError) {
                    Log::error('خطا در رمزگشایی پاسخ JSON: ' . $jsonError->getMessage(), [
                        'response_body' => $response->body()
                    ]);
                }

                if (isset($errorData['failed']) && is_array($errorData['failed'])) {
                    foreach ($errorData['failed'] as $failed) {
                        $result['errors'][] = [
                            'unique_id' => $failed['unique_id'] ?? 'نامشخص',
                            'error' => $failed['error'] ?? 'خطای نامشخص'
                        ];
                        $result['failed']++;
                    }
                } else {
                    // اگر ساختار خطا متفاوت است، کل پاسخ را به عنوان خطا در نظر می‌گیریم
                    $errorMessage = $response->body();

                    // بررسی خطاهای خاص و قابل شناسایی
                    if ($response->status() === 400 && isset($errorData['message'])) {
                        $errorMessage = $errorData['message'];

                        // خطای فیلدهای اجباری
                        if (strpos($errorMessage, 'یکی از ویژگی‌های لازم') !== false ||
                            strpos($errorMessage, 'required property') !== false) {

                            // اضافه کردن اطلاعات تشخیصی بیشتر
                            $errorMessage .= ' - بررسی کنید تمامی فیلدهای اجباری مانند name وجود داشته باشند';
                        }
                    }

                    $result['errors'][] = [
                        'error' => $errorMessage,
                        'status' => $response->status()
                    ];
                    $result['failed'] = count($products);
                }

                return $result;
            }

            // تلاش برای رمزگشایی پاسخ JSON
            $responseData = null;
            try {
                $responseData = json_decode($response->body(), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('JSON error: ' . json_last_error_msg());
                }
            } catch (\Exception $jsonError) {
                Log::error('خطا در رمزگشایی پاسخ JSON: ' . $jsonError->getMessage(), [
                    'response_body' => substr($response->body(), 0, 1000), // فقط 1000 کاراکتر اول
                    'license_id' => $this->license_id
                ]);

                $result['errors'][] = [
                    'error' => 'خطا در رمزگشایی پاسخ JSON: ' . $jsonError->getMessage(),
                    'partial_response' => substr($response->body(), 0, 1000)
                ];
                $result['failed'] = count($products);

                return $result;
            }

            // بررسی ساختار پاسخ
            if (!is_array($responseData)) {
                Log::error('پاسخ API ووکامرس به فرمت مورد انتظار نیست', [
                    'response_type' => gettype($responseData),
                    'partial_response' => substr($response->body(), 0, 1000),
                    'license_id' => $this->license_id
                ]);

                $result['errors'][] = [
                    'error' => 'پاسخ API ووکامرس به فرمت مورد انتظار نیست',
                    'response_type' => gettype($responseData)
                ];
                $result['failed'] = count($products);

                return $result;
            }

            // پردازش موارد موفق
            if (isset($responseData['success']) && is_array($responseData['success'])) {
                $result['successful'] = count($responseData['success']);

                // لاگ جزئیات موارد موفق
                Log::info('محصولات با موفقیت درج شدند', [
                    'count' => $result['successful'],
                    'first_few' => array_slice($responseData['success'], 0, 5),
                    'license_id' => $this->license_id
                ]);
            } else {
                Log::warning('کلید "success" در پاسخ API ووکامرس وجود ندارد یا آرایه نیست', [
                    'license_id' => $this->license_id,
                    'response_keys' => array_keys($responseData)
                ]);
            }

            // پردازش موارد ناموفق
            if (isset($responseData['failed']) && is_array($responseData['failed'])) {
                foreach ($responseData['failed'] as $failed) {
                    $result['errors'][] = [
                        'unique_id' => $failed['unique_id'] ?? 'نامشخص',
                        'error' => $failed['error'] ?? 'خطای نامشخص'
                    ];
                    $result['failed']++;
                }

                // لاگ جزئیات موارد ناموفق
                if ($result['failed'] > 0) {
                    Log::warning('برخی محصولات درج نشدند', [
                        'count' => $result['failed'],
                        'errors' => array_slice($result['errors'], 0, 5), // نمایش 5 خطای اول
                        'license_id' => $this->license_id
                    ]);
                }
            }

            // ثبت اطلاعات زمان پاسخگویی
            $responseTime = $response->transferStats ? $response->transferStats->getTransferTime() : null;

            Log::info('نتیجه ' . ($operation === 'insert' ? 'درج' : 'به‌روزرسانی') . ' دسته محصولات:', [
                'license_id' => $this->license_id,
                'total_count' => count($products),
                'successful_count' => $result['successful'],
                'failed_count' => $result['failed'],
                'response_time' => $responseTime
            ]);

            return $result;

        } catch (\Exception $e) {
            // در صورت بروز هر گونه خطا در پردازش پاسخ
            Log::error('خطا در پردازش پاسخ API: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'license_id' => $this->license_id
            ]);

            $result['errors'][] = [
                'error' => 'خطا در پردازش پاسخ: ' . $e->getMessage()
            ];
            $result['failed'] = count($products);

            return $result;
        }
    }    protected function handleResponse($response, $products, $operation)
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
