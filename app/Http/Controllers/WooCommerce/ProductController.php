<?php

namespace App\Http\Controllers\WooCommerce;

use App\Http\Controllers\Controller;
use App\Jobs\WordPress\CoordinateProductUpdate;
use App\Jobs\WordPress\UpdateWooCommerceProducts;
use App\Jobs\WordPress\SyncUniqueIds;
use App\Jobs\WordPress\ProcessEmptyUniqueIds;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use App\Jobs\WordPress\ProcessProductChanges;
use App\Models\License;
use App\Jobs\WordPress\SyncCategories;
use Illuminate\Support\Facades\Http;

class ProductController extends Controller
{
    public function sync(Request $request)
    {

        //{"insert":[],"update":[{"ItemName":"\u0638\u0631\u0641 \u0633\u0631\u0627\u0645\u06cc\u06a9\u06cc \u0628\u0631\u06a9\u0647 \u0633\u0627\u06cc\u0632 3 - \u0633\u0641\u06cc\u062f","ItemId":"47E31A18-2074-400B-A1EB-7755CFDF84C2","Barcode":"11507-10","PriceAmount":3600000,"PriceAfterDiscount":1800000,"TotalCount":0,"StockID":"3320F176-BAA9-4B99-A7F6-2202DAC20F54","BrandID":"D091AF2A-1C78-4E6E-B6E6-30F884140BF0","Brand":"\u0641\u0631\u0632\u0627\u062f \u0641\u0631\u062c\u06cc","Type":239,"ParentID":"E1E654C6-A223-45B2-83C4-5AA3A23645B4","DepartmentName":"\u0638\u0631\u0641","Attributes":[{"AttributeName":"\u0631\u0646\u06af \u0627\u0635\u0644\u06cc \u062e\u0627\u0646\u0647 \u0648 \u062f\u06a9\u0648\u0631","ValueText":"\u0633\u0641\u06cc\u062f"},{"AttributeName":"\u0637\u0631\u062d \u0648 \u0646\u0642\u0634 \u062e\u0627\u0646\u0647 \u0648 \u062f\u06a9\u0648\u0631","ValueText":"\u0628\u0631\u06a9\u0647 \u0633\u0627\u06cc\u0632 3"},{"AttributeName":"\u062c\u0646\u0633 \u06a9\u0627\u0644\u0627 \u062e\u0627\u0646\u0647 \u0648 \u062f\u06a9\u0648\u0631","ValueText":"\u0633\u0631\u0627\u0645\u06cc\u06a9\u06cc"},{"AttributeName":"\u06af\u0631\u0648\u0647 \u0645\u062d\u0635\u0648\u0644","ValueText":"\u0638\u0631\u0641"},{"AttributeName":"\u06af\u0631\u0648\u0647 \u0645\u062d\u0635\u0648\u0644","ValueText":"\u0638\u0631 \u0641"},{"AttributeName":"\u0633\u0627\u06cc\u0632 \u0627\u0628\u0639\u0627\u062f \u062e\u0627\u0646\u0647 \u0648 \u062f\u06a9\u0648\u0631","ValueText":"\u0633\u0627\u06cc\u0632 3"},{"AttributeName":"\u0645\u0627\u0647\u06cc\u062a","ValueText":"\u0645\u062d\u0635\u0648\u0644"},{"AttributeName":"\u0631\u0646\u06af \u0627\u0635\u0644\u06cc \u062e\u0627\u0646\u0647 \u0648 \u062f\u06a9\u0648\u0631","ValueText":"\u0633\u0641\u06cc\u062f"},{"AttributeName":"\u0637\u0631\u062d \u0648 \u0646\u0642\u0634 \u062e\u0627\u0646\u0647 \u0648 \u062f\u06a9\u0648\u0631","ValueText":"\u0628\u0631\u06a9\u0647 \u0633\u0627\u06cc\u0632 3"},{"AttributeName":"\u062c\u0646\u0633 \u06a9\u0627\u0644\u0627 \u062e\u0627\u0646\u0647 \u0648 \u062f\u06a9\u0648\u0631","ValueText":"\u0633\u0631\u0627\u0645\u06cc\u06a9\u06cc"},{"AttributeName":"\u06af\u0631\u0648\u0647 \u0645\u062d\u0635\u0648\u0644","ValueText":"\u0638\u0631\u0641"},{"AttributeName":"\u06af\u0631\u0648\u0647 \u0645\u062d\u0635\u0648\u0644","ValueText":"\u0638\u0631 \u0641"},{"AttributeName":"\u0633\u0627\u06cc\u0632 \u0627\u0628\u0639\u0627\u062f \u062e\u0627\u0646\u0647 \u0648 \u062f\u06a9\u0648\u0631","ValueText":"\u0633\u0627\u06cc\u0632 3"},{"AttributeName":"\u0645\u0627\u0647\u06cc\u062a","ValueText":"\u0645\u062d\u0635\u0648\u0644"}]}
        try {

            // Get and validate JWT token
            $token = $request->bearerToken();
            if (!$token) {
                Log::error('No token provided in request');
                return response()->json([
                    'success' => false,
                    'message' => 'No token provided'
                ], 401);
            }

            try {
                // Attempt to authenticate license with token
                $license = JWTAuth::parseToken()->authenticate();
                if (!$license) {
                    Log::error('Invalid token - license not found');
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid token - license not found'
                    ], 401);
                }

                // ذخیره وبهوک دریافتی در جدول webhook_logs
                \App\Models\WebhookLog::create([
                    'license_id' => $license->id,
                    'logged_at' => now(),
                    'payload' => $request->all()
                ]);

                if (!$license->isActive()) {
                    Log::error('License is not active', [
                        'license_id' => $license->id
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'License is not active'
                    ], 403);
                }

                $user = $license->user;
                if (!$user) {
                    Log::error('User not found for license', [
                        'license_id' => $license->id
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'User not found for license'
                    ], 404);
                }

                // if($user->is_admin){
                //     Log::info('Sync request received', [
                //         'request' => $request->all()
                //     ]);
                // }

                // if($user->id==5)
                //     return response()->json([
                //         'success' => true,
                //         'message' => 'Sync request queued successfully',
                //         'data' => [
                //             'processed_count' => 0,
                //             'error_count' => 0,
                //             'errors' => null,
                //             'queue_status' => 'processing'
                //         ]
                //     ]);

                // Get user settings using license_id
                $settings = UserSetting::where('license_id', $license->id)->first();
                if (!$settings) {
                    Log::error('Settings not found for license', [
                        'license_id' => $license->id
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Settings not found for license'
                    ], 404);
                }

                // Log initial request
                Log::info('Sync request received', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'license_id' => $license->id,
                    'insert_count' => count($request->input('insert', [])),
                    'update_count' => count($request->input('update', []))
                ]);

