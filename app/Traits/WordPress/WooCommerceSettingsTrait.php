<?php

namespace App\Traits\WordPress;

use Illuminate\Support\Facades\Log;
use App\Traits\PriceUnitConverter;

trait WooCommerceSettingsTrait
{
    use PriceUnitConverter;

    /**
     * دریافت تنظیمات واحد قیمت برای WooCommerce
     *
     * @param object $userSettings تنظیمات کاربر
     * @return array تنظیمات واحد قیمت
     */
    protected function getWooCommercePriceSettings($userSettings)
    {
        return [
            'rain_sale_unit' => $userSettings->rain_sale_price_unit ?? 'rial',
            'woocommerce_unit' => $userSettings->woocommerce_price_unit ?? 'toman'
        ];
    }

    /**
     * اعتبارسنجی تنظیمات WooCommerce
     *
     * @param object $license لایسنس
     * @param object $userSettings تنظیمات کاربر
     * @return array نتیجه اعتبارسنجی
     */
    protected function validateWooCommerceSettings($license, $userSettings)
    {
        $errors = [];

        // بررسی وجود website_url
        if (empty($license->website_url)) {
            $errors[] = 'آدرس وب‌سایت تنظیم نشده است';
        }

        // بررسی وجود WooCommerce API Key
        $wooApiKey = $license->woocommerceApiKey;
        if (!$wooApiKey) {
            $errors[] = 'کلید API WooCommerce تنظیم نشده است';
        } else {
            if (empty($wooApiKey->api_key)) {
                $errors[] = 'کلید API WooCommerce خالی است';
            }
            if (empty($wooApiKey->api_secret)) {
                $errors[] = 'رمز API WooCommerce خالی است';
            }
        }

        // بررسی تنظیمات موجودی
        if (!$userSettings->enable_stock_update) {
            $errors[] = 'به‌روزرسانی موجودی غیرفعال است';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * آماده‌سازی هدرهای احراز هویت برای WooCommerce API
     *
     * @param object $wooApiKey کلید API WooCommerce
     * @return array هدرهای HTTP
     */
    protected function prepareWooCommerceHeaders($wooApiKey)
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($wooApiKey->api_key . ':' . $wooApiKey->api_secret)
        ];
    }

    /**
     * ساخت URL کامل برای endpoint WooCommerce
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $endpoint نام endpoint
     * @return string URL کامل
     */
    protected function buildWooCommerceUrl($websiteUrl, $endpoint)
    {
        $baseUrl = rtrim($websiteUrl, '/');
        $endpoint = ltrim($endpoint, '/');

        return $baseUrl . '/wp-json/wc/v3/' . $endpoint;
    }

    /**
     * لاگ کردن فعالیت‌های WooCommerce
     *
     * @param string $action نوع فعالیت
     * @param array $context اطلاعات مربوط به فعالیت
     * @param string $level سطح لاگ (info, error, warning)
     */
    protected function logWooCommerceActivity($action, $context = [], $level = 'info')
    {
        $logData = array_merge([
            'woocommerce_action' => $action,
            'timestamp' => now()->toISOString()
        ], $context);

        switch ($level) {
            case 'error':
                Log::error("WooCommerce: {$action}", $logData);
                break;
            case 'warning':
                Log::warning("WooCommerce: {$action}", $logData);
                break;
            default:
                Log::info("WooCommerce: {$action}", $logData);
                break;
        }
    }

    /**
     * پردازش خطاهای WooCommerce API
     *
     * @param object $response پاسخ HTTP
     * @param string $action نوع فعالیت
     * @param array $context اطلاعات اضافی
     * @return array اطلاعات خطا
     */
    protected function handleWooCommerceError($response, $action, $context = [])
    {
        $errorData = [
            'action' => $action,
            'status_code' => $response->status(),
            'response_body' => $response->body(),
            'context' => $context
        ];

        $this->logWooCommerceActivity("خطا در {$action}", $errorData, 'error');

        return [
            'success' => false,
            'message' => "خطا در {$action}: " . $response->status(),
            'error_details' => $response->body(),
            'status_code' => $response->status()
        ];
    }

    /**
     * بررسی محدودیت‌های API WooCommerce
     *
     * @param object $response پاسخ HTTP
     * @return array اطلاعات محدودیت
     */
    protected function checkWooCommerceRateLimit($response)
    {
        $headers = $response->headers();

        return [
            'limit' => $headers['X-WP-TotalPages'][0] ?? null,
            'remaining' => $headers['X-WP-Total'][0] ?? null,
            'reset_time' => $headers['Date'][0] ?? null
        ];
    }
}
