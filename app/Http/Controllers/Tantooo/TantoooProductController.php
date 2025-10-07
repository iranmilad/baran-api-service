<?php

namespace App\Http\Controllers\Tantooo;

use App\Http\Controllers\Controller;
use App\Traits\Tantooo\TantoooApiTrait;
use App\Models\License;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class TantoooProductController extends Controller
{
    use TantoooApiTrait;

    /**
     * به‌روزرسانی محصول در سیستم Tantooo
     */
    public function updateProduct(Request $request)
    {
        try {
            // Get license ID from JWT token (already validated by middleware)
            $user = $request->attributes->get('user');
            $licenseId = $user['license_id'] ?? null;

            if (!$licenseId) {
                return response()->json([
                    'success' => false,
                    'message' => 'License ID not found in token'
                ], 401);
            }

            $license = \App\Models\License::find($licenseId);
            if (!$license || !$license->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or inactive license'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'code' => 'required|string',
                'title' => 'required|string',
                'price' => 'required|numeric|min:0',
                'discount' => 'nullable|numeric|min:0|max:100'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'اطلاعات وارد شده نامعتبر است',
                    'errors' => $validator->errors()
                ], 422);
            }

            // دریافت تنظیمات API Tantooo
            $tantoooSettings = $this->getTantoooApiSettings($license);
            if (!$tantoooSettings) {
                return response()->json([
                    'success' => false,
                    'message' => 'تنظیمات API Tantooo یافت نشد'
                ], 400);
            }

            // به‌روزرسانی محصول در API Tantooo
            $result = $this->updateProductInTantoooApi(
                $request->code,
                $request->title,
                $request->price,
                $request->discount ?? 0,
                $tantoooSettings['api_url'],
                $tantoooSettings['api_key'],
                $tantoooSettings['bearer_token']
            );

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی محصول Tantooo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در به‌روزرسانی محصول'
            ], 500);
        }
    }

    /**
     * همگام‌سازی محصولات با سیستم Tantooo - exactly like WooCommerce input format
     * عملیات سنگین روی queue انجام می‌شود
     */
    public function sync(Request $request)
    {
        try {
            // Get license ID from JWT token (already validated by middleware)
            $user = $request->attributes->get('user');
            $licenseId = $user['license_id'] ?? null;

            if (!$licenseId) {
                return response()->json([
                    'success' => false,
                    'message' => 'License ID not found in token'
                ], 401);
            }

            $license = \App\Models\License::find($licenseId);
            if (!$license || !$license->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or inactive license'
                ], 401);
            }

            // Validation - exactly like WooCommerce
            $validator = Validator::make($request->all(), [
                'update' => 'array',
                'insert' => 'array',
                'update.*.Barcode' => 'required_with:update.*|string',
                'insert.*.Barcode' => 'required_with:insert.*|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = $license->user;

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

            // بررسی تنظیمات API Tantooo قبل از ارسال به queue
            $tantoooSettings = $this->getTantoooApiSettings($license);
            if (!$tantoooSettings) {
                return response()->json([
                    'success' => false,
                    'message' => 'تنظیمات API Tantooo یافت نشد'
                ], 400);
            }

            $insertProducts = $request->input('insert', []);
            $updateProducts = $request->input('update', []);

            // بررسی وجود محصولات
            if (empty($insertProducts) && empty($updateProducts)) {
                return response()->json([
                    'success' => false,
                    'message' => 'هیچ محصولی برای پردازش یافت نشد'
                ], 400);
            }

            $syncId = 'tantooo_sync_' . uniqid();

            // Log initial request
            Log::info('درخواست همگام‌سازی Tantooo دریافت شد - ارسال به queue', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'license_id' => $license->id,
                'sync_id' => $syncId,
                'insert_count' => count($insertProducts),
                'update_count' => count($updateProducts),
                'total_products' => count($insertProducts) + count($updateProducts)
            ]);

            // ارسال درخواست به queue برای پردازش
            \App\Jobs\Tantooo\ProcessTantoooSyncRequest::dispatch(
                $license->id,
                $syncId,
                $insertProducts,
                $updateProducts
            )->onQueue('tantooo-sync');

            // پاسخ فوری به کلاینت
            return response()->json([
                'success' => true,
                'message' => 'درخواست همگام‌سازی با موفقیت دریافت شد و در صف پردازش قرار گرفت',
                'data' => [
                    'sync_id' => $syncId,
                    'status' => 'queued',
                    'total_products' => count($insertProducts) + count($updateProducts),
                    'insert_count' => count($insertProducts),
                    'update_count' => count($updateProducts),
                    'estimated_processing_time' => $this->calculateEstimatedTime(count($insertProducts) + count($updateProducts)),
                    'check_status_url' => '/api/tantooo/sync-status/' . $syncId,
                    'queued_at' => now()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در دریافت درخواست همگام‌سازی محصولات Tantooo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت درخواست همگام‌سازی محصولات'
            ], 500);
        }
    }

    /**
     * محاسبه زمان تخمینی پردازش
     */
    private function calculateEstimatedTime($productCount)
    {
        // فرض: هر محصول حدود 2-3 ثانیه زمان می‌برد
        $secondsPerProduct = 2.5;
        $totalSeconds = $productCount * $secondsPerProduct;

        if ($totalSeconds < 60) {
            return round($totalSeconds) . ' ثانیه';
        } elseif ($totalSeconds < 3600) {
            return round($totalSeconds / 60) . ' دقیقه';
        } else {
            return round($totalSeconds / 3600, 1) . ' ساعت';
        }
    }

    /**
     * بررسی وضعیت همگام‌سازی
     */
    public function getSyncStatus(Request $request, $syncId)
    {
        try {
            // Get license ID from JWT token (already validated by middleware)
            $user = $request->attributes->get('user');
            $licenseId = $user['license_id'] ?? null;

            if (!$licenseId) {
                return response()->json([
                    'success' => false,
                    'message' => 'License ID not found in token'
                ], 401);
            }

            $license = \App\Models\License::find($licenseId);
            if (!$license || !$license->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or inactive license'
                ], 401);
            }

            $cacheKey = "tantooo_sync_result_{$syncId}";
            $result = \Illuminate\Support\Facades\Cache::get($cacheKey);

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => 'نتیجه همگام‌سازی یافت نشد',
                    'data' => [
                        'sync_id' => $syncId,
                        'status' => 'not_found'
                    ]
                ], 404);
            }

            // اگر همچنان در حال پردازش است
            if (!isset($result['completed_at']) && !isset($result['failed_at'])) {
                return response()->json([
                    'success' => true,
                    'message' => 'همگام‌سازی در حال پردازش است',
                    'data' => [
                        'sync_id' => $syncId,
                        'status' => 'processing'
                    ]
                ]);
            }

            // نتیجه نهایی
            return response()->json([
                'success' => $result['success'],
                'message' => $result['success'] ? 'همگام‌سازی تکمیل شد' : 'همگام‌سازی با خطا مواجه شد',
                'data' => array_merge($result, ['status' => $result['success'] ? 'completed' : 'failed'])
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در بررسی وضعیت همگام‌سازی', [
                'sync_id' => $syncId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در بررسی وضعیت همگام‌سازی'
            ], 500);
        }
    }
}
