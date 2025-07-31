<?php
namespace App\Jobs;

use App\Models\Invoice;
use App\Models\License;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ProcessInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Invoice $invoice;
    protected License $license;
    protected User $user;

    public function __construct(Invoice $invoice, License $license)
    {
        $this->invoice = $invoice;
        $this->license = $license;
        $this->onQueue('invoices');
    }

    public function handle()
    {
        // بررسی وجود لایسنس و یوزر
        throw_if(!$this->license, new \Exception('License not found.'));
        $this->user = $this->license->user;
        throw_if(!$this->user, new \Exception('User not found for license.'));

        // بررسی اطلاعات API
        throw_if(empty($this->user->api_webservice), new \Exception('API URL not set.'));
        throw_if(
            empty($this->user->api_username) ||
            empty($this->user->api_password) ||
            empty($this->user->api_storeId) ||
            empty($this->user->api_userId),
            new \Exception('Incomplete API credentials.')
        );

        $customerCode = $this->getCustomerCodeFromWooCommerce();
        $customerData = $this->getCustomerFromRainSale($customerCode);

        $this->sendInvoiceToRainSale($customerData['CustomerID']);
    }

    protected function getCustomerCodeFromWooCommerce(): string
    {
        $order = json_decode($this->invoice->order_data, true);
        return $order['billing']['email'] ?? throw new \Exception('Customer email not found.');
    }

    protected function getCustomerFromRainSale(string $customerCode): array
    {
        $url = rtrim($this->user->api_webservice, '/') . '/RainSaleService.svc/GetCustomerByCode';

        $response = $this->sendRequest($url, [
            'CustomerCode' => $customerCode,
            'StoreId' => $this->user->api_storeId,
        ]);

        $data = json_decode($response->json()['GetCustomerByCodeResult'] ?? '{}', true);

        if (empty($data['CustomerID'])) {
            throw new \Exception('Customer not found in RainSale.');
        }

        return $data;
    }

    protected function sendInvoiceToRainSale(string $customerId): void
    {
        $order = json_decode($this->invoice->order_data, true);
        $orderId = $order['id'];

        $url = rtrim($this->user->api_webservice, '/') . '/RainSaleService.svc/AddInvoiceWooCommerce';

        $payload = [
            'StoreId' => $this->user->api_storeId,
            'UserId' => $this->user->api_userId,
            'CustomerId' => $customerId,
            'WooOrderId' => $orderId,
            'WooOrderData' => $this->invoice->order_data,
        ];

        $response = $this->sendRequest($url, $payload);

        $result = json_decode($response->json()['AddInvoiceWooCommerceResult'] ?? '{}', true);

        if (!isset($result['Status']) || $result['Status'] !== true) {
            Log::error('Invoice not saved in RainSale', [
                'invoice_id' => $this->invoice->id,
                'response' => $result,
            ]);
            throw new \Exception('Invoice not saved in RainSale');
        }

        // موفقیت در ذخیره‌سازی
        $this->invoice->update(['rainsale_invoice_id' => $result['InvoiceId'] ?? null]);

        Log::info('Invoice synced with RainSale', [
            'invoice_id' => $this->invoice->id,
            'rainsale_invoice_id' => $result['InvoiceId'] ?? null,
        ]);
    }

    protected function sendRequest(string $url, array $data)
    {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($this->user->api_username . ':' . $this->user->api_password),
        ])->post($url, $data);

        if (!$response->ok()) {
            Log::error('API request failed', [
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception("API request failed with status: " . $response->status());
        }

        return $response;
    }
}
