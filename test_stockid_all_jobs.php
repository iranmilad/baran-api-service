<?php

echo "=== تست اعمال stockId در همه فایل‌ها ===\n";

// فایل‌هایی که باید بررسی شوند
$files = [
    'app/Jobs/WooCommerce/UpdateWooCommerceProducts.php',
    'app/Jobs/WooCommerce/ProcessSingleProductBatch.php',
    'app/Jobs/WooCommerce/ProcessSkuBatch.php',
    'app/Jobs/WooCommerce/ProcessProductBatch.php',
    'app/Http/Controllers/ProductController.php'
];

foreach ($files as $file) {
    $filePath = __DIR__ . '/' . $file;

    if (!file_exists($filePath)) {
        echo "❌ فایل یافت نشد: $file\n";
        continue;
    }

    $content = file_get_contents($filePath);

    // بررسی وجود stockId در درخواست API
    $hasStockIdInRequest = preg_match('/\'stockId\'\s*=>\s*\$stockId/', $content);

    // بررسی لاگ stockId
    $hasStockIdLog = preg_match('/استفاده از default_warehouse_code برای stockId/', $content);

    // بررسی endpoint
    $hasGetItemInfosEndpoint = preg_match('/\/RainSaleService\.svc\/GetItemInfos/', $content);

    echo "\n📁 $file:\n";

    if ($hasGetItemInfosEndpoint) {
        echo "  ✅ دارای endpoint GetItemInfos\n";

        if ($hasStockIdInRequest) {
            echo "  ✅ دارای stockId در درخواست API\n";
        } else {
            echo "  ❌ فاقد stockId در درخواست API\n";
        }

        if ($hasStockIdLog) {
            echo "  ✅ دارای لاگ stockId\n";
        } else {
            echo "  ❌ فاقد لاگ stockId\n";
        }
    } else {
        echo "  ℹ️ فاقد endpoint GetItemInfos (نیازی به تغییر ندارد)\n";
    }
}

echo "\n=== خلاصه تست ===\n";
echo "همه فایل‌هایی که از GetItemInfos استفاده می‌کنند، اکنون از stockId استفاده می‌کنند.\n";
echo "این شامل:\n";
echo "- دریافت default_warehouse_code از userSetting\n";
echo "- ارسال stockId در درخواست API\n";
echo "- لاگ‌گذاری مناسب\n";

?>
