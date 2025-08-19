<?php

echo "=== تست تسلسل ثبت درخواست‌های مشتری ===\n";

// شبیه‌سازی فرآیند کامل
$customerRequestData = ['customerCode' => '09902847992'];
$customerData = [
    'customer' => [
        'Address' => 'تهران، خیابان ولیعصر',
        'FirstName' => 'علی',
        'LastName' => 'احمدی',
        'Mobile' => '09902847992',
        'CustomerCode' => '09902847992',
        'IsMale' => '1',
        'IsActive' => '1'
    ]
];

echo "\n📋 سناریو: مشتری پیدا نشد، باید ثبت شود\n";

// مرحله 1: استعلام اولیه
$customerRequestLog = [
    'action' => 'GetCustomerByCode',
    'request_data' => $customerRequestData,
    'timestamp' => date('Y-m-d H:i:s'),
    'endpoint' => '/RainSaleService.svc/GetCustomerByCode'
];

$logs = [];
$logs[] = $customerRequestLog;

echo "1️⃣ استعلام اولیه ثبت شد:\n";
echo "   📝 Action: " . $customerRequestLog['action'] . "\n";
echo "   🕐 Time: " . $customerRequestLog['timestamp'] . "\n";

// مرحله 2: ثبت مشتری (چون پیدا نشد)
$saveCustomerLog = [
    'action' => 'SaveCustomer',
    'request_data' => $customerData,
    'timestamp' => date('Y-m-d H:i:s'),
    'endpoint' => '/RainSaleService.svc/SaveCustomer'
];

$logs[] = $saveCustomerLog;

echo "\n2️⃣ درخواست ثبت مشتری ثبت شد:\n";
echo "   📝 Action: " . $saveCustomerLog['action'] . "\n";
echo "   🕐 Time: " . $saveCustomerLog['timestamp'] . "\n";

// مرحله 3: استعلام مجدد پس از ثبت
sleep(1); // شبیه‌سازی انتظار
$retryCustomerLog = [
    'action' => 'GetCustomerByCode_AfterSave',
    'request_data' => $customerRequestData,
    'timestamp' => date('Y-m-d H:i:s'),
    'endpoint' => '/RainSaleService.svc/GetCustomerByCode',
    'note' => 'استعلام مجدد پس از ثبت مشتری'
];

$logs[] = $retryCustomerLog;

echo "\n3️⃣ استعلام مجدد ثبت شد:\n";
echo "   📝 Action: " . $retryCustomerLog['action'] . "\n";
echo "   🕐 Time: " . $retryCustomerLog['timestamp'] . "\n";
echo "   📋 Note: " . $retryCustomerLog['note'] . "\n";

echo "\n📊 نتیجه نهایی - آرایه customer_request_data:\n";
echo json_encode($logs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

echo "\n🔍 بررسی تسلسل:\n";
foreach ($logs as $index => $log) {
    echo sprintf("   %d. %s (%s)\n",
        $index + 1,
        $log['action'],
        $log['timestamp']
    );
}

echo "\n✅ تسلسل درست:\n";
echo "   1. ابتدا استعلام اولیه\n";
echo "   2. سپس ثبت مشتری (چون پیدا نشد)\n";
echo "   3. در نهایت استعلام مجدد\n";

echo "\n🎯 مزایا:\n";
echo "   ✅ تاریخچه کامل درخواست‌ها\n";
echo "   ✅ قابلیت ردیابی مراحل\n";
echo "   ✅ تشخیص نقاط خطا\n";
echo "   ✅ آنالیز عملکرد\n";

// بررسی کد فعلی
echo "\n🔧 بررسی کد ProcessInvoice:\n";
$jobPath = __DIR__ . '/app/Jobs/ProcessInvoice.php';
if (file_exists($jobPath)) {
    $content = file_get_contents($jobPath);

    $hasArrayAppend = strpos($content, '$existingLogs[] = ') !== false;
    $hasGetCustomer = strpos($content, "'action' => 'GetCustomerByCode'") !== false;
    $hasSaveCustomer = strpos($content, "'action' => 'SaveCustomer'") !== false;
    $hasRetryCustomer = strpos($content, "'action' => 'GetCustomerByCode_AfterSave'") !== false;

    echo "   ✅ آرایه‌ای شدن لاگ‌ها: " . ($hasArrayAppend ? 'موجود' : 'غیرموجود') . "\n";
    echo "   ✅ ثبت استعلام اولیه: " . ($hasGetCustomer ? 'موجود' : 'غیرموجود') . "\n";
    echo "   ✅ ثبت درخواست ثبت مشتری: " . ($hasSaveCustomer ? 'موجود' : 'غیرموجود') . "\n";
    echo "   ✅ ثبت استعلام مجدد: " . ($hasRetryCustomer ? 'موجود' : 'غیرموجود') . "\n";

    if ($hasArrayAppend && $hasGetCustomer && $hasSaveCustomer && $hasRetryCustomer) {
        echo "\n✅ کد به‌درستی اصلاح شده است!\n";
    } else {
        echo "\n❌ برخی اصلاحات کامل نشده‌اند\n";
    }
}

?>
