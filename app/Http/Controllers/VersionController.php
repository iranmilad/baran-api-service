<?php

namespace App\Http\Controllers;

use App\Models\License;
use App\Models\Version;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class VersionController extends Controller
{
    public function check(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'serial_key' => 'required|string',
            'site_url' => 'required|url',
            'current_version' => 'required|string'
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

        $latestVersion = Version::orderBy('version', 'desc')->first();

        if (!$latestVersion) {
            return response()->json([
                'success' => true,
                'message' => 'نسخه جدیدی موجود نیست',
                'data' => [
                    'has_update' => false
                ]
            ]);
        }

        $hasUpdate = version_compare($latestVersion->version, $request->current_version, '>');

        return response()->json([
            'success' => true,
            'message' => $hasUpdate ? 'نسخه جدیدی موجود است' : 'نسخه شما به‌روز است',
            'data' => [
                'has_update' => $hasUpdate,
                'latest_version' => $hasUpdate ? [
                    'version' => $latestVersion->version,
                    'download_url' => $latestVersion->download_url,
                    'changelog' => $latestVersion->changelog,
                    'release_date' => $latestVersion->created_at->format('Y-m-d H:i:s')
                ] : null
            ]
        ]);
    }
}
