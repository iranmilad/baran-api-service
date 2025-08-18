<?php

// تست فیلدهای جدید shipping در UserSetting
use App\Models\UserSetting;

// تست متد fromPluginArray با فیلدهای جدید
$testData = [
    'license_id' => 1,
    'enable_price_update' => true,
    'enable_stock_update' => true,
    'enable_name_update' => true,
    'enable_new_product' => true,
    'enable_invoice' => false,
    'enable_cart_sync' => false,
    'rain_sale_price_unit' => 'toman',
    'woocommerce_price_unit' => 'toman',
    'shipping_cost_method' => 'expense',
    'shipping_product_unique_id' => 'SHIPPING-001',
    'invoice_settings' => [
        'cash_on_delivery' => 'cash',
        'credit_payment' => 'cash',
        'invoice_pending_type' => 'off',
        'invoice_on_hold_type' => 'off',
        'invoice_processing_type' => 'off',
        'invoice_complete_type' => 'off',
        'invoice_cancelled_type' => 'off',
        'invoice_refunded_type' => 'off',
        'invoice_failed_type' => 'off'
    ]
];

// تبدیل به فرمت دیتابیس
$dbData = UserSetting::fromPluginArray($testData);
echo "DB Data:\n";
print_r($dbData);

// شبیه‌سازی ذخیره در دیتابیس
$setting = new UserSetting($dbData);
echo "\nModel toArray:\n";
print_r($setting->toArray());

// تبدیل به فرمت پلاگین
echo "\nPlugin Array:\n";
print_r($setting->toPluginArray());
