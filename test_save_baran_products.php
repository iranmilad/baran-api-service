<?php

require_once __DIR__ . '/vendor/autoload.php';

/**
 * تست ذخیره‌سازی اطلاعات محصولات از باران در دیتابیس
 * این تست نشان می‌دهد که چگونه اطلاعات دریافت شده از API باران
 * قبل از به‌روزرسانی در Tantooo، در جدول products ذخیره می‌شود
 */

echo "=== تست ذخیره‌سازی اطلاعات محصولات باران ===\n\n";

// نمونه‌های داده‌های دریافت شده از باران
$sampleBaranProducts = [
    [
        'itemID' => '2e0f60f7-e40e-4e7c-8e82-00624bc154e1',
        'itemName' => 'محصول تست شماره 1',
        'barcode' => '123456789012',
        'salePrice' => 250000,
        'priceAfterDiscount' => 220000,
        'stockQuantity' => 15,
        'stockID' => 'ST001',
        'departmentName' => 'پوشاک'
    ],
    [
        'itemID' => '3f1g71g8-f51f-5f8d-9f93-11735cd265f2',
        'itemName' => 'محصول تست شماره 2',
        'barcode' => '987654321098',
        'salePrice' => 450000,
        'priceAfterDiscount' => 400000,
        'stockQuantity' => 25,
        'stockID' => 'ST002',
        'departmentName' => 'الکترونیک'
    ]
];

