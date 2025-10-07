<?php

namespace App\Http\Controllers\Tantooo;

use App\Http\Controllers\Controller;
use App\Traits\Tantooo\TantoooApiTrait;
use App\Models\License;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class TantoooSyncController extends Controller
{
    use TantoooApiTrait;

    /**
     * تنظیمات همگام‌سازی Tantooo
     */
    public function syncSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'serial_key' => 'required|string',
            'site_url' => 'required|url',
            'last_sync' => 'nullable|date'
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

        // بررسی تنظیمات Tantooo
        $tantoooSettings = $this->getTantoooApiSettings($license);

        return response()->json([
            'success' => true,
            'message' => 'تنظیمات همگام‌سازی Tantooo دریافت شد',
            'data' => [
                'tantooo_configured' => !empty($tantoooSettings['api_key']),
                'api_url' => $tantoooSettings['api_url'] ?? null,
                'last_sync' => $request->last_sync,
                'current_time' => now()->toISOString()
            ]
        ]);
    }

    /**
     * شروع همگام‌سازی با سیستم Tantooo
     */
    public function triggerSync(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'serial_key' => 'required|string',
                'site_url' => 'required|url',
                'sync_type' => 'required|in:full,partial,products_only',
                'product_codes' => 'nullable|array',
                'product_codes.*' => 'string'
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

            // بررسی تنظیمات Tantooo
            $tantoooSettings = $this->getTantoooApiSettings($license);
            if (!$tantoooSettings || empty($tantoooSettings['api_key'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'تنظیمات API Tantooo یافت نشد'
                ], 400);
            }

            $syncId = 'tantooo_' . uniqid();

            // در اینجا می‌توانید Job های مختلف را بر اساس نوع sync راه‌اندازی کنید
            Log::info('درخواست همگام‌سازی Tantooo دریافت شد', [
                'license_id' => $license->id,
                'sync_type' => $request->sync_type,
                'sync_id' => $syncId,
                'product_codes' => $request->product_codes
            ]);

            // TODO: اینجا می‌توانید Job های مربوطه را dispatch کنید
            // dispatch(new TantoooSyncJob($license, $request->sync_type, $request->product_codes, $syncId));

            return response()->json([
                'success' => true,
                'message' => 'درخواست همگام‌سازی Tantooo با موفقیت ثبت شد',
                'data' => [
                    'sync_id' => $syncId,
                    'sync_type' => $request->sync_type,
                    'status' => 'queued'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در شروع همگام‌سازی Tantooo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در شروع همگام‌سازی'
            ], 500);
        }
    }

    /**
     * بررسی وضعیت همگام‌سازی
     */
    public function getSyncStatus(Request $request, $syncId)
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

            // TODO: در اینجا وضعیت همگام‌سازی را از پایگاه داده یا cache دریافت کنید
            // فعلاً یک پاسخ نمونه برمی‌گردانیم

            return response()->json([
                'success' => true,
                'data' => [
                    'sync_id' => $syncId,
                    'status' => 'in_progress', // completed, failed, in_progress, queued
                    'progress' => 75,
                    'processed_items' => 75,
                    'total_items' => 100,
                    'started_at' => now()->subMinutes(5)->toISOString(),
                    'estimated_completion' => now()->addMinutes(2)->toISOString()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت وضعیت همگام‌سازی'
            ], 500);
        }
    }

    /**
     * تست اتصال به سیستم Tantooo
     */
    public function testConnection(Request $request)
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

            $tantoooSettings = $this->getTantoooApiSettings($license);
            if (!$tantoooSettings) {
                return response()->json([
                    'success' => false,
                    'message' => 'تنظیمات API Tantooo یافت نشد'
                ], 400);
            }

            // تست اتصال
            $headers = [
                'X-API-KEY' => $tantoooSettings['api_key'],
                'Content-Type' => 'application/json'
            ];

            if (!empty($tantoooSettings['bearer_token'])) {
                $headers['Authorization'] = 'Bearer ' . $tantoooSettings['bearer_token'];
            }

            $response = \Illuminate\Support\Facades\Http::withOptions([
                'verify' => false,
                'timeout' => 30
            ])->withHeaders($headers)->post($tantoooSettings['api_url'], [
                'fn' => 'test_connection'
            ]);

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => 'اتصال به سیستم Tantooo برقرار است',
                    'data' => $response->json()
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'خطا در اتصال به سیستم Tantooo'
                ], 400);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در تست اتصال'
            ], 500);
        }
    }
}
