<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\UserSetting;
use App\Models\License;

class UserSettingSeeder extends Seeder
{
    public function run()
    {
        // دریافت همه لایسنس‌های فعال
        $licenses = License::where('status', 'active')->get();

        foreach ($licenses as $license) {
            // بررسی عدم وجود تنظیمات برای این لایسنس
            if (!UserSetting::where('license_id', $license->id)->exists()) {
                UserSetting::create([
                    'license_id' => $license->id,
                    'enable_price_update' => true,
                    'enable_stock_update' => true,
                    'enable_name_update' => true,
                    'enable_new_product' => true,
                    'enable_invoice' => false,
                    'enable_cart_sync' => false,
                    'payment_gateways' => [],
                    'invoice_settings' => [
                        'cash_on_delivery' => false,
                        'credit_payment' => false
                    ],
                    'rain_sale_price_unit' => 'toman',
                    'woocommerce_price_unit' => 'toman'
                ]);

                $this->command->info("تنظیمات برای لایسنس {$license->key} با آدرس سایت {$license->website_url} ایجاد شد");
            }
        }
    }
}