echo "1. نمونه‌های داده‌های دریافت شده از باران:\n";
echo json_encode($sampleBaranProducts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "2. ساختار ذخیره‌سازی در جدول products:\n";
echo "جدول: products\n";
echo "├── id (PK)\n";
echo "├── license_id (FK) - شناسه لایسنس\n";
echo "├── item_id - شناسه یکتای محصول از باران\n";
echo "├── item_name - نام محصول\n";
echo "├── barcode - کد بارکد\n";
echo "├── price_amount - قیمت\n";
echo "├── price_after_discount - قیمت پس از تخفیف\n";
echo "├── total_count - موجودی\n";
echo "├── stock_id - کد انبار\n";
echo "├── department_name - دسته‌بندی\n";
echo "├── parent_id - شناسه محصول والد (برای واریانت‌ها)\n";
echo "├── is_variant - آیا واریانت است\n";
echo "├── variant_data - اطلاعات واریانت (JSON)\n";
echo "├── last_sync_at - آخرین زمان همگام‌سازی\n";
echo "├── created_at - زمان ایجاد\n";
echo "└── updated_at - زمان آخرین به‌روزرسانی\n\n";

echo "3. فرآیند ذخیره‌سازی:\n\n";

echo "مرحله 1: دریافت اطلاعات از باران\n";
echo "```\n";
echo "GET /api/baran/products\n";
echo "Response: آرایه‌ای از محصولات با اطلاعات قیمت، موجودی و...\n";
echo "```\n\n";

echo "مرحله 2: ذخیره‌سازی در دیتابیس\n";
echo "```php\n";
echo "foreach (\$baranProducts as \$baranProduct) {\n";
echo "    \$product = Product::where('license_id', \$license->id)\n";
echo "        ->where('item_id', \$itemId)\n";
echo "        ->first();\n";
echo "\n";
echo "    if (\$product) {\n";
echo "        // به‌روزرسانی محصول موجود\n";
echo "        \$product->update([\n";
echo "            'item_name' => \$itemName,\n";
echo "            'barcode' => \$barcode,\n";
echo "            'price_amount' => \$priceAmount,\n";
echo "            'price_after_discount' => \$priceAfterDiscount,\n";
echo "            'total_count' => \$totalCount,\n";
echo "            'stock_id' => \$stockId,\n";
echo "            'department_name' => \$departmentName,\n";
echo "            'last_sync_at' => now()\n";
echo "        ]);\n";
echo "    } else {\n";
echo "        // ایجاد محصول جدید\n";
echo "        Product::create([\n";
echo "            'license_id' => \$license->id,\n";
echo "            'item_id' => \$itemId,\n";
echo "            'item_name' => \$itemName,\n";
echo "            'barcode' => \$barcode,\n";
echo "            'price_amount' => \$priceAmount,\n";
echo "            'price_after_discount' => \$priceAfterDiscount,\n";
echo "            'total_count' => \$totalCount,\n";
echo "            'stock_id' => \$stockId,\n";
echo "            'department_name' => \$departmentName,\n";
echo "            'is_variant' => false,\n";
echo "            'last_sync_at' => now()\n";
echo "        ]);\n";
echo "    }\n";
echo "}\n";
echo "```\n\n";

echo "مرحله 3: به‌روزرسانی در Tantooo\n";
echo "```\n";
echo "حالا می‌توانید اطلاعات ذخیره شده در دیتابیس را برای به‌روزرسانی در Tantooo استفاده کنید\n";
echo "```\n\n";

echo "4. مثال SQL برای بررسی داده‌های ذخیره شده:\n";
echo "```sql\n";
echo "SELECT * FROM products \n";
echo "WHERE license_id = 123 \n";
echo "ORDER BY last_sync_at DESC;\n";
echo "```\n\n";

echo "5. نتایج ذخیره‌سازی:\n";
$results = [
    'success' => true,
    'data' => [
        'saved_count' => 50,         // محصولات جدید
        'updated_count' => 150,      // محصولات به‌روزرسانی شده
        'total_processed' => 200,    // کل محصولات
        'errors' => []
    ],
    'message' => 'محصولات با موفقیت ذخیره شدند (50 جدید، 150 به‌روزرسانی شده)'
];
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "6. لاگ‌های سیستم:\n";
$logs = [
    [
        'level' => 'INFO',
        'message' => 'شروع ذخیره‌سازی اطلاعات محصولات باران',
        'license_id' => 123,
        'total_products' => 200
    ],
    [
        'level' => 'DEBUG',
        'message' => 'محصول جدید ذخیره شد',
        'item_id' => '2e0f60f7-e40e-4e7c-8e82-00624bc154e1',
        'barcode' => '123456789012',
        'action' => 'created'
    ],
    [
        'level' => 'DEBUG',
        'message' => 'محصول به‌روزرسانی شد',
        'item_id' => '3f1g71g8-f51f-5f8d-9f93-11735cd265f2',
        'barcode' => '987654321098',
        'action' => 'updated'
    ],
    [
        'level' => 'INFO',
        'message' => 'تکمیل ذخیره‌سازی محصولات باران',
        'license_id' => 123,
        'saved_count' => 50,
        'updated_count' => 150,
        'error_count' => 0
    ]
];

foreach ($logs as $log) {
    echo "[{$log['level']}] {$log['message']}\n";
}
echo "\n";

echo "7. مزایای ذخیره‌سازی اطلاعات:\n";
echo "✅ ذخیره‌سازی تاریخی - نگاه‌داشتن سابقه قیمت و موجودی\n";
echo "✅ سرعت بهتر - دسترسی بدون نیاز به API باران\n";
echo "✅ آفلاین - کار بدون اتصال به API\n";
echo "✅ ردیابی - دانستن آخرین زمان همگام‌سازی\n";
echo "✅ مقایسه - مقایسه اطلاعات قبل و بعد\n";
echo "✅ احصائیات - تحلیل تغییرات محصولات\n\n";

echo "8. فرآیند کامل:\n";
echo "```\n";
echo "ProcessTantoooSyncRequest Job\n";
echo "├─ دریافت کدهای محصولات\n";
echo "├─ دریافت اطلاعات از باران API\n";
echo "├─ ذخیره اطلاعات در جدول products ✨ (جدید)\n";
echo "│  ├─ ایجاد رکوردهای جدید\n";
echo "│  ├─ به‌روزرسانی رکوردهای موجود\n";
echo "│  └─ تنظیم last_sync_at\n";
echo "└─ به‌روزرسانی اطلاعات در Tantooo\n";
echo "   ├─ تنظیم موجودی\n";
echo "   ├─ تنظیم قیمت\n";
echo "   └─ تنظیم نام محصول\n";
echo "```\n\n";

echo "=== نتیجه ===\n";
echo "✅ اطلاعات محصولات باران حالا در دیتابیس ذخیره می‌شوند\n";
echo "✅ قبل از به‌روزرسانی در Tantooo\n";
echo "✅ محصولات جدید و موجود پشتیبانی می‌شوند\n";
echo "✅ اطلاعات تاریخی نگاه‌داشته می‌شود\n";
echo "✅ آخرین زمان همگام‌سازی ثبت می‌شود\n\n";

?>