                $processedCount = 0;
                $errors = [];
                $changes = [];

                // Process insert products
                foreach ($request->input('insert', []) as $index => $productData) {
                    try {
                        // if (empty($productData['Barcode'])) {
                        //     $errors[] = [
                        //         'type' => 'insert',
                        //         'index' => $index,
                        //         'error' => 'Empty barcode'
                        //     ];
                        //     continue;
                        // }

                        // Remap BrandID to parent_id (even if null)
                        // if (array_key_exists('BrandID', $productData)) {
                        //     //$productData['parent_id'] = $productData['BrandID'];
                        //     unset($productData['BrandID']);
                        // }

                        // Set is_variant based on Brand field
                        if (array_key_exists('ParentID', $productData)) {
                            $productData['is_variant'] = ($productData['Type'] == 239);
                            $productData['parent_id'] = $productData['ParentID'];
                            unset($productData['ParentID']);
                        }

                        $changes[] = [
                            'product' => $productData,
                            'change_type' => 'insert',
                            'changed_at' => now()->timestamp,
                            'license_id' => $license->id,
                            'user_id' => $user->id
                        ];
                        $processedCount++;

                    } catch (\Exception $e) {
                        $errors[] = [
                            'type' => 'insert',
                            'index' => $index,
                            'barcode' => $productData['Barcode'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ];
                    }
                }

                // Process update products
                foreach ($request->input('update', []) as $index => $productData) {
                    try {
                        // if (empty($productData['Barcode'])) {
                        //     $errors[] = [
                        //         'type' => 'update',
                        //         'index' => $index,
                        //         'error' => 'Empty barcode'
                        //     ];
                        //     continue;
                        // }

                        if (array_key_exists('ParentID', $productData)) {
                            $productData['is_variant'] = ($productData['Type'] == 239);
                            $productData['parent_id'] = $productData['ParentID'];
                            unset($productData['ParentID']);
                        }


                        $changes[] = [
                            'product' => $productData,
                            'change_type' => 'update',
                            'changed_at' => now()->timestamp,
                            'license_id' => $license->id,
                            'user_id' => $user->id
                        ];
                        $processedCount++;

                    } catch (\Exception $e) {
                        $errors[] = [
                            'type' => 'update',
                            'index' => $index,
                            'barcode' => $productData['Barcode'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ];
                    }
                }

                // Process changes in batches
                $batchSize = 100;
                foreach (array_chunk($changes, $batchSize) as $batchIndex => $batch) {
                    try {
                        // Dispatch batch changes to queue with increasing delay
                        ProcessProductChanges::dispatch($batch, $license->id)
                            ->onQueue('products');

                    } catch (\Exception $e) {
                        Log::error('Error dispatching batch: ' . $e->getMessage());
                        $errors[] = [
                            'error' => 'Error dispatching batch: ' . $e->getMessage()
                        ];
                    }
                }

                // Log completion
                Log::info('Sync request queued', [
                    'user_id' => $user->id,
                    'license_id' => $license->id,
                    'processed_count' => $processedCount,
                    'error_count' => count($errors),
                    'batch_count' => ceil(count($changes) / $batchSize)
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Sync request queued successfully',
                    'data' => [
                        'processed_count' => $processedCount,
                        'error_count' => count($errors),
                        'errors' => $errors,
                        'queue_status' => 'processing'
                    ]
                ]);

            } catch (TokenExpiredException $e) {
                Log::error('Token expired', [
                    'error' => $e->getMessage()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Token has expired'
                ], 401);
            } catch (TokenInvalidException $e) {
                Log::error('Invalid token', [
                    'error' => $e->getMessage()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid token'
                ], 401);
            }

        } catch (\Exception $e) {
            Log::error('Sync error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function bulkSync(Request $request)
    {
        try {
            $license = JWTAuth::parseToken()->authenticate();
            if (!$license || !$license->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or inactive license'
                ], 401);
            }

            // Check settings using license_id
            $settings = UserSetting::where('license_id', $license->id)->first();
            if (!$settings) {
                Log::error('Settings not found for license', [
                    'license_id' => $license->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Settings not found for license'
                ], 404);
            }

            // دریافت barcodes از درخواست
            $barcodes = $request->input('barcodes', []);

            // تعیین اندازه هر دسته - کاهش برای جلوگیری از timeout
            $batchSize = 50; // کاهش از 100 به 50 محصول در هر دسته

            // ثبت لاگ قبل از شروع عملیات به‌روزرسانی
            Log::info('Bulk update request received', [
                'license_id' => $license->id,
                'website_url' => $license->website_url,
                'barcodes_count' => count($barcodes),
                'update_type' => empty($barcodes) ? 'all_products' : 'selected_products',
                'batch_size' => $batchSize,
                'timestamp' => now()->toDateTimeString()
            ]);

            // اگر درخواست برای همه محصولات است (barcodes خالی)
            if (empty($barcodes)) {
                // استفاده از معماری جدید coordination
                CoordinateProductUpdate::dispatch($license->id, [])
                    ->onQueue('product-coordination')
                    ->delay(now()->addSeconds(5));

                Log::info('شروع coordination برای همه محصولات', [
                    'license_id' => $license->id,
                    'batch_size' => $batchSize
                ]);
            } else {
                // استفاده از معماری جدید coordination برای barcodes خاص
                CoordinateProductUpdate::dispatch($license->id, $barcodes)
                    ->onQueue('product-coordination')
                    ->delay(now()->addSeconds(5));

                Log::info('شروع coordination برای محصولات خاص', [
                    'license_id' => $license->id,
                    'barcodes_count' => count($barcodes),
                    'batch_size' => $batchSize
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Bulk product update request queued',
                'data' => [
                    'barcodes_count' => count($barcodes),
                    'update_type' => empty($barcodes) ? 'all_products' : 'selected_products',
                    'batch_size' => $batchSize,
                    'total_batches' => empty($barcodes) ? 'auto' : ceil(count($barcodes) / $batchSize)
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Bulk sync error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getSyncStatus($syncId)
    {
        try {
            $license = JWTAuth::parseToken()->authenticate();
            if (!$license || !$license->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or inactive license'
                ], 401);
            }

            // This method can return the update status
            // For example, number of updated products, number of errors, etc.
            return response()->json([
                'success' => true,
                'status' => 'processing',
                'progress' => 0
            ]);
        } catch (\Exception $e) {
            Log::error('Get sync status error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getUniqueIdBySku(Request $request)
    {
        try {


           // Get and validate JWT token
           $token = $request->bearerToken();
           if (!$token) {
               Log::error('No token provided in request');
               return response()->json([
                   'success' => false,
                   'message' => 'No token provided'
               ], 401);
           }


            // Attempt to authenticate license with token
            $license = JWTAuth::parseToken()->authenticate();
            if (!$license) {
                Log::error('Invalid token - license not found');
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid token - license not found'
                ], 401);
            }

            if (!$license->isActive()) {
                Log::error('License is not active', [
                    'license_id' => $license->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'License is not active'
                ], 403);
            }

            $user = $license->user;
            if (!$user) {
                Log::error('User not found for license', [
                    'license_id' => $license->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'User not found for license'
                ], 404);
            }



            // بررسی وجود اطلاعات وب‌سرویس باران
            if (empty($user->api_webservice) || empty($user->api_username) || empty($user->api_password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'اطلاعات وب‌سرویس باران تنظیم نشده است'
                ], 400);
            }

            $sku = $request->input('sku');
            log::info("SKU received for unique ID lookup:", [$sku]);

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user->api_username . ':' . $user->api_password)
            ])->post($user->api_webservice . '/RainSaleService.svc/GetItemInfo', [
                'barcode' => $sku
            ]);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'خطا در ارتباط با وب‌سرویس باران: ' . $response->status()
                ], 500);
            }


