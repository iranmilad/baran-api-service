# Laravel Tinker Test for Customer Request Logging

# Run this in artisan tinker:
# php artisan tinker

# Copy and paste these commands one by one:

# Test 1: Create a test invoice
$license = App\Models\License::with('userSetting')->first();
$invoice = App\Models\Invoice::create([
    'license_id' => $license->id,
    'invoice_number' => 'TEST-LOG-' . time(),
    'customer_mobile' => '09902847992',
    'total_amount' => 1000000,
    'status' => 'pending',
    'invoice_data' => ['customer' => ['mobile' => '09902847992']],
    'customer_request_data' => []
]);

echo "Invoice created: " . $invoice->id;

# Test 2: Add first request (GetCustomerByCode)
$existingLogs = $invoice->customer_request_data ?? [];
$existingLogs[] = [
    'action' => 'GetCustomerByCode',
    'request_data' => ['customerCode' => '09902847992'],
    'timestamp' => date('Y-m-d H:i:s'),
    'endpoint' => '/RainSaleService.svc/GetCustomerByCode'
];
$invoice->update(['customer_request_data' => $existingLogs]);
echo "First request logged. Count: " . count($invoice->fresh()->customer_request_data);

# Test 3: Add second request (SaveCustomer)
$existingLogs = $invoice->fresh()->customer_request_data ?? [];
$existingLogs[] = [
    'action' => 'SaveCustomer',
    'request_data' => ['customer' => ['CustomerCode' => '09902847992']],
    'timestamp' => date('Y-m-d H:i:s'),
    'endpoint' => '/RainSaleService.svc/SaveCustomer'
];
$invoice->update(['customer_request_data' => $existingLogs]);
echo "Second request logged. Count: " . count($invoice->fresh()->customer_request_data);

# Test 4: Add third request (GetCustomerByCode_AfterSave)
$existingLogs = $invoice->fresh()->customer_request_data ?? [];
$existingLogs[] = [
    'action' => 'GetCustomerByCode_AfterSave',
    'request_data' => ['customerCode' => '09902847992'],
    'timestamp' => date('Y-m-d H:i:s'),
    'endpoint' => '/RainSaleService.svc/GetCustomerByCode'
];
$invoice->update(['customer_request_data' => $existingLogs]);
echo "Third request logged. Count: " . count($invoice->fresh()->customer_request_data);

# Test 5: Check final result
$finalLogs = $invoice->fresh()->customer_request_data;
echo "Final count: " . count($finalLogs);
foreach ($finalLogs as $index => $log) {
    echo ($index + 1) . ". " . $log['action'] . " at " . $log['timestamp'];
}

# Test 6: Show JSON
echo json_encode($finalLogs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

# Test 7: Cleanup
$invoice->delete();
echo "Test invoice deleted";
