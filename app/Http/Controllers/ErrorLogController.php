<?php

namespace App\Http\Controllers;

use App\Models\License;
use App\Models\ErrorLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;

class ErrorLogController extends Controller
{
    public function getLogs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'serial_key' => 'required|string',
            'site_url' => 'required|url',
            'type' => 'nullable|string|in:sync,api,plugin',
            'limit' => 'nullable|integer|min:1|max:100'
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

        $query = $license->errorLogs()->orderBy('created_at', 'desc');

        if ($request->type) {
            $query->where('type', $request->type);
        }

        $logs = $query->limit($request->limit ?? 50)->get();

        return response()->json([
            'success' => true,
            'data' => $logs
        ]);
    }

    public function getPluginLogs(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'serial_key' => 'required|string',
            'site_url' => 'required|url',
            'limit' => 'nullable|integer|min:1|max:1000'
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

        // ارسال درخواست به پلاگین برای دریافت لاگ‌ها
        $response = Http::withHeaders([
            'X-API-Key' => $license->api_key
        ])->get($license->domain . '/wp-json/baran-inventory-manager/v1/logs', [
            'limit' => $request->limit ?? 1000
        ]);

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت لاگ‌های پلاگین',
                'error' => $response->body()
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => $response->json()
        ]);
    }
}
