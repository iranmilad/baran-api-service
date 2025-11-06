<?php

namespace App\Traits\Samtatech;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\License;

trait Samtatech
{
    /**
     * دریافت توکن معتبر برای لایسنس
     */
    protected function getValidToken(License $license)
    {
        try {
            // بررسی توکن موجود
            if ($license->api_token && $license->token_expires_at && $license->token_expires_at > now()) {
                return $license->api_token;
            }

            // درخواست توکن جدید
            $response = Http::timeout(30)
                ->post(env('SAMTATECH_API_URL') . '/api/v1/login', [
                    'website_url' => $license->website_url,
                    'license_key' => $license->key
                ]);

            if (!$response->successful()) {
                throw new Exception('خطا در دریافت توکن: ' . $response->body());
            }

            $data = $response->json();

            if (!$data['success']) {
                throw new Exception($data['message'] ?? 'خطا در ورود به سیستم');
            }

            // بروزرسانی توکن در لایسنس
            $license->update([
                'api_token' => $data['data']['token'],
                'token_expires_at' => now()->addSeconds($data['data']['expires_at'] - time())
            ]);

            Log::info('توکن جدید دریافت شد', [
                'license_id' => $license->id,
                'expires_at' => $license->token_expires_at
            ]);

            return $data['data']['token'];

        } catch (Exception $e) {
            Log::error('خطا در دریافت توکن: ' . $e->getMessage(), [
                'license_id' => $license->id
            ]);
            throw $e;
        }
    }

    /**
     * ارسال درخواست به API سام‌تاتک
     */
    protected function request(License $license, string $endpoint, string $method = 'GET', array $data = [])
    {
        try {
            $token = $this->getValidToken($license);

            // ساخت URL با فرمت صحیح
            $url = rtrim(env('SAMTATECH_API_URL'), '/') . '/api/v1/' . ltrim($endpoint, '/');

            Log::info('API: ' . $url);

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ]);

            if ($method === 'POST') {
                $response = $response->post($url, $data);
            } else {
                $response = $response->get($url, $data);
            }

            if (!$response->successful()) {
                throw new Exception('خطا در ارتباط با سرور: HTTP ' . $response->status());
            }

