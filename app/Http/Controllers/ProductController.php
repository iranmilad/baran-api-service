<?php

namespace App\Http\Controllers;

use App\Jobs\UpdateWooCommerceProducts;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use App\Jobs\ProcessProductChanges;
use App\Models\License;
use App\Jobs\SyncCategories;
use Illuminate\Support\Facades\Http;

class ProductController extends Controller
{
    public function sync(Request $request)
    {
        Log::info('Sync request received', [
            'request' => $request->all()
        ]);
        //example input request format: {"update":[{"ItemName":"گاباردين راسته 263","ItemId": "B6A449EE-5D8E-41E7-BD7B-00B8D2D53EEB","Barcode":"TRS18263NANA","PriceAmount":1290000,"PriceAfterDiscount":1290000,"TotalCount":0,"StockID":null,"DepartmentName":"داروخانه"},"insert":[{"ItemName":"گاباردين  ","ItemId": "B6A449EE-5D8E-41E7-3421-00B8D2D53EEB","Barcode":"TRS1845NANA","PriceAmount":1290000,"PriceAfterDiscount":1290000,"TotalCount":0,"StockID":null,"DepartmentName":"داروخانه"}]}
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
                        if (empty($productData['Barcode'])) {
                            $errors[] = [
                                'type' => 'insert',
                                'index' => $index,
                                'error' => 'Empty barcode'
                            ];
                            continue;
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
                        if (empty($productData['Barcode'])) {
                            $errors[] = [
                                'type' => 'update',
                                'index' => $index,
                                'error' => 'Empty barcode'
                            ];
                            continue;
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
                $batchSize = 10;
                foreach (array_chunk($changes, $batchSize) as $batchIndex => $batch) {
                    try {
                        // Dispatch batch changes to queue with increasing delay
                        ProcessProductChanges::dispatch($batch, $license->id)
                            ->onQueue('products')
                            ->delay(now()->addSeconds($batchIndex * 15));

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

            // ثبت لاگ قبل از شروع عملیات به‌روزرسانی
            Log::info('Bulk update request received', [
                'license_id' => $license->id,
                'website_url' => $license->website_url,
                'barcodes_count' => count($barcodes),
                'barcodes' => $barcodes,
                'update_type' => empty($barcodes) ? 'all_products' : 'selected_products',
                'timestamp' => now()->toDateTimeString()
            ]);

            // Queue bulk update
            UpdateWooCommerceProducts::dispatch($license->id, 'bulk', $barcodes)
                ->onQueue('bulk-update')
                ->delay(now()->addSeconds(5));

            return response()->json([
                'success' => true,
                'message' => 'Bulk product update request queued',
                'data' => [
                    'barcodes_count' => count($barcodes),
                    'update_type' => empty($barcodes) ? 'all_products' : 'selected_products'
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
}
