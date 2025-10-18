<?php

require_once __DIR__ . '/vendor/autoload.php';

/**
 * تست منطق ذخیره‌سازی محصولات بر اساس item_id
 *
 * منطق جدید:
 * - اگر item_id مشابه باشد → به‌روزرسانی رکورد موجود
 * - در غیر این صورت → درج رکورد جدید مستقل
 */

echo "=== تست منطق ذخیره‌سازی بر اساس item_id ===\n\n";

echo "📋 منطق جدید:\n";
echo "1. جستجو بر اساس item_id (نه license_id + item_id)\n";
echo "2. اگر موجود: به‌روزرسانی\n";
echo "3. اگر جدید: ایجاد\n\n";

// سناریوهای مختلف
$scenarios = [
    [
        'name' => 'سناریو 1: محصول جدید',
        'license_id' => 1,
        'item_id' => 'ITEM-001',
        'database_before' => [
            ['license_id' => 2, 'item_id' => 'ITEM-001'],  // محصول متفاوت از license دیگر
        ],
        'expected_action' => 'ایجاد',
        'explanation' => 'هر لایسنس می‌تواند محصول جدید بسازد (item_id را دریافت می‌کند)'
    ],
    [
        'name' => 'سناریو 2: محصول موجود (همان license)',
        'license_id' => 1,
        'item_id' => 'ITEM-001',
        'database_before' => [
            ['license_id' => 1, 'item_id' => 'ITEM-001', 'price' => 100000],
        ],
        'expected_action' => 'به‌روزرسانی',
        'explanation' => 'به‌روزرسانی محصول موجود'
    ],
    [
        'name' => 'سناریو 3: محصول موجود (license متفاوت)',
        'license_id' => 2,
        'item_id' => 'ITEM-001',
        'database_before' => [
            ['license_id' => 1, 'item_id' => 'ITEM-001', 'price' => 100000],
        ],
        'expected_action' => 'به‌روزرسانی',
        'explanation' => 'منطق: اگر item_id یکسان = به‌روزرسانی (حتی license متفاوت)',
        'warning' => 'توجه: license_id هم به‌روزرسانی می‌شود'
    ],
    [
        'name' => 'سناریو 4: محصولات متفاوت',
        'license_id' => 1,
        'item_id' => 'ITEM-002',
        'database_before' => [
            ['license_id' => 1, 'item_id' => 'ITEM-001'],
        ],
        'expected_action' => 'ایجاد',
        'explanation' => 'item_id متفاوت = محصول جدید'
    ]
];

