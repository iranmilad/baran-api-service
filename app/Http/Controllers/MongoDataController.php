<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\License;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class MongoDataController extends Controller
{
    public function clearData(Request $request)
    {
        try {
            // احراز هویت با JWT
            $license = JWTAuth::parseToken()->authenticate();
            if (!$license) {
                return response()->json([
                    'success' => false,
                    'message' => 'لایسنس نامعتبر است'
                ], 401);
            }

            // پیدا کردن کاربر مرتبط با لایسنس
            $user = $license->user;
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'کاربر مرتبط با لایسنس یافت نشد'
                ], 400);
            }



            // حذف محصولات از ووکامرس (در دیتابیس محلی)
            $result=$license->products()->delete();

            return response()->json([
                'success'       => true,
                'message'       => 'درخواست دریافت مجدد تمامی کالاها دریافت شد'
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در پاک کردن داده‌ها: ' . $e->getMessage(), [
                'error'       => $e->getMessage(),
                'user_id'     => $user->id ?? null,
                'license_id'  => $license->id ?? null
            ]);
            return response()->json([
                'success' => false,
                'message' => 'خطا در پاک کردن داده‌ها: ' . $e->getMessage()
            ], 500);
        }
    }
}
