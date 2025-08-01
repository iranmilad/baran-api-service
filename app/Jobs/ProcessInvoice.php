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
            Log::error('لایسنس یافت نشد');
            $this->fail('لایسنس یافت نشد');
            return;
        }

        // یافتن کاربر مربوط به لایسنس
        $this->user = $this->license->user;
        if (!$this->user) {
            Log::error('کاربر مربوط به لایسنس یافت نشد');
            $this->fail('کاربر مربوط به لایسنس یافت نشد');
            return;
        }

        $this->onQueue('invoices');
    }

    /**
     * محدود کردن طول پیام خطا برای ذخیره در sync_error
     */
    private function limitSyncError($errorMessage)
    {
        if (strlen($errorMessage) > 255) {
            return 'ساختار برگشتی غیر استاندارد';
        }
        return $errorMessage;
    }

    /**
     * محدود کردن لاگ پاسخ سرور - اگر بیش از 250 کارکتر باشد لاگ نمی‌شود
     */
    private function shouldLogResponse($response)
    {
        return strlen($response) <= 250;
    }

    public function handle()
    {
            // بررسی وجود آدرس API در اطلاعات کاربر
            if (empty($this->user->api_webservice)) {
                Log::error('آدرس API در تنظیمات کاربر تنظیم نشده است');
                $this->fail('آدرس API در تنظیمات کاربر تنظیم نشده است');
                return;
            }

            // بررسی وجود سایر اطلاعات API کاربر
            if (empty($this->user->api_username) || empty($this->user->api_password) || empty($this->user->api_storeId) || empty($this->user->api_userId)) {
                Log::error('اطلاعات API کاربر به صورت کامل تنظیم نشده است');
                $this->fail('اطلاعات API کاربر به صورت کامل تنظیم نشده است');
                return;
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
                }
            }

            // اگر مشتری وجود نداشت، آن را ثبت می‌کنیم
            if (!$customerExists) {

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


                // ثبت مشتری در RainSale
                $saveCustomerResponse = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($this->user->api_username . ':' . $this->user->api_password)
                ])->post($this->user->api_webservice.'/RainSaleService.svc/SaveCustomer', $customerData);

                if (!$saveCustomerResponse->successful()) {
                $responseBody = $saveCustomerResponse->body();
                $logData = [
                    'invoice_id' => $this->invoice->id,
                    'order_id' => $this->invoice->woocommerce_order_id,
                    'status_code' => $saveCustomerResponse->status()
                ];

                if ($this->shouldLogResponse($responseBody)) {
                    $logData['response'] = $responseBody;
                }

                Log::error('خطا در ثبت مشتری', $logData);

                // ذخیره پاسخ سرویس باران در ستون rain_sale_response
                $this->invoice->update([
                    'rain_sale_response' => [
                        'error' => 'خطا در ثبت مشتری',
                        'response' => $saveCustomerResponse->body(),
                        'status_code' => $saveCustomerResponse->status(),
                        'status' => 'error'
                    ],
                    'is_synced' => false,
                    'sync_error' => $this->limitSyncError('خطا در ثبت مشتری: ' . $saveCustomerResponse->body())
                ]);

                $this->fail('خطا در ثبت مشتری: ' . $saveCustomerResponse->body());
                return;
            }

                // استعلام مجدد برای دریافت CustomerID
                $customerResponse = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($this->user->api_username . ':' . $this->user->api_password)
                ])->post($this->user->api_webservice.'/RainSaleService.svc/GetCustomerByCode', $customerRequestData);

                if (!$customerResponse->successful()) {
                $responseBody = $customerResponse->body();
                $logData = [
                    'invoice_id' => $this->invoice->id,
                    'order_id' => $this->invoice->woocommerce_order_id,
                    'status_code' => $customerResponse->status()
                ];

                if ($this->shouldLogResponse($responseBody)) {
                    $logData['response'] = $responseBody;
                }

                Log::error('خطا در دریافت اطلاعات مشتری پس از ثبت', $logData);

                // ذخیره پاسخ سرویس باران در ستون rain_sale_response
                $this->invoice->update([
                    'rain_sale_response' => [
                        'error' => 'خطا در دریافت اطلاعات مشتری پس از ثبت',
                        'response' => $customerResponse->body(),
                        'status_code' => $customerResponse->status(),
                        'status' => 'error'
                    ],
                    'is_synced' => false,
                    'sync_error' => $this->limitSyncError('خطا در دریافت اطلاعات مشتری پس از ثبت: ' . $customerResponse->body())
                ]);

                $this->fail('خطا در دریافت اطلاعات مشتری پس از ثبت: ' . $customerResponse->body());
                return;
            }

                $customerResult = json_decode($customerResponse->json()['GetCustomerByCodeResult'], true);
            }

            if (!isset($customerResult['CustomerID'])) {
                $logData = [
                    'invoice_id' => $this->invoice->id,
                    'order_id' => $this->invoice->woocommerce_order_id
                ];

                $responseString = json_encode($customerResult);
                if ($this->shouldLogResponse($responseString)) {
                    $logData['response'] = $customerResult;
                }

                Log::error('پاسخ نامعتبر از RainSale برای اطلاعات مشتری', $logData);

                // ذخیره پاسخ سرویس باران در ستون rain_sale_response
                $this->invoice->update([
                    'rain_sale_response' => [
                        'error' => 'پاسخ نامعتبر از RainSale برای اطلاعات مشتری',
                        'response' => $customerResult,
                        'status' => 'error'
                    ],
                    'is_synced' => false,
                    'sync_error' => $this->limitSyncError('پاسخ نامعتبر از RainSale برای اطلاعات مشتری')
                ]);

                $this->fail('پاسخ نامعتبر از RainSale برای اطلاعات مشتری');
                return;
            }

            $this->invoice->customer_id = $customerResult['CustomerID'];
            $this->invoice->save();


            // آماده‌سازی آیتم‌های فاکتور
            $items = [];
            foreach ($this->invoice->order_data['items'] as $index => $item) {
                // بررسی وجود unique_id
                if (empty($item['unique_id'])) {
                    $errorMessage = 'فاکتور دارای اقلام فاقد کد یکتا است';

                    Log::error('آیتم فاقد unique_id', [
                        'invoice_id' => $this->invoice->id,
                        'order_id' => $this->invoice->woocommerce_order_id,
                        'item_index' => $index,
                        'item' => $item,
                        'has_unique_id' => !empty($item['unique_id'])
                    ]);

                    // ذخیره خطا در دیتابیس
                    $this->invoice->update([
                        'rain_sale_response' => [
                            'function' => 'SaveSaleInvoiceByOrder',
                            'error' => $errorMessage,
                            'item_index' => $index,
                            'item_data' => $item,
                            'status' => 'error'
                        ],
                        'is_synced' => false,
                        'sync_error' => $this->limitSyncError($errorMessage)
                    ]);

                    // ارسال خطا به ووکامرس
                    $this->updateWooCommerceStatus(false, $errorMessage);

                    $this->fail($errorMessage);
                    return;
                }

                // آماده‌سازی مقادیر ItemId
                $itemId = $item['unique_id'];

                // دریافت اطلاعات محصول از دیتابیس برای استخراج بارکد
                $product = \App\Models\Product::where('item_id', $itemId)->first();

                if (!$product || empty($product->barcode)) {
                    $errorMessage = 'بارکد برای آیتم با کد یکتا ' . $itemId . ' نامعتبر است';

                    Log::error('بارکد محصول یافت نشد یا نامعتبر است', [
                        'invoice_id' => $this->invoice->id,
                        'order_id' => $this->invoice->woocommerce_order_id,
                        'item_index' => $index,
                        'item' => $item,
                        'item_id' => $itemId,
                        'product_found' => (bool) $product,
                        'barcode_empty' => empty($product->barcode ?? null)
                    ]);

                    // ذخیره خطا در دیتابیس
                    $this->invoice->update([
                        'rain_sale_response' => [
                            'function' => 'SaveSaleInvoiceByOrder',
                            'error' => $errorMessage,
                            'item_index' => $index,
                            'item_data' => $item,
                            'status' => 'error'
                        ],
                        'is_synced' => false,
                        'sync_error' => $this->limitSyncError($errorMessage)
                    ]);

                    // ارسال خطا به ووکامرس
                    $this->updateWooCommerceStatus(false, $errorMessage);

                    $this->fail($errorMessage);
                    return;
                }

                $barcode = $product->barcode;

                // محاسبه مقدار total در صورت عدم وجود
                $itemPrice = (float)$item['price'];
                $itemQuantity = (int)$item['quantity'];
                $total = isset($item['total']) ? (float)$item['total'] : ($itemPrice * $itemQuantity);

                $items[] = [
                    'IsPriceWithTax' => true,
                    'ItemId' => $itemId,
                    'Barcode' => $barcode,
                    'LineItemID' => count($items) + 1,
                    'NetAmount' => $total,
                    'OperationType' => 1,
                    'Price' => $itemPrice,
                    'Quantity' => $itemQuantity,
                    'Tax' => isset($item['tax']) ? (float)$item['tax'] : 0,
                    //'StockId' => $product->stock_id,
                    'Type' => 302
                ];
            }

            // آماده‌سازی پرداخت‌ها
            $payments = [];
            $paymentTypeId = 1; // پیش‌فرض برای پرداخت نقدی
            if ($this->invoice->order_data['payment_method'] === 'cod') {
                $paymentTypeId = 1; // پرداخت نقدی
            }

            $totalAmount = (float)$this->invoice->order_data['total'];


            $payments[] = [
                'Amount' => $totalAmount,
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


            // ارسال فاکتور به RainSale
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->user->api_username . ':' . $this->user->api_password)
            ])->post($this->user->api_webservice.'/RainSaleService.svc/SaveSaleInvoiceByOrder', $invoiceRequestData);

            if (!$response->successful()) {
                $statusCode = $response->status();
                $responseBody = $response->body();

                $logData = [
                    'invoice_id' => $this->invoice->id,
                    'order_id' => $this->invoice->woocommerce_order_id,
                    'status_code' => $statusCode
                ];

                if ($this->shouldLogResponse($responseBody)) {
                    $logData['response'] = $responseBody;
                }

                Log::error('خطا در ثبت فاکتور در RainSale', $logData);

                // بررسی خطای 400 و ساختار نامعتبر پاسخ
                $errorMessage = 'خطا در ثبت فاکتور در RainSale: ' . $responseBody;
                if ($statusCode === 400) {
                    // تلاش برای پارس کردن JSON
                    $jsonResponse = json_decode($responseBody, true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($jsonResponse)) {
                        // پاسخ ساختار استاندارد ندارد
                        $errorMessage = 'درخواست نامعتبر سرور - ساختار پاسخ نامعتبر';
                    }
                }

                // ذخیره پاسخ سرویس باران در ستون rain_sale_response
                $this->invoice->update([
                    'rain_sale_response' => [
                        'function' => 'SaveSaleInvoiceByOrder',
                        'request' => $invoiceRequestData,
                        'error' => 'خطا در ثبت فاکتور در RainSale',
                        'response' => $responseBody,
                        'status_code' => $statusCode,
                        'status' => 'error'
                    ],
                    'is_synced' => false,
                    'sync_error' => $this->limitSyncError($errorMessage)
                ]);

                // به‌روزرسانی وضعیت خطا در ووکامرس
                $this->updateWooCommerceStatus(false, $errorMessage);

                $this->fail($errorMessage);
                return;
            }

            $responseData = $response->json();
            if (!isset($responseData['SaveSaleInvoiceByOrderResult'])) {
                $logData = [
                    'invoice_id' => $this->invoice->id,
                    'order_id' => $this->invoice->woocommerce_order_id
                ];

                $responseString = json_encode($responseData);
                if ($this->shouldLogResponse($responseString)) {
                    $logData['response'] = $responseData;
                }

                Log::error('پاسخ نامعتبر از RainSale', $logData);

                // ذخیره پاسخ سرویس باران در ستون rain_sale_response
                $this->invoice->update([
                    'rain_sale_response' => [
                        'function' => 'SaveSaleInvoiceByOrder',
                        'request' => $invoiceRequestData,
                        'error' => 'پاسخ نامعتبر از RainSale',
                        'response' => $responseData,
                        'status' => 'error'
                    ],
                    'is_synced' => false,
                    'sync_error' => $this->limitSyncError('خطا در ثبت فاکتور در RainSale: پاسخ نامعتبر')
                ]);

                $this->fail('خطا در ثبت فاکتور در RainSale: پاسخ نامعتبر');
                return;
            }

            $result = $responseData['SaveSaleInvoiceByOrderResult'];

            // بررسی ساختار ریسپانس و وضعیت پاسخ
            if (isset($result['Status']) && $result['Status'] === 3) {

                // به‌روزرسانی وضعیت فاکتور
                $this->invoice->update([
                    'rain_sale_response' => [
                        'function' => 'SaveSaleInvoiceByOrder',
                        'request' => $invoiceRequestData,
                        'response' => $responseData,
                        'status' => 'success',
                        'message' => $result['Message']
                    ],
                    'is_synced' => true
                ]);

                // به‌روزرسانی وضعیت در ووکامرس با شماره فاکتور
                $this->updateWooCommerceStatus(true, $result['Message']);
            } else {
                $errorMessage = isset($result['Message']) ? $result['Message'] : 'خطای نامشخص';
                $errorStatus = isset($result['Status']) ? $result['Status'] : null;

                $logData = [
                    'invoice_id' => $this->invoice->id,
                    'order_id' => $this->invoice->woocommerce_order_id,
                    'status' => $errorStatus,
                    'message' => $errorMessage
                ];

                $responseString = json_encode($responseData);
                if ($this->shouldLogResponse($responseString)) {
                    $logData['response'] = $responseData;
                }

                Log::error('خطا در ثبت فاکتور در RainSale', $logData);


                $finalErrorMessage = $errorMessage;



                // به‌روزرسانی وضعیت فاکتور
                $this->invoice->update([
                    'rain_sale_response' => [
                        'function' => 'SaveSaleInvoiceByOrder',
                        'request' => $invoiceRequestData,
                        'response' => $responseData,
                        'status' => 'error',
                        'error' => $errorMessage,
                        'error_status' => $errorStatus
                    ],
                    'is_synced' => false,
                    'sync_error' => $this->limitSyncError($finalErrorMessage)
                ]);

                // به‌روزرسانی وضعیت خطا در ووکامرس
                $this->updateWooCommerceStatus(false, $finalErrorMessage);

            }


    }

    protected function updateWooCommerceStatus($success, $message)
    {
        try {
            // دریافت اطلاعات WooCommerce API از جدول woocommerce_api_keys
            $wooCommerceApiKey = $this->license->woocommerceApiKey;

            if (!$wooCommerceApiKey) {
                Log::warning('کلید API WooCommerce برای این لایسنس یافت نشد', [
                    'license_id' => $this->license->id,
                    'order_id' => $this->invoice->woocommerce_order_id
                ]);
                return;
            }

            // بررسی وجود اطلاعات WooCommerce
            if (empty($wooCommerceApiKey->api_key) || empty($wooCommerceApiKey->api_secret)) {
                Log::warning('اطلاعات WooCommerce API کامل نیست', [
                    'license_id' => $this->license->id,
                    'order_id' => $this->invoice->woocommerce_order_id,
                    'has_api_key' => !empty($wooCommerceApiKey->api_key),
                    'has_api_secret' => !empty($wooCommerceApiKey->api_secret)
                ]);
                return;
            }

            if (empty($this->license->website_url)) {
                Log::warning('آدرس وب‌سایت موجود نیست', [
                    'license_id' => $this->license->id,
                    'order_id' => $this->invoice->woocommerce_order_id
                ]);
                return;
            }

            $httpClient = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->retry(3, 300, function ($exception, $request) {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException ||
                       (isset($exception->response) && $exception->response->status() >= 500);
            })->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]);

            // استفاده از Basic Auth با کلیدهای صحیح
            $httpClient = $httpClient->withBasicAuth(
                $wooCommerceApiKey->api_key,
                $wooCommerceApiKey->api_secret
            );

            $response = $httpClient->put($this->license->website_url . '/wp-json/wc/v3/orders/' . $this->invoice->woocommerce_order_id, [
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
                $responseBody = $response->body();
                $logData = [
                    'order_id' => $this->invoice->woocommerce_order_id,
                    'status_code' => $response->status(),
                    'url' => $this->license->website_url . '/wp-json/wc/v3/orders/' . $this->invoice->woocommerce_order_id,
                    'license_id' => $this->license->id
                ];

                if ($this->shouldLogResponse($responseBody)) {
                    $logData['response_body'] = $responseBody;
                }

                Log::error('خطا در به‌روزرسانی وضعیت فاکتور در ووکامرس', $logData);
            }
        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی وضعیت فاکتور در ووکامرس', [
                'order_id' => $this->invoice->woocommerce_order_id,
                'error' => $e->getMessage(),
                'url' => ($this->license->website_url ?? 'unknown') . '/wp-json/wc/v3/orders/' . $this->invoice->woocommerce_order_id,
                'license_id' => $this->license->id
            ]);
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('خطا در پردازش صف فاکتور: ' . $exception->getMessage());
    }
}
