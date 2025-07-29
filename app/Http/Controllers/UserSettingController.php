<?php

namespace App\Http\Controllers;

use App\Models\License;
use App\Models\UserSetting;
use App\Events\SettingsUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class UserSettingController extends Controller
{
    public function get(Request $request)
    {
        try {
            // دریافت و اعتبارسنجی توکن JWT
            $token = $request->bearerToken();
            if (!$token) {
                Log::error('توکن در درخواست ارسال نشده است');
                return response()->json([
                    'success' => false,
                    'message' => 'توکن ارسال نشده است'
                ], 401);
            }

            try {
                // تلاش برای احراز هویت لایسنس با توکن
                $license = JWTAuth::parseToken()->authenticate();
                if (!$license) {
                    Log::error('توکن نامعتبر - لایسنس یافت نشد');
                    return response()->json([
                        'success' => false,
                        'message' => 'توکن نامعتبر - لایسنس یافت نشد'
                    ], 401);
                }

                if (!$license->isActive()) {
                    Log::error('لایسنس فعال نیست', [
                        'license_id' => $license->id
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'لایسنس فعال نیست'
                    ], 403);
                }

                $settings = $license->userSetting;

                if (!$settings) {
                    // مقدار دیفالت به صورت flat برای پلاگین
                    $default = [
                        'license_id' => $license->id,
                        'rain_sale_price_unit' => 'rial',
                        'woocommerce_price_unit' => 'toman',
                        'enable_cart_sync' => false,
                        'enable_invoice' => false,
                        'enable_price_update' => false,
                        'enable_stock_update' => false,
                        'enable_name_update' => false,
                        'enable_new_product' => false,
                        'cash_on_delivery' => 'cash',
                        'credit_payment' => 'cash',
                        'invoice_pending_type' => 'off',
                        'invoice_on_hold_type' => 'off',
                        'invoice_processing_type' => 'off',
                        'invoice_complete_type' => 'off',
                        'invoice_cancelled_type' => 'off',
                        'invoice_refunded_type' => 'off',
                        'invoice_failed_type' => 'off',
                    ];
                    return response()->json([
                        'success' => true,
                        'data' => $default
                    ]);
                }

                // همیشه خروجی را به فرمت flat (پلاگین) برگردان و فقط کلیدهای مورد انتظار پلاگین را بازگردان
                $pluginSettings = $settings->toPluginArray();
                // فقط کلیدهای مورد انتظار پلاگین را نگه دار
                $allowedKeys = [
                    'license_id',
                    'rain_sale_price_unit',
                    'woocommerce_price_unit',
                    'enable_cart_sync',
                    'enable_invoice',
                    'enable_price_update',
                    'enable_stock_update',
                    'enable_name_update',
                    'enable_new_product',
                    'cash_on_delivery',
                    'credit_payment',
                    'invoice_pending_type',
                    'invoice_on_hold_type',
                    'invoice_processing_type',
                    'invoice_complete_type',
                    'invoice_cancelled_type',
                    'invoice_refunded_type',
                    'invoice_failed_type',
                    'payment_gateways'
                ];
                // اگر invoice_settings به صورت آرایه وجود داشت، کلیدهای آن را به سطح بالا اضافه کن
                if (isset($settings->invoice_settings) && is_array($settings->invoice_settings)) {
                    foreach ($settings->invoice_settings as $k => $v) {
                        $pluginSettings[$k] = $v;
                    }
                }
                // invoice_settings را به طور کامل حذف کن
                unset($pluginSettings['invoice_settings']);
                $filtered = [];
                foreach ($allowedKeys as $key) {
                    if (array_key_exists($key, $pluginSettings)) {
                        $filtered[$key] = $pluginSettings[$key];
                    } else {
                        $filtered[$key] = null;
                    }
                }
                return response()->json([
                    'success' => true,
                    'data' => $filtered
                ]);

            } catch (\Exception $e) {
                Log::error('خطا در احراز هویت توکن', [
                    'error' => $e->getMessage()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'خطا در احراز هویت توکن'
                ], 401);
            }

        } catch (\Exception $e) {
            Log::error('خطا در دریافت تنظیمات', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت تنظیمات'
            ], 500);
        }
    }

    public function update(Request $request)
    {
        try {
            // دریافت و اعتبارسنجی توکن JWT
            $token = $request->bearerToken();
            if (!$token) {
                Log::error('توکن در درخواست ارسال نشده است');
                return response()->json([
                    'success' => false,
                    'message' => 'توکن ارسال نشده است'
                ], 401);
            }

            try {
                // تلاش برای احراز هویت لایسنس با توکن
                $license = JWTAuth::parseToken()->authenticate();
                if (!$license) {
                    Log::error('توکن نامعتبر - لایسنس یافت نشد');
                    return response()->json([
                        'success' => false,
                        'message' => 'توکن نامعتبر - لایسنس یافت نشد'
                    ], 401);
                }
                \Log::info('درخواست به‌روزرسانی تنظیمات کاربر', [
                    'license_id' => $license->id,
                    'incoming_settings' => $request->settings
                ]);
                if (!$license->isActive()) {
                    Log::error('لایسنس فعال نیست', [
                        'license_id' => $license->id
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'لایسنس فعال نیست'
                    ], 403);
                }

                $validator = Validator::make($request->all(), [
                    'settings' => 'required|array',
                    'settings.enable_new_product' => 'required|boolean',
                    'settings.enable_price_update' => 'required|boolean',
                    'settings.enable_stock_update' => 'required|boolean',
                    'settings.enable_name_update' => 'required|boolean',
                    'settings.rain_sale_price_unit' => 'required|in:rial,toman',
                    'settings.woocommerce_price_unit' => 'required|in:rial,toman',
                    'settings.enable_invoice' => 'required|boolean',
                    'settings.enable_cart_sync' => 'required|boolean',
                    'settings.invoice_settings' => 'required|array',
                    'settings.invoice_settings.cash_on_delivery' => 'required|in:cash,credit',
                    'settings.invoice_settings.invoice_pending_type' => 'required|in:off,invoice,proforma,order',
                    'settings.invoice_settings.invoice_on_hold_type' => 'required|in:off,invoice,proforma,order',
                    'settings.invoice_settings.invoice_processing_type' => 'required|in:off,invoice,proforma,order',
                    'settings.invoice_settings.invoice_complete_type' => 'required|in:off,invoice,proforma,order'
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'خطا در اعتبارسنجی داده‌ها',
                        'errors' => $validator->errors()
                    ], 422);
                }

                \Log::info('درخواست به‌روزرسانی تنظیمات کاربر', [
                    'license_id' => $license->id,
                    'incoming_settings' => $request->settings
                ]);

                // تبدیل داده flat به ساختار دیتابیس
                $dbSettings = UserSetting::fromPluginArray($request->settings);
                $settings = UserSetting::updateOrCreate(
                    ['license_id' => $license->id],
                    $dbSettings
                );

                if(isset($request->sync) and $request->sync==true)
                    // ارسال رویداد به‌روزرسانی تنظیمات
                    event(new SettingsUpdated( $license,$settings));

                \Log::info('تنظیمات کاربر ذخیره شد', [
                    'license_id' => $settings->license_id,
                    'saved_settings' => $settings->toArray()
                ]);
                return response()->json([
                    'success' => true,
                    'message' => 'تنظیمات با موفقیت به‌روزرسانی شد',
                    'data' => $settings->toPluginArray()
                ]);

            } catch (\Exception $e) {
                Log::error('خطا در احراز هویت توکن', [
                    'error' => $e->getMessage()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'خطا در احراز هویت توکن'
                ], 401);
            }

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی تنظیمات', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'خطا در به‌روزرسانی تنظیمات'
            ], 500);
        }
    }

    public function getPaymentGateways(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'serial_key' => 'required|string',
            'site_url' => 'required|url'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات وارد شده نامعتبر است',
                'errors' => $validator->errors()
            ], 422);
        }

        $license = License::where('serial_key', $request->serial_key)
            ->where('domain', $request->site_url)
            ->first();

        if (!$license) {
            return response()->json([
                'success' => false,
                'message' => 'لایسنس نامعتبر است'
            ], 404);
        }

        $settings = $license->userSetting;

        if (!$settings) {
            return response()->json([
                'success' => true,
                'data' => [
                    'payment_gateways' => []
                ]
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'payment_gateways' => $settings->settings['payment_gateways'] ?? []
            ]
        ]);
    }

    public function updatePaymentGateways(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'serial_key' => 'required|string',
            'site_url' => 'required|url',
            'payment_gateways' => 'required|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات وارد شده نامعتبر است',
                'errors' => $validator->errors()
            ], 422);
        }

        $license = License::where('serial_key', $request->serial_key)
            ->where('domain', $request->site_url)
            ->first();

        if (!$license) {
            return response()->json([
                'success' => false,
                'message' => 'لایسنس نامعتبر است'
            ], 404);
        }

        $settings = $license->userSetting;

        if (!$settings) {
            $settings = $license->userSetting()->create([
                'settings' => [
                    'payment_gateways' => $request->payment_gateways
                ]
            ]);
        } else {
            $currentSettings = $settings->settings;
            $currentSettings['payment_gateways'] = $request->payment_gateways;
            $settings->update([
                'settings' => $currentSettings
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'تنظیمات درگاه‌های پرداخت با موفقیت به‌روزرسانی شد',
            'data' => [
                'payment_gateways' => $settings->settings['payment_gateways']
            ]
        ]);
    }
}
