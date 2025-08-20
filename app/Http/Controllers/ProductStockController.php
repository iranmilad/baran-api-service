<?php

namespace App\Http\Controllers;

use App\Models\License;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductStockController extends Controller
{
    /**
     * دریافت موجودی محصول بر اساس کد یکتا و انبار پیش‌فرض
     */
    public function getStockByUniqueId(Request $request)
    {
        try {
            // احراز هویت لایسنس
            $license = JWTAuth::parseToken()->authenticate();
            if (!$license) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid token - license not found'
                ], 401);
            }

            if (!$license->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'License is not active'
                ], 403);
            }

            // بررسی وجود کاربر
            $user = $license->user;
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found for license'
                ], 404);
            }

            // اعتبارسنجی ورودی
            $validator = \Validator::make($request->all(), [
                'unique_id' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 400);
            }

            $uniqueId = $request->unique_id;

            // دریافت تنظیمات کاربر
            $userSettings = UserSetting::where('license_id', $license->id)->first();
            if (!$userSettings || empty($userSettings->default_warehouse_code)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Default warehouse code not configured'
                ], 400);
            }

            $defaultWarehouseCode = $userSettings->default_warehouse_code;

            Log::info('درخواست استعلام موجودی محصول', [
                'license_id' => $license->id,
                'unique_id' => $uniqueId,
                'default_warehouse_code' => $defaultWarehouseCode
            ]);

            // بررسی اطلاعات API کاربر
            if (empty($user->api_webservice) || empty($user->api_username) || empty($user->api_password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'API credentials not configured'
                ], 400);
            }

            // درخواست به RainSale API
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user->api_username . ':' . $user->api_password)
            ])->post('http://103.216.62.61:4645/api/itemlist/GetItemsByIds', [$uniqueId]);

            if (!$response->successful()) {
                Log::error('خطا در درخواست GetItemsByIds', [
                    'license_id' => $license->id,
                    'unique_id' => $uniqueId,
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to fetch product data from RainSale',
                    'error_code' => $response->status()
                ], 500);
            }

            $productsData = $response->json();

            if (!is_array($productsData) || empty($productsData)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No product data found for the given unique ID'
                ], 404);
            }

            // جستجو برای محصول با itemID مطابق و stockID مطابق با default_warehouse_code
            $targetProduct = null;
            foreach ($productsData as $product) {
                if (isset($product['itemID']) &&
                    strtolower($product['itemID']) === strtolower($uniqueId) &&
                    isset($product['stockID']) &&
                    $product['stockID'] === $defaultWarehouseCode) {

                    $targetProduct = $product;
                    break;
                }
            }

            if (!$targetProduct) {
                // اگر محصول در انبار پیش‌فرض پیدا نشد، لیست تمام انبارها را برگردان
                $availableStocks = [];
                foreach ($productsData as $product) {
                    if (isset($product['itemID']) && strtolower($product['itemID']) === strtolower($uniqueId)) {
                        $availableStocks[] = [
                            'stock_id' => $product['stockID'] ?? 'unknown',
                            'stock_name' => $product['stockName'] ?? 'unknown',
                            'quantity' => $product['stockQuantity'] ?? 0
                        ];
                    }
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Product not found in default warehouse',
                    'unique_id' => $uniqueId,
                    'default_warehouse_code' => $defaultWarehouseCode,
                    'available_stocks' => $availableStocks
                ], 404);
            }

            Log::info('موجودی محصول با موفقیت دریافت شد', [
                'license_id' => $license->id,
                'unique_id' => $uniqueId,
                'stock_id' => $targetProduct['stockID'],
                'stock_name' => $targetProduct['stockName'] ?? 'unknown',
                'quantity' => $targetProduct['stockQuantity']
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'unique_id' => $uniqueId,
                    'item_id' => $targetProduct['itemID'],
                    'item_name' => $targetProduct['itemName'] ?? 'unknown',
                    'stock_id' => $targetProduct['stockID'],
                    'stock_name' => $targetProduct['stockName'] ?? 'unknown',
                    'stock_quantity' => $targetProduct['stockQuantity'] ?? 0,
                    'sale_price' => $targetProduct['salePrice'] ?? 0,
                    'current_discount' => $targetProduct['currentDiscount'] ?? 0,
                    'barcode' => $targetProduct['barcode'] ?? '',
                    'department_code' => $targetProduct['departmentCode'] ?? '',
                    'department_name' => $targetProduct['departmentName'] ?? ''
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در استعلام موجودی محصول', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