            $body = $response->json();
            $itemId = $body['GetItemInfoResult']['ItemID'] ?? null;

            if (!$itemId || $itemId == '00000000-0000-0000-0000-000000000000') {
                return response()->json([
                    'success' => false,
                    'message' => 'کد یکتا برای این SKU یافت نشد'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'unique_id' => $itemId,
                    'sku' => $sku
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت کد یکتا: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getUniqueIdsBySkus(Request $request)
    {
        try {
            // Get and validate JWT token
            $token = $request->bearerToken();
            if (!$token) {
                Log::error('No token provided in request');
                return response()->json([
                    'success' => false,
                    'message' => 'No token provided'
                ], 401);
            }

            // Attempt to authenticate license with token
            $license = JWTAuth::parseToken()->authenticate();
            if (!$license) {
                Log::error('Invalid token - license not found');
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid token - license not found'
                ], 401);
            }

            if (!$license->isActive()) {
                Log::error('License is not active', [
                    'license_id' => $license->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'License is not active'
                ], 403);
            }

            $user = $license->user;
            if (!$user) {
                Log::error('User not found for license', [
                    'license_id' => $license->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // دریافت تنظیمات کاربر برای دسترسی به default_warehouse_code
            $license->load('userSetting');
            $stockId = $license->userSetting ? $license->userSetting->default_warehouse_code : '';

            Log::info('استفاده از default_warehouse_code برای stockId', [
                'license_id' => $license->id,
                'stock_id' => $stockId,
                'has_user_settings' => !is_null($license->userSetting)
            ]);

            // Validate input
            $request->validate([
                'barcodes' => 'required|array|min:1',
                'barcodes.*' => 'required|string'
            ]);

            $barcodes = $request->input('barcodes');

            // آماده‌سازی body درخواست
            $requestBody = ['barcodes' => $barcodes];

            // اضافه کردن stockId فقط در صورت وجود مقدار
            if (!empty($stockId)) {
                $requestBody['stockId'] = $stockId;
            }

            // Call external API with array of barcodes
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user->api_username . ':' . $user->api_password)
            ])->post($user->api_webservice . '/RainSaleService.svc/GetItemInfos', $requestBody);

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'خطا در ارتباط با وب‌سرویس باران: ' . $response->status()
                ], 500);
            }

