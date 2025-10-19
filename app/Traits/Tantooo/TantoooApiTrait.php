<?php

namespace App\Traits\Tantooo;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait TantoooApiTrait
{
    /**
     * به‌روزرسانی اطلاعات محصول در API Tantooo
     *
     * @param string $code کد یکتای محصول
     * @param string $title نام محصول
     * @param float $price قیمت محصول
     * @param float $discount درصد تخفیف (اختیاری)
     * @param string $apiUrl آدرس API Tantooo
     * @param string $apiKey کلید API
     * @param string $bearerToken توکن Bearer (اختیاری)
     * @return array نتیجه درخواست
     */
    protected function updateProductInTantoooApi($code, $title, $price, $discount = 0, $apiUrl = null, $apiKey = null, $bearerToken = null)
    {
        try {
            // بررسی پارامترهای الزامی
            if (empty($code) || empty($title) || empty($price)) {
                return [
                    'success' => false,
                    'message' => 'پارامترهای الزامی (کد، نام، قیمت) خالی هستند'
                ];
            }

            if (empty($apiUrl) || empty($apiKey)) {
                return [
                    'success' => false,
                    'message' => 'اطلاعات API Tantooo تنظیم نشده است'
                ];
            }

            // آماده‌سازی هدرها
            $headers = [
                'X-API-KEY' => $apiKey,
                'Content-Type' => 'application/json'
            ];

            // اضافه کردن Bearer Token اگر موجود باشد
            if (!empty($bearerToken)) {
                $headers['Authorization'] = 'Bearer ' . $bearerToken;
            }

            // آماده‌سازی داده‌های درخواست
            $requestData = [
                'fn' => 'update_product_sku_code',
                'sku' => $code,
                'title' => $title,
                'price' => (float) $price,
                'discount' => (float) $discount
            ];

            Log::info('ارسال درخواست به‌روزرسانی محصول به API Tantooo', [
                'api_url' => $apiUrl,
                'product_code' => $code,
                'product_title' => $title,
                'price' => $price,
                'discount' => $discount
            ]);

            // ارسال درخواست
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 60,
                'connect_timeout' => 30
            ])->withHeaders($headers)->post($apiUrl, $requestData);

