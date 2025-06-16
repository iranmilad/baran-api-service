<?php

namespace App\Http\Controllers;

use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PriceUnitSettingController extends Controller
{
    /**
     * به‌روزرسانی تنظیمات واحد قیمت
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'license_key' => 'required|string',
            'rain_sale_price_unit' => 'required|in:rial,toman',
            'woocommerce_price_unit' => 'required|in:rial,toman'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در اعتبارسنجی داده‌ها',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $settings = UserSetting::where('license_key', $request->license_key)->first();

            if (!$settings) {
                return response()->json([
                    'success' => false,
                    'message' => 'تنظیمات کاربر یافت نشد'
                ], 404);
            }

            $settings->update([
                'rain_sale_price_unit' => $request->rain_sale_price_unit,
                'woocommerce_price_unit' => $request->woocommerce_price_unit
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تنظیمات واحد قیمت با موفقیت به‌روزرسانی شد',
                'data' => [
                    'rain_sale_price_unit' => $settings->rain_sale_price_unit,
                    'woocommerce_price_unit' => $settings->woocommerce_price_unit
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در به‌روزرسانی تنظیمات',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * دریافت تنظیمات واحد قیمت
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function get(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'license_key' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در اعتبارسنجی داده‌ها',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $settings = UserSetting::where('license_key', $request->license_key)->first();

            if (!$settings) {
                return response()->json([
                    'success' => false,
                    'message' => 'تنظیمات کاربر یافت نشد'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'rain_sale_price_unit' => $settings->rain_sale_price_unit,
                    'woocommerce_price_unit' => $settings->woocommerce_price_unit
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت تنظیمات',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}