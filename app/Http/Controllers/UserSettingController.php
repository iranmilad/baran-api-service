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
                    // Create default settings if not found
                    $settings = UserSetting::create([
                        'license_id' => $license->id,
                        'rain_sale_price_unit' => 'rial',
                        'woocommerce_price_unit' => 'toman',
                        'enable_cart_sync' => false,
                        'enable_invoice' => false,
                        'enable_price_update' => false,
                        'enable_stock_update' => false,
                        'enable_name_update' => false,
                        'enable_new_product' => false,
                        'invoice_settings' => [
                            'cash_on_delivery' => 'cash',
                            'invoice_pending_type' => 'off',
                            'invoice_on_hold_type' => 'off',
                            'invoice_processing_type' => 'off',
                            'invoice_complete_type' => 'off',
                        ],
                    ]);
                }

                return response()->json([
                    'success' => true,
                    'data' => $settings
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

                $settings = UserSetting::updateOrCreate(
                    ['license_id' => $license->id],
                    [
                        'rain_sale_price_unit' => $request->settings['rain_sale_price_unit'],
                        'woocommerce_price_unit' => $request->settings['woocommerce_price_unit'],
                        'enable_cart_sync' => $request->settings['enable_cart_sync'],
                        'enable_invoice' => $request->settings['enable_invoice'],
                        'enable_price_update' => $request->settings['enable_price_update'],
                        'enable_stock_update' => $request->settings['enable_stock_update'],
                        'enable_name_update' => $request->settings['enable_name_update'],
                        'enable_new_product' => $request->settings['enable_new_product'],
                        'invoice_settings' => $request->settings['invoice_settings']
                    ]
                );

                if(isset($request->sync) and $request->sync==true)
                    // ارسال رویداد به‌روزرسانی تنظیمات
                    event(new SettingsUpdated( $license,$settings));

                return response()->json([
                    'success' => true,
                    'message' => 'تنظیمات با موفقیت به‌روزرسانی شد',
                    'data' => $settings
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
