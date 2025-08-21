<?php

// تست فیلدهای جدید API انبار در User model
require_once 'vendor/autoload.php';

// بررسی وجود فیلدهای جدید در User model
$userModel = new \App\Models\User();

echo "=== بررسی فیلدهای User Model ===\n";

// بررسی fillable fields
$fillable = $userModel->getFillable();
echo "Fillable fields:\n";
foreach ($fillable as $field) {
    echo "- " . $field . "\n";
}

echo "\n=== بررسی فیلدهای Hidden ===\n";
$hidden = $userModel->getHidden();
foreach ($hidden as $field) {
    echo "- " . $field . "\n";
}

echo "\n=== تست ایجاد کاربر جدید ===\n";

try {
    // تست ایجاد کاربر با فیلدهای جدید
    $userData = [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
        'warehouse_api_url' => 'https://warehouse.example.com/api',
        'warehouse_api_username' => 'warehouse_user',
        'warehouse_api_password' => 'warehouse_pass'
    ];

    echo "داده‌های تست:\n";
    foreach ($userData as $key => $value) {
        if (in_array($key, ['password', 'warehouse_api_password'])) {
            echo "- $key: [مخفی]\n";
        } else {
            echo "- $key: $value\n";
        }
    }

    // ایجاد instance جدید (بدون ذخیره در دیتابیس)
    $user = new \App\Models\User($userData);

    echo "\nUser instance ایجاد شد با موفقیت!\n";

    // بررسی دسترسی به فیلدهای جدید
    echo "\nدسترسی به فیلدهای جدید:\n";
    echo "- warehouse_api_url: " . ($user->warehouse_api_url ?? 'null') . "\n";
    echo "- warehouse_api_username: " . ($user->warehouse_api_username ?? 'null') . "\n";
    echo "- warehouse_api_password: [باید مخفی باشد]\n";

    // تبدیل به آرایه و بررسی hidden fields
    $userArray = $user->toArray();
    if (isset($userArray['warehouse_api_password'])) {
        echo "\n❌ خطا: warehouse_api_password در آرایه نمایش داده می‌شود (باید مخفی باشد)\n";
    } else {
        echo "\n✅ موفق: warehouse_api_password در آرایه مخفی است\n";
    }

} catch (\Exception $e) {
    echo "خطا: " . $e->getMessage() . "\n";
}

echo "\nتست تمام شد.\n";
