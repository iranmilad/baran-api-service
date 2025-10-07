<?php

namespace App\Http\Controllers\Tantooo;

use App\Http\Controllers\Controller;
use App\Traits\Tantooo\TantoooApiTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TantoooDataController extends Controller
{
    use TantoooApiTrait;

    /**
     * دریافت اطلاعات اصلی از Tantooo (دسته‌بندی‌ها، رنگ‌ها، سایزها)
     */
    public function getMainData(Request $request)
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

            Log::info('درخواست دریافت اطلاعات اصلی Tantooo', [
                'license_id' => $license->id,
                'website_url' => $license->website_url
            ]);

            // دریافت اطلاعات اصلی با مدیریت خودکار توکن
            $result = $this->getTantoooMainDataWithToken($license);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'اطلاعات اصلی با موفقیت دریافت شد',
                    'data' => $result['data']
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('خطا در کنترلر دریافت اطلاعات اصلی Tantooo', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت اطلاعات اصلی'
            ], 500);
        }
    }

    /**
     * دریافت دسته‌بندی‌ها از Tantooo
     */
    public function getCategories(Request $request)
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

            // دریافت اطلاعات اصلی
            $result = $this->getTantoooMainDataWithToken($license);

            if ($result['success'] && isset($result['data']['category'])) {
                return response()->json([
                    'success' => true,
                    'message' => 'دسته‌بندی‌ها با موفقیت دریافت شدند',
                    'data' => [
                        'categories' => $result['data']['category']
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'خطا در دریافت دسته‌بندی‌ها'
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('خطا در دریافت دسته‌بندی‌های Tantooo', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت دسته‌بندی‌ها'
            ], 500);
        }
    }

    /**
     * دریافت رنگ‌ها از Tantooo
     */
    public function getColors(Request $request)
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

            // دریافت اطلاعات اصلی
            $result = $this->getTantoooMainDataWithToken($license);

            if ($result['success'] && isset($result['data']['colors'])) {
                return response()->json([
                    'success' => true,
                    'message' => 'رنگ‌ها با موفقیت دریافت شدند',
                    'data' => [
                        'colors' => $result['data']['colors']
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'خطا در دریافت رنگ‌ها'
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('خطا در دریافت رنگ‌های Tantooo', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت رنگ‌ها'
            ], 500);
        }
    }

    /**
     * دریافت سایزها از Tantooo
     */
    public function getSizes(Request $request)
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

            // دریافت اطلاعات اصلی
            $result = $this->getTantoooMainDataWithToken($license);

            if ($result['success'] && isset($result['data']['sizes'])) {
                return response()->json([
                    'success' => true,
                    'message' => 'سایزها با موفقیت دریافت شدند',
                    'data' => [
                        'sizes' => $result['data']['sizes']
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'خطا در دریافت سایزها'
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('خطا در دریافت سایزهای Tantooo', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت سایزها'
            ], 500);
        }
    }

    /**
     * تجدید توکن Tantooo
     */
    public function refreshToken(Request $request)
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

            // پاک کردن توکن قبلی و دریافت توکن جدید
            $license->clearToken();

            $result = $this->getTantoooToken($license);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'توکن با موفقیت تجدید شد',
                    'data' => [
                        'token_expires_at' => $license->token_expires_at
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 400);
            }

        } catch (\Exception $e) {
            Log::error('خطا در تجدید توکن Tantooo', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در تجدید توکن'
            ], 500);
        }
    }
}
