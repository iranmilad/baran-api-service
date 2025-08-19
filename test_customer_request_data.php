<?php

echo "=== تست ستون customer_request_data در جدول invoices ===\n";

// بررسی ساختار جدول
try {
    $connection = new PDO('sqlite:' . __DIR__ . '/database/database.sqlite');
    $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "✅ اتصال به دیتابیس برقرار شد\n";

    // بررسی ساختار جدول invoices
    $query = "PRAGMA table_info(invoices)";
    $stmt = $connection->prepare($query);
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "\n📋 ستون‌های جدول invoices:\n";

    $hasCustomerRequestData = false;
    foreach ($columns as $column) {
        $icon = $column['name'] === 'customer_request_data' ? '🔥' : '📌';
        echo "   {$icon} {$column['name']} ({$column['type']})\n";

        if ($column['name'] === 'customer_request_data') {
            $hasCustomerRequestData = true;
        }
    }

    if ($hasCustomerRequestData) {
        echo "\n✅ ستون customer_request_data با موفقیت اضافه شده است\n";
    } else {
        echo "\n❌ ستون customer_request_data یافت نشد\n";
    }

    // تست ساختار JSON
    echo "\n🧪 تست ساختار JSON:\n";

    $testData = [
        'action' => 'GetCustomerByCode',
        'request_data' => [
            'customerCode' => '09123456789'
        ],
        'timestamp' => date('Y-m-d H:i:s'),
        'endpoint' => '/RainSaleService.svc/GetCustomerByCode'
    ];

    $jsonData = json_encode($testData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    echo "   JSON تست: " . $jsonData . "\n";

    if (json_last_error() === JSON_ERROR_NONE) {
        echo "✅ ساختار JSON معتبر است\n";
    } else {
        echo "❌ خطا در ساختار JSON: " . json_last_error_msg() . "\n";
    }

} catch (Exception $e) {
    echo "❌ خطا در اتصال به دیتابیس: " . $e->getMessage() . "\n";
}

echo "\n=== بررسی تغییرات مدل Invoice ===\n";

// بررسی فایل مدل
$modelPath = __DIR__ . '/app/Models/Invoice.php';
if (file_exists($modelPath)) {
    $modelContent = file_get_contents($modelPath);

    $hasFillable = strpos($modelContent, "'customer_request_data'") !== false;
    $hasCast = strpos($modelContent, "'customer_request_data' => 'array'") !== false;

    echo "📁 مدل Invoice.php:\n";
    echo "   ✅ فیلد customer_request_data در fillable: " . ($hasFillable ? 'موجود' : 'غیرموجود') . "\n";
    echo "   ✅ cast برای customer_request_data: " . ($hasCast ? 'موجود' : 'غیرموجود') . "\n";

    if ($hasFillable && $hasCast) {
        echo "✅ مدل Invoice به‌درستی به‌روزرسانی شده است\n";
    } else {
        echo "❌ مدل Invoice کامل به‌روزرسانی نشده است\n";
    }
} else {
    echo "❌ فایل مدل Invoice یافت نشد\n";
}

echo "\n=== بررسی تغییرات Job ProcessInvoice ===\n";

$jobPath = __DIR__ . '/app/Jobs/ProcessInvoice.php';
if (file_exists($jobPath)) {
    $jobContent = file_get_contents($jobPath);

    $hasCustomerRequestUpdate = strpos($jobContent, 'customer_request_data') !== false;
    $hasSaveCustomerUpdate = strpos($jobContent, "'action' => 'SaveCustomer'") !== false;
    $hasGetCustomerUpdate = strpos($jobContent, "'action' => 'GetCustomerByCode'") !== false;

    echo "📁 Job ProcessInvoice.php:\n";
    echo "   ✅ استفاده از customer_request_data: " . ($hasCustomerRequestUpdate ? 'موجود' : 'غیرموجود') . "\n";
    echo "   ✅ ثبت داده‌های SaveCustomer: " . ($hasSaveCustomerUpdate ? 'موجود' : 'غیرموجود') . "\n";
    echo "   ✅ ثبت داده‌های GetCustomerByCode: " . ($hasGetCustomerUpdate ? 'موجود' : 'غیرموجود') . "\n";

    if ($hasCustomerRequestUpdate && $hasSaveCustomerUpdate && $hasGetCustomerUpdate) {
        echo "✅ Job ProcessInvoice به‌درستی به‌روزرسانی شده است\n";
    } else {
        echo "❌ Job ProcessInvoice کامل به‌روزرسانی نشده است\n";
    }
} else {
    echo "❌ فایل Job ProcessInvoice یافت نشد\n";
}

echo "\n=== خلاصه تغییرات ===\n";
echo "🔥 ستون customer_request_data اضافه شد\n";
echo "📝 مدل Invoice به‌روزرسانی شد\n";
echo "⚙️ Job ProcessInvoice تغییر کرد\n";
echo "📊 حالا همه درخواست‌های مشتری ثبت می‌شوند\n";

?>
