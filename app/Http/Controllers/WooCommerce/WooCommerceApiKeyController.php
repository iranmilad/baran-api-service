<?php

namespace App\Http\Controllers\WooCommerce;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\WooCommerceApiKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use App\Traits\WordPress\WordPressMasterTrait;

class WooCommerceApiKeyController extends Controller
{
    use WordPressMasterTrait;
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
        $connectionTest = $this->validateWooCommerceApiCredentials(
            $request->site_url,
            $request->api_key,
            $request->api_secret
        );

        if (!$connectionTest['success']) {
            return response()->json([
                'success' => false,
                'message' => $connectionTest['message'],
                'error' => $connectionTest['error_body'] ?? null
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
        $connectionTest = $this->validateWooCommerceApiCredentials(
            $request->site_url,
            $request->api_key,
            $request->api_secret
        );

        if (!$connectionTest['success']) {
            return response()->json([
                'success' => false,
                'message' => $connectionTest['message'],
                'error' => $connectionTest['error_body'] ?? null
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => $connectionTest['message'],
            'data' => $connectionTest['system_status']
        ]);
    }
}
