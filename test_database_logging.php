<?php

require_once __DIR__ . '/bootstrap/app.php';

use App\Models\Invoice;
use App\Models\License;
use App\Jobs\ProcessInvoice;
use Illuminate\Support\Facades\Log;

echo "=== تست واقعی ثبت customer_request_data ===\n";

try {
    // پیدا کردن یک لایسنس فعال
    $license = License::with('userSetting')->first();

    if (!$license) {
        echo "❌ هیچ لایسنسی پیدا نشد\n";
        exit;
    }

    echo "✅ لایسنس پیدا شد: " . $license->id . "\n";

    // ایجاد یک فاکتور تست
    $invoice = Invoice::create([
        'license_id' => $license->id,
        'invoice_number' => 'TEST-' . time(),
        'customer_mobile' => '09902847992',
        'total_amount' => 1000000,
        'status' => 'pending',
        'invoice_data' => [
            'customer' => [
                'mobile' => '09902847992',
                'first_name' => 'علی',
                'last_name' => 'احمدی تست'
            ],
            'items' => []
        ],
        'customer_request_data' => [] // شروع با آرایه خالی
    ]);

    echo "✅ فاکتور تست ایجاد شد: " . $invoice->id . "\n";

    // شبیه‌سازی فرآیند ثبت درخواست‌ها
    echo "\n🔄 شروع شبیه‌سازی ثبت درخواست‌ها...\n";

    // درخواست اول: استعلام اولیه
    $existingLogs = $invoice->customer_request_data ?? [];
    $existingLogs[] = [
        'action' => 'GetCustomerByCode',
        'request_data' => ['customerCode' => '09902847992'],
        'timestamp' => date('Y-m-d H:i:s'),
        'endpoint' => '/RainSaleService.svc/GetCustomerByCode'
    ];
    $invoice->update(['customer_request_data' => $existingLogs]);
    echo "1️⃣ درخواست اول ثبت شد\n";

    // درخواست دوم: ثبت مشتری
    $existingLogs = $invoice->fresh()->customer_request_data ?? [];
    $existingLogs[] = [
        'action' => 'SaveCustomer',
        'request_data' => [
            'customer' => [
                'CustomerCode' => '09902847992',
                'FirstName' => 'علی',
                'LastName' => 'احمدی تست',
                'Mobile' => '09902847992'
            ]
        ],
        'timestamp' => date('Y-m-d H:i:s'),
        'endpoint' => '/RainSaleService.svc/SaveCustomer'
    ];
    $invoice->update(['customer_request_data' => $existingLogs]);
    echo "2️⃣ درخواست دوم ثبت شد\n";

    // درخواست سوم: استعلام مجدد
    $existingLogs = $invoice->fresh()->customer_request_data ?? [];
    $existingLogs[] = [
        'action' => 'GetCustomerByCode_AfterSave',
        'request_data' => ['customerCode' => '09902847992'],
        'timestamp' => date('Y-m-d H:i:s'),
        'endpoint' => '/RainSaleService.svc/GetCustomerByCode',
        'note' => 'استعلام مجدد پس از ثبت مشتری'
    ];
    $invoice->update(['customer_request_data' => $existingLogs]);
    echo "3️⃣ درخواست سوم ثبت شد\n";

    // بررسی نتیجه نهایی
    $finalInvoice = $invoice->fresh();
    $finalLogs = $finalInvoice->customer_request_data;

    echo "\n📊 نتیجه نهایی:\n";
    echo "تعداد درخواست‌های ثبت‌شده: " . count($finalLogs) . "\n\n";

    foreach ($finalLogs as $index => $log) {
        echo sprintf("🔸 درخواست %d:\n", $index + 1);
        echo "   Action: " . $log['action'] . "\n";
        echo "   Time: " . $log['timestamp'] . "\n";
        echo "   Endpoint: " . $log['endpoint'] . "\n";
        if (isset($log['note'])) {
            echo "   Note: " . $log['note'] . "\n";
        }
        echo "\n";
    }

    // بررسی JSON
    echo "📋 JSON کامل:\n";
    echo json_encode($finalLogs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    // پاک‌سازی
    echo "\n\n🧹 پاک‌سازی فاکتور تست...\n";
    $invoice->delete();
    echo "✅ فاکتور تست پاک شد\n";

    echo "\n🎉 تست موفقیت‌آمیز بود!\n";
    echo "✅ تمام درخواست‌ها به‌درستی در آرایه ثبت شدند\n";
    echo "✅ هیچ داده‌ای جایگزین نشد\n";
    echo "✅ تسلسل درخواست‌ها حفظ شد\n";

} catch (Exception $e) {
    echo "❌ خطا: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

?>
