<?php

namespace App\Jobs;

use App\Models\License;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Automattic\WooCommerce\Client;

class ProcessSingleProductBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $licenseId;
    protected $barcodes;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 2;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public $maxExceptions = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 35; // 35 ثانیه - کوتاه و ایمن

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public $backoff = [10, 30];

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId, $barcodes)
    {
        $this->licenseId = $licenseId;
        $this->barcodes = $barcodes;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        $maxExecutionTime = 30; // 30 ثانیه برای اطمینان

        try {
            Log::info('شروع پردازش batch محصولات', [
                'license_id' => $this->licenseId,
                'barcodes_count' => count($this->barcodes),
                'barcodes' => $this->barcodes
            ]);

            $license = License::with(['userSetting', 'woocommerceApiKey', 'user'])->find($this->licenseId);
            if (!$license || !$license->isActive()) {
                Log::error('لایسنس معتبر نیست', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            $userSettings = $license->userSetting;
            $wooApiKey = $license->woocommerceApiKey;
            $user = $license->user;

            if (!$userSettings || !$wooApiKey || !$user) {
                Log::error('اطلاعات ضروری یافت نشد', [
                    'license_id' => $license->id,
                    'has_settings' => !!$userSettings,
                    'has_api_key' => !!$wooApiKey,
                    'has_user' => !!$user
                ]);
                return;
            }

            // گام 1: دریافت اطلاعات محصولات از RainSale
            $rainProducts = $this->getRainProducts($this->barcodes, $user);

            if (empty($rainProducts)) {
                Log::info('هیچ محصولی از RainSale دریافت نشد', [
                    'license_id' => $this->licenseId,
                    'barcodes' => $this->barcodes
                ]);
                return;
            }

            // بررسی زمان
            $elapsedTime = microtime(true) - $startTime;
            if ($elapsedTime > $maxExecutionTime - 10) {
                Log::warning('زمان کافی برای به‌روزرسانی WooCommerce نیست', [
                    'license_id' => $this->licenseId,
                    'elapsed_time' => round($elapsedTime, 2)
                ]);
                return;
            }

            // گام 2: به‌روزرسانی در WooCommerce
            $this->updateWooCommerceProducts($rainProducts, $license, $userSettings, $wooApiKey);

            Log::info('پردازش batch محصولات تکمیل شد', [
                'license_id' => $this->licenseId,
                'products_processed' => count($rainProducts),
                'execution_time' => round(microtime(true) - $startTime, 2)
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در پردازش batch محصولات: ' . $e->getMessage(), [
                'license_id' => $this->licenseId,
                'barcodes' => $this->barcodes,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * دریافت اطلاعات محصولات از RainSale API
     */
    private function getRainProducts($barcodes, $user)
    {
        try {
            if (!$user->api_webservice || !$user->api_username || !$user->api_password) {
                Log::warning('اطلاعات API RainSale یافت نشد', [
                    'user_id' => $user->id,
                    'license_id' => $this->licenseId
                ]);
                return [];
            }

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 15, // timeout کوتاه
                'connect_timeout' => 5
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user->api_username . ':' . $user->api_password)
            ])->post($user->api_webservice . "/RainSaleService.svc/GetItemInfos", [
                'barcodes' => $barcodes
            ]);

            if (!$response->successful()) {
                Log::warning('خطا در دریافت از RainSale API', [
                    'license_id' => $this->licenseId,
                    'status' => $response->status(),
                    'barcodes' => $barcodes
                ]);
                return [];
            }

            $data = $response->json();
            $products = $data['GetItemInfosResult'] ?? [];

            Log::info('محصولات از RainSale دریافت شد', [
                'license_id' => $this->licenseId,
                'requested_count' => count($barcodes),
                'received_count' => count($products)
            ]);

            return $products;

        } catch (\Exception $e) {
            Log::error('خطا در درخواست RainSale API: ' . $e->getMessage(), [
                'license_id' => $this->licenseId,
                'barcodes' => $barcodes
            ]);
            return [];
        }
    }

    /**
     * به‌روزرسانی محصولات در WooCommerce
     */
    private function updateWooCommerceProducts($rainProducts, $license, $userSettings, $wooApiKey)
    {
        try {
            $woocommerce = new Client(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                [
                    'version' => 'wc/v3',
                    'verify_ssl' => false,
                    'timeout' => 15 // timeout کوتاه
                ]
            );

            // دریافت محصولات WooCommerce
            $wooProducts = $this->getWooProductsByBarcodes($woocommerce, $this->barcodes);

            if (empty($wooProducts)) {
                Log::info('هیچ محصول WooCommerce ای یافت نشد', [
                    'license_id' => $this->licenseId,
                    'barcodes' => $this->barcodes
                ]);
                return;
            }

            $productsToUpdate = [];
            foreach ($rainProducts as $rainProduct) {
                $wooProduct = collect($wooProducts)->first(function ($product) use ($rainProduct) {
                    return $product['barcode'] === $rainProduct["Barcode"];
                });

                if ($wooProduct) {
                    $productsToUpdate[] = $this->prepareProductData([
                        'barcode' => $rainProduct["Barcode"],
                        'unique_id' => $rainProduct["ItemID"],
                        'name' => $rainProduct["Name"],
                        'regular_price' => $rainProduct["Price"],
                        'stock_quantity' => $rainProduct["CurrentUnitCount"],
                        'product_id' => $wooProduct['product_id'],
                        'variation_id' => $wooProduct['variation_id']
                    ], $userSettings);
                }
            }

            if (!empty($productsToUpdate)) {
                $this->performWooCommerceUpdate($woocommerce, $productsToUpdate);
            }

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی WooCommerce: ' . $e->getMessage(), [
                'license_id' => $this->licenseId
            ]);
        }
    }

    /**
     * دریافت محصولات WooCommerce بر اساس barcodes
     */
    private function getWooProductsByBarcodes($woocommerce, $barcodes)
    {
        try {
            $response = $woocommerce->get('products/unique');

            if (!isset($response->success) || !$response->success || !isset($response->data)) {
                return [];
            }

            $wooProducts = [];
            foreach ($response->data as $product) {
                if (in_array($product->barcode, $barcodes)) {
                    $wooProducts[] = [
                        'barcode' => $product->barcode,
                        'product_id' => $product->product_id,
                        'variation_id' => $product->variation_id
                    ];
                }
            }

            return $wooProducts;

        } catch (\Exception $e) {
            Log::error('خطا در دریافت محصولات WooCommerce: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * آماده‌سازی داده‌های محصول
     */
    private function prepareProductData($product, $userSettings)
    {
        return [
            'id' => $product['variation_id'] ?: $product['product_id'],
            'regular_price' => (string) $product['regular_price'],
            'stock_quantity' => (int) $product['stock_quantity'],
            'meta_data' => [
                [
                    'key' => '_bim_unique_id',
                    'value' => $product['unique_id']
                ]
            ]
        ];
    }

    /**
     * انجام به‌روزرسانی در WooCommerce
     */
    private function performWooCommerceUpdate($woocommerce, $productsToUpdate)
    {
        try {
            // به‌روزرسانی یکی یکی برای جلوگیری از timeout
            foreach ($productsToUpdate as $product) {
                try {
                    $woocommerce->put('products/' . $product['id'], $product);

                    Log::info('محصول به‌روزرسانی شد', [
                        'license_id' => $this->licenseId,
                        'product_id' => $product['id']
                    ]);

                } catch (\Exception $e) {
                    Log::warning('خطا در به‌روزرسانی محصول منفرد', [
                        'license_id' => $this->licenseId,
                        'product_id' => $product['id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error('خطا در انجام به‌روزرسانی WooCommerce: ' . $e->getMessage(), [
                'license_id' => $this->licenseId
            ]);
        }
    }
}
