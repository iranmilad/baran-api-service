<?php

namespace App\Http\Controllers\WooCommerce;

use App\Http\Controllers\Controller;
use App\Jobs\WordPress\ProcessInvoice;
use App\Models\Invoice;
use App\Models\UserSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class InvoiceController extends Controller
{
    public function handleWebhook(Request $request)
    {
        Log::info('دریافت درخواست وب‌هوک فاکتور', [
            'request_data' => $request->all(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        // Attempt to authenticate license with token
        $license = JWTAuth::parseToken()->authenticate();
        if (!$license) {
            Log::error('Invalid token - license not found');
            return response()->json([
                'success' => false,
                'message' => 'Invalid token - license not found'
            ], 401);
        }

        if (!$license->isActive()) {
            Log::error('License is not active', [
                'license_id' => $license->id
            ]);
            return response()->json([
                'success' => false,
                'message' => 'License is not active'
            ], 403);
        }

        $user = $license->user;
        if (!$user) {
            Log::error('User not found for license', [
                'license_id' => $license->id
            ]);
            return response()->json([
                'success' => false,
                'message' => 'User not found for license'
            ], 404);
        }

        try {
            // بررسی تنظیمات کاربر
            $settings = UserSetting::where('license_id', $license->id)->first();
            if (!$settings) {
                Log::error('تنظیمات کاربر یافت نشد', [
                    'license_id' => $license->id
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'تنظیمات کاربر یافت نشد'
                ], 404);
            }

            Log::info('تنظیمات کاربر یافت شد', [
                'license_id' => $license->id,
                'user_id' => $settings->user_id
            ]);

            // بررسی تکراری بودن سفارش
            $existingInvoice = Invoice::where('license_key', $license->id)
                ->where('woocommerce_order_id', $request->order_id)
                ->where('status', $request->order_data['status'])
                ->first();

            if ($existingInvoice) {
                // به‌روزرسانی order_data با داده‌های جدید
                $existingInvoice->update([
                    'order_data' => $request->order_data,
                    'customer_mobile' => $request->customer_mobile
                ]);

                Log::info('داده‌های فاکتور موجود به‌روزرسانی شد', [
                    'invoice_id' => $existingInvoice->id,
                    'order_id' => $request->order_id,
                    'status' => $request->order_data['status'],
                    'updated_order_data' => $request->order_data
                ]);

                if ($existingInvoice->is_synced) {
                    Log::info('فاکتور قبلاً با این وضعیت ثبت شده است', [
                        'invoice_id' => $existingInvoice->id,
                        'order_id' => $request->order_id,
                        'status' => $request->order_data['status']
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'فاکتور قبلاً با این وضعیت ثبت شده است'
                    ]);
                } else {
                    Log::info('فاکتور قبلاً ثبت شده اما همگام‌سازی نشده است، در حال تلاش مجدد', [
                        'invoice_id' => $existingInvoice->id,
                        'order_id' => $request->order_id,
                        'status' => $request->order_data['status']
                    ]);

                    // ارسال به صف پردازش مجدد
                    ProcessInvoice::dispatch($existingInvoice,$license);

                    return response()->json([
                        'success' => true,
                        'message' => 'فاکتور قبلاً ثبت شده و در حال پردازش مجدد است'
                    ]);
                }
            }

            // ذخیره فاکتور در دیتابیس
            $invoice = Invoice::create([
                'license_key' => $license->id,
                'woocommerce_order_id' => $request->order_id,
                'status' => $request->order_data['status'],
                'customer_mobile' => $request->customer_mobile,
                'order_data' => $request->order_data
            ]);

            Log::info('فاکتور در دیتابیس ذخیره شد', [
                'invoice_id' => $invoice->id,
                'order_id' => $request->order_id
            ]);

            // ارسال به صف پردازش
            ProcessInvoice::dispatch($invoice,$license);

            Log::info('فاکتور به صف پردازش ارسال شد', [
                'invoice_id' => $invoice->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'فاکتور با موفقیت دریافت شد و در صف پردازش قرار گرفت'
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در پردازش فاکتور', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در پردازش فاکتور',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
