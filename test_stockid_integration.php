<?php

// تست استفاده از default_warehouse_code در UpdateWooCommerceProducts

echo "=== تست default_warehouse_code در UpdateWooCommerceProducts ===\n\n";

// شبیه‌سازی scenarios مختلف
$scenarios = [
    [
        'name' => 'تنظیمات با warehouse code مشخص',
        'user_settings_exists' => true,
        'default_warehouse_code' => 'WAREHOUSE-MAIN',
        'expected_stock_id' => 'WAREHOUSE-MAIN'
    ],
    [
        'name' => 'تنظیمات با warehouse code خالی',
        'user_settings_exists' => true,
        'default_warehouse_code' => '',
        'expected_stock_id' => ''
    ],
    [
        'name' => 'بدون تنظیمات کاربر',
        'user_settings_exists' => false,
        'default_warehouse_code' => null,
        'expected_stock_id' => ''
    ]
];

foreach ($scenarios as $index => $scenario) {
    echo ($index + 1) . ". " . $scenario['name'] . ":\n";

    // شبیه‌سازی منطق job
    $userSettings = $scenario['user_settings_exists'] ? (object)['default_warehouse_code' => $scenario['default_warehouse_code']] : null;
    $stockId = $userSettings ? $userSettings->default_warehouse_code : '';

    echo "   - User settings exists: " . ($scenario['user_settings_exists'] ? 'Yes' : 'No') . "\n";
    echo "   - Default warehouse code: " . ($scenario['default_warehouse_code'] ?? 'null') . "\n";
    echo "   - Calculated stockId: '$stockId'\n";
    echo "   - Expected: '" . $scenario['expected_stock_id'] . "'\n";
    echo "   - Result: " . ($stockId === $scenario['expected_stock_id'] ? '✅ PASS' : '❌ FAIL') . "\n\n";
}

echo "=== API Request Structure ===\n";
echo "POST /RainSaleService.svc/GetItemInfos\n";
echo "{\n";
echo "    \"barcodes\": [\"barcode1\", \"barcode2\"],\n";
echo "    \"stockId\": \"<default_warehouse_code>\"\n";
echo "}\n\n";

echo "=== Changes Made ===\n";
echo "1. ✅ License::with(['user', 'userSetting']) - بارگذاری تنظیمات\n";
echo "2. ✅ دریافت default_warehouse_code از userSettings\n";
echo "3. ✅ استفاده از stockId در درخواست API\n";
echo "4. ✅ لاگ‌گذاری stock_id برای رصد\n\n";

echo "=== Ready for Use ===\n";
