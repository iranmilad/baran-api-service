<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\License;
use App\Models\UserSetting;
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

    /**
     * استاندارد کردن شماره موبایل به فرمت 09xxxxxxxxx
     */
    private function standardizeMobileNumber($mobile)
    {
        if (empty($mobile)) {
            return null;
        }

        // حذف همه کاراکترهای غیر عددی
        $mobile = preg_replace('/[^0-9]/', '', $mobile);

        // بررسی و تبدیل فرمت‌های مختلف
        if (strlen($mobile) == 10 && !str_starts_with($mobile, '0')) {
            // شماره 10 رقمی بدون 0 اول (مثل 9124100137)
            $mobile = '0' . $mobile;
        } elseif (strlen($mobile) == 13 && str_starts_with($mobile, '98')) {
            // شماره با کد کشور 98 (مثل 989124100137)
            $mobile = '0' . substr($mobile, 2);
        } elseif (strlen($mobile) == 14 && str_starts_with($mobile, '0098')) {
            // شماره با 0098 (مثل 00989124100137)
            $mobile = '0' . substr($mobile, 4);
        } elseif (strlen($mobile) == 12 && str_starts_with($mobile, '98')) {
            // شماره با +98 که + حذف شده (مثل 989124100137)
            $mobile = '0' . substr($mobile, 2);
        }

        // بررسی نهایی: باید 11 رقم باشد و با 09 شروع شود
        if (strlen($mobile) == 11 && str_starts_with($mobile, '09')) {
            return $mobile;
        }

        // اگر هیچ فرمت معتبری پیدا نشد
        Log::warning('شماره موبایل نامعتبر', [
            'original_mobile' => $mobile,
            'length' => strlen($mobile)
        ]);

        return null;
    }

    /**
     * پردازش پاسخ GetCustomerByCode
     */
    private function parseCustomerResponse($responseJson, $context = 'initial')
    {
        Log::info("پاسخ خام GetCustomerByCode {$context}", [
            'invoice_id' => $this->invoice->id,
            'response_structure' => $responseJson ? array_keys($responseJson) : 'null_response',
            'has_result_key' => isset($responseJson['GetCustomerByCodeResult']),
            'result_value_type' => isset($responseJson['GetCustomerByCodeResult']) ? gettype($responseJson['GetCustomerByCodeResult']) : 'not_set',
            'result_is_null' => isset($responseJson['GetCustomerByCodeResult']) ? ($responseJson['GetCustomerByCodeResult'] === null) : true
        ]);

        if (!isset($responseJson['GetCustomerByCodeResult'])) {
            Log::error('کلید GetCustomerByCodeResult در پاسخ وجود ندارد', [
                'invoice_id' => $this->invoice->id,
                'context' => $context,
                'available_keys' => array_keys($responseJson)
            ]);
            return null;
        }

        if ($responseJson['GetCustomerByCodeResult'] === null) {
            Log::info('مشتری در RainSale وجود ندارد', [
                'invoice_id' => $this->invoice->id,
                'customer_mobile' => $this->invoice->customer_mobile,
                'context' => $context
            ]);
            return null;
        }

        $resultString = $responseJson['GetCustomerByCodeResult'];

        // بررسی اینکه آیا نتیجه یک JSON string است یا خود یک آرایه
        if (is_string($resultString)) {
            $customerResult = json_decode($resultString, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("خطا در decode کردن JSON نتیجه مشتری {$context}", [
                    'invoice_id' => $this->invoice->id,
                    'json_error' => json_last_error_msg(),
                    'raw_result' => substr($resultString, 0, 200)
                ]);
                return null;
            }

            Log::info("نتیجه پردازش شده مشتری {$context}", [
                'invoice_id' => $this->invoice->id,
                'customer_result_keys' => $customerResult ? array_keys($customerResult) : 'null_result',
                'has_customer_id' => isset($customerResult['CustomerID']),
                'customer_id' => $customerResult['CustomerID'] ?? 'not_found'
            ]);

            return $customerResult;
        } else {
            // اگر خود آرایه است
            Log::info("نتیجه پردازش شده مشتری {$context}", [
                'invoice_id' => $this->invoice->id,
                'customer_result_keys' => is_array($resultString) ? array_keys($resultString) : 'not_array',
                'has_customer_id' => isset($resultString['CustomerID']),
                'customer_id' => $resultString['CustomerID'] ?? 'not_found'
            ]);

            return $resultString;
        }
    }

    public function handle()
    {
            // دریافت تنظیمات کاربر
            $userSettings = UserSetting::where('license_id', $this->license->id)->first();
            $shippingCostMethod = $userSettings ? $userSettings->shipping_cost_method : 'expense';
            $shippingProductUniqueId = $userSettings ? $userSettings->shipping_product_unique_id : '';

            Log::info('تنظیمات حمل و نقل', [
                'invoice_id' => $this->invoice->id,
                'shipping_cost_method' => $shippingCostMethod,
                'shipping_product_unique_id' => $shippingProductUniqueId
            ]);

            // استاندارد کردن شماره موبایل مشتری
            $originalMobile = $this->invoice->customer_mobile;
            $standardizedMobile = $this->standardizeMobileNumber($originalMobile);

            if (!$standardizedMobile) {
                Log::error('شماره موبایل مشتری نامعتبر است', [
                    'invoice_id' => $this->invoice->id,
                    'original_mobile' => $originalMobile
                ]);

                $this->invoice->update([
                    'rain_sale_response' => [
                        'error' => 'شماره موبایل مشتری نامعتبر است',
                        'original_mobile' => $originalMobile,
                        'status' => 'error'
                    ],
                    'is_synced' => false,
                    'sync_error' => $this->limitSyncError('شماره موبایل مشتری نامعتبر است')
                ]);

                $this->updateWooCommerceStatus(false, 'شماره موبایل مشتری نامعتبر است. شماره باید 11 رقم و با 09 شروع شود.');
                return;
            }

            // به‌روزرسانی شماره موبایل استاندارد شده در invoice
            if ($originalMobile !== $standardizedMobile) {
                $this->invoice->customer_mobile = $standardizedMobile;
                $this->invoice->save();

                Log::info('شماره موبایل استاندارد شد', [
                    'invoice_id' => $this->invoice->id,
                    'original' => $originalMobile,
                    'standardized' => $standardizedMobile
                ]);
            }

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
                $responseJson = $customerResponse->json();
                $customerResult = $this->parseCustomerResponse($responseJson, 'initial');

                // بررسی وجود CustomerID در نتیجه
                if ($customerResult && isset($customerResult['CustomerID']) && !empty($customerResult['CustomerID'])) {
                    $customerExists = true;

                    Log::info('مشتری در RainSale یافت شد', [
                        'invoice_id' => $this->invoice->id,
                        'customer_id' => $customerResult['CustomerID'],
                        'customer_mobile' => $this->invoice->customer_mobile
                    ]);
                }
            } else {
                Log::error('درخواست GetCustomerByCode ناموفق', [
                    'invoice_id' => $this->invoice->id,
                    'status_code' => $customerResponse->status(),
                    'response_body' => substr($customerResponse->body(), 0, 200)
                ]);
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

                    // ارسال پیام مناسب به ووکامرس به جای fail کردن
                    $this->updateWooCommerceStatus(false, 'خطا در ثبت مشتری در سیستم انبار. لطفاً مجدداً تلاش کنید.');
                    return;
                }

                Log::info('مشتری با موفقیت در RainSale ثبت شد', [
                    'invoice_id' => $this->invoice->id,
                    'customer_mobile' => $this->invoice->customer_mobile
                ]);

                // انتظار 10 ثانیه قبل از استعلام مجدد
                sleep(10);

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

                    // ارسال پیام مناسب به ووکامرس به جای fail کردن
                    $this->updateWooCommerceStatus(false, 'خطا در ارتباط با سیستم انبار. لطفاً مجدداً تلاش کنید.');
                    return;
                }

                // پردازش پاسخ مجدد با استفاده از تابع helper
                $responseJson = $customerResponse->json();
                $customerResult = $this->parseCustomerResponse($responseJson, 'after_save');

                if (!$customerResult || !isset($customerResult['CustomerID']) || empty($customerResult['CustomerID'])) {
                    // اگر بعد از ثبت هم null است، یعنی مشکلی در ثبت بوده
                    Log::error('مشتری بعد از ثبت در RainSale پیدا نشد', [
                        'invoice_id' => $this->invoice->id,
                        'customer_mobile' => $this->invoice->customer_mobile
                    ]);

                    $this->invoice->update([
                        'rain_sale_response' => [
                            'error' => 'مشتری بعد از ثبت در RainSale پیدا نشد',
                            'response' => $responseJson,
                            'status' => 'error'
                        ],
                        'is_synced' => false,
                        'sync_error' => $this->limitSyncError('مشتری بعد از ثبت در RainSale پیدا نشد')
                    ]);

                    // ارسال پیام مناسب به ووکامرس به جای fail کردن
                    $this->updateWooCommerceStatus(false, 'خطا در ثبت مشتری در سیستم انبار. لطفاً مجدداً تلاش کنید.');
                    return;
                }

                Log::info('CustomerID مشتری پس از ثبت دریافت شد', [
                    'invoice_id' => $this->invoice->id,
                    'customer_id' => $customerResult['CustomerID'],
                    'customer_mobile' => $this->invoice->customer_mobile
                ]);
            }

            // بررسی وجود CustomerID
            if (!isset($customerResult['CustomerID'])) {
                $logData = [
                    'invoice_id' => $this->invoice->id,
                    'order_id' => $this->invoice->woocommerce_order_id,
                    'expected_fields' => ['CustomerID'],
                    'received_fields' => $customerResult ? array_keys($customerResult) : 'null_response',
                    'customer_mobile' => $this->invoice->customer_mobile ?? 'not_set'
                ];

                $responseString = json_encode($customerResult);
                if ($this->shouldLogResponse($responseString)) {
                    $logData['response'] = $customerResult;
                }

                Log::error('پاسخ نامعتبر از RainSale برای اطلاعات مشتری', $logData);

                // بررسی تعداد تلاش‌ها
                if ($this->attempts() < $this->tries) {
                    Log::info('تلاش مجدد برای دریافت اطلاعات مشتری', [
                        'invoice_id' => $this->invoice->id,
                        'attempt' => $this->attempts(),
                        'max_tries' => $this->tries
                    ]);

                    // ذخیره وضعیت retry در دیتابیس
                    $this->invoice->update([
                        'rain_sale_response' => [
                            'error' => 'پاسخ نامعتبر از RainSale برای اطلاعات مشتری - در حال تلاش مجدد',
                            'response' => $customerResult,
                            'status' => 'retrying',
                            'attempt' => $this->attempts()
                        ],
                        'is_synced' => false,
                        'sync_error' => $this->limitSyncError('در حال تلاش مجدد - تلاش ' . $this->attempts() . ' از ' . $this->tries)
                    ]);

                    // اجازه retry به job
                    throw new \Exception('پاسخ نامعتبر از RainSale برای اطلاعات مشتری - تلاش ' . $this->attempts());
                }

                // اگر همه تلاش‌ها شکست خوردند
                $this->invoice->update([
                    'rain_sale_response' => [
                        'error' => 'پاسخ نامعتبر از RainSale برای اطلاعات مشتری - همه تلاش‌ها شکست خوردند',
                        'response' => $customerResult,
                        'status' => 'failed',
                        'attempts' => $this->attempts()
                    ],
                    'is_synced' => false,
                    'sync_error' => $this->limitSyncError('پاسخ نامعتبر از RainSale برای اطلاعات مشتری - همه تلاش‌ها شکست خوردند')
                ]);

                // ارسال پیام مناسب به ووکامرس به جای fail کردن
                $this->updateWooCommerceStatus(false, 'خطا در دریافت اطلاعات مشتری از سیستم انبار. لطفاً مجدداً تلاش کنید.');
                return;
            }

            // ذخیره CustomerID

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
                    return;
                }

                // آماده‌سازی مقادیر ItemId - بررسی ساختار unique_id
                if (is_array($item['unique_id']) && isset($item['unique_id']['unique_id'])) {
                    // اگر unique_id یک object است که خود unique_id و barcode دارد
                    $itemId = $item['unique_id']['unique_id'];
                    Log::info('استخراج unique_id از object', [
                        'invoice_id' => $this->invoice->id,
                        'item_index' => $index,
                        'extracted_unique_id' => $itemId,
                        'original_structure' => $item['unique_id']
                    ]);
                } else {
                    // اگر unique_id یک string است
                    $itemId = $item['unique_id'];
                    Log::info('استفاده از unique_id به صورت string', [
                        'invoice_id' => $this->invoice->id,
                        'item_index' => $index,
                        'unique_id' => $itemId
                    ]);
                }


                // محاسبه مقدار total در صورت عدم وجود
                $itemPrice = (float)$item['price'];
                $itemQuantity = (int)$item['quantity'];
                $total = isset($item['total']) ? (float)$item['total'] : ($itemPrice * $itemQuantity);

                $items[] = [
                    'IsPriceWithTax' => true,
                    'ItemId' => $itemId,
                    //'Barcode' => $barcode,
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

            // بررسی نیاز به اضافه کردن هزینه حمل و نقل به عنوان آیتم
            $shippingTotal = isset($this->invoice->order_data['shipping_total']) ? (float)$this->invoice->order_data['shipping_total'] : 0;

            if ($shippingCostMethod === 'product' && $shippingTotal > 0) {
                // اضافه کردن هزینه حمل و نقل به عنوان یک آیتم
                $items[] = [
                    'IsPriceWithTax' => true,
                    'ItemId' => $shippingProductUniqueId,
                    'LineItemID' => count($items) + 1,
                    'NetAmount' => $shippingTotal,
                    'OperationType' => 1,
                    'Price' => $shippingTotal,
                    'Quantity' => 1,
                    'Tax' => 0,
                    'Type' => 302
                ];

                Log::info('هزینه حمل و نقل به عنوان آیتم اضافه شد', [
                    'invoice_id' => $this->invoice->id,
                    'shipping_unique_id' => $shippingProductUniqueId,
                    'shipping_amount' => $shippingTotal
                ]);
            }

            // آماده‌سازی پرداخت‌ها
            $payments = [];
            $paymentTypeId = 1; // پیش‌فرض برای پرداخت نقدی
            if ($this->invoice->order_data['payment_method'] === 'cod') {
                $paymentTypeId = 1; // پرداخت نقدی
            }

            // محاسبه مبلغ کل بر اساس تنظیمات حمل و نقل
            $orderTotal = (float)$this->invoice->order_data['total'];
            // $shippingTotal قبلاً محاسبه شده است

            // بررسی اینکه آیا total قبلاً شامل هزینه ارسال است یا نه
            $itemsTotal = 0;
            foreach ($this->invoice->order_data['items'] as $item) {
                $itemsTotal += (float)$item['total'];
            }

            // محاسبه مبلغ کل و DeliveryCost بر اساس روش حمل و نقل
            $deliveryCost = 0;
            $totalAmount = $orderTotal;

            if ($shippingCostMethod === 'expense') {
                // حمل و نقل به عنوان هزینه - در DeliveryCost قرار می‌گیرد
                $deliveryCost = $shippingTotal;

                if (abs($orderTotal - ($itemsTotal + $shippingTotal)) < 1) {
                    // total قبلاً شامل shipping است
                    $totalAmount = $orderTotal;
                } else {
                    // total شامل shipping نیست، باید اضافه کنیم
                    $totalAmount = $orderTotal + $deliveryCost;
                }
            } else {
                // حمل و نقل به عنوان محصول - در آیتم‌ها قرار گرفته، DeliveryCost صفر
                $deliveryCost = 0;
                $totalAmount = $orderTotal; // مبلغ کل همان total سفارش است
            }

            Log::info('محاسبه مبلغ کل پرداخت', [
                'invoice_id' => $this->invoice->id,
                'items_total' => $itemsTotal,
                'order_total' => $orderTotal,
                'shipping_total' => $shippingTotal,
                'shipping_method' => $shippingCostMethod,
                'delivery_cost' => $deliveryCost,
                'final_total' => $totalAmount
            ]);

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
                    'DeliveryCost' => $deliveryCost
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

                // ارسال پیام مناسب به ووکامرس به جای fail کردن
                $this->updateWooCommerceStatus(false, 'خطا در ثبت فاکتور در سیستم انبار. لطفاً مجدداً تلاش کنید.');
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
