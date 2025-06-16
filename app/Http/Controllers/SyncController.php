<?php

namespace App\Http\Controllers;

use App\Models\License;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Event;

class SyncController extends Controller
{
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

        $settings = $license->userSettings()->first();

        if (!$settings) {
            return response()->json([
                'success' => true,
                'data' => [
                    'has_changes' => false,
                    'settings' => null
                ]
            ]);
        }

        // اگر last_sync وجود داشته باشد، فقط تغییرات جدید را برمی‌گردانیم
        if ($request->last_sync) {
            $lastSync = \Carbon\Carbon::parse($request->last_sync);
            $hasChanges = $settings->updated_at->gt($lastSync);

            return response()->json([
                'success' => true,
                'data' => [
                    'has_changes' => $hasChanges,
                    'settings' => $hasChanges ? $settings : null,
                    'last_sync' => $settings->updated_at->format('Y-m-d H:i:s')
                ]
            ]);
        }

        // اگر last_sync وجود نداشته باشد، تمام تنظیمات را برمی‌گردانیم
        return response()->json([
            'success' => true,
            'data' => [
                'has_changes' => true,
                'settings' => $settings,
                'last_sync' => $settings->updated_at->format('Y-m-d H:i:s')
            ]
        ]);
    }

    public function triggerSync(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'site_url' => 'required|url',
            'api_key' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات وارد شده نامعتبر است',
                'errors' => $validator->errors()
            ], 422);
        }

        // ارسال درخواست همگام‌سازی به پلاگین
        $response = Http::withHeaders([
            'X-API-Key' => $request->api_key
        ])->post($request->site_url . '/wp-json/baran-inventory-manager/v1/sync-settings');

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در ارسال درخواست همگام‌سازی به پلاگین',
                'error' => $response->body()
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'درخواست همگام‌سازی با موفقیت ارسال شد'
        ]);
    }

    public function notifySettingsChange(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'license_id' => 'required|exists:licenses,id',
            'settings' => 'required|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات وارد شده نامعتبر است',
                'errors' => $validator->errors()
            ], 422);
        }

        $license = License::find($request->license_id);

        // ارسال درخواست همگام‌سازی به پلاگین
        $response = Http::withHeaders([
            'X-API-Key' => $license->api_key
        ])->post($license->domain . '/wp-json/baran-inventory-manager/v1/sync-settings');

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در ارسال درخواست همگام‌سازی به پلاگین',
                'error' => $response->body()
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'درخواست همگام‌سازی با موفقیت ارسال شد'
        ]);
    }
}
