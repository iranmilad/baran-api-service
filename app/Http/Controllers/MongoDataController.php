<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\License;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use MongoDB\Client;
use MongoDB\Driver\Exception\ConnectionTimeoutException;
use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Driver\Exception\AuthenticationException;

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

            if (!$user->mongo_connection_string) {
                Log::warning('اطلاعات اتصال به مونگو تنظیم نشده است', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'license_id' => $license->id
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'اطلاعات اتصال به مونگو تنظیم نشده است'
                ], 400);
            }

            // اتصال به مونگو با درایور جدید
            $client = new Client("mongodb://" . $user->mongo_connection_string, [
                'username'   => $user->mongo_username,
                'password'   => $user->mongo_password,
                'authSource' => 'admin'
            ]);

            // انتخاب دیتابیس و کالکشن
            $collection = $client->baran_sync->products;

            // پاک کردن تمام داده‌ها
            $result = $collection->deleteMany([]);

            Log::info('محتوای کالکشن products با موفقیت پاک شد', [
                'user_id'       => $user->id,
                'license_id'    => $license->id,
                'deleted_count' => $result->getDeletedCount()
            ]);

            // حذف محصولات از ووکامرس (در دیتابیس محلی)
            $result=$license->products()->delete();

            return response()->json([
                'success'       => true,
                'message'       => 'درخواست دریافت مجدد تمامی کالاها دریافت شد'
            ]);

        } catch (ConnectionTimeoutException $e) {
            Log::error('خطا در اتصال به مونگو: ' . $e->getMessage(), [
                'user_id'        => $user->id ?? null,
                'license_id'     => $license->id ?? null,
                'connection_str' => $user->mongo_connection_string ?? null,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'خطا در اتصال به مونگو: زمان اتصال به پایان رسید'
            ], 500);

        } catch (AuthenticationException $e) {
            Log::error('خطا در احراز هویت مونگو: ' . $e->getMessage(), [
                'user_id'        => $user->id ?? null,
                'license_id'     => $license->id ?? null,
                'connection_str' => $user->mongo_connection_string ?? null,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'خطا در احراز هویت مونگو: نام کاربری یا رمز عبور اشتباه است'
            ], 500);

        } catch (RuntimeException $e) {
            Log::error('خطا در عملیات مونگو: ' . $e->getMessage(), [
                'user_id'        => $user->id ?? null,
                'license_id'     => $license->id ?? null,
                'connection_str' => $user->mongo_connection_string ?? null,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'خطا در عملیات مونگو: عملیات با خطا مواجه شد'
            ], 500);

        } catch (\Exception $e) {
            Log::error('خطای ناشناخته در پاک کردن داده‌ها: ' . $e->getMessage(), [
                'error'       => $e->getMessage(),
                'user_id'     => $user->id ?? null,
                'license_id'  => $license->id ?? null
            ]);
            return response()->json([
                'success' => false,
                'message' => 'خطای ناشناخته در پاک کردن داده‌ها'
            ], 500);
        }
    }
}
