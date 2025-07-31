<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\License;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300;
    public $maxExceptions = 3;
    public $backoff = [30, 60, 120];

    protected $invoice;
    protected $license;
    protected $user;

    public function __construct(Invoice $invoice,License $license)
    {
        $this->invoice = $invoice;
        $this->license = $license;

        if (!$this->license) {
            throw new \Exception('لایسنس یافت نشد');
        }

        // یافتن کاربر مربوط به لایسنس
        $this->user = $this->license->user;
        if (!$this->user) {
            throw new \Exception('کاربر مربوط به لایسنس یافت نشد');
        }

        $this->onQueue('invoices');
    }

    public function handle()
    {
        try {
            // بررسی وجود آدرس API در اطلاعات کاربر
            if (empty($this->user->api_webservice)) {
                throw new \Exception('آدرس API در تنظیمات کاربر تنظیم نشده است');
            }

            // بررسی وجود سایر اطلاعات API کاربر
            if (empty($this->user->api_username) || empty($this->user->api_password) || empty($this->user->api_storeId) || empty($this->user->api_userId)) {
                throw new \Exception('اطلاعات API کاربر به صورت کامل تنظیم نشده است');
            }

            // به‌روزرسانی status در صورت نیاز
            if ($this->invoice->status !== $this->invoice->order_data['status']) {
                $this->invoice->status = $this->invoice->order_data['status'];
                $this->invoice->save();
            }

            // استعلام مشتری از RainSale
            $customerRequestData = [
                'customerCode' => $this->invoice->customer_mobile
            ];

            Log::info('درخواست استعلام مشتری', [
                'invoice_id' => $this->invoice->id,
                'order_id' => $this->invoice->woocommerce_order_id,
                'request_data' => $customerRequestData,
                'api_url' => $this->user->api_webservice.'/RainSaleService.svc/GetCustomerByCode'
            ]);

            $customerResponse = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->user->api_username . ':' . $this->user->api_password)
            ])->post($this->user->api_webservice.'/RainSaleService.svc/GetCustomerByCode', $customerRequestData);

            $customerResult = null;
            $customerExists = false;

            if ($customerResponse->successful()) {
                $customerResult = json_decode($customerResponse->json()['GetCustomerByCodeResult'], true);
                if (isset($customerResult['CustomerID'])) {
                    $customerExists = true;
                    Log::info('مشتری با موفقیت یافت شد', [
                        'invoice_id' => $this->invoice->id,
                        'order_id' => $this->invoice->woocommerce_order_id,
                        'customer_id' => $customerResult['CustomerID']
                    ]);
                }
            }

            // اگر مشتری وجود نداشت، آن را ثبت می‌کنیم
            if (!$customerExists) {
                Log::info('مشتری یافت نشد، در حال ثبت مشتری جدید', [
                    'invoice_id' => $this->invoice->id,
                    'order_id' => $this->invoice->woocommerce_order_id
                ]);

                // آماده‌سازی داده‌های مشتری برای ثبت
                $customerData = [
                    'customer' => [
                        'Address' => $this->invoice->order_data['customer']['address']['address_1'],
                        'FirstName' => $this->invoice->order_data['customer']['first_name'],
                        'LastName' => $this->invoice->order_data['customer']['last_name'],
                        'Mobile' => $this->invoice->customer_mobile,
                        'CustomerCode' => $this->invoice->customer_mobile,
                        'IsMale' => '1',
                        'IsActive' => '1'
                    ]
                ];

                Log::info('درخواست ثبت مشتری', [
                    'invoice_id' => $this->invoice->id,
                    'order_id' => $this->invoice->woocommerce_order_id,
                    'request_data' => $customerData,
                    'api_url' => $this->user->api_webservice.'/RainSaleService.svc/SaveCustomer'
                ]);

                // ثبت مشتری در RainSale
                $saveCustomerResponse = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($this->user->api_username . ':' . $this->user->api_password)
                ])->post($this->user->api_webservice.'/RainSaleService.svc/SaveCustomer', $customerData);

                if (!$saveCustomerResponse->successful()) {
                Log::error('خطا در ثبت مشتری', [
                    'invoice_id' => $this->invoice->id,
                    'order_id' => $this->invoice->woocommerce_order_id,
                    'response' => $saveCustomerResponse->body(),
                    'status_code' => $saveCustomerResponse->status()
                ]);
                
                // ذخیره پاسخ سرویس باران در ستون rain_sale_response
                $this->invoice->update([
                    'rain_sale_response' => [
                        'error' => 'خطا در ثبت مشتری',
                        'response' => $saveCustomerResponse->body(),
                        'status_code' => $saveCustomerResponse->status(),
                        'status' => 'error'
                    ],
                    'is_synced' => false,
                    'sync_error' => 'خطا در ثبت مشتری: ' . $saveCustomerResponse->body()
                ]);
                
                throw new \Exception('خطا در ثبت مشتری: ' . $saveCustomerResponse->body());
            }

                // استعلام مجدد برای دریافت CustomerID
                $customerResponse = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($this->user->api_username . ':' . $this->user->api_password)
                ])->post($this->user->api_webservice.'/RainSaleService.svc/GetCustomerByCode', $customerRequestData);

                if (!$customerResponse->successful()) {
                Log::error('خطا در دریافت اطلاعات مشتری پس از ثبت', [
                    'invoice_id' => $this->invoice->id,
                    'order_id' => $this->invoice->woocommerce_order_id,
                    'response' => $customerResponse->body(),
                    'status_code' => $customerResponse->status()
                ]);
                
                // ذخیره پاسخ سرویس باران در ستون rain_sale_response
                $this->invoice->update([
                    'rain_sale_response' => [
                        'error' => 'خطا در دریافت اطلاعات مشتری پس از ثبت',
                        'response' => $customerResponse->body(),
                        'status_code' => $customerResponse->status(),
                        'status' => 'error'
                    ],
                    'is_synced' => false,
                    'sync_error' => 'خطا در دریافت اطلاعات مشتری پس از ثبت: ' . $customerResponse->body()
                ]);
                
                throw new \Exception('خطا در دریافت اطلاعات مشتری پس از ثبت: ' . $customerResponse->body());
            }

                $customerResult = json_decode($customerResponse->json()['GetCustomerByCodeResult'], true);
            }

            if (!isset($customerResult['CustomerID'])) {
                Log::error('پاسخ نامعتبر از RainSale برای اطلاعات مشتری', [
                    'invoice_id' => $this->invoice->id,
                    'order_id' => $this->invoice->woocommerce_order_id,
                    'response' => $customerResult
                ]);
                
                // ذخیره پاسخ سرویس باران در ستون rain_sale_response
                $this->invoice->update([
                    'rain_sale_response' => [
                        'error' => 'پاسخ نامعتبر از RainSale برای اطلاعات مشتری',
                        'response' => $customerResult,
                        'status' => 'error'
                    ],
                    'is_synced' => false,
                    'sync_error' => 'پاسخ نامعتبر از RainSale برای اطلاعات مشتری'
                ]);
                
                throw new \Exception('پاسخ نامعتبر از RainSale برای اطلاعات مشتری');
            }

            $this->invoice->customer_id = $customerResult['CustomerID'];
            $this->invoice->save();

            Log::info('اطلاعات مشتری با موفقیت دریافت شد', [
                'invoice_id' => $this->invoice->id,
                'order_id' => $this->invoice->woocommerce_order_id,
                'customer_id' => $customerResult['CustomerID']
            ]);

            // آماده‌سازی آیتم‌های فاکتور
            $items = [];
            foreach ($this->invoice->order_data['items'] as $item) {
                // دریافت اطلاعات محصول از دیتابیس
                // بررسی ساختار unique_id (رشته یا آرایه)
                $barcode = is_array($item['unique_id']) ? $item['unique_id']['barcode'] : $item['sku'];
                
                $product = \App\Models\Product::where('license_id', $this->license->license_key)
                    ->where('barcode', $barcode)
                    ->first();

                if (!$product) {
                    Log::error('محصول در دیتابیس یافت نشد', [
                        'invoice_id' => $this->invoice->id,
                        'order_id' => $this->invoice->woocommerce_order_id,
                        'barcode' => $barcode
                    ]);
                    
                    // ذخیره خطا در ستون rain_sale_response
                    $this->invoice->update([
                        'rain_sale_response' => [
                            'error' => 'محصول با بارکد ' . $barcode . ' در دیتابیس یافت نشد',
                            'status' => 'error'
                        ],
                        'is_synced' => false,
                        'sync_error' => 'محصول با بارکد ' . $barcode . ' در دیتابیس یافت نشد'
                    ]);
                    
                    throw new \Exception('محصول با بارکد ' . $barcode . ' در دیتابیس یافت نشد');
                }

                // آماده‌سازی مقادیر ItemId و Barcode با توجه به ساختار unique_id
                $itemId = is_array($item['unique_id']) ? $item['unique_id']['unique_id'] : $item['unique_id'];
                
                // محاسبه مقدار total در صورت عدم وجود
                $total = isset($item['total']) ? $item['total'] : ($item['price'] * $item['quantity']);
                
                $items[] = [
                    'IsPriceWithTax' => true,
                    'ItemId' => $itemId,
                    'Barcode' => $barcode,
                    'LineItemID' => count($items) + 1,
                    'NetAmount' => $total,
                    'OperationType' => 1,
                    'Price' => $item['price'],
                    'Quantity' => $item['quantity'],
                    'Tax' => $item['tax'] ?? 0,
                    'StockId' => $product->stock_id,
                    'Type' => 302
                ];

                Log::info('اطلاعات محصول از دیتابیس دریافت شد', [
                    'invoice_id' => $this->invoice->id,
                    'order_id' => $this->invoice->woocommerce_order_id,
                    'barcode' => $barcode,
                    'stock_id' => $product->stock_id
                ]);
            }

            // آماده‌سازی پرداخت‌ها
            $payments = [];
            $paymentTypeId = 1; // پیش‌فرض برای پرداخت نقدی
            if ($this->invoice->order_data['payment_method'] === 'cod') {
                $paymentTypeId = 1; // پرداخت نقدی
            }

            $payments[] = [
                'Amount' => $this->invoice->order_data['total'],
                'DueDate' => now()->format('Y-m-d H:i:s'),
                'LineItemID' => 1,
                'TypeID' => 2,
            ];

            // آماده‌سازی داده‌های فاکتور
            $invoiceRequestData = [
                'allowToMakeInvoice' => true,
                'calcPromotion' => false,
                'calcTax' => false,
                'order' => [
                    'CustomerId' => $customerResult['CustomerID'],
                    'Address' => $this->invoice->order_data['customer']['address']['address_1'],
                    'Items' => $items,
                    'Payments' => $payments,
                    'StoreId' => $this->user->api_storeId,
                    'UserId' => $this->user->api_userId,
                ],
                'useCredit' => false
            ];

            Log::info('درخواست ثبت فاکتور', [
                'invoice_id' => $this->invoice->id,
                'order_id' => $this->invoice->woocommerce_order_id,
                'request_data' => $invoiceRequestData,
                'api_url' => $this->user->api_webservice.'/RainSaleService.svc/SaveSaleInvoiceByOrder'
            ]);

            // ارسال فاکتور به RainSale
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->user->api_username . ':' . $this->user->api_password)
            ])->post($this->user->api_webservice.'/RainSaleService.svc/SaveSaleInvoiceByOrder', $invoiceRequestData);

            if (!$response->successful()) {
                Log::error('خطا در ثبت فاکتور در RainSale', [
                    'invoice_id' => $this->invoice->id,
                    'order_id' => $this->invoice->woocommerce_order_id,
                    'response' => $response->body(),
                    'status_code' => $response->status()
                ]);
                
                // ذخیره پاسخ سرویس باران در ستون rain_sale_response
                $this->invoice->update([
                    'rain_sale_response' => [
                        'error' => 'خطا در ثبت فاکتور در RainSale',
                        'response' => $response->body(),
                        'status_code' => $response->status(),
                        'status' => 'error'
                    ],
                    'is_synced' => false,
                    'sync_error' => 'خطا در ثبت فاکتور در RainSale: ' . $response->body()
                ]);
                
                throw new \Exception('خطا در ثبت فاکتور در RainSale: ' . $response->body());
            }

            $responseData = $response->json();
            if (!isset($responseData['SaveSaleInvoiceByOrderResult'])) {
                Log::error('پاسخ نامعتبر از RainSale', [
                    'invoice_id' => $this->invoice->id,
                    'order_id' => $this->invoice->woocommerce_order_id,
                    'response' => $responseData
                ]);
                
                // ذخیره پاسخ سرویس باران در ستون rain_sale_response
                $this->invoice->update([
                    'rain_sale_response' => [
                        'error' => 'پاسخ نامعتبر از RainSale',
                        'response' => $responseData,
                        'status' => 'error'
                    ],
                    'is_synced' => false,
                    'sync_error' => 'خطا در ثبت فاکتور در RainSale: پاسخ نامعتبر'
                ]);
                
                throw new \Exception('خطا در ثبت فاکتور در RainSale: پاسخ نامعتبر');
            }

            $result = $responseData['SaveSaleInvoiceByOrderResult'];

            // بررسی وضعیت پاسخ
            if ($result['status'] === 3) {
                Log::info('فاکتور با موفقیت ثبت شد', [
                    'invoice_id' => $this->invoice->id,
                    'order_id' => $this->invoice->woocommerce_order_id,
                    'response' => $responseData,
                    'invoice_number' => $result['Message']
                ]);

                // به‌روزرسانی وضعیت فاکتور
                $this->invoice->update([
                    'rain_sale_response' => $responseData,
                    'is_synced' => true
                ]);

                // به‌روزرسانی وضعیت در ووکامرس با شماره فاکتور
                $this->updateWooCommerceStatus(true, $result['Message']);
            } else {
                Log::error('خطا در ثبت فاکتور در RainSale', [
                    'invoice_id' => $this->invoice->id,
                    'order_id' => $this->invoice->woocommerce_order_id,
                    'response' => $responseData,
                    'status' => $result['status']
                ]);

                // به‌روزرسانی وضعیت فاکتور
                $this->invoice->update([
                    'rain_sale_response' => $responseData,
                    'is_synced' => false,
                    'sync_error' => $result['Message']
                ]);

                // به‌روزرسانی وضعیت خطا در ووکامرس
                $this->updateWooCommerceStatus(false, $result['Message']);

                // تلاش مجدد برای ثبت فاکتور
                throw new \Exception($result['Message']);
            }

        } catch (\Exception $e) {
            Log::error('خطا در پردازش فاکتور', [
                'error' => $e->getMessage(),
                'invoice_id' => $this->invoice->id,
                'order_id' => $this->invoice->woocommerce_order_id,
                'trace' => $e->getTraceAsString()
            ]);

            // اگر rain_sale_response قبلاً تنظیم نشده باشد، آن را تنظیم می‌کنیم
            if (!$this->invoice->rain_sale_response) {
                $this->invoice->update([
                    'rain_sale_response' => [
                        'error' => $e->getMessage(),
                        'status' => 'error'
                    ],
                    'is_synced' => false,
                    'sync_error' => $e->getMessage()
                ]);
            } else {
                // فقط sync_error را به‌روز می‌کنیم
                $this->invoice->update([
                    'sync_error' => $e->getMessage()
                ]);
            }

            // به‌روزرسانی وضعیت خطا در ووکامرس
            $this->updateWooCommerceStatus(false, $e->getMessage());

            throw $e;
        }
    }

    protected function updateWooCommerceStatus($success, $message)
    {
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->license->woocommerce_consumer_key . ':' . $this->license->woocommerce_consumer_secret)
            ])->put($this->license->woocommerce_url . '/wp-json/wc/v3/orders/' . $this->invoice->woocommerce_order_id, [
                'meta_data' => [
                    [
                        'key' => '_bim_web_service_status',
                        'value' => $success ? 'true' : 'false'
                    ],
                    [
                        'key' => '_bim_web_service_message',
                        'value' => $message
                    ],
                    [
                        'key' => '_rain_sale_sync_date',
                        'value' => now()->format('Y-m-d H:i:s')
                    ]
                ]
            ]);

            if (!$response->successful()) {
                Log::error('خطا در به‌روزرسانی وضعیت فاکتور در ووکامرس: ' . $response->body());
            } else {
                Log::info('وضعیت فاکتور در ووکامرس با موفقیت به‌روز شد', [
                    'order_id' => $this->invoice->woocommerce_order_id,
                    'success' => $success,
                    'message' => $message
                ]);
            }
        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی وضعیت فاکتور در ووکامرس: ' . $e->getMessage());
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('خطا در پردازش صف فاکتور: ' . $exception->getMessage());
    }
}
