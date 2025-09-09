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

class TantoooApiKeyController extends Controller
{
    use TantoooApiTrait;

    /**
     * ثبت کلید API Tantooo
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'serial_key' => 'required|string',
            'site_url' => 'required|url',
            'api_key' => 'required|string',
            'bearer_token' => 'nullable|string',
            'api_url' => 'nullable|url'
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

        // ذخیره یا به‌روزرسانی تنظیمات API Tantooo
        $userSettings = UserSetting::firstOrCreate(
            ['license_id' => $license->id],
            ['settings' => []]
        );

        $settings = $userSettings->settings;
        $settings['tantooo_api_key'] = $request->api_key;
        $settings['tantooo_bearer_token'] = $request->bearer_token;
        $settings['tantooo_api_url'] = $request->api_url;

        $userSettings->settings = $settings;
        $userSettings->save();

        return response()->json([
            'success' => true,
            'message' => 'کلید API Tantooo با موفقیت ثبت شد'
        ]);
    }

    /**
     * اعتبارسنجی کلید API Tantooo
     */
    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'serial_key' => 'required|string',
            'site_url' => 'required|url',
            'api_key' => 'required|string',
            'bearer_token' => 'nullable|string',
            'api_url' => 'required|url'
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

        // تست اتصال به API Tantooo
        $testResult = $this->testTantoooApiConnection(
            $request->api_url,
            $request->api_key,
            $request->bearer_token
        );

        if ($testResult['success']) {
            return response()->json([
                'success' => true,
                'message' => 'اتصال به API Tantooo برقرار است',
                'data' => $testResult['data']
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'خطا در اتصال به API Tantooo',
                'error' => $testResult['message']
            ], 400);
        }
    }

    /**
     * تست اتصال به API Tantooo
     */
    private function testTantoooApiConnection($apiUrl, $apiKey, $bearerToken = null)
    {
        try {
            $headers = [
                'X-API-KEY' => $apiKey,
                'Content-Type' => 'application/json'
            ];

            if (!empty($bearerToken)) {
                $headers['Authorization'] = 'Bearer ' . $bearerToken;
            }

            // تست اتصال با یک درخواست ساده
            $response = \Illuminate\Support\Facades\Http::withOptions([
                'verify' => false,
                'timeout' => 30
            ])->withHeaders($headers)->post($apiUrl, [
                'fn' => 'test_connection'
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json()
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'خطا در اتصال: ' . $response->status()
                ];
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطای سیستمی: ' . $e->getMessage()
            ];
        }
    }
}
