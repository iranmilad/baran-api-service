<?php

namespace App\Jobs\WooCommerce;

use App\Models\ProductSyncLog;
use App\Models\License;
use App\Traits\PriceUnitConverter;
use App\Traits\WordPress\WordPressMasterTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncProductFromRainSale implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, PriceUnitConverter, WordPressMasterTrait;

    public $tries = 3;
    public $timeout = 300;
    public $maxExceptions = 3;
    public $backoff = [30, 60, 120];

    protected $licenseKey;
    protected $barcode;
    protected $license;
    protected $wooCommerceProductId;

    public function __construct($licenseKey, $barcode)
    {
        $this->licenseKey = $licenseKey;
        $this->barcode = $barcode;
        $this->license = License::where('license_key', $licenseKey)->first();
        $this->onQueue('products');
    }

    public function handle()
    {
        try {
            if (!$this->license) {
                throw new \Exception('لایسنس یافت نشد');
            }

            // بررسی وجود آدرس API
            if (empty($this->license->api_url)) {
                throw new \Exception('آدرس API در تنظیمات لایسنس تنظیم نشده است');
            }

            // دریافت اطلاعات محصول از RainSale
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->license->rain_sale_username . ':' . $this->license->rain_sale_password)
            ])->post($this->license->api_url . '/GetItemInfo', [
                'barcode' => $this->barcode
            ]);

            if (!$response->successful()) {
                throw new \Exception('خطا در دریافت اطلاعات محصول: ' . $response->body());
            }

            $itemData = $response->json()['GetItemInfoResult'];

            // ذخیره لاگ
            $log = ProductSyncLog::create([
                'isbn' => $this->barcode,
                'name' => $itemData['Name'],
                'price_amount' => $itemData['Price'],
                'price_after_discount' => $itemData['PriceAfterDiscount'] ?? $itemData['Price'],
                'total_count' => $itemData['Stock'],
                'stock_id' => $itemData['StockID'] ?? null,
                'raw_response' => $itemData,
                'is_success' => true
            ]);

            // به‌روزرسانی در ووکامرس
            $this->updateWooCommerce($itemData);

            Log::info('محصول با موفقیت از RainSale به ووکامرس منتقل شد', [
                'barcode' => $this->barcode,
                'woocommerce_product_id' => $this->wooCommerceProductId
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در همگام‌سازی محصول: ' . $e->getMessage());

            // ذخیره لاگ خطا
            ProductSyncLog::create([
                'isbn' => $this->barcode,
                'is_success' => false,
                'error_message' => $e->getMessage()
            ]);

            // استفاده از آخرین اطلاعات موفق
            $lastSuccessfulSync = ProductSyncLog::where('isbn', $this->barcode)
                ->where('is_success', true)
                ->latest()
                ->first();

            if ($lastSuccessfulSync) {
                $this->updateWooCommerce($lastSuccessfulSync->raw_response);
            }
        }
    }

    protected function updateWooCommerce($data)
    {
        // محاسبه قیمت نهایی با اعمال تخفیف و افزایش قیمت
        $finalPrice = $this->calculateFinalPrice(
            $data['Price'],
            $this->license->discount_percentage,
            $this->license->price_increase_percentage
        );

        // تبدیل واحد قیمت
        $finalPrice = $this->convertPriceUnit(
            $finalPrice,
            $this->license->rain_sale_price_unit,
            $this->license->woocommerce_price_unit
        );

        // جستجوی محصول با ISBN
        $searchResult = $this->getWooCommerceProductsBySku(
            $this->license->woocommerce_url,
            $this->license->woocommerce_consumer_key,
            $this->license->woocommerce_consumer_secret,
            $this->barcode
        );

        if (!$searchResult['success']) {
            throw new \Exception('خطا در جستجوی محصول در ووکامرس: ' . $searchResult['message']);
        }

        $products = $searchResult['data'];

        if (!empty($products)) {
            $product = $products[0];

            // به‌روزرسانی محصول
            $updateData = [
                'name' => $data['Name'],
                'regular_price' => (string)$finalPrice,
                'sale_price' => (string)($data['PriceAfterDiscount'] ?? $finalPrice),
                'stock_quantity' => $data['Stock'],
                'stock_status' => $data['Stock'] > 0 ? 'instock' : 'outofstock'
            ];

            $updateResult = $this->updateWooCommerceProduct(
                $this->license->woocommerce_url,
                $this->license->woocommerce_consumer_key,
                $this->license->woocommerce_consumer_secret,
                $product['id'],
                $updateData
            );

            if (!$updateResult['success']) {
                throw new \Exception('خطا در به‌روزرسانی محصول در ووکامرس: ' . $updateResult['message']);
            }

            $this->wooCommerceProductId = $product['id'];
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('خطا در پردازش صف همگام‌سازی محصول: ' . $exception->getMessage());
    }
}