foreach ($scenarios as $index => $scenario) {
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo ($index + 1) . ". {$scenario['name']}\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    echo "📊 وضعیت:\n";
    echo "├─ License ID: {$scenario['license_id']}\n";
    echo "├─ Item ID: {$scenario['item_id']}\n";
    echo "└─ محصولات موجود قبل: " . count($scenario['database_before']) . "\n\n";

    echo "💾 پرس‌وجو در دیتابیس:\n";
    echo "```sql\n";
    echo "SELECT * FROM products WHERE item_id = '{$scenario['item_id']}';\n";
    echo "```\n\n";

    echo "✅ نتیجه مورد انتظار:\n";
    echo "├─ عملیات: {$scenario['expected_action']}\n";
    echo "└─ {$scenario['explanation']}\n";

    if (isset($scenario['warning'])) {
        echo "\n⚠️  توجه: {$scenario['warning']}\n";
    }

    echo "\n📝 کد PHP:\n";
    echo "```php\n";
    echo "// منطق جدید:\n";
    echo "\$product = Product::where('item_id', \$itemId)->first();\n\n";

    if ($scenario['expected_action'] === 'به‌روزرسانی') {
        echo "if (\$product) {\n";
        echo "    \$product->update([\n";
        echo "        'license_id' => \$license->id,  // به‌روزرسانی license_id\n";
        echo "        'item_name' => \$itemName,\n";
        echo "        'barcode' => \$barcode,\n";
        echo "        'price_amount' => \$priceAmount,\n";
        echo "        'last_sync_at' => now()\n";
        echo "    ]);\n";
        echo "}\n";
    } else {
        echo "if (!\$product) {\n";
        echo "    Product::create([\n";
        echo "        'license_id' => \$license->id,\n";
        echo "        'item_id' => \$itemId,\n";
        echo "        'item_name' => \$itemName,\n";
        echo "        'barcode' => \$barcode,\n";
        echo "        'price_amount' => \$priceAmount,\n";
        echo "        'last_sync_at' => now()\n";
        echo "    ]);\n";
        echo "}\n";
    }
    echo "```\n\n";
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🔑 تفاوت‌های اصلی:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$comparison = [
    [
        'جنبه' => 'معیار جستجو',
        'قبل' => 'license_id + item_id',
        'بعد' => 'فقط item_id'
    ],
    [
        'جنبه' => 'محصول متفاوت license',
        'قبل' => 'محصول جدید ایجاد می‌شود',
        'بعد' => 'محصول موجود به‌روزرسانی می‌شود'
    ],
    [
        'جنبه' => 'تأثیر license_id',
        'قبل' => 'تأثیر نمی‌گذارد',
        'بعد' => 'license_id برای رکورد موجود هم به‌روزرسانی می‌شود'
    ],
    [
        'جنبه' => 'مورد استفاده',
        'قبل' => 'محصولات مستقل برای هر license',
        'بعد' => 'محصولات مشترک میان licenses'
    ]
];

echo "┌─────────────────────┬──────────────────────┬──────────────────────┐\n";
echo "│ جنبه                │ منطق قبلی           │ منطق جدید           │\n";
echo "├─────────────────────┼──────────────────────┼──────────────────────┤\n";

foreach ($comparison as $row) {
    printf("│ %-19s │ %-20s │ %-20s │\n",
        mb_substr($row['جنبه'], 0, 19),
        mb_substr($row['قبل'], 0, 20),
        mb_substr($row['بعد'], 0, 20)
    );
}

echo "└─────────────────────┴──────────────────────┴──────────────────────┘\n\n";

echo "📈 مثال عملی:\n";
echo "```sql\n";
echo "-- قبل:\n";
echo "-- License 1: Product(item_id=ITEM-001, price=100000)\n";
echo "-- License 2: Product(item_id=ITEM-001, price=150000)  -- محصول جدید\n\n";
echo "-- بعد:\n";
echo "-- License 2: Product(item_id=ITEM-001, price=150000)  -- به‌روزرسانی\n";
echo "-- license_id تغییر می‌کند: 1 → 2\n";
echo "```\n\n";

echo "⚠️  نکات مهم:\n";
echo "1. ✅ اگر item_id مشابه = همان رکورد به‌روزرسانی می‌شود\n";
echo "2. ✅ حتی اگر license متفاوت باشد\n";
echo "3. ✅ license_id هم به‌روزرسانی می‌شود\n";
echo "4. ✅ آخرین license_id برنده خواهد بود\n";
echo "5. ✅ در صورتی که item_id جدید = رکورد جدید ایجاد می‌شود\n\n";

echo "🧪 تست SQL:\n";
echo "```sql\n";
echo "-- بررسی item_id های تکراری:\n";
echo "SELECT item_id, COUNT(*) as count FROM products GROUP BY item_id HAVING COUNT(*) > 1;\n\n";
echo "-- دیدن license_id برای یک item:\n";
echo "SELECT license_id, item_id, item_name, price_amount FROM products WHERE item_id = 'ITEM-001';\n";
echo "```\n\n";

echo "✅ تکمیل شد!\n";
echo "اطلاعات محصولات حالا بر اساس item_id (مستقل) ذخیره و به‌روزرسانی می‌شوند.\n";

?>
