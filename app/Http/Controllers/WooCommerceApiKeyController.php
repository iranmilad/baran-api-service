<?php

namespace App\Http\Controllers;

use App\Models\License;
use App\Models\WooCommerceApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;

class WooCommerceApiKeyController extends Controller
{
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'serial_key' => 'required|string',
            'site_url' => 'required|url',
            'api_key' => 'required|string',
            'api_secret' => 'required|string'
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

        // تست اتصال به ووکامرس
        $response = Http::withBasicAuth($request->api_key, $request->api_secret)
            ->get($request->site_url . '/wp-json/wc/v3/system_status');

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در اتصال به ووکامرس',
                'error' => $response->body()
            ], 400);
        }

        // ذخیره کلید API
        $apiKey = WooCommerceApiKey::updateOrCreate(
            ['license_id' => $license->id],
            [
                'api_key' => $request->api_key,
                'api_secret' => $request->api_secret
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'کلید API با موفقیت ثبت شد',
            'data' => $apiKey
        ]);
    }

    public function validate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'serial_key' => 'required|string',
            'site_url' => 'required|url',
            'api_key' => 'required|string',
            'api_secret' => 'required|string'
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

        // تست اتصال به ووکامرس
        $response = Http::withBasicAuth($request->api_key, $request->api_secret)
            ->get($request->site_url . '/wp-json/wc/v3/system_status');

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در اتصال به ووکامرس',
                'error' => $response->body()
            ], 400);
        }

        $systemStatus = $response->json();

        return response()->json([
            'success' => true,
            'message' => 'اتصال به ووکامرس برقرار است',
            'data' => [
                'woocommerce_version' => $systemStatus['woocommerce_version'] ?? null,
                'wordpress_version' => $systemStatus['wordpress_version'] ?? null,
                'php_version' => $systemStatus['php_version'] ?? null,
                'database_version' => $systemStatus['database_version'] ?? null
            ]
        ]);
    }
}
