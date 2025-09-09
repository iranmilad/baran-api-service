<?php

namespace App\Http\Controllers\WooCommerce;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\LicenseWarehouseCategory;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\DB;
use App\Traits\WordPress\WordPressMasterTrait;

class ProductStockController extends Controller
{
    use WordPressMasterTrait;
    /**
     * دریافت موجودی محصولات بر اساس کدهای یکتا و انبار پیش‌فرض
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

            // اعتبارسنجی ورودی - فقط پارامتر unique_ids
            $validator = Validator::make($request->all(), [
                'unique_ids' => 'required|array|min:1',
                'unique_ids.*' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'پارامتر unique_ids الزامی است و باید آرایه‌ای از رشته‌ها باشد',
                    'errors' => $validator->errors()
                ], 422);
            }

            $uniqueIds = $request->unique_ids;

            // دریافت تنظیمات کاربر
            $userSettings = UserSetting::where('license_id', $license->id)->first();
            if (!$userSettings || empty($userSettings->default_warehouse_code)) {
                return response()->json([
                    'success' => false,
                    'message' => 'کد انبار پیش‌فرض تنظیم نشده است'
                ], 400);
            }

            $defaultWarehouseCode = $userSettings->default_warehouse_code;

            Log::info('درخواست استعلام موجودی محصولات', [
                'license_id' => $license->id,
                'unique_ids' => $uniqueIds,
                'unique_ids_count' => count($uniqueIds),
                'default_warehouse_code' => $defaultWarehouseCode
            ]);

            // بررسی اطلاعات API انبار
            if (empty($user->warehouse_api_url) || empty($user->warehouse_api_username) || empty($user->warehouse_api_password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'اطلاعات API انبار تنظیم نشده است'
                ], 400);
            }

            // درخواست به Warehouse API با آرایه کدهای یکتا (بدون warehouse_code در درخواست)
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user->warehouse_api_username . ':' . $user->warehouse_api_password)
            ])->post($user->warehouse_api_url . '/api/itemlist/GetItemsByIds', $uniqueIds);

            if (!$response->successful()) {
                Log::error('خطا در درخواست Warehouse API', [
                    'license_id' => $license->id,
                    'unique_ids' => $uniqueIds,
                    'api_url' => $user->warehouse_api_url,
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to fetch product data from Warehouse API',
                    'error_code' => $response->status()
                ], 500);
            }

            $warehouseData = $response->json();

            // پردازش نتایج از Warehouse API (مشابه ساختار RainSale)
            $foundProducts = [];
            $notFoundProducts = [];

            // بررسی ساختار پاسخ Warehouse API
            if (isset($warehouseData['data']['items']) && is_array($warehouseData['data']['items'])) {
                $items = $warehouseData['data']['items'];

                foreach ($uniqueIds as $uniqueId) {
                    $found = false;

                    foreach ($items as $item) {
                        if (isset($item['uniqueId']) && $item['uniqueId'] === $uniqueId) {
                            // بررسی موجودی در انبار پیش‌فرض
                            $defaultWarehouseStock = null;

                            if (isset($item['inventories']) && is_array($item['inventories'])) {
                                foreach ($item['inventories'] as $inventory) {
                                    if (isset($inventory['warehouse']) &&
                                        isset($inventory['warehouse']['code']) &&
                                        $inventory['warehouse']['code'] === $defaultWarehouseCode) {
                                        $defaultWarehouseStock = $inventory;
                                        break;
                                    }
                                }
                            }

                            $foundProducts[] = [
                                'unique_id' => $uniqueId,
                                'product_info' => $item,
                                'default_warehouse_stock' => $defaultWarehouseStock
                            ];
                            $found = true;
                            break;
                        }
                    }

                    if (!$found) {
                        $notFoundProducts[] = [
                            'unique_id' => $uniqueId,
                            'message' => 'محصول در انبار یافت نشد'
                        ];
                    }
                }
            } else {
                // اگر ساختار پاسخ متفاوت باشد، همه محصولات را به عنوان پیدا نشده در نظر بگیریم
                foreach ($uniqueIds as $uniqueId) {
                    $notFoundProducts[] = [
                        'unique_id' => $uniqueId,
                        'message' => 'خطا در دریافت اطلاعات از API انبار'
                    ];
                }
            }

            Log::info('موجودی محصولات از API انبار با موفقیت دریافت شد', [
                'license_id' => $license->id,
                'requested_count' => count($uniqueIds),
                'found_count' => count($foundProducts),
                'not_found_count' => count($notFoundProducts),
                'warehouse_api_url' => $user->warehouse_api_url
            ]);

            // به‌روزرسانی اطلاعات در وردپرس اگر محصولی پیدا شده باشد
            $wordpressUpdateResult = null;
            if (!empty($foundProducts)) {
                $wordpressUpdateResult = $this->updateWordPressProducts($license, $foundProducts, $userSettings);
            }

            $responseData = [
                'success' => true,
                'data' => [
                    'found_products' => $foundProducts,
                    'total_requested' => count($uniqueIds),
                    'total_found' => count($foundProducts),
                    'total_not_found' => count($notFoundProducts)
                ]
            ];

            // اضافه کردن نتیجه به‌روزرسانی وردپرس
            if ($wordpressUpdateResult !== null) {
                $responseData['data']['wordpress_update'] = $wordpressUpdateResult;
            }

            // اضافه کردن محصولات پیدا نشده اگر وجود داشته باشند
            if (!empty($notFoundProducts)) {
                $responseData['data']['not_found_products'] = $notFoundProducts;
                $responseData['data']['default_warehouse_code'] = $defaultWarehouseCode;
            }

            return response()->json($responseData);
        } catch (\Exception $e) {
            Log::error('خطا در استعلام موجودی محصول از Warehouse API', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
                'warehouse_api_url' => $user->warehouse_api_url ?? 'not_set',
                'license_id' => $license->id ?? 'unknown'
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * دریافت موجودی بلادرنگ محصولات از وب‌سرویس باران
     */
    public function getRealtimeStock(Request $request)
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
            $validator = Validator::make($request->all(), [
                'unique_ids' => 'required|array|min:1',
                'unique_ids.*' => 'required|string',
                'categories' => 'nullable|array',
                'categories.*' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // بررسی اطلاعات API باران
            if (empty($user->warehouse_api_url) ||
                empty($user->warehouse_api_username) ||
                empty($user->warehouse_api_password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Warehouse API credentials not configured'
                ], 400);
            }

            $uniqueIds = $request->unique_ids;
            $categories = $request->categories ?? [];

            // دریافت کدهای انبار برای کتگوری‌ها
            $warehouseIds = [];
            $categoryWarehouseMap = [];
            $userSettings = UserSetting::where('license_id', $license->id)->first();
            $defaultWarehouseCode = $userSettings->default_warehouse_code ?? null;
            if (!empty($categories)) {
                $warehouseCategories = LicenseWarehouseCategory::where('license_id', $license->id)
                    ->whereIn('category_name', $categories)
                    ->get();

                foreach ($warehouseCategories as $category) {
                    $warehouseIds = array_merge($warehouseIds, $category->warehouse_codes);
                }
                $warehouseIds = array_unique($warehouseIds);
            }
            else{
                if ($defaultWarehouseCode) {
                    $warehouseIds[] = $defaultWarehouseCode;
                }
                else{
                    $warehouseIds = [];
                }
            }

            // دریافت موجودی از API باران
            $baranResponse = $this->fetchStockFromBaran($user, $uniqueIds);

            if (!$baranResponse['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $baranResponse['message']
                ], 500);
            }

