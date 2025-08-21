<?php

namespace App\Http\Controllers;

use App\Models\License;
use App\Models\UserSetting;
use App\Models\WooCommerceApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductStockController extends Controller
{
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

            // اعتبارسنجی ورودی - پشتیبانی از تک کد یا آرایه کدها
            $validator = Validator::make($request->all(), [
                'unique_ids' => 'required|array|min:1',
                'unique_ids.*' => 'required|string'
            ]);

            // اگر validation شکست خورد، سعی کن با unique_id تکی
            if ($validator->fails()) {
                $singleValidator = Validator::make($request->all(), [
                    'unique_id' => 'required|string'
                ]);

                if ($singleValidator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'مقدار unique_id یا unique_ids الزامی است',
                        'errors' => [
                            'unique_ids' => $validator->errors()->get('unique_ids'),
                            'unique_id' => $singleValidator->errors()->get('unique_id')
                        ]
                    ], 422);
                }

                // تبدیل unique_id تکی به آرایه
                $uniqueIds = [$request->unique_id];
            } else {
                $uniqueIds = $request->unique_ids;
            }

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

            // درخواست به Warehouse API با آرایه کدهای یکتا (بدون warehouse_code)
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
     * به‌روزرسانی محصولات در وردپرس
     */
    private function updateWordPressProducts($license, $foundProducts, $userSettings)
    {
        try {
            // بررسی تنظیمات enable_stock_update
            if (!$userSettings->enable_stock_update) {
                return [
                    'success' => false,
                    'message' => 'به‌روزرسانی موجودی غیرفعال است',
                    'updated_count' => 0
                ];
            }

            // دریافت اطلاعات WooCommerce API
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                return [
                    'success' => false,
                    'message' => 'اطلاعات API وردپرس تنظیم نشده است',
                    'updated_count' => 0
                ];
            }

            // تبدیل محصولات به فرمت وردپرس
            $wordpressProducts = [];
            foreach ($foundProducts as $product) {
                $productInfo = $product['product_info'];
                $defaultWarehouseStock = $product['default_warehouse_stock'];

                // محاسبه موجودی و وضعیت موجودی
                $stockQuantity = 0;
                $stockStatus = 'outofstock';

                if ($defaultWarehouseStock && isset($defaultWarehouseStock['quantity'])) {
                    $stockQuantity = (int) $defaultWarehouseStock['quantity'];
                    $stockStatus = $stockQuantity > 0 ? 'instock' : 'outofstock';
                }

                // محاسبه قیمت با در نظر گیری واحد قیمت
                $regularPrice = '0';
                if (isset($productInfo['sellPrice'])) {
                    $price = (float) $productInfo['sellPrice'];

                    // تبدیل واحد قیمت از RainSale به WooCommerce
                    if ($userSettings->rain_sale_price_unit && $userSettings->woocommerce_price_unit) {
                        $rainSaleUnit = $userSettings->rain_sale_price_unit;
                        $wooCommerceUnit = $userSettings->woocommerce_price_unit;

                        // تبدیل قیمت بر اساس واحد
                        if ($rainSaleUnit === 'rial' && $wooCommerceUnit === 'toman') {
                            $price = $price / 10; // ریال به تومان
                        } elseif ($rainSaleUnit === 'toman' && $wooCommerceUnit === 'rial') {
                            $price = $price * 10; // تومان به ریال
                        }
                    }

                    $regularPrice = (string) $price;
                }

                $wordpressProduct = [
                    'unique_id' => $product['unique_id'],
                    'sku' => $productInfo['code'] ?? '',
                    'regular_price' => $regularPrice,
                    'stock_quantity' => $stockQuantity,
                    'manage_stock' => true,
                    'stock_status' => $stockStatus
                ];

                // اضافه کردن product_id اگر وجود دارد
                if (isset($productInfo['product_id'])) {
                    $wordpressProduct['product_id'] = $productInfo['product_id'];
                }

                $wordpressProducts[] = $wordpressProduct;
            }

            // ارسال درخواست به وردپرس
            $wordpressUrl = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products/unique/batch/update';

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 120,
                'connect_timeout' => 30
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($wooApiKey->api_key . ':' . $wooApiKey->api_secret)
            ])->put($wordpressUrl, [
                'products' => $wordpressProducts
            ]);

            if ($response->successful()) {
                $responseData = $response->json();

                Log::info('محصولات با موفقیت در وردپرس به‌روزرسانی شدند', [
                    'license_id' => $license->id,
                    'updated_count' => count($wordpressProducts),
                    'wordpress_response' => $responseData
                ]);

                return [
                    'success' => true,
                    'message' => 'محصولات با موفقیت به‌روزرسانی شدند',
                    'updated_count' => count($wordpressProducts),
                    'wordpress_response' => $responseData
                ];
            } else {
                Log::error('خطا در به‌روزرسانی محصولات وردپرس', [
                    'license_id' => $license->id,
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'url' => $wordpressUrl
                ]);

                return [
                    'success' => false,
                    'message' => 'خطا در به‌روزرسانی وردپرس: ' . $response->status(),
                    'updated_count' => 0,
                    'error_details' => $response->body()
                ];
            }

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی محصولات وردپرس', [
                'license_id' => $license->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطای سیستمی در به‌روزرسانی وردپرس',
                'updated_count' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
}
