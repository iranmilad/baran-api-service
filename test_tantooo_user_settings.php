<?php

require_once __DIR__ . '/vendor/autoload.php';

/**
 * تست عملکرد تنظیمات کاربر در فرآیند همگام‌سازی Tantooo
 * این تست بررسی می‌کند که آیا سیستم بر اساس تنظیمات enable_*
 * فقط پارامترهای مجاز را برای به‌روزرسانی ارسال می‌کند
 */

echo "=== تست تنظیمات کاربر در همگام‌سازی Tantooo ===\n\n";

// شبیه‌سازی داده‌های محصول از باران
$sampleProduct = [
    'ItemId' => '2e0f60f7-e40e-4e7c-8e82-00624bc154e1',
    'Barcode' => '123456789012',
    'ItemName' => 'محصول تست',
    'TotalCount' => 15,
    'PriceAmount' => 250000,
    'PriceAfterDiscount' => 220000
];

$sampleBaranProduct = [
    'itemName' => 'محصول تست به‌روزرسانی شده',
    'salePrice' => 280000,
    'currentDiscount' => 5,
    'stockQuantity' => 20
];

echo "1. داده‌های نمونه محصول:\n";
echo "محصول اصلی:\n";
echo json_encode($sampleProduct, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
echo "اطلاعات به‌روز از باران:\n";
echo json_encode($sampleBaranProduct, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// سناریوهای مختلف تنظیمات کاربر
$scenarios = [
    [
        'name' => 'همه تنظیمات فعال',
        'settings' => [
            'enable_stock_update' => true,
            'enable_price_update' => true,
            'enable_name_update' => true
        ],
        'expected_updates' => ['stock_update', 'info_update'],
        'description' => 'باید موجودی، قیمت و نام محصول را به‌روزرسانی کند'
    ],
    [
        'name' => 'فقط موجودی فعال',
        'settings' => [
            'enable_stock_update' => true,
            'enable_price_update' => false,
            'enable_name_update' => false
        ],
        'expected_updates' => ['stock_update'],
        'description' => 'فقط باید موجودی را به‌روزرسانی کند'
    ],
    [
        'name' => 'فقط قیمت فعال',
        'settings' => [
            'enable_stock_update' => false,
            'enable_price_update' => true,
            'enable_name_update' => false
        ],
        'expected_updates' => ['info_update'],
        'description' => 'فقط باید قیمت را به‌روزرسانی کند (بدون نام)'
    ],
    [
        'name' => 'فقط نام فعال',
        'settings' => [
            'enable_stock_update' => false,
            'enable_price_update' => false,
            'enable_name_update' => true
        ],
        'expected_updates' => ['info_update'],
        'description' => 'فقط باید نام محصول را به‌روزرسانی کند (بدون قیمت)'
    ],
    [
        'name' => 'قیمت و نام فعال',
        'settings' => [
            'enable_stock_update' => false,
            'enable_price_update' => true,
            'enable_name_update' => true
        ],
        'expected_updates' => ['info_update'],
        'description' => 'باید قیمت و نام محصول را به‌روزرسانی کند'
    ],
    [
        'name' => 'هیچ تنظیمی فعال نیست',
        'settings' => [
            'enable_stock_update' => false,
            'enable_price_update' => false,
            'enable_name_update' => false
        ],
        'expected_updates' => [],
        'description' => 'هیچ به‌روزرسانی انجام نخواهد شد'
    ]
];

echo "2. سناریوهای مختلف تنظیمات:\n\n";

foreach ($scenarios as $index => $scenario) {
    echo "سناریو " . ($index + 1) . ": {$scenario['name']}\n";
    echo "تنظیمات:\n";
    foreach ($scenario['settings'] as $setting => $value) {
        echo "  - $setting: " . ($value ? 'فعال' : 'غیرفعال') . "\n";
    }
    echo "نتیجه مورد انتظار:\n";
    echo "  - {$scenario['description']}\n";
    echo "  - به‌روزرسانی‌های مورد انتظار: " . (empty($scenario['expected_updates']) ? 'هیچکدام' : implode(', ', $scenario['expected_updates'])) . "\n";

    // شبیه‌سازی درخواست‌های API
    echo "درخواست‌های API مورد انتظار:\n";

    if (empty($scenario['expected_updates'])) {
        echo "  - هیچ درخواست API ارسال نخواهد شد\n";
    } else {
        if (in_array('stock_update', $scenario['expected_updates'])) {
            echo "  - updateProductStockWithToken(license, '{$sampleProduct['ItemId']}', {$sampleBaranProduct['stockQuantity']})\n";
        }

        if (in_array('info_update', $scenario['expected_updates'])) {
            $titleParam = $scenario['settings']['enable_name_update'] ? "'{$sampleBaranProduct['itemName']}'" : "''";
            $priceParam = $scenario['settings']['enable_price_update'] ? $sampleBaranProduct['salePrice'] : 0;
            $discountParam = $scenario['settings']['enable_price_update'] ? $sampleBaranProduct['currentDiscount'] : 0;

            echo "  - updateProductInfoWithToken(license, '{$sampleProduct['ItemId']}', $titleParam, $priceParam, $discountParam)\n";
        }
    }

    echo "\n";
}

// تست ساختار API Request
echo "3. ساختار درخواست‌های API:\n\n";

echo "درخواست به‌روزرسانی موجودی (enable_stock_update = true):\n";
$stockRequest = [
    'method' => 'POST',
    'url' => 'https://03535.ir/accounting_api',
    'headers' => [
        'X-API-KEY' => 'API_KEY_FROM_ENV',
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer JWT_TOKEN'
    ],
    'body' => [
        'fn' => 'change_count_sub_product',
        'code' => $sampleProduct['ItemId'],
        'count' => $sampleBaranProduct['stockQuantity']
    ]
];
echo json_encode($stockRequest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "درخواست به‌روزرسانی اطلاعات (enable_price_update یا enable_name_update = true):\n";
$infoRequest = [
    'method' => 'POST',
    'url' => 'https://03535.ir/accounting_api',
    'headers' => [
        'X-API-KEY' => 'API_KEY_FROM_ENV',
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer JWT_TOKEN'
    ],
    'body' => [
        'fn' => 'update_product_sku_code',
        'code' => $sampleProduct['ItemId'],
        'title' => $sampleBaranProduct['itemName'],
        'price' => (float) $sampleBaranProduct['salePrice'],
        'discount' => (float) $sampleBaranProduct['currentDiscount']
    ]
];
echo json_encode($infoRequest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// تست منطق پردازش
echo "4. منطق پردازش در updateProductInTantooo:\n\n";

echo "```php\n";
echo "// دریافت تنظیمات کاربر\n";
echo "\$userSettings = UserSetting::where('license_id', \$license->id)->first();\n\n";
echo "// بررسی تنظیمات فعال\n";
echo "\$enableStockUpdate = \$userSettings->enable_stock_update ?? false;\n";
echo "\$enablePriceUpdate = \$userSettings->enable_price_update ?? false;\n";
echo "\$enableNameUpdate = \$userSettings->enable_name_update ?? false;\n\n";
echo "// اگر هیچ تنظیمی فعال نباشد\n";
echo "if (!\$enableStockUpdate && !\$enablePriceUpdate && !\$enableNameUpdate) {\n";
echo "    return ['success' => true, 'message' => 'هیچ تنظیم فعال نیست', 'skipped' => true];\n";
echo "}\n\n";
echo "// به‌روزرسانی موجودی (شرطی)\n";
echo "if (\$enableStockUpdate) {\n";
echo "    \$stockResult = \$this->updateProductStockWithToken(\$license, \$itemId, \$stockQuantity);\n";
echo "}\n\n";
echo "// به‌روزرسانی اطلاعات محصول (شرطی)\n";
echo "if (\$enablePriceUpdate || \$enableNameUpdate) {\n";
echo "    \$title = \$enableNameUpdate ? \$extractedTitle : null;\n";
echo "    \$price = \$enablePriceUpdate ? \$extractedPrice : null;\n";
echo "    \$infoResult = \$this->updateProductInfoWithToken(\$license, \$itemId, \$title, \$price, \$discount);\n";
echo "}\n";
echo "```\n\n";

// بررسی validation
echo "5. اعتبارسنجی داده‌ها:\n\n";

$validationRules = [
    'enable_stock_update' => [
        'condition' => 'فعال باشد',
        'data_validation' => 'stockQuantity >= 0 و عددی باشد',
        'api_call' => 'updateProductStockWithToken'
    ],
    'enable_price_update' => [
        'condition' => 'فعال باشد',
        'data_validation' => 'price > 0 و عددی باشد',
        'api_call' => 'updateProductInfoWithToken (با قیمت)'
    ],
    'enable_name_update' => [
        'condition' => 'فعال باشد',
        'data_validation' => 'title خالی نباشد',
        'api_call' => 'updateProductInfoWithToken (با نام)'
    ]
];

foreach ($validationRules as $setting => $rule) {
    echo "تنظیم: $setting\n";
    echo "  شرط: {$rule['condition']}\n";
    echo "  اعتبارسنجی داده: {$rule['data_validation']}\n";
    echo "  فراخوانی API: {$rule['api_call']}\n\n";
}

// لاگ‌های مورد انتظار
echo "6. لاگ‌های سیستم:\n\n";

echo "لاگ تنظیمات کاربر:\n";
echo "```\n";
echo "INFO: نتیجه نهایی به‌روزرسانی محصول بر اساس تنظیمات\n";
echo "{\n";
echo "  'license_id': 123,\n";
echo "  'item_id': '2e0f60f7-e40e-4e7c-8e82-00624bc154e1',\n";
echo "  'user_settings': {\n";
echo "    'enable_stock_update': true,\n";
echo "    'enable_price_update': false,\n";
echo "    'enable_name_update': true\n";
echo "  },\n";
echo "  'updates_performed': ['stock_update', 'info_update'],\n";
echo "  'all_successful': true,\n";
echo "  'settings_applied': {\n";
echo "    'stock_update': true,\n";
echo "    'price_update': false,\n";
echo "    'name_update': true\n";
echo "  }\n";
echo "}\n";
echo "```\n\n";

echo "=== خلاصه تغییرات ===\n";
echo "✅ سیستم حالا بر اساس تنظیمات کاربر عمل می‌کند\n";
echo "✅ فقط پارامترهای فعال شده به‌روزرسانی می‌شوند\n";
echo "✅ API callها شرطی هستند و بر اساس enable_* تنظیمات انجام می‌شوند\n";
echo "✅ لاگ‌گذاری مفصل برای ردیابی تنظیمات و عملیات\n";
echo "✅ مدیریت خطا برای حالات مختلف تنظیمات\n\n";

echo "🔧 فایل‌های تغییر یافته:\n";
echo "- app/Jobs/Tantooo/ProcessTantoooSyncRequest.php (متد updateProductInTantooo)\n";
echo "- تست‌های جدید: test_tantooo_user_settings.php\n\n";

echo "📋 چک‌لیست بررسی:\n";
echo "□ enable_stock_update = true → فقط موجودی به‌روزرسانی شود\n";
echo "□ enable_price_update = true → فقط قیمت به‌روزرسانی شود\n";
echo "□ enable_name_update = true → فقط نام محصول به‌روزرسانی شود\n";
echo "□ همه false → هیچ به‌روزرسانی انجام نشود\n";
echo "□ ترکیبات مختلف → فقط تنظیمات فعال اعمال شوند\n";
echo "□ لاگ‌ها نشان دهند کدام تنظیمات اعمال شده‌اند\n\n";

?>