            // تبدیل پاسخ باران به فرمت مورد نظر
            $stockData = [];
            $baranItems = $baranResponse['data'] ?? [];

            // گروه‌بندی آیتم‌ها بر اساس itemID
            $groupedItems = collect($baranItems)->groupBy('itemID');

            foreach ($uniqueIds as $uniqueId) {
                $items = $groupedItems->get($uniqueId, collect());

                if ($items->isNotEmpty()) {
                    $totalStock = 0;

                    // اگر warehouseIds خالی بود جمع کل انبارها، اگر مقدار داشت فقط جمع انبارهای موجود در warehouseIds
                    if (empty($warehouseIds)) {
                        foreach ($items as $item) {
                            $stockQuantity = (int)($item['stockQuantity'] ?? 0);
                            if ($stockQuantity > 0) {
                                $totalStock += $stockQuantity;
                            }
                        }
                    } else {
                        foreach ($items as $item) {
                            if (isset($item['stockID']) && in_array($item['stockID'], $warehouseIds)) {
                                $stockQuantity = (int)($item['stockQuantity'] ?? 0);
                                if ($stockQuantity > 0) {
                                    $totalStock += $stockQuantity;
                                }
                            }
                        }
                    }

                    $stockData[$uniqueId] = [
                        'stock_quantity' => $totalStock
                    ];
                } else {
                    $stockData[$uniqueId] = [
                        'stock_quantity' => 0
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => $stockData,
                'warehouse_ids_used' => $warehouseIds,
                'categories_processed' => $categories,
                'category_warehouse_map' => $categoryWarehouseMap
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در دریافت موجودی بلادرنگ: ' . $e->getMessage(), [
                'license_id' => $license->id ?? null,
                'unique_ids' => $request->unique_ids ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطای سیستمی در دریافت موجودی'
            ], 500);
        }
    }

    /**
     * دریافت موجودی از API باران
     */
    private function fetchStockFromBaran($user, $uniqueIds)
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user->warehouse_api_username . ':' . $user->warehouse_api_password)
            ])->timeout(30)->post($user->warehouse_api_url . '/api/itemlist/GetItemsByIds', $uniqueIds);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در ارتباط با وب‌سرویس باران: HTTP ' . $response->status()
                ];
            }

            $data = $response->json();

            // بررسی اینکه پاسخ آرایه‌ای مستقیم است
            if (!is_array($data)) {
                return [
                    'success' => false,
                    'message' => 'ساختار پاسخ نامعتبر از وب‌سرویس باران'
                ];
            }

            return [
                'success' => true,
                'data' => $data
            ];

        } catch (\Exception $e) {
            Log::error('خطا در درخواست به API باران: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => 'خطا در ارتباط با وب‌سرویس باران'
            ];
        }
    }


    /**
     * بروزرسانی موجودی محصولات ووکامرس بر اساس همه دسته‌بندی‌ها (Job)
     */
    public function updateWooCommerceStockAllCategories(Request $request)
    {
        try {
            $license = JWTAuth::parseToken()->authenticate();
            if (!$license) {
                return response()->json([
                    'message' => 'Invalid token - license not found'
                ], 401);
            }
            if (!$license->isActive()) {
                return response()->json([
                    'message' => 'License is not active'
                ], 403);
            }

            // استفاده از متد trait برای dispatch job
            $result = $this->dispatchWooCommerceStockUpdateJob($license);

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('خطا در بروزرسانی موجودی همه دسته‌بندی‌ها: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'خطای سیستمی در بروزرسانی موجودی'
            ], 500);
        }
    }


}
