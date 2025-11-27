<?php

namespace App\Http\Controllers\WooCommerce;

use App\Http\Controllers\Controller;
use App\Jobs\WordPress\SyncProductFromRainSale;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Jobs\WordPress\ProcessProductSync;
use Illuminate\Support\Facades\Log;

class ProductSyncController extends Controller
{
    public function syncOnCart(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'license_key' => 'required|string',
            'isbn' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در اعتبارسنجی داده‌ها',
                'errors' => $validator->errors()
            ], 422);
        }

        // بررسی فعال بودن همگام‌سازی سبد خرید
        $userSettings = UserSetting::where('license_key', $request->license_key)->first();

        if (!$userSettings || !$userSettings->enable_cart_sync) {
            return response()->json([
                'success' => false,
                'message' => 'همگام‌سازی سبد خرید غیرفعال است'
            ], 403);
        }

        // ارسال درخواست به صف
        SyncProductFromRainSale::dispatch($request->isbn, $request->license_key);

        return response()->json([
            'success' => true,
            'message' => 'درخواست همگام‌سازی با موفقیت ثبت شد'
        ]);
    }

    public function handleProductChanges(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'license_key' => 'required|string',
                'products' => 'required|array',
                'products.*.Barcode' => 'required|string',
                'products.*.ItemName' => 'required|string',
                'products.*.PriceAmount' => 'required|numeric',
                'products.*.PriceAfterDiscount' => 'required|numeric',
                'products.*.TotalCount' => 'required|integer',
                'products.*.StockID' => 'nullable|string',
                'products.*.Attributes' => 'nullable|array'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'خطا در اعتبارسنجی داده‌ها',
                    'errors' => $validator->errors()
                ], 422);
            }

            $changes = $request->all();

            if (!is_array($changes)) {
                return response()->json([
                    'success' => false,
                    'message' => 'فرمت داده نامعتبر است'
                ], 400);
            }

            foreach ($changes as $change) {
                ProcessProductSync::dispatch($change);
            }

            return response()->json([
                'success' => true,
                'message' => 'تغییرات با موفقیت در صف قرار گرفتند'
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در دریافت تغییرات محصول: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'خطا در پردازش تغییرات'
            ], 500);
        }
    }
}