            $result = $response->json();

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('خطا در پردازش پاسخ API');
            }

            // بررسی خطای نامعتبر بودن توکن
            if (!$result['success'] && (
                $response->status() === 401 ||
                (isset($result['message']) && (
                    strpos($result['message'], 'token') !== false ||
                    strpos($result['message'], 'توکن') !== false ||
                    strpos($result['message'], 'unauthorized') !== false
                ))
            )) {
                Log::warning('توکن نامعتبر است، تلاش برای لاگین مجدد');

                // تلاش برای لاگین مجدد
                $token = $this->getValidToken($license);

                // تکرار درخواست با توکن جدید
                $response = Http::timeout(30)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json'
                    ]);

                if ($method === 'POST') {
                    $response = $response->post($url, $data);
                } else {
                    $response = $response->get($url, $data);
                }

                if (!$response->successful()) {
                    throw new Exception('خطا در ارتباط با سرور: HTTP ' . $response->status());
                }

                $result = $response->json();

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('خطا در پردازش پاسخ API');
                }
            }

            return $result;

        } catch (Exception $e) {
            Log::error('خطا در ارتباط با API: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * ارسال درخواست به API برای به‌روزرسانی موجودی گروه‌بندی شده همه دسته‌ها
     * @return array
     */
    public function update_woocommerce_stock_all_categories(License $license)
    {
        try {
            $token = $this->getValidToken($license);
            if (!$token) {
                return ['success' => false, 'message' => 'توکن معتبر نیست'];
            }

            $url = rtrim(env('SAMTATECH_API_URL'), '/') . '/api/v1/products/update-woocommerce-stock-all-categories';

            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ])
                ->post($url);

            if (!$response->successful()) {
                throw new Exception('خطا در ارتباط با سرور: HTTP ' . $response->status());
            }

            $data = $response->json();

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('خطا در پردازش پاسخ API');
            }

            return $data;

        } catch (Exception $e) {
            Log::error('خطا در به‌روزرسانی گروهی موجودی: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * درخواست موجودی/قیمت محصولات بر اساس لیست کدهای یکتا
     * فقط در صورت وجود حداقل یک کد یکتا، درخواست ارسال می‌شود.
     * @param License $license
     * @param array $unique_ids لیست کدهای یکتا
     * @return array پاسخ وب‌سرویس یا نتیجه عدم ارسال
     */
    public function request_cart_stock(License $license, array $unique_ids)
    {
        try {
            if (empty($unique_ids)) {
                // عدم ارسال درخواست به وب سرویس – خروج سریع
                return ['success' => false, 'message' => 'لیست خالی', 'skipped' => true];
            }

            $token = $this->getValidToken($license);
            if (!$token) {
                return ['success' => false, 'message' => 'توکن معتبر نیست'];
            }

            $url = rtrim(env('SAMTATECH_API_URL'), '/') . '/api/v1/products/stock';

            $response = Http::timeout(20)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ])
                ->post($url, [
                    'unique_ids' => array_values(array_unique($unique_ids))
                ]);

            if (!$response->successful()) {
                throw new Exception('خطا در ارتباط با سرور: HTTP ' . $response->status());
            }

            $data = $response->json();

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('خطا در پردازش پاسخ');
            }

            Log::info('cart stock sync request done', [
                'url' => $url,
                'unique_ids' => $unique_ids,
                'response' => $data
            ]);

            return $data;

        } catch (Exception $e) {
            Log::error('cart stock sync failed', [
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * درخواست موجودی/قیمت محصولات با اطلاعات دسته‌بندی (برای product view sync)
     * @param License $license
     * @param array $unique_ids لیست کدهای یکتا
     * @param array $categories لیست نام دسته‌بندی‌های محصول
     * @param int $timeout تایم‌اوت درخواست (برای جلوگیری از کند شدن صفحه)
     * @return array پاسخ وب‌سرویس یا نتیجه عدم ارسال
     */
    public function request_product_stock_with_category(License $license, array $unique_ids, array $categories = [], $timeout = 20)
    {
        try {
            if (empty($unique_ids)) {
                return ['success' => false, 'message' => 'لیست خالی', 'skipped' => true];
            }

            $token = $this->getValidToken($license);
            if (!$token) {
                return ['success' => false, 'message' => 'توکن معتبر نیست'];
            }

            $url = rtrim(env('SAMTATECH_API_URL'), '/') . '/api/v1/products/realtime-stock';

            $payload = [
                'unique_ids' => array_values(array_unique($unique_ids))
            ];

            if (!empty($categories)) {
                $payload['categories'] = array_values(array_unique($categories));
            }

            $response = Http::timeout(max(5, min(30, (int) $timeout)))
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ])
                ->post($url, $payload);

            if (!$response->successful()) {
                throw new Exception('خطا در ارتباط با سرور: HTTP ' . $response->status());
            }

            $data = $response->json();

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('خطا در پردازش پاسخ JSON');
            }

            Log::info('product view stock sync request completed', [
                'url' => $url,
                'unique_ids' => $unique_ids,
                'categories' => $categories,
                'timeout' => $timeout,
                'response' => $data
            ]);

            // رفع خطای Undefined array key 'success'
            if (!is_array($data) || !array_key_exists('success', $data)) {
                return ['success' => false, 'message' => 'پاسخ نامعتبر از سرور', 'raw' => $data];
            }

            return $data;

        } catch (Exception $e) {
            Log::error('product view stock sync failed', [
                'error' => $e->getMessage(),
                'unique_ids' => $unique_ids,
                'categories' => $categories,
                'timeout' => $timeout
            ]);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * به‌روزرسانی موجودی گروه‌بندی شده
     */
    public function updateGroupedStock(License $license)
    {
        return $this->request($license, 'products/update-stock-grouped', 'POST');
    }

    /**
     * بازگرداندن سفارشات در انتظار پاسخ
     */
    public function resetPendingOrders(License $license)
    {
        return $this->request($license, 'orders/reset-pending', 'POST');
    }

    /**
     * تست بررسی متا کی
     */
    public function testMetaCheck(License $license)
    {
        return $this->request($license, 'system/meta-check', 'GET');
    }

    /**
     * ارسال کدهای یکتا
     */
    public function syncUniqueIds(License $license, array $uniqueIds = [])
    {
        return $this->request($license, 'products/sync-unique-ids', 'POST', [
            'unique_ids' => $uniqueIds
        ]);
    }

    /**
     * به‌روزرسانی کد یکتا از روی شناسه
     */
    public function updateUniqueIdsByProductId(License $license, array $productIds = [])
    {
        return $this->request($license, 'products/update-unique-ids-by-id', 'POST', [
            'product_ids' => $productIds
        ]);
    }

    /**
     * تست ارتباط با API
     */
    public function testConnection(License $license)
    {
        return $this->request($license, 'system/health', 'GET');
    }

    /**
     * دریافت دسته‌بندی‌ها و ویژگی‌ها
     */
    public function getCategoriesAttributes(License $license)
    {
        return $this->request($license, 'categories', 'GET');
    }

    /**
     * دریافت همه کالاها
     */
    public function getAllProducts(License $license)
    {
        return $this->request($license, 'products/get_all_products', 'POST');
    }

    /**
     * به‌روزرسانی همه کالاها
     */
    public function updateAllProducts(License $license)
    {
        return $this->request($license, 'products/sync-all', 'POST');
    }

    /**
     * همگام‌سازی محصولات
     */
    public function syncProducts(License $license, array $update = [], array $insert = [])
    {
        return $this->request($license, 'products/sync', 'POST', [
            'update' => $update,
            'insert' => $insert
        ]);
    }

    /**
     * همگام‌سازی انبوه محصولات
     */
    public function bulkSyncProducts(License $license)
    {
        return $this->request($license, 'products/bulk-sync', 'POST');
    }

    /**
     * دریافت وضعیت همگام‌سازی
     */
    public function getSyncStatus(License $license, $syncId)
    {
        return $this->request($license, "products/sync-status/{$syncId}", 'GET');
    }

    /**
     * همگام‌سازی در سبد خرید
     */
    public function syncOnCart(License $license, array $cartItems)
    {
        return $this->request($license, 'products/sync-on-cart', 'POST', [
            'cart_items' => $cartItems
        ]);
    }

    /**
     * پاک کردن داده‌های MongoDB
     */
    public function clearMongoData(License $license)
    {
        return $this->request($license, 'mongo/clear-data', 'POST');
    }

    /**
     * دریافت کد یکتا بر اساس SKU
     */
    public function getUniqueIdBySku(License $license, string $sku)
    {
        return $this->request($license, "products/unique-by-sku/{$sku}", 'GET');
    }

    /**
     * دریافت کدهای یکتا بر اساس چندین SKU
     */
    public function getUniqueIdsBySkus(License $license, array $barcodes)
    {
        return $this->request($license, 'products/unique-by-skus', 'POST', [
            'barcodes' => $barcodes
        ]);
    }

    /**
     * پردازش کدهای یکتای خالی
     */
    public function processEmptyUniqueIds(License $license)
    {
        return $this->request($license, 'products/process-empty-unique-ids', 'POST');
    }

    /**
     * همگام‌سازی تنظیمات
     */
    public function syncSettings(License $license, array $settings)
    {
        return $this->request($license, 'sync-settings', 'POST', [
            'settings' => $settings
        ]);
    }

    /**
     * راه‌اندازی همگام‌سازی
     */
    public function triggerSync(License $license)
    {
        return $this->request($license, 'trigger-sync', 'POST');
    }

    /**
     * دریافت لاگ‌ها
     */
    public function getLogs(License $license)
    {
        return $this->request($license, 'logs', 'GET');
    }

    /**
     * دریافت لاگ‌های پلاگین
     */
    public function getPluginLogs(License $license)
    {
        return $this->request($license, 'logs/plugin', 'GET');
    }

    /**
     * ثبت کلید WooCommerce
     */
    public function registerWooCommerceKey(License $license, string $key, string $secret)
    {
        return $this->request($license, 'register-woocommerce-key', 'POST', [
            'key' => $key,
            'secret' => $secret
        ]);
    }

    /**
     * اعتبارسنجی کلید WooCommerce
     */
    public function validateWooCommerceKey(License $license, string $key, string $secret)
    {
        return $this->request($license, 'validate-woocommerce-key', 'POST', [
            'key' => $key,
            'secret' => $secret
        ]);
    }

    /**
     * دریافت تنظیمات
     */
    public function getSettings(License $license)
    {
        return $this->request($license, 'settings', 'GET');
    }

    /**
     * به‌روزرسانی تنظیمات
     */
    public function updateSettings(License $license, array $settings, bool $sync = false)
    {
        return $this->request($license, 'settings', 'POST', [
            'settings' => $settings,
            'sync' => $sync
        ]);
    }

    /**
     * دریافت درگاه‌های پرداخت
     */
    public function getPaymentGateways(License $license)
    {
        return $this->request($license, 'settings/payment-gateways', 'GET');
    }

    /**
     * به‌روزرسانی درگاه‌های پرداخت
     */
    public function updatePaymentGateways(License $license, array $gateways)
    {
        return $this->request($license, 'settings/payment-gateways', 'POST', [
            'gateways' => $gateways
        ]);
    }

    /**
     * بررسی نسخه
     */
    public function checkVersion(License $license, string $currentVersion)
    {
        return $this->request($license, 'check-version', 'POST', [
            'current_version' => $currentVersion
        ]);
    }

    /**
     * webhook تغییرات محصولات
     */
    public function webhookProductChanges(License $license, array $changes)
    {
        return $this->request($license, 'webhook/product-changes', 'POST', [
            'changes' => $changes
        ]);
    }

    /**
     * دریافت اطلاعات وضعیت لایسنس
     */
    public function getLicenseStatus(License $license)
    {
        return $this->request($license, 'licenses/status', 'GET');
    }

    /**
     * ورود به سیستم
     */
    public function login(License $license)
    {
        try {
            $url = rtrim(env('SAMTATECH_API_URL'), '/') . '/api/v1/login';

            $response = Http::timeout(30)
                ->post($url, [
                    'website_url' => $license->website_url,
                    'license_key' => $license->key
                ]);

            if (!$response->successful()) {
                throw new Exception('خطا در ارتباط با سرور: HTTP ' . $response->status());
            }

            $data = $response->json();

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('خطا در پردازش پاسخ API');
            }

            if (!$data['success']) {
                return [
                    'success' => false,
                    'message' => $data['message'] ?? 'خطا در ورود به سیستم'
                ];
            }

            // ذخیره توکن و تاریخ انقضا
            $license->update([
                'api_token' => $data['data']['token'],
                'token_expires_at' => now()->addSeconds($data['data']['expires_at'] - time()),
                'account_type' => $data['data']['account_type'] ?? 'basic'
            ]);

            Log::info('ورود موفقیت‌آمیز به API', [
                'license_id' => $license->id,
                'expires_at' => $license->token_expires_at,
                'account_type' => $data['data']['account_type'] ?? 'basic'
            ]);

            return [
                'success' => true,
                'message' => 'ورود موفقیت‌آمیز',
                'data' => $data['data']
            ];

        } catch (Exception $e) {
            Log::error('خطا در ورود به API: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * بررسی اعتبار لایسنس
     */
    public function verify_license(License $license)
    {
        try {
            $response = $this->login($license);

            if (!$response['success']) {
                throw new Exception($response['message'] ?? 'خطا در بررسی لایسنس');
            }

            Log::info('لایسنس با موفقیت فعال شد', [
                'license_id' => $license->id,
                'website_url' => $license->website_url
            ]);

            return [
                'success' => true,
                'message' => 'لایسنس با موفقیت فعال شد'
            ];

        } catch (Exception $e) {
            Log::error('خطا در فعال‌سازی لایسنس: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * تست اتصال به API
     */
    public function test_connection(License $license)
    {
        try {
            // تست اتصال به API
            $url = rtrim(env('SAMTATECH_API_URL'), '/') . '/api/v1/test-connection';

            $response = Http::timeout(30)
                ->withHeaders([
                    'Accept' => 'application/json'
                ])
                ->get($url);

            if (!$response->successful()) {
                throw new Exception('خطا در ارتباط با سرور: HTTP ' . $response->status());
            }

            $data = $response->json();

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('خطا در پردازش پاسخ API');
            }

            if (!$data['success']) {
                throw new Exception($data['message'] ?? 'خطا در اتصال به API');
            }

            // بررسی اعتبار توکن
            if ($license->api_token && $license->token_expires_at && $license->token_expires_at > now()) {
                // تست اعتبار توکن با درخواست به /me
                $me_response = $this->request($license, 'me', 'GET');
                if (!$me_response['success']) {
                    // اگر توکن نامعتبر است، تلاش برای دریافت توکن جدید
                    $login_response = $this->login($license);
                    if (!$login_response['success']) {
                        throw new Exception('خطا در تمدید توکن: ' . $login_response['message']);
                    }
                }
            } else {
                // اگر توکن منقضی شده، تلاش برای دریافت توکن جدید
                $login_response = $this->login($license);
                if (!$login_response['success']) {
                    throw new Exception('خطا در دریافت توکن جدید: ' . $login_response['message']);
                }
            }

            return [
                'success' => true,
                'message' => 'اتصال به API برقرار است و توکن معتبر است',
                'timestamp' => $data['timestamp'] ?? now()
            ];

        } catch (Exception $e) {
            Log::error('خطا در تست اتصال: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * دریافت نوع حساب کاربری
     */
    public function get_account_type(License $license)
    {
        return $license->account_type ?? 'basic';
    }

    /**
     * دریافت توکن فعلی
     * @param License $license
     * @return string|null توکن فعلی یا null در صورت عدم وجود
     */
    public function get_token(License $license)
    {
        // بررسی اعتبار توکن
        if (!$license->api_token || !$license->token_expires_at || $license->token_expires_at < now()) {
            // تلاش برای دریافت توکن جدید
            $login_response = $this->login($license);
            if (!$login_response['success']) {
                Log::error('خطا در دریافت توکن جدید: ' . ($login_response['message'] ?? 'خطای ناشناخته'));
                return null;
            }

            // بازخوانی لایسنس برای دریافت توکن جدید
            $license->refresh();
        }

        return $license->api_token;
    }

    /**
     * ارسال مجدد سفارش به وبسرویس
     * @param License $license
     * @param int $order_id شناسه سفارش
     * @param array $order_data اطلاعات سفارش
     * @return array نتیجه عملیات
     */
    public function resend_order(License $license, int $order_id, array $order_data)
    {
        try {
            Log::info('شروع ارسال مجدد سفارش', [
                'order_id' => $order_id,
                'license_id' => $license->id
            ]);

            $token = $this->getValidToken($license);
            if (!$token) {
                throw new Exception('توکن معتبر نیست');
            }

            $url = rtrim(env('SAMTATECH_API_URL'), '/') . '/api/invoices/webhook';

            $response = Http::timeout(30)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $token
                ])
                ->post($url, [
                    'order_id' => $order_id,
                    'customer_mobile' => $order_data['customer']['mobile'] ?? $order_data['customer']['phone'] ?? '',
                    'order_data' => $order_data
                ]);

            if (!$response->successful()) {
                $error_message = 'خطا در ارتباط با سرور: HTTP ' . $response->status();

                Log::error('خطا در ارسال مجدد فاکتور به وبسرویس', [
                    'error' => $error_message,
                    'order_id' => $order_id,
                    'status_code' => $response->status()
                ]);

                return [
                    'success' => false,
                    'message' => $error_message
                ];
            }

            $body = $response->json();

            if (!$body['success']) {
                Log::error('خطا در پردازش مجدد فاکتور توسط وبسرویس', [
                    'error' => $body['message'] ?? 'خطای نامشخص',
                    'order_id' => $order_id,
                    'response' => $body
                ]);

                return [
                    'success' => false,
                    'message' => $body['message'] ?? 'خطای نامشخص'
                ];
            }

            Log::info('فاکتور با موفقیت مجدداً ارسال شد', [
                'order_id' => $order_id,
                'response' => $body
            ]);

            return [
                'success' => true,
                'message' => 'فاکتور با موفقیت مجدداً ارسال شد'
            ];

        } catch (Exception $e) {
            Log::error('خطا در ارسال مجدد سفارش: ' . $e->getMessage(), [
                'order_id' => $order_id,
                'license_id' => $license->id
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
