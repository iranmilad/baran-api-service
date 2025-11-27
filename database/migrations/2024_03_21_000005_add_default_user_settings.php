<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // دریافت تمام کاربران
        $users = DB::table('users')->get();

        foreach ($users as $user) {
            // بررسی وجود تنظیمات برای کاربر
            $exists = DB::table('user_settings')
                ->where('user_id', $user->id)
                ->exists();

            if (!$exists) {
                // ایجاد تنظیمات پیش‌فرض برای کاربر
                DB::table('user_settings')->insert([
                    'user_id' => $user->id,
                    'enable_price_update' => true,
                    'enable_stock_update' => true,
                    'enable_name_update' => true,
                    'enable_new_product' => true,
                    'enable_invoice' => true,
                    'enable_cart_sync' => true,
                    'payment_gateway_accounts' => json_encode([]),
                    'invoice_settings' => json_encode([
                        'cash_on_delivery' => true,
                        'credit_payment' => true
                    ]),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }
        }
    }

    public function down()
    {
        // در صورت نیاز به برگشت، می‌توانیم تنظیمات را حذف کنیم
        DB::table('user_settings')->truncate();
    }
};
