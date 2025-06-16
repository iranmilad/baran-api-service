<?php

namespace Database\Seeders;

use App\Models\License;
use App\Models\User;
use App\Models\UserSetting;
use App\Models\WooCommerceApiKey;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WooCommerceSeeder extends Seeder
{
    public function run(): void
    {
        // بررسی و ایجاد کاربر تست
        $user = User::firstOrCreate(
            ['email' => 'kazemi.milad@gmail.com'],
            [
                'name' => 'کاظمی',
                'password' => Hash::make('password'),
            ]
        );

        // تولید کلید لایسنس یکتا
        $licenseKey = 'test-license-key-22';

        // بررسی و ایجاد لایسنس
        $license = License::firstOrCreate(
            [
                'key' => $licenseKey,
                'website_url' => 'https://wordpress.loc',
            ],
            [
                'user_id' => $user->id,
                'status' => 'active',
                'expires_at' => now()->addYear(),
            ]
        );

        // بررسی و ایجاد کلید API ووکامرس
        WooCommerceApiKey::firstOrCreate(
            ['license_id' => $license->id],
            [
                'api_key' => 'ck_4a51ab2c397e516dcc25be36d9dc5afdd6262ef1',
                'api_secret' => 'cs_4239d9d4ac277eeb6ff57bb8689f025fe8a6a17a',
            ]
        );

        // بررسی و ایجاد تنظیمات کاربر
        UserSetting::firstOrCreate(
            ['license_id' => $license->id],
            [
                'enable_price_update' => true,
                'enable_stock_update' => true,
                'enable_name_update' => true,
                'enable_new_product' => true,
                'enable_invoice' => false,
                'enable_cart_sync' => false,
                'rain_sale_price_unit' => 'toman',
                'woocommerce_price_unit' => 'toman',
            ]
        );

        $this->command->info('داده‌های اولیه ووکامرس با موفقیت ایجاد شدند.');
        $this->command->info("کلید لایسنس ایجاد شده: {$licenseKey}");
    }
}