            if ($response->successful()) {
                $responseData = $response->json();

                Log::info('محصول با موفقیت در API Tantooo به‌روزرسانی شد', [
                    'product_code' => $code,
                    'api_response' => $responseData
                ]);

                return [
                    'success' => true,
                    'message' => 'محصول با موفقیت در API Tantooo به‌روزرسانی شد',
                    'data' => $responseData
                ];
            } else {
                Log::error('خطا در به‌روزرسانی محصول در API Tantooo', [
                    'product_code' => $code,
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'api_url' => $apiUrl
                ]);

                return [
                    'success' => false,
                    'message' => 'خطا در به‌روزرسانی API Tantooo: ' . $response->status(),
                    'error_details' => $response->body()
                ];
            }

        } catch (\Exception $e) {
            Log::error('خطای سیستمی در به‌روزرسانی API Tantooo', [
                'product_code' => $code,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطای سیستمی در به‌روزرسانی API Tantooo',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * به‌روزرسانی چندین محصول در API Tantooo
     *
     * @param array $products آرایه محصولات شامل code, title, price, discount
     * @param string $apiUrl آدرس API Tantooo
     * @param string $apiKey کلید API
     * @param string $bearerToken توکن Bearer (اختیاری)
     * @return array نتیجه درخواست‌ها
     */
    protected function updateMultipleProductsInTantoooApi($products, $apiUrl = null, $apiKey = null, $bearerToken = null)
    {
        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($products as $product) {
            $code = $product['code'] ?? '';
            $title = $product['title'] ?? '';
            $price = $product['price'] ?? 0;
            $discount = $product['discount'] ?? 0;

            $result = $this->updateProductInTantoooApi(
                $code,
                $title,
                $price,
                $discount,
                $apiUrl,
                $apiKey,
                $bearerToken
            );

            $results[$code] = $result;

            if ($result['success']) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        return [
            'success' => $failureCount === 0,
            'total_products' => count($products),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'results' => $results
        ];
    }

    /**
     * دریافت تنظیمات API Tantooo از لایسنس
     *
     * @param object $license لایسنس
     * @return array|null تنظیمات API
     */
    protected function getTantoooApiSettings($license)
    {
        try {
            // استفاده از آدرس وب‌سایت از جدول License
            $websiteUrl = $license->website_url ?? null;

            if (empty($websiteUrl)) {
                Log::error('آدرس وب‌سایت در لایسنس یافت نشد', [
                    'license_id' => $license->id
                ]);
                return null;
            }

            // تبدیل آدرس وب‌سایت به آدرس API
            $apiUrl = rtrim($websiteUrl, '/') . '/accounting_api';

            // دریافت API key از متغیر محیطی
            $apiKey = config('services.tantooo.api_key') ?? env('TANTOOO_API_KEY');

            if (empty($apiKey)) {
                Log::error('API key Tantooo در متغیرهای محیطی یافت نشد', [
                    'license_id' => $license->id,
                    'config_key' => 'TANTOOO_API_KEY'
                ]);
                return null;
            }

            // دریافت توکن از لایسنس (از سیستم مدیریت توکن جدید)
            $token = null;
            if ($license->isTokenValid()) {
                $token = $license->api_token;
            }

            Log::info('تنظیمات API Tantooo تهیه شد', [
                'license_id' => $license->id,
                'api_url' => $apiUrl,
                'api_key_length' => strlen($apiKey),
                'api_key_preview' => substr($apiKey, 0, 8) . '...',
                'has_token' => !empty($token),
                'token_valid' => $license->isTokenValid()
            ]);

            return [
                'api_url' => $apiUrl,
                'api_key' => $apiKey,
                'bearer_token' => $token
            ];

        } catch (\Exception $e) {
            Log::error('خطا در دریافت تنظیمات API Tantooo', [
                'license_id' => $license->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * محاسبه قیمت نهایی با در نظر گیری تخفیف
     *
     * @param float $originalPrice قیمت اصلی
     * @param float $discountPercent درصد تخفیف
     * @return float قیمت نهایی
     */
    protected function calculateDiscountedPrice($originalPrice, $discountPercent)
    {
        if ($discountPercent <= 0 || $discountPercent > 100) {
            return $originalPrice;
        }

        $discountAmount = ($originalPrice * $discountPercent) / 100;
        return $originalPrice - $discountAmount;
    }

    /**
     * تبدیل اطلاعات محصول از فرمت انبار به فرمت API Tantooo
     *
     * @param array $productInfo اطلاعات محصول از انبار
     * @param object $userSettings تنظیمات کاربر
     * @return array اطلاعات محصول برای API Tantooo
     */
    protected function convertProductForTantoooApi($productInfo, $userSettings = null)
    {
        $code = $productInfo['code'] ?? $productInfo['uniqueId'] ?? '';
        $title = $productInfo['name'] ?? $productInfo['title'] ?? '';
        $price = $productInfo['sellPrice'] ?? $productInfo['price'] ?? 0;
        $discount = $productInfo['discount'] ?? 0;

        // تبدیل واحد قیمت اگر نیاز باشد
        if ($userSettings && isset($userSettings->rain_sale_price_unit) && isset($userSettings->woocommerce_price_unit)) {
            $rainSaleUnit = $userSettings->rain_sale_price_unit;
            $wooCommerceUnit = $userSettings->woocommerce_price_unit;

            if ($rainSaleUnit === 'rial' && $wooCommerceUnit === 'toman') {
                $price = $price / 10; // ریال به تومان
            } elseif ($rainSaleUnit === 'toman' && $wooCommerceUnit === 'rial') {
                $price = $price * 10; // تومان به ریال
            }
        }

        return [
            'code' => $code,
            'title' => $title,
            'price' => (float) $price,
            'discount' => (float) $discount
        ];
    }

    /**
     * دریافت اطلاعات اصلی از API Tantooo (دسته‌بندی‌ها، رنگ‌ها، سایزها)
     *
     * @param string $apiUrl آدرس API Tantooo
     * @param string $apiKey کلید API
     * @param string $bearerToken توکن Bearer
     * @return array نتیجه درخواست
     */
    protected function getTantoooMainData($apiUrl, $apiKey, $bearerToken)
    {
        try {
            if (empty($apiUrl) || empty($apiKey) || empty($bearerToken)) {
                return [
                    'success' => false,
                    'message' => 'اطلاعات API Tantooo یا توکن ناقص است'
                ];
            }

            // آماده‌سازی هدرها
            $headers = [
                'X-API-KEY' => $apiKey,
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $bearerToken
            ];

            // آماده‌سازی داده‌های درخواست
            $requestData = [
                'fn' => 'main'
            ];

            Log::info('درخواست دریافت اطلاعات اصلی از API Tantooo', [
                'api_url' => $apiUrl,
                'api_key' => substr($apiKey, 0, 8) . '...',
                'token' => substr($bearerToken, 0, 20) . '...'
            ]);

            // ارسال درخواست
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 60,
                'connect_timeout' => 30
            ])->withHeaders($headers)->post($apiUrl, $requestData);

            if ($response->successful()) {
                $responseData = $response->json();

                Log::info('اطلاعات اصلی با موفقیت از API Tantooo دریافت شد', [
                    'categories_count' => count($responseData['category'] ?? []),
                    'colors_count' => count($responseData['colors'] ?? []),
                    'sizes_count' => count($responseData['sizes'] ?? [])
                ]);

                return [
                    'success' => true,
                    'message' => 'اطلاعات اصلی با موفقیت دریافت شد',
                    'data' => $responseData
                ];
            } else {
                Log::error('خطا در دریافت اطلاعات اصلی از API Tantooo', [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'api_url' => $apiUrl
                ]);

                return [
                    'success' => false,
                    'message' => 'خطا در دریافت اطلاعات اصلی: ' . $response->status(),
                    'error_details' => $response->body()
                ];
            }

        } catch (\Exception $e) {
            Log::error('خطا در متد دریافت اطلاعات اصلی Tantooo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در دریافت اطلاعات اصلی: ' . $e->getMessage()
            ];
        }
    }

    /**
     * دریافت یا به‌روزرسانی توکن Tantooo برای لایسنس
     *
     * @param \App\Models\License $license
     * @return string|null توکن یا null در صورت خطا
     */
    protected function getTantoooToken($license)
    {
        try {
            // بررسی اعتبار توکن موجود
            Log::info('بررسی وضعیت توکن موجود', [
                'license_id' => $license->id,
                'has_token' => !empty($license->api_token),
                'expires_at' => $license->token_expires_at?->format('Y-m-d H:i:s'),
                'is_expired' => $license->token_expires_at ? $license->token_expires_at <= now() : null,
                'is_valid' => $license->isTokenValid()
            ]);

            if ($license->isTokenValid()) {
                Log::info('توکن معتبر موجود برای لایسنس', [
                    'license_id' => $license->id,
                    'expires_at' => $license->token_expires_at
                ]);
                return $license->api_token;
            }

            // دریافت تنظیمات API
            $settings = $this->getTantoooApiSettings($license);
            if (!$settings) {
                Log::error('تنظیمات API Tantooo یافت نشد', [
                    'license_id' => $license->id
                ]);
                return null;
            }

            // درخواست توکن جدید از API
            $tokenResult = $this->requestNewTantoooToken($settings['api_url'], $settings['api_key']);

            if ($tokenResult['success']) {
                $tokenData = $tokenResult['data'];

                // استخراج توکن از response (ساختار Tantooo API)
                $token = $tokenData['token'] ?? null;

                if (!$token) {
                    Log::error('توکن در پاسخ API یافت نشد', [
                        'license_id' => $license->id,
                        'response_data' => $tokenData
                    ]);
                    return null;
                }

                // بررسی وضعیت پاسخ API
                $msgCode = $tokenData['msg'] ?? null;
                $errors = $tokenData['error'] ?? [];

                if ($msgCode !== 0 || !empty($errors)) {
                    Log::error('خطا در دریافت توکن از Tantooo API', [
                        'license_id' => $license->id,
                        'msg_code' => $msgCode,
                        'errors' => $errors
                    ]);
                    return null;
                }

                // تنظیم زمان انقضای توکن - یک سال (بر اساس درخواست شما)
                $expiresAt = now()->addYear();

                // ذخیره توکن در لایسنس - فقط api_token و token_expires_at را تغییر دهید
                // expires_at (JWT token) را تغییر ندهید
                $license->update([
                    'api_token' => $token,
                    'token_expires_at' => $expiresAt
                ]);

                Log::info('توکن جدید Tantooo برای لایسنس دریافت و ذخیره شد', [
                    'license_id' => $license->id,
                    'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                    'token_length' => strlen($token),
                    'msg_code' => $msgCode
                ]);

                return $token;
            }

            Log::error('خطا در دریافت توکن جدید', [
                'license_id' => $license->id,
                'error' => $tokenResult['message'] ?? 'نامشخص'
            ]);
            return null;

        } catch (\Exception $e) {
            Log::error('خطا در دریافت/به‌روزرسانی توکن', [
                'license_id' => $license->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * درخواست توکن جدید از API Tantooo
     *
     * @param string $apiUrl آدرس API Tantooo
     * @param string $apiKey کلید API
     * @return array نتیجه درخواست
     */
    protected function requestNewTantoooToken($apiUrl, $apiKey)
    {
        try {
            if (empty($apiUrl) || empty($apiKey)) {
                return [
                    'success' => false,
                    'message' => 'اطلاعات API Tantooo تنظیم نشده است'
                ];
            }

            // آماده‌سازی هدرها
            $headers = [
                'X-API-KEY' => $apiKey,
                'Content-Type' => 'application/json'
            ];

            // آماده‌سازی داده‌های درخواست
            $requestData = [
                'fn' => 'get_token'
            ];

            Log::info('درخواست دریافت توکن جدید از API Tantooo', [
                'api_url' => $apiUrl,
                'api_key' => substr($apiKey, 0, 8) . '...' // نمایش بخشی از کلید برای امنیت
            ]);

            // ارسال درخواست
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 60,
                'connect_timeout' => 30
            ])->withHeaders($headers)->post($apiUrl, $requestData);

            if ($response->successful()) {
                $responseData = $response->json();

                Log::info('توکن جدید با موفقیت از API Tantooo دریافت شد', [
                    'response_keys' => array_keys($responseData),
                    'response_data' => $responseData, // لاگ کامل پاسخ برای تشخیص
                    'has_token' => isset($responseData['token']),
                    'msg_code' => $responseData['msg'] ?? 'undefined',
                    'has_errors' => !empty($responseData['error'] ?? [])
                ]);

                return [
                    'success' => true,
                    'message' => 'توکن با موفقیت دریافت شد',
                    'data' => $responseData
                ];
            } else {
                Log::error('خطا در دریافت توکن از API Tantooo', [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'api_url' => $apiUrl
                ]);

                return [
                    'success' => false,
                    'message' => 'خطا در دریافت توکن: ' . $response->status(),
                    'error_details' => $response->body()
                ];
            }

        } catch (\Exception $e) {
            Log::error('خطا در متد درخواست توکن جدید Tantooo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در دریافت توکن: ' . $e->getMessage()
            ];
        }
    }

    /**
     * دریافت اطلاعات اصلی Tantooo با مدیریت خودکار توکن
     *
     * @param \App\Models\License $license
     * @return array نتیجه درخواست
     */
    protected function getTantoooMainDataWithToken($license)
    {
        try {
            // دریافت تنظیمات API
            $settings = $this->getTantoooApiSettings($license);
            if (!$settings) {
                return [
                    'success' => false,
                    'message' => 'تنظیمات API Tantooo یافت نشد'
                ];
            }

            // دریافت توکن (با مدیریت خودکار تجدید)
            $token = $this->getTantoooToken($license);
            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت توکن Tantooo'
                ];
            }

            // دریافت اطلاعات اصلی
            $mainDataResult = $this->getTantoooMainData(
                $settings['api_url'],
                $settings['api_key'],
                $token
            );

            if ($mainDataResult['success']) {
                Log::info('اطلاعات اصلی Tantooo با موفقیت دریافت شد', [
                    'license_id' => $license->id,
                    'data_keys' => array_keys($mainDataResult['data'])
                ]);
            }

            return $mainDataResult;

        } catch (\Exception $e) {
            Log::error('خطا در دریافت اطلاعات اصلی Tantooo با توکن', [
                'license_id' => $license->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در دریافت اطلاعات اصلی: ' . $e->getMessage()
            ];
        }
    }

    /**
     * به‌روزرسانی موجودی محصول در API Tantooo
     *
     * @param string $code کد یکتای محصول
     * @param int $count موجودی جدید
     * @param string $apiUrl آدرس API Tantooo
     * @param string $apiKey کلید API
     * @param string $bearerToken توکن Bearer
     * @return array نتیجه درخواست
     */
    protected function updateProductStockInTantoooApi($code, $count, $apiUrl, $apiKey, $bearerToken)
    {
        try {
            // بررسی پارامترهای الزامی
            if (empty($code) || !is_numeric($count)) {
                return [
                    'success' => false,
                    'message' => 'کد محصول یا موجودی نامعتبر است'
                ];
            }

            if (empty($apiUrl) || empty($apiKey) || empty($bearerToken)) {
                return [
                    'success' => false,
                    'message' => 'اطلاعات API Tantooo یا توکن ناقص است'
                ];
            }

            // آماده‌سازی هدرها
            $headers = [
                'X-API-KEY' => $apiKey,
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $bearerToken
            ];

            // آماده‌سازی داده‌های درخواست
            $requestData = [
                'fn' => 'change_count_sub_product',
                'code' => $code,
                'count' => (int) $count
            ];

            Log::info('ارسال درخواست به‌روزرسانی موجودی محصول به API Tantooo', [
                'api_url' => $apiUrl,
                'product_sku' => $code,
                'new_count' => $count
            ]);

            // ارسال درخواست
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 60,
                'connect_timeout' => 30
            ])->withHeaders($headers)->post($apiUrl, $requestData);

            if ($response->successful()) {
                $responseData = $response->json();

                // بررسی کد پاسخ
                $msgCode = $responseData['msg'] ?? null;
                $errors = $responseData['error'] ?? [];

                // بررسی برای توکن منقضی (msg: 150)
                if ($msgCode == 150) {
                    Log::warning('توکن Tantooo منقضی شده است، تلاش برای تجدید', [
                        'product_code' => $code,
                        'license_id' => $this->currentLicenseId ?? 'unknown'
                    ]);

                    // توکن منقضی است، باید مجدد دریافت شود
                    return [
                        'success' => false,
                        'message' => 'توکن منقضی شده است',
                        'error_code' => 'TOKEN_EXPIRED',
                        'should_retry' => true
                    ];
                }

                // بررسی برای موفقیت (msg: 0 و بدون خطا)
                if ($msgCode === 0 && empty($errors)) {
                    Log::info('موجودی محصول با موفقیت در API Tantooo به‌روزرسانی شد', [
                        'product_code' => $code,
                        'new_count' => $count,
                        'api_response' => $responseData
                    ]);

                    return [
                        'success' => true,
                        'message' => 'موجودی محصول با موفقیت به‌روزرسانی شد',
                        'data' => $responseData
                    ];
                }

                // بررسی برای کد 4 (محصول وجود ندارد - نوع هشدار)
                if ($msgCode === 4) {
                    Log::warning('محصول در سیستم Tantooo وجود ندارد', [
                        'product_code' => $code,
                        'new_count' => $count,
                        'msg_code' => $msgCode,
                        'msg_text' => $responseData['msg_txt'] ?? 'کد محصول اشتباه است',
                        'api_response' => $responseData
                    ]);

                    return [
                        'success' => false,
                        'message' => 'محصول در سیستم Tantooo وجود ندارد',
                        'error_code' => $msgCode,
                        'error_details' => $responseData
                    ];
                }

                // اگر msg کد خطایی دیگری است
                Log::error('خطا در به‌روزرسانی موجودی محصول در API Tantooo - کد خطا دریافت شد', [
                    'product_code' => $code,
                    'new_count' => $count,
                    'msg_code' => $msgCode,
                    'msg_text' => $responseData['msg_txt'] ?? 'نامشخص',
                    'errors' => $errors,
                    'api_response' => $responseData,
                    'api_url' => $apiUrl
                ]);

                return [
                    'success' => false,
                    'message' => 'خطا در به‌روزرسانی موجودی: ' . ($responseData['msg_txt'] ?? 'خطای نامشخص'),
                    'error_code' => $msgCode,
                    'error_details' => $responseData
                ];
            } else {
                Log::error('خطا در به‌روزرسانی موجودی محصول در API Tantooo', [
                    'product_code' => $code,
                    'new_count' => $count,
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'api_url' => $apiUrl
                ]);

                return [
                    'success' => false,
                    'message' => 'خطا در به‌روزرسانی موجودی: ' . $response->status(),
                    'error_details' => $response->body()
                ];
            }

        } catch (\Exception $e) {
            Log::error('خطا در متد به‌روزرسانی موجودی محصول Tantooo', [
                'product_code' => $code,
                'new_count' => $count,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در به‌روزرسانی موجودی: ' . $e->getMessage()
            ];
        }
    }

    /**
     * به‌روزرسانی اطلاعات محصول (نام، قیمت، تخفیف) در API Tantooo
     *
     * @param string $code کد یکتای محصول
     * @param string $title نام محصول
     * @param float $price قیمت محصول
     * @param float $discount درصد تخفیف
     * @param string $apiUrl آدرس API Tantooo
     * @param string $apiKey کلید API
     * @param string $bearerToken توکن Bearer
     * @return array نتیجه درخواست
     */
    protected function updateProductInfoInTantoooApi($code, $title, $price, $discount = 0, $apiUrl, $apiKey, $bearerToken)
    {
        try {
            // بررسی پارامترهای الزامی
            if (empty($code) || empty($title) || !is_numeric($price)) {
                return [
                    'success' => false,
                    'message' => 'پارامترهای الزامی (کد، نام، قیمت) نامعتبر هستند'
                ];
            }

            if (empty($apiUrl) || empty($apiKey) || empty($bearerToken)) {
                return [
                    'success' => false,
                    'message' => 'اطلاعات API Tantooo یا توکن ناقص است'
                ];
            }

            // آماده‌سازی هدرها
            $headers = [
                'X-API-KEY' => $apiKey,
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $bearerToken
            ];

            // آماده‌سازی داده‌های درخواست
            $requestData = [
                'fn' => 'update_product_sku_code',
                'code' => $code,
                'title' => $title,
                'price' => (float) $price,
                'discount' => (float) $discount
            ];

            Log::info('ارسال درخواست به‌روزرسانی اطلاعات محصول به API Tantooo', [
                'api_url' => $apiUrl,
                'product_code' => $code,
                'product_title' => $title,
                'price' => $price,
                'discount' => $discount
            ]);

            // ارسال درخواست
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 60,
                'connect_timeout' => 30
            ])->withHeaders($headers)->post($apiUrl, $requestData);

            if ($response->successful()) {
                $responseData = $response->json();

                // بررسی کد پاسخ
                $msgCode = $responseData['msg'] ?? null;
                $errors = $responseData['error'] ?? [];

                // بررسی برای توکن منقضی (msg: 150)
                if ($msgCode == 150) {
                    Log::warning('توکن Tantooo منقضی شده است، تلاش برای تجدید', [
                        'product_code' => $code,
                        'license_id' => $this->currentLicenseId ?? 'unknown'
                    ]);

                    // توکن منقضی است، باید مجدد دریافت شود
                    return [
                        'success' => false,
                        'message' => 'توکن منقضی شده است',
                        'error_code' => 'TOKEN_EXPIRED',
                        'should_retry' => true
                    ];
                }

                // بررسی برای موفقیت (msg: 0 و بدون خطا)
                if ($msgCode === 0 && empty($errors)) {
                    Log::info('اطلاعات محصول با موفقیت در API Tantooo به‌روزرسانی شد', [
                        'product_code' => $code,
                        'product_title' => $title,
                        'price' => $price,
                        'discount' => $discount,
                        'api_response' => $responseData
                    ]);

                    return [
                        'success' => true,
                        'message' => 'اطلاعات محصول با موفقیت به‌روزرسانی شد',
                        'data' => $responseData
                    ];
                }

                // بررسی برای کد 4 (محصول وجود ندارد - نوع هشدار)
                if ($msgCode === 4) {
                    Log::warning('محصول در سیستم Tantooo وجود ندارد', [
                        'product_code' => $code,
                        'product_title' => $title,
                        'price' => $price,
                        'discount' => $discount,
                        'msg_code' => $msgCode,
                        'msg_text' => $responseData['msg_txt'] ?? 'کد محصول اشتباه است',
                        'api_response' => $responseData
                    ]);

                    return [
                        'success' => false,
                        'message' => 'محصول در سیستم Tantooo وجود ندارد',
                        'error_code' => $msgCode,
                        'error_details' => $responseData
                    ];
                }

                // اگر msg کد خطایی دیگری است
                Log::error('خطا در به‌روزرسانی اطلاعات محصول در API Tantooo - کد خطا دریافت شد', [
                    'product_code' => $code,
                    'product_title' => $title,
                    'price' => $price,
                    'discount' => $discount,
                    'msg_code' => $msgCode,
                    'msg_text' => $responseData['msg_txt'] ?? 'نامشخص',
                    'errors' => $errors,
                    'api_response' => $responseData,
                    'api_url' => $apiUrl
                ]);

                return [
                    'success' => false,
                    'message' => 'خطا در به‌روزرسانی اطلاعات محصول: ' . ($responseData['msg_txt'] ?? 'خطای نامشخص'),
                    'error_code' => $msgCode,
                    'error_details' => $responseData
                ];
            } else {
                Log::error('خطا در به‌روزرسانی اطلاعات محصول در API Tantooo', [
                    'product_code' => $code,
                    'product_title' => $title,
                    'price' => $price,
                    'discount' => $discount,
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'api_url' => $apiUrl
                ]);

                return [
                    'success' => false,
                    'message' => 'خطا در به‌روزرسانی اطلاعات محصول: ' . $response->status(),
                    'error_details' => $response->body()
                ];
            }

        } catch (\Exception $e) {
            Log::error('خطا در متد به‌روزرسانی اطلاعات محصول Tantooo', [
                'product_code' => $code,
                'product_title' => $title,
                'price' => $price,
                'discount' => $discount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در به‌روزرسانی اطلاعات محصول: ' . $e->getMessage()
            ];
        }
    }

    /**
     * به‌روزرسانی موجودی محصول با مدیریت خودکار توکن
     *
     * @param \App\Models\License $license
     * @param string $code کد یکتای محصول
     * @param int $count موجودی جدید
     * @return array نتیجه درخواست
     */
    protected function updateProductStockWithToken($license, $code, $count)
    {
        try {
            // دریافت تنظیمات API
            $settings = $this->getTantoooApiSettings($license);
            if (!$settings) {
                return [
                    'success' => false,
                    'message' => 'تنظیمات API Tantooo یافت نشد'
                ];
            }

            // دریافت توکن (با مدیریت خودکار تجدید)
            $token = $this->getTantoooToken($license);
            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت توکن Tantooo'
                ];
            }

            // به‌روزرسانی موجودی محصول
            $result = $this->updateProductStockInTantoooApi(
                $code,
                $count,
                $settings['api_url'],
                $settings['api_key'],
                $token
            );

            // اگر توکن منقضی بود، تجدید و retry کنیم
            if (!$result['success'] && isset($result['error_code']) && $result['error_code'] === 'TOKEN_EXPIRED') {
                Log::info('تجدید توکن و تلاش مجدد برای به‌روزرسانی موجودی', [
                    'license_id' => $license->id,
                    'product_code' => $code
                ]);

                // حذف توکن قدیم برای فرض تجدید
                $license->update(['api_token' => null]);

                // دریافت توکن جدید
                $newToken = $this->getTantoooToken($license);
                if (!$newToken) {
                    return [
                        'success' => false,
                        'message' => 'خطا در تجدید توکن Tantooo'
                    ];
                }

                // تلاش مجدد
                return $this->updateProductStockInTantoooApi(
                    $code,
                    $count,
                    $settings['api_url'],
                    $settings['api_key'],
                    $newToken
                );
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی موجودی محصول با توکن', [
                'license_id' => $license->id,
                'product_code' => $code,
                'new_count' => $count,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در به‌روزرسانی موجودی محصول: ' . $e->getMessage()
            ];
        }
    }

    /**
     * به‌روزرسانی اطلاعات محصول با مدیریت خودکار توکن
     *
     * @param \App\Models\License $license
     * @param string $code کد یکتای محصول
     * @param string $title نام محصول
     * @param float $price قیمت محصول
     * @param float $discount درصد تخفیف
     * @return array نتیجه درخواست
     */
    protected function updateProductInfoWithToken($license, $code, $title, $price, $discount = 0)
    {
        try {
            // دریافت تنظیمات API
            $settings = $this->getTantoooApiSettings($license);
            if (!$settings) {
                return [
                    'success' => false,
                    'message' => 'تنظیمات API Tantooo یافت نشد'
                ];
            }

            // دریافت توکن (با مدیریت خودکار تجدید)
            $token = $this->getTantoooToken($license);
            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت توکن Tantooo'
                ];
            }

            // به‌روزرسانی اطلاعات محصول
            $result = $this->updateProductInfoInTantoooApi(
                $code,
                $title,
                $price,
                $discount,
                $settings['api_url'],
                $settings['api_key'],
                $token
            );

            // اگر توکن منقضی بود، تجدید و retry کنیم
            if (!$result['success'] && isset($result['error_code']) && $result['error_code'] === 'TOKEN_EXPIRED') {
                Log::info('تجدید توکن و تلاش مجدد برای به‌روزرسانی اطلاعات محصول', [
                    'license_id' => $license->id,
                    'product_code' => $code
                ]);

                // حذف توکن قدیم برای فرض تجدید
                $license->update(['api_token' => null]);

                // دریافت توکن جدید
                $newToken = $this->getTantoooToken($license);
                if (!$newToken) {
                    return [
                        'success' => false,
                        'message' => 'خطا در تجدید توکن Tantooo'
                    ];
                }

                // تلاش مجدد
                return $this->updateProductInfoInTantoooApi(
                    $code,
                    $title,
                    $price,
                    $discount,
                    $settings['api_url'],
                    $settings['api_key'],
                    $newToken
                );
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی اطلاعات محصول با توکن', [
                'license_id' => $license->id,
                'product_code' => $code,
                'product_title' => $title,
                'price' => $price,
                'discount' => $discount,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در به‌روزرسانی اطلاعات محصول: ' . $e->getMessage()
            ];
        }
    }

    /**
     * دریافت لیست محصولات از سیستم Tantooo API با پیجینیشن
     *
     * @param object $license لایسنس کاربر
     * @param int $page شماره صفحه (پیش‌فرض: 1)
     * @param int $countPerPage تعداد محصول در هر صفحه (پیش‌فرض: 100)
     * @return array نتیجه درخواست
     */
    protected function getProductsFromTantoooApi($license, $page = 1, $countPerPage = 100)
    {
        try {
            // دریافت تنظیمات API
            $tantoooSettings = $this->getTantoooApiSettings($license);
            if (!$tantoooSettings) {
                return [
                    'success' => false,
                    'message' => 'تنظیمات API Tantooo یافت نشد'
                ];
            }

            // دریافت توکن (با مدیریت خودکار تجدید)
            $token = $this->getTantoooToken($license);
            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت توکن Tantooo'
                ];
            }

            return $this->getProductsFromTantoooApiWithToken($license, $token, $page, $countPerPage);

        } catch (\Exception $e) {
            Log::error('خطا در دریافت محصولات از Tantooo API', [
                'license_id' => $license->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در دریافت محصولات: ' . $e->getMessage()
            ];
        }
    }

    /**
     * دریافت لیست محصولات از سیستم Tantooo API با توکن معین
     *
     * @param object $license لایسنس کاربر
     * @param string $token توکن Tantooo
     * @param int $page شماره صفحه
     * @param int $countPerPage تعداد محصول در هر صفحه
     * @return array نتیجه درخواست
     */
    protected function getProductsFromTantoooApiWithToken($license, $token, $page = 1, $countPerPage = 100)
    {
        try {
            // دریافت تنظیمات API
            $settings = $this->getTantoooApiSettings($license);
            if (!$settings) {
                return [
                    'success' => false,
                    'message' => 'تنظیمات API Tantooo یافت نشد'
                ];
            }

            // آماده‌سازی داده‌های درخواست
            $requestData = [
                'fn' => 'get_sub_main',
                'page' => intval($page),
                'count_per_page' => intval($countPerPage)
            ];

            // آماده‌سازی هدرها
            $headers = [
                'X-API-KEY' => $settings['api_key'],
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ];

            Log::info('درخواست دریافت محصولات از Tantooo API', [
                'license_id' => $license->id,
                'api_url' => $settings['api_url'],
                'page' => $page,
                'count_per_page' => $countPerPage
            ]);

            // ارسال درخواست به API
            $response = Http::withHeaders($headers)
                ->timeout(60)
                ->post($settings['api_url'], $requestData);

            if ($response->successful()) {
                $data = $response->json();

                Log::info('دریافت محصولات از Tantooo API موفق', [
                    'license_id' => $license->id,
                    'page' => $page,
                    'products_count' => count($data['product'] ?? []),
                    'total_count' => $data['count'] ?? 0
                ]);

                return [
                    'success' => true,
                    'message' => 'دریافت محصولات موفق',
                    'data' => [
                        'products' => $data['product'] ?? [],
                        'total_count' => $data['count'] ?? 0,
                        'current_page' => $page,
                        'per_page' => $countPerPage,
                        'msg' => $data['msg'] ?? null,
                        'error' => $data['error'] ?? []
                    ]
                ];
            } else {
                // بررسی اگر توکن منقضی شده باشد
                if ($response->status() === 401) {
                    Log::info('توکن Tantooo منقضی شده، تلاش برای تمدید');

                    // پاک کردن توکن قدیمی و دریافت توکن جدید
                    $license->clearToken();
                    $newToken = $this->getTantoooToken($license);

                    if ($newToken && $newToken !== $token) {
                        return $this->getProductsFromTantoooApiWithToken($license, $newToken, $page, $countPerPage);
                    }
                }

                Log::error('خطا در دریافت محصولات از Tantooo API', [
                    'license_id' => $license->id,
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);

                return [
                    'success' => false,
                    'message' => 'خطا در دریافت محصولات از سرور'
                ];
            }

        } catch (\Exception $e) {
            Log::error('خطا در دریافت محصولات از Tantooo API با توکن', [
                'license_id' => $license->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در دریافت محصولات: ' . $e->getMessage()
            ];
        }
    }

    /**
     * استخراج کدهای محصولات از داده‌های دریافتی
     *
     * @param array $productsData آرایه محصولات
     * @return array آرایه کدهای محصولات
     */
    protected function extractProductCodes($productsData)
    {
        $productCodes = [];

        foreach ($productsData as $product) {
            $code = $this->extractSingleProductCode($product);
            if ($code) {
                $productCodes[] = $code;
            }
        }

        return array_unique($productCodes);
    }

    /**
     * استخراج کد یک محصول از داده‌های محصول
     *
     * @param array $product داده‌های محصول
     * @return string|null کد محصول یا null
     */
    protected function extractSingleProductCode($product)
    {
        // جستجو در فیلدهای مختلف برای کد محصول
        $possibleFields = [
            'ItemID',
            'item_id',
            'ItemId'
        ];

        foreach ($possibleFields as $field) {
            if (isset($product[$field]) && !empty(trim($product[$field]))) {
                return trim($product[$field]);
            }
        }

        // اگر هیچ کدی یافت نشد
        Log::warning('کد محصول در داده‌های محصول یافت نشد', [
            'product_fields' => array_keys($product),
            'product_sample' => array_slice($product, 0, 3, true)
        ]);

        return null;
    }

    /**
     * دریافت اطلاعات به‌روز محصولات از Warehouse API بر اساس کدهای یکتا
     * استفاده از endpoint جدید GetItemsByIds
     *
     * @param \App\Models\License $license
     * @param array $productCodes آرایه کدهای یکتای محصولات
     * @return array نتیجه درخواست شامل اطلاعات محصولات
     */
    protected function getUpdatedProductInfoFromBaran($license, $productCodes)
    {
        try {
            if (empty($productCodes)) {
                return [
                    'success' => false,
                    'message' => 'کدهای محصول خالی است'
                ];
            }

            // دریافت کاربر و تنظیمات API
            $user = \App\Models\User::find($license->user_id);
            if (!$user || !$user->warehouse_api_url || !$user->warehouse_api_username || !$user->warehouse_api_password) {
                return [
                    'success' => false,
                    'message' => 'تنظیمات Warehouse API ناقص است'
                ];
            }

            // دریافت تنظیمات کاربر برای انبار پیش‌فرض
            $userSetting = \App\Models\UserSetting::where('license_id', $license->id)->first();
            $defaultWarehouseCode = $userSetting ? $userSetting->default_warehouse_code : null;

            Log::info('درخواست اطلاعات محصولات از Warehouse API', [
                'license_id' => $license->id,
                'product_codes_count' => count($productCodes),
                'default_warehouse_code' => $defaultWarehouseCode,
                'api_url' => $user->warehouse_api_url
            ]);
            Log::info(json_encode($productCodes));
            // ارسال درخواست به Warehouse API (مشابه RainSale format)
            $response = \Illuminate\Support\Facades\Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user->warehouse_api_username . ':' . $user->warehouse_api_password)
            ])->post($user->warehouse_api_url . '/api/itemlist/GetItemsByIds', $productCodes);

            if (!$response->successful()) {
                Log::error('خطا در دریافت از Warehouse API', [
                    'license_id' => $license->id,
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ]);

                return [
                    'success' => false,
                    'message' => 'خطا در دریافت از Warehouse API. کد وضعیت: ' . $response->status()
                ];
            }

            $warehouseData = $response->json();

            if (empty($warehouseData)) {
                Log::warning('هیچ داده‌ای از Warehouse API دریافت نشد', [
                    'license_id' => $license->id,
                    'product_codes' => $productCodes
                ]);

                return [
                    'success' => false,
                    'message' => 'هیچ داده‌ای از Warehouse API دریافت نشد'
                ];
            }

            // پردازش داده‌های Warehouse API و گروه‌بندی بر اساس itemID
            $groupedProducts = [];
            foreach ($warehouseData as $item) {
                $itemId = $item['itemID'] ?? null;
                if ($itemId) {
                    if (!isset($groupedProducts[$itemId])) {
                        $groupedProducts[$itemId] = [
                            'itemID' => $item['itemID'],
                            'itemName' => $item['itemName'] ?? '',
                            'salePrice' => $item['salePrice'] ?? 0,
                            'currentDiscount' => $item['currentDiscount'] ?? 0,
                            'barcode' => $item['barcode'] ?? '',
                            'departmentCode' => $item['departmentCode'] ?? '',
                            'departmentName' => $item['departmentName'] ?? '',
                            'stocks' => []
                        ];
                    }

                    // اضافه کردن اطلاعات موجودی انبار
                    $groupedProducts[$itemId]['stocks'][] = [
                        'stockID' => $item['stockID'] ?? '',
                        'stockName' => $item['stockName'] ?? '',
                        'stockQuantity' => $item['stockQuantity'] ?? 0
                    ];
                }
            }

            // استخراج اطلاعات نهایی محصولات
            $processedProducts = [];
            foreach ($groupedProducts as $itemId => $productData) {
                // محاسبه موجودی از انبار پیش‌فرض یا مجموع کل
                $stockQuantity = 0;
                $stockName = 'نامشخص';

                if (!empty($defaultWarehouseCode)) {
                    // جستجو در انبار پیش‌فرض
                    foreach ($productData['stocks'] as $stock) {
                        if ($stock['stockID'] === $defaultWarehouseCode) {
                            $stockQuantity = max(0, (float)$stock['stockQuantity']);
                            $stockName = $stock['stockName'];
                            break;
                        }
                    }
                } else {
                    // مجموع موجودی از همه انبارها (فقط موجودی مثبت)
                    foreach ($productData['stocks'] as $stock) {
                        $quantity = (float)($stock['stockQuantity'] ?? 0);
                        if ($quantity > 0) {
                            $stockQuantity += $quantity;
                        }
                    }
                    $stockName = 'مجموع انبارها';
                }

                // آماده‌سازی داده‌های نهایی به فرمت مشابه RainSale
                $processedProducts[] = [
                    'ItemID' => $productData['itemID'],
                    'Name' => $productData['itemName'],
                    'Barcode' => $productData['barcode'],
                    'Price' => (float)$productData['salePrice'],
                    'CurrentUnitCount' => $stockQuantity,
                    'DiscountPercentage' => (float)$productData['currentDiscount'],
                    'DepartmentCode' => $productData['departmentCode'],
                    'DepartmentName' => $productData['departmentName'],
                    'StockName' => $stockName,
                    'AllStocks' => $productData['stocks']
                ];
            }

            Log::info('اطلاعات محصولات از Warehouse API پردازش شد', [
                'license_id' => $license->id,
                'requested_count' => count($productCodes),
                'received_count' => count($processedProducts),
                'default_warehouse_code' => $defaultWarehouseCode
            ]);

            return [
                'success' => true,
                'data' => [
                    'products' => $processedProducts,
                    'total_requested' => count($productCodes),
                    'total_received' => count($processedProducts),
                    'warehouse_code' => $defaultWarehouseCode
                ]
            ];

        } catch (\Exception $e) {
            Log::error('خطا در دریافت اطلاعات از Warehouse API', [
                'license_id' => $license->id,
                'product_codes_count' => count($productCodes),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در دریافت از Warehouse: ' . $e->getMessage()
            ];
        }
    }

    /**
     * پردازش و به‌روزرسانی محصولات بر اساس اطلاعات دریافتی از باران
     *
     * @param \App\Models\License $license
     * @param array $originalProducts محصولات اصلی از درخواست
     * @param array $baranProducts اطلاعات به‌روز از باران
     * @return array نتیجه به‌روزرسانی
     */
    protected function processAndUpdateProductsFromBaran($license, $originalProducts, $baranProducts)
    {
        try {
            $userSetting = \App\Models\UserSetting::where('license_id', $license->id)->first();
            if (!$userSetting) {
                return [
                    'success' => false,
                    'message' => 'تنظیمات کاربر یافت نشد'
                ];
            }

            $warehouseCode = $userSetting->default_warehouse_code;
            $updatedProducts = [];
            $successCount = 0;
            $errorCount = 0;
            $errors = [];

            foreach ($originalProducts as $originalProduct) {
                try {
                    $productCode = $this->extractSingleProductCode($originalProduct);
                    if (!$productCode) {
                        $errors[] = [
                            'product' => $originalProduct,
                            'error' => 'کد محصول یافت نشد'
                        ];
                        $errorCount++;
                        continue;
                    }

                    // پیدا کردن محصول متناظر از باران
                    $baranProduct = $this->findProductInBaranData($productCode, $baranProducts, $warehouseCode);

                    if (!$baranProduct) {
                        $errors[] = [
                            'product_code' => $productCode,
                            'error' => 'محصول در باران یافت نشد'
                        ];
                        $errorCount++;
                        continue;
                    }

                    // تهیه داده‌های به‌روزرسانی شده
                    $updatedProduct = $this->prepareUpdatedProduct($originalProduct, $baranProduct, $userSetting);

                    if ($updatedProduct) {
                        $updatedProducts[] = $updatedProduct;
                        $successCount++;

                        Log::info('محصول آماده به‌روزرسانی شد', [
                            'product_code' => $productCode,
                            'stock' => $baranProduct['stockQuantity'] ?? 0,
                            'price' => $baranProduct['salePrice'] ?? 0
                        ]);
                    }

                } catch (\Exception $e) {
                    $errors[] = [
                        'product' => $originalProduct,
                        'error' => 'خطا در پردازش: ' . $e->getMessage()
                    ];
                    $errorCount++;
                }
            }

            // اگر محصولی برای به‌روزرسانی آماده شده، آن‌ها را به Tantooo ارسال کن
            $updateResult = null;
            if (!empty($updatedProducts)) {
                $settings = $this->getTantoooApiSettings($license);
                if ($settings) {
                    $updateResult = $this->updateMultipleProductsInTantoooApi(
                        $updatedProducts,
                        $settings['api_url'],
                        $settings['api_key'],
                        $settings['bearer_token']
                    );
                }
            }

            return [
                'success' => true,
                'data' => [
                    'total_processed' => count($originalProducts),
                    'success_count' => $successCount,
                    'error_count' => $errorCount,
                    'updated_products' => $updatedProducts,
                    'errors' => $errors,
                    'tantooo_update_result' => $updateResult
                ]
            ];

        } catch (\Exception $e) {
            Log::error('خطا در پردازش و به‌روزرسانی محصولات', [
                'license_id' => $license->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در پردازش محصولات: ' . $e->getMessage()
            ];
        }
    }

    /**
     * پیدا کردن محصول در داده‌های دریافتی از باران
     *
     * @param string $productCode کد محصول
     * @param array $baranProducts محصولات دریافتی از باران
     * @param string $warehouseCode کد انبار
     * @return array|null محصول یافت شده یا null
     */
    protected function findProductInBaranData($productCode, $baranProducts, $warehouseCode = null)
    {
        foreach ($baranProducts as $product) {
            // بررسی تطبیق کد محصول
            $matchesCode = (
                (isset($product['barcode']) && strtolower(trim($product['barcode'])) === strtolower(trim($productCode))) ||
                (isset($product['itemID']) && strtolower(trim($product['itemID'])) === strtolower(trim($productCode)))
            );

            if (!$matchesCode) {
                continue;
            }

            // اگر کد انبار مشخص شده، ابتدا محصول با همان انبار را جستجو کن
            if (!empty($warehouseCode)) {
                $matchesWarehouse = (
                    isset($product['stockID']) &&
                    strtolower(trim($product['stockID'])) === strtolower(trim($warehouseCode))
                );

                if ($matchesWarehouse) {
                    return $product; // اولویت با انبار مطابق
                }
            }
        }

        // اگر محصول با انبار مشخص پیدا نشد، اولین محصول با کد مطابق را برگردان
        foreach ($baranProducts as $product) {
            $matchesCode = (
                (isset($product['barcode']) && strtolower(trim($product['barcode'])) === strtolower(trim($productCode))) ||
                (isset($product['itemID']) && strtolower(trim($product['itemID'])) === strtolower(trim($productCode)))
            );

            if ($matchesCode) {
                return $product;
            }
        }

        return null;
    }

    /**
     * آماده‌سازی محصول به‌روزرسانی شده
     *
     * @param array $originalProduct محصول اصلی
     * @param array $baranProduct اطلاعات از باران
     * @param \App\Models\UserSetting $userSetting تنظیمات کاربر
     * @return array محصول آماده شده
     */
    protected function prepareUpdatedProduct($originalProduct, $baranProduct, $userSetting)
    {
        $updatedProduct = $originalProduct; // شروع با داده‌های اصلی

        // به‌روزرسانی موجودی (اگر مجاز باشد)
        if ($userSetting->enable_stock_update && isset($baranProduct['stockQuantity'])) {
            $updatedProduct['Stock'] = $baranProduct['stockQuantity'];
            $updatedProduct['stock'] = $baranProduct['stockQuantity']; // همه فرمت‌ها
        }

        // به‌روزرسانی قیمت (اگر مجاز باشد)
        if ($userSetting->enable_price_update && isset($baranProduct['salePrice'])) {
            $price = $baranProduct['salePrice'];

            // تبدیل واحد قیمت در صورت نیاز
            if ($userSetting->rain_sale_price_unit === 'rial' && $userSetting->tantooo_price_unit === 'toman') {
                $price = $price / 10;
            } elseif ($userSetting->rain_sale_price_unit === 'toman' && $userSetting->tantooo_price_unit === 'rial') {
                $price = $price * 10;
            }

            $updatedProduct['Price'] = $price;
            $updatedProduct['price'] = $price; // همه فرمت‌ها
        }

        // به‌روزرسانی نام (اگر مجاز باشد)
        if ($userSetting->enable_name_update && isset($baranProduct['itemName'])) {
            $updatedProduct['Title'] = $baranProduct['itemName'];
            $updatedProduct['title'] = $baranProduct['itemName']; // همه فرمت‌ها
        }

        // اضافه کردن اطلاعات انبار
        if (isset($baranProduct['stockID'])) {
            $updatedProduct['warehouse_id'] = $baranProduct['stockID'];
            $updatedProduct['warehouse_name'] = $baranProduct['stockName'] ?? null;
        }

        return $updatedProduct;
    }

    /**
     * به‌روزرسانی محصول با موجودی + قیمت + تخفیف در یک درخواست
     * فقط فیلدهای فعال‌شده را ارسال می‌کند
     *
     * @param string $code کد یکتای محصول (SKU)
     * @param int $count موجودی
     * @param float $price قیمت
     * @param float $discount درصد تخفیف
     * @param string $apiUrl آدرس API
     * @param string $apiKey کلید API
     * @param string $bearerToken توکن Bearer
     * @param object $userSetting تنظیمات کاربر برای بررسی enable/disable
     * @return array نتیجه درخواست
     */
    protected function updateProductCompleteInTantoooApi($code, $count, $price, $discount = 0, $apiUrl, $apiKey, $bearerToken, $userSetting = null)
    {
        try {
            // بررسی پارامترهای الزامی
            if (empty($code)) {
                return [
                    'success' => false,
                    'message' => 'کد محصول الزامی است'
                ];
            }

            if (empty($apiUrl) || empty($apiKey) || empty($bearerToken)) {
                return [
                    'success' => false,
                    'message' => 'اطلاعات API Tantooo یا توکن ناقص است'
                ];
            }

            // آماده‌سازی هدرها
            $headers = [
                'X-API-KEY' => $apiKey,
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $bearerToken
            ];

            // آماده‌سازی داده‌های درخواست - فقط فیلدهای فعال
            $requestData = [
                'fn' => 'update_product_sku_code',
                'sku' => $code
            ];

            // اضافه کردن موجودی فقط اگر فعال باشد
            $includedFields = [];
            if (!$userSetting || $userSetting->enable_stock_update) {
                if (is_numeric($count)) {
                    $requestData['count'] = (int) $count;
                    $includedFields[] = 'stock';
                }
            }

            // اضافه کردن قیمت فقط اگر فعال باشد
            if (!$userSetting || $userSetting->enable_price_update) {
                if (is_numeric($price)) {
                    $requestData['price'] = (float) $price;
                    $includedFields[] = 'price';
                }
            }

            // اضافه کردن تخفیف فقط اگر فعال باشد
            // می‌تواند enable_discount_update یا enable_price_update باشد
            $hasDiscountEnabled = $userSetting && (
                (property_exists($userSetting, 'enable_discount_update') && $userSetting->enable_discount_update) ||
                ($userSetting->enable_price_update)
            );

            if (!$userSetting || $hasDiscountEnabled) {
                if (is_numeric($discount) && $discount > 0) {
                    $requestData['discount'] = (float) $discount;
                    $includedFields[] = 'discount';
                }
            }

            // اگر هیچ فیلدی برای ارسال وجود ندارد، درخواست را انجام ندهیم
            if (count($includedFields) === 0) {
                Log::warning('هیچ فیلدی برای به‌روزرسانی فعال نیست، درخواست لغو شد', [
                    'product_code' => $code,
                    'enable_stock_update' => $userSetting?->enable_stock_update ?? true,
                    'enable_price_update' => $userSetting?->enable_price_update ?? true
                ]);

                return [
                    'success' => true,
                    'message' => 'هیچ فیلدی برای به‌روزرسانی فعال نیست',
                    'skipped' => true
                ];
            }

            Log::info('ارسال درخواست به‌روزرسانی محصول به API Tantooo', [
                'api_url' => $apiUrl,
                'product_sku' => $code,
                'included_fields' => $includedFields,
                'request_data' => $requestData
            ]);

            // ارسال درخواست
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 60,
                'connect_timeout' => 30
            ])->withHeaders($headers)->post($apiUrl, $requestData);

            if ($response->successful()) {
                $responseData = $response->json();

                // بررسی کد پاسخ
                $msgCode = $responseData['msg'] ?? null;
                $errors = $responseData['error'] ?? [];

                // بررسی برای توکن منقضی (msg: 150)
                if ($msgCode == 150) {
                    Log::warning('توکن Tantooo منقضی شده است، تلاش برای تجدید', [
                        'product_code' => $code,
                        'license_id' => $this->currentLicenseId ?? 'unknown'
                    ]);

                    return [
                        'success' => false,
                        'message' => 'توکن منقضی شده است',
                        'error_code' => 'TOKEN_EXPIRED',
                        'should_retry' => true
                    ];
                }

                // بررسی برای موفقیت (msg: 0 و بدون خطا)
                if ($msgCode === 0 && empty($errors)) {
                    Log::info('محصول با موفقیت در API Tantooo به‌روزرسانی شد', [
                        'product_code' => $code,
                        'included_fields' => $includedFields,
                        'api_response' => $responseData
                    ]);

                    return [
                        'success' => true,
                        'message' => 'محصول با موفقیت به‌روزرسانی شد',
                        'data' => $responseData
                    ];
                }

                // بررسی برای کد 4 (محصول وجود ندارد - نوع هشدار)
                if ($msgCode === 4) {
                    Log::warning('محصول در سیستم Tantooo وجود ندارد', [
                        'product_code' => $code,
                        'count' => $count,
                        'price' => $price,
                        'discount' => $discount,
                        'msg_code' => $msgCode,
                        'msg_text' => $responseData['msg_txt'] ?? 'کد محصول اشتباه است',
                        'api_response' => $responseData
                    ]);

                    return [
                        'success' => false,
                        'message' => 'محصول در سیستم Tantooo وجود ندارد',
                        'error_code' => $msgCode,
                        'error_details' => $responseData
                    ];
                }

                // اگر msg کد خطایی دیگری است
                Log::error('خطا در به‌روزرسانی مکمل محصول در API Tantooo - کد خطا دریافت شد', [
                    'product_code' => $code,
                    'count' => $count,
                    'price' => $price,
                    'discount' => $discount,
                    'msg_code' => $msgCode,
                    'msg_text' => $responseData['msg_txt'] ?? 'نامشخص',
                    'errors' => $errors,
                    'api_response' => $responseData,
                    'api_url' => $apiUrl
                ]);

                return [
                    'success' => false,
                    'message' => 'خطا در به‌روزرسانی محصول: ' . ($responseData['msg_txt'] ?? 'خطای نامشخص'),
                    'error_code' => $msgCode,
                    'error_details' => $responseData
                ];
            } else {
                Log::error('خطا در به‌روزرسانی مکمل محصول در API Tantooo', [
                    'product_code' => $code,
                    'count' => $count,
                    'price' => $price,
                    'discount' => $discount,
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'api_url' => $apiUrl
                ]);

                return [
                    'success' => false,
                    'message' => 'خطا در به‌روزرسانی محصول: ' . $response->status(),
                    'error_details' => $response->body()
                ];
            }

        } catch (\Exception $e) {
            Log::error('خطا در متد به‌روزرسانی مکمل محصول Tantooo', [
                'product_code' => $code,
                'count' => $count,
                'price' => $price,
                'discount' => $discount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در به‌روزرسانی محصول: ' . $e->getMessage()
            ];
        }
    }

    /**
     * به‌روزرسانی محصول مکمل (موجودی + قیمت + تخفیف) با مدیریت خودکار توکن
     *
     * @param \App\Models\License $license
     * @param string $code کد یکتای محصول
     * @param int $count موجودی
     * @param float $price قیمت
     * @param float $discount درصد تخفیف
     * @param object $userSetting تنظیمات کاربر برای بررسی enable/disable
     * @return array نتیجه درخواست
     */
    protected function updateProductCompleteWithToken($license, $code, $count, $price, $discount = 0, $userSetting = null)
    {
        try {
            // دریافت تنظیمات API
            $settings = $this->getTantoooApiSettings($license);
            if (!$settings) {
                return [
                    'success' => false,
                    'message' => 'تنظیمات API Tantooo یافت نشد'
                ];
            }

            // دریافت تنظیمات کاربر اگر ارائه نشده‌باشد
            if (!$userSetting) {
                $userSetting = \App\Models\UserSetting::where('license_id', $license->id)->first();
            }

            // دریافت توکن (با مدیریت خودکار تجدید)
            $token = $this->getTantoooToken($license);
            if (!$token) {
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت توکن Tantooo'
                ];
            }

            // به‌روزرسانی مکمل محصول (تنظیمات کاربر را برای بررسی enable/disable ارسال کنید)
            $result = $this->updateProductCompleteInTantoooApi(
                $code,
                $count,
                $price,
                $discount,
                $settings['api_url'],
                $settings['api_key'],
                $token,
                $userSetting
            );

            // اگر توکن منقضی بود، تجدید و retry کنیم
            if (!$result['success'] && isset($result['error_code']) && $result['error_code'] === 'TOKEN_EXPIRED') {
                Log::info('تجدید توکن و تلاش مجدد برای به‌روزرسانی مکمل محصول', [
                    'license_id' => $license->id,
                    'product_code' => $code
                ]);

                // حذف توکن قدیم برای فرض تجدید
                $license->update(['api_token' => null]);

                // دریافت توکن جدید
                $newToken = $this->getTantoooToken($license);
                if (!$newToken) {
                    return [
                        'success' => false,
                        'message' => 'خطا در تجدید توکن Tantooo'
                    ];
                }

                // تلاش مجدد
                return $this->updateProductCompleteInTantoooApi(
                    $code,
                    $count,
                    $price,
                    $discount,
                    $settings['api_url'],
                    $settings['api_key'],
                    $newToken,
                    $userSetting
                );
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی مکمل محصول با توکن', [
                'license_id' => $license->id,
                'product_code' => $code,
                'count' => $count,
                'price' => $price,
                'discount' => $discount,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در به‌روزرسانی محصول: ' . $e->getMessage()
            ];
        }
    }
}

