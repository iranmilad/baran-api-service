<?php

namespace App\Http\Controllers;

use App\Models\License;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class LicenseController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('admin')->except(['status']);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|string',
            'store_id' => 'required|string',
            'subscription_type' => 'required|string',
            'api_username' => 'required|string',
            'api_password' => 'required|string',
            'api_url' => 'required|url',
            'website_url' => 'required|url',
            'phone_number' => 'required|string',
            'expires_at' => 'required|date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در اعتبارسنجی داده‌ها',
                'errors' => $validator->errors()
            ], 422);
        }

        $license = License::create([
            'license_key' => Str::uuid(),
            'user_id' => $request->user_id,
            'store_id' => $request->store_id,
            'subscription_type' => $request->subscription_type,
            'api_username' => $request->api_username,
            'api_password' => $request->api_password,
            'api_url' => $request->api_url,
            'website_url' => $request->website_url,
            'phone_number' => $request->phone_number,
            'expires_at' => $request->expires_at,
            'is_active' => true
        ]);

        return response()->json([
            'success' => true,
            'message' => 'لایسنس با موفقیت ایجاد شد',
            'license_key' => $license->license_key
        ]);
    }

    public function update(Request $request, License $license)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|string',
            'store_id' => 'required|string',
            'subscription_type' => 'required|string',
            'api_username' => 'required|string',
            'api_password' => 'required|string',
            'api_url' => 'required|url',
            'website_url' => 'required|url',
            'phone_number' => 'required|string',
            'expires_at' => 'required|date'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در اعتبارسنجی داده‌ها',
                'errors' => $validator->errors()
            ], 422);
        }

        $license->update([
            'user_id' => $request->user_id,
            'store_id' => $request->store_id,
            'subscription_type' => $request->subscription_type,
            'api_username' => $request->api_username,
            'api_password' => $request->api_password,
            'api_url' => $request->api_url,
            'website_url' => $request->website_url,
            'phone_number' => $request->phone_number,
            'expires_at' => $request->expires_at
        ]);

        return response()->json([
            'success' => true,
            'message' => 'لایسنس با موفقیت به‌روزرسانی شد',
            'license_key' => $license->license_key
        ]);
    }

    public function extend(Request $request, License $license)
    {
        $validator = Validator::make($request->all(), [
            'expires_at' => 'required|date|after:today'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در اعتبارسنجی داده‌ها',
                'errors' => $validator->errors()
            ], 422);
        }

        $license->update([
            'expires_at' => $request->expires_at
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تاریخ انقضای لایسنس با موفقیت تمدید شد',
            'expires_at' => $license->expires_at
        ]);
    }

    public function status(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'license_key' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در اعتبارسنجی داده‌ها',
                'errors' => $validator->errors()
            ], 422);
        }

        $license = License::where('license_key', $request->license_key)->first();

        if (!$license) {
            return response()->json([
                'success' => false,
                'message' => 'لایسنس یافت نشد'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'is_active' => $license->is_active,
            'expires_at' => $license->expires_at,
            'subscription_type' => $license->subscription_type
        ]);
    }
}
