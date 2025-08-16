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
                // بررسی وجود محصول در دیتابیس
                $itemId = $product['ItemID'] ?? $product['item_id'] ?? $product['ItemId'] ?? null;
                if (empty($itemId)) {
                    Log::warning('شناسه یکتای محصول خالی است', [
                        'product' => $product
                    ]);
                    continue;
                }

                $existingProduct = Product::where('item_id', $itemId)
                    ->where('license_id', $this->license_id)
                    ->first();

                if ($existingProduct) {
                    // محصول وجود دارد، به‌روزرسانی کن
                    $productsToUpdate[] = $this->prepareProductData($product, $userSetting);
                } else {
                    // محصول وجود ندارد، به عنوان محصول جدید درج کن
                    Log::info("محصول با ItemID {$itemId} در دیتابیس یافت نشد، به عنوان محصول جدید درج می‌شود");
                    $productsToInsert[] = $product;
                }
            }

            // به‌روزرسانی محصولات موجود
            if (!empty($productsToUpdate)) {
                Log::info('محصولات در حال به‌روزرسانی:', [
                    'license_id' => $this->license_id,
                    'unique_ids' => collect($productsToUpdate)->pluck('unique_id')->take(10)->toArray(), // نمایش 10 آیتم اول
                    'total_count' => count($productsToUpdate),
                    'batch_size' => $this->batchSize
                ]);

                // تقسیم محصولات به دسته‌های کوچکتر برای جلوگیری از خطاهای JSON
                $chunks = array_chunk($productsToUpdate, $this->batchSize);
                $totalChunks = count($chunks);

                Log::info('تقسیم محصولات به دسته‌های کوچکتر برای به‌روزرسانی', [
                    'total_products' => count($productsToUpdate),
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

                        try {
                            $httpClient = Http::withOptions([
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
                            );

                            // لاگ کردن ساختار دقیق درخواست برای بررسی
                            Log::info('ارسال درخواست batch update به ووکامرس', [
                                'license_id' => $this->license_id,
                                'chunk_index' => $index + 1,
                                'url' => $license->website_url . '/wp-json/wc/v3/products/unique/batch/update',
                                'products_count' => count($chunk),
                                'sample_product' => $chunk[0] ?? null, // نمونه اول برای بررسی ساختار
                                'request_structure' => [
                                    'products' => count($chunk)
                                ]
                            ]);

                            $response = $httpClient->put($license->website_url . '/wp-json/wc/v3/products/unique/batch/update', [
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
                        }                        // پردازش پاسخ برای این دسته
                        $chunkResult = $this->processChunkResponse($response, $chunk, 'update');
                        $successfulUpdates += $chunkResult['successful'];
                        $failedUpdates += $chunkResult['failed'];

                        if (!empty($chunkResult['errors'])) {
                            $errors = array_merge($errors, $chunkResult['errors']);
                        }

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
                    'total_products' => count($productsToUpdate),
                    'successful_updates' => $successfulUpdates,
                    'failed_updates' => $failedUpdates,
                    'total_chunks' => $totalChunks,
                    'errors_count' => count($errors),
                    'license_id' => $this->license_id
                ]);

                // اگر خطایی رخ داده باشد، گزارش آن را ثبت می‌کنیم
                if (!empty($errors)) {
                    if ($successfulUpdates > 0) {
                        // برخی موارد با موفقیت انجام شده‌اند
                        Log::warning('به‌روزرسانی با برخی خطاها انجام شد', [
                            'errors_sample' => array_slice($errors, 0, 5), // نمایش 5 خطای اول
                            'total_errors' => count($errors),
                            'license_id' => $this->license_id
                        ]);
                    } else {
                        // هیچ موردی با موفقیت انجام نشده است
                        Log::error('هیچ یک از محصولات با موفقیت به‌روزرسانی نشدند', [
                            'errors_sample' => array_slice($errors, 0, 5), // نمایش 5 خطای اول
                            'total_errors' => count($errors),
                            'license_id' => $this->license_id
                        ]);

                        // به جای پرتاب استثنا، فقط لاگ می‌کنیم و ادامه می‌دهیم
                        // این باعث می‌شود حتی اگر به‌روزرسانی با شکست مواجه شود، پردازش ادامه یابد
                        Log::warning('علی‌رغم خطاها، پردازش ادامه می‌یابد', [
                            'license_id' => $this->license_id
                        ]);
                    }
                }
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

        // لاگ کردن داده‌های ورودی برای debugging
        Log::info('آماده‌سازی داده‌های محصول برای به‌روزرسانی', [
            'license_id' => $this->license_id,
            'item_id' => $productData['ItemID'] ?? $productData['item_id'] ?? $productData['ItemId'] ?? 'نامشخص',
            'received_fields' => array_keys($productData),
            'enable_price_update' => $userSetting->enable_price_update,
            'enable_stock_update' => $userSetting->enable_stock_update,
            'enable_name_update' => $userSetting->enable_name_update
        ]);

        $data = [
            'unique_id' => (string)($productData['ItemID'] ?? $productData['item_id']),
            'sku' => (string)($productData['Barcode'] ?? $productData['barcode'] ?? $productData['sku'] ?? '')
        ];

        if ($userSetting->enable_name_update) {
            $data['name'] = $productData['Name'] ?? $productData['name'] ?? $productData['item_name'] ?? $productData['ItemName'] ?? '';
        }

        if ($userSetting->enable_price_update) {
            // استفاده از فیلدهای صحیح RainSale API بر اساس response structure
            // Response: {"GetItemInfosResult": [{"Price": 490000000.000, ...}]}
            $regularPrice = (float)($productData['Price'] ?? $productData['price'] ?? $productData['price_amount'] ?? $productData['PriceAmount'] ?? $productData['regular_price'] ?? 0);

            // برای قیمت با تخفیف از CurrentDiscount استفاده می‌کنیم
            $currentDiscount = (float)($productData['CurrentDiscount'] ?? $productData['current_discount'] ?? 0);
            $salePrice = 0;

            // محاسبه قیمت با تخفیف اگر تخفیف وجود دارد
            if ($currentDiscount > 0) {
                $salePrice = $regularPrice - ($regularPrice * $currentDiscount / 100);
            }

            // لاگ کردن قیمت‌های دریافتی
            Log::info('قیمت‌های دریافت شده برای محصول', [
                'license_id' => $this->license_id,
                'item_id' => $productData['ItemID'] ?? $productData['item_id'] ?? 'نامشخص',
                'regular_price_raw' => $regularPrice,
                'current_discount' => $currentDiscount,
                'calculated_sale_price' => $salePrice,
                'rain_sale_unit' => $userSetting->rain_sale_price_unit,
                'woocommerce_unit' => $userSetting->woocommerce_price_unit
            ]);

            // تبدیل قیمت‌ها به واحد ووکامرس
            if ($regularPrice > 0) {
                $data['regular_price'] = (string)$this->convertPriceUnit(
                    $regularPrice,
                    $userSetting->rain_sale_price_unit,
                    $userSetting->woocommerce_price_unit
                );

                // اگر تخفیف وجود دارد، قیمت فروش را تنظیم می‌کنیم
                if ($salePrice > 0 && $salePrice < $regularPrice) {
                    $data['sale_price'] = (string)$this->convertPriceUnit(
                        $salePrice,
                        $userSetting->rain_sale_price_unit,
                        $userSetting->woocommerce_price_unit
                    );
                }
            }
        }

        if ($userSetting->enable_stock_update) {
            // دریافت موجودی از مدل Product محلی به جای API باران
            $itemId = $productData['ItemID'] ?? $productData['item_id'] ?? $productData['ItemId'] ?? null;
            $stockQuantity = 0;

            if ($itemId) {
                $localProduct = Product::where('item_id', $itemId)
                    ->where('license_id', $this->license_id)
                    ->first();

                if ($localProduct) {
                    $stockQuantity = (int)$localProduct->total_count;

                    Log::info('موجودی از دیتابیس محلی دریافت شد', [
                        'license_id' => $this->license_id,
                        'item_id' => $itemId,
                        'local_stock_quantity' => $stockQuantity,
                        'barcode' => $productData['Barcode'] ?? $productData['barcode'] ?? 'نامشخص'
                    ]);
                } else {
                    Log::warning('محصول در دیتابیس محلی یافت نشد، موجودی صفر تنظیم شد', [
                        'license_id' => $this->license_id,
                        'item_id' => $itemId,
                        'barcode' => $productData['Barcode'] ?? $productData['barcode'] ?? 'نامشخص'
                    ]);
                }
            } else {
                Log::warning('item_id برای محصول وجود ندارد، موجودی صفر تنظیم شد', [
                    'license_id' => $this->license_id,
                    'product_data' => $productData
                ]);
            }

            $data['manage_stock'] = true;
            $data['stock_quantity'] = $stockQuantity;
            $data['stock_status'] = $stockQuantity > 0 ? 'instock' : 'outofstock';
        }        // لاگ کردن داده‌های نهایی آماده شده
        Log::info('داده‌های نهایی آماده شده برای WooCommerce', [
            'license_id' => $this->license_id,
            'item_id' => $productData['ItemID'] ?? $productData['item_id'] ?? $productData['ItemId'] ?? 'نامشخص',
            'prepared_data' => $data
        ]);

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
                    $result['errors'][] = [
                        'error' => $response->body(),
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
                Log::info('محصولات با موفقیت به‌روزرسانی شدند', [
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
                    Log::warning('برخی محصولات به‌روزرسانی نشدند', [
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
    }

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