            $body = $response->json();
            $results = $body['GetItemInfosResult'] ?? [];

            // Transform response to return only barcode and ItemID mapping
            $mappedResults = [];
            foreach ($results as $item) {
                $barcode = $item['Barcode'] ?? null;
                $itemId = $item['ItemID'] ?? null;

                if ($barcode && $itemId && $itemId !== '00000000-0000-0000-0000-000000000000') {
                    $mappedResults[] = [
                        'barcode' => $barcode,
                        'unique_id' => $itemId
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => $mappedResults
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت کدهای یکتا: ' . $e->getMessage()
            ], 500);
        }
    }

    public function syncUniqueIds(Request $request)
    {
        try {
            // Get and validate JWT token
            $token = $request->bearerToken();
            if (!$token) {
                Log::error('No token provided in request');
                return response()->json([
                    'success' => false,
                    'message' => 'No token provided'
                ], 401);
            }

            // Attempt to authenticate license with token
            $license = JWTAuth::parseToken()->authenticate();
            if (!$license) {
                Log::error('Invalid token - license not found');
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid token - license not found'
                ], 401);
            }

            if (!$license->isActive()) {
                Log::error('License is not active', [
                    'license_id' => $license->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'License is not active'
                ], 403);
            }

            $user = $license->user;
            if (!$user) {
                Log::error('User not found for license', [
                    'license_id' => $license->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Validate input
            $request->validate([
                'products' => 'required|array|min:1',
                'products.*.unique_id' => 'required|string',
                'products.*.product_id' => 'required|integer',
                'products.*.variation_id' => 'nullable|integer',
                'products.*.sku' => 'nullable|string'
            ]);

            $products = $request->input('products');

            // Log the sync request
            Log::info('Unique IDs sync request received', [
                'license_id' => $license->id,
                'user_id' => $user->id,
                'products_count' => count($products),
                'timestamp' => now()->toDateTimeString()
            ]);

            // All products should have unique_id (as per requirement)
            // No need to separate products without unique_id

            // Prepare job data
            $syncData = [
                'products_with_unique_id' => $products, // All products have unique_id
                'license_id' => $license->id,
                'user_id' => $user->id,
                'timestamp' => now()->timestamp
            ];

            // Queue the sync job
            SyncUniqueIds::dispatch($syncData)
                ->onQueue('unique-ids-sync')
                ->delay(now()->addSeconds(2));

            return response()->json([
                'success' => true,
                'message' => 'درخواست همگام‌سازی کدهای یکتا با موفقیت ثبت شد',
                'data' => [
                    'total_products' => count($products),
                    'queue_status' => 'processing'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Sync unique IDs error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'خطا در ثبت درخواست همگام‌سازی کدهای یکتا: ' . $e->getMessage()
            ], 500);
        }
    }

    public function syncCategories()
    {
        try {
            $license = JWTAuth::parseToken()->authenticate();
            if (!$license || !$license->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'لایسنس معتبر نیست'
                ], 403);
            }

            // ارسال جاب به صف
            SyncCategories::dispatch($license->id)->onQueue('category');

            return response()->json([
                'success' => true,
                'message' => 'درخواست همگام‌سازی دسته‌بندی‌ها با موفقیت ثبت شد'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در ثبت درخواست همگام‌سازی دسته‌بندی‌ها: ' . $e->getMessage()
            ], 500);
        }
    }

    public function processEmptyUniqueIds(Request $request)
    {
        try {
            // Get and validate JWT token
            $token = $request->bearerToken();
            if (!$token) {
                Log::error('No token provided in request');
                return response()->json([
                    'success' => false,
                    'message' => 'No token provided'
                ], 401);
            }

            // Attempt to authenticate license with token
            $license = JWTAuth::parseToken()->authenticate();
            if (!$license) {
                Log::error('Invalid token - license not found');
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid token - license not found'
                ], 401);
            }

            if (!$license->isActive()) {
                Log::error('License is not active', [
                    'license_id' => $license->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'License is not active'
                ], 403);
            }

            $user = $license->user;
            if (!$user) {
                Log::error('User not found for license', [
                    'license_id' => $license->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Log the process request
            Log::info('Empty unique IDs processing request received', [
                'license_id' => $license->id,
                'user_id' => $user->id,
                'timestamp' => now()->toDateTimeString()
            ]);

            // Queue the process job
            ProcessEmptyUniqueIds::dispatch($license->id)
                ->onQueue('empty-unique-ids')
                ->delay(now()->addSeconds(2));

            return response()->json([
                'success' => true,
                'message' => 'درخواست پردازش محصولات بدون کد یکتا با موفقیت ثبت شد',
                'data' => [
                    'queue_status' => 'processing',
                    'job_type' => 'empty_unique_ids_processing'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Process empty unique IDs error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'خطا در ثبت درخواست پردازش محصولات بدون کد یکتا: ' . $e->getMessage()
            ], 500);
        }
    }
}
