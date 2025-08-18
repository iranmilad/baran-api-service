<?php

namespace App\Jobs;

use App\Models\License;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

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
    public $timeout = 60; // 35 ثانیه - کوتاه و ایمن

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
        $maxExecutionTime = 60; // 60 ثانیه برای اطمینان

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
            // آماده‌سازی محصولات برای batch update
            $productsToUpdate = [];
            foreach ($rainProducts as $rainProduct) {
                // دریافت موجودی از دیتابیس محلی به جای RainSale API
                $itemId = $rainProduct["ItemID"];
                $localStockQuantity = 0;

                $localProduct = Product::where('item_id', $itemId)
                    ->where('license_id', $this->licenseId)
                    ->first();

                if ($localProduct) {
                    $localStockQuantity = (int)$localProduct->total_count;

                    Log::info('موجودی از دیتابیس محلی دریافت شد (ProcessSingleProductBatch)', [
                        'license_id' => $this->licenseId,
                        'item_id' => $itemId,
                        'local_stock_quantity' => $localStockQuantity,
                        'barcode' => $rainProduct["Barcode"]
                    ]);
                } else {
                    Log::warning('محصول در دیتابیس محلی یافت نشد، موجودی صفر تنظیم شد (ProcessSingleProductBatch)', [
                        'license_id' => $this->licenseId,
                        'item_id' => $itemId,
                        'barcode' => $rainProduct["Barcode"]
                    ]);
                }

                $productData = $this->prepareProductDataForBatchUpdate([
                    'unique_id' => $rainProduct["ItemID"],
                    'barcode' => $rainProduct["Barcode"],
                    'name' => $rainProduct["Name"],
                    'regular_price' => $rainProduct["Price"], // قیمت از RainSale
                    'stock_quantity' => $localStockQuantity, // موجودی از دیتابیس محلی
                ], $userSettings);

                if (!empty($productData)) {
                    $productsToUpdate[] = $productData;
                }
            }

            if (!empty($productsToUpdate)) {
                $this->performBatchUpdate($productsToUpdate, $license, $wooApiKey);
            }

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی WooCommerce: ' . $e->getMessage(), [
                'license_id' => $this->licenseId
            ]);
        }
    }

    /**
     * دریافت محصولات WooCommerce بر اساس barcodes
     * DEPRECATED: این متد دیگر استفاده نمی‌شود چون از batch update استفاده می‌کنیم
     */
    /*
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
    */

    /**
     * آماده‌سازی داده‌های محصول برای batch update
     */
    private function prepareProductDataForBatchUpdate($product, $userSettings)
    {
        $data = [
            'unique_id' => $product['unique_id']
        ];

        // بررسی تنظیمات کاربر برای قیمت
        if ($userSettings->enable_price_update && !empty($product['regular_price'])) {
            $data['regular_price'] = (string) $product['regular_price'];

            Log::info('قیمت محصول برای batch update آماده شد', [
                'license_id' => $this->licenseId,
                'unique_id' => $product['unique_id'],
                'regular_price' => $product['regular_price']
            ]);
        }

        // بررسی تنظیمات کاربر برای موجودی
        if ($userSettings->enable_stock_update) {
            $stockQuantity = (int) $product['stock_quantity'];
            $data['stock_quantity'] = $stockQuantity;
            $data['manage_stock'] = true;
            $data['stock_status'] = $stockQuantity > 0 ? 'instock' : 'outofstock';

            Log::info('موجودی محصول برای batch update آماده شد', [
                'license_id' => $this->licenseId,
                'unique_id' => $product['unique_id'],
                'stock_quantity' => $stockQuantity
            ]);
        }

        // بررسی تنظیمات کاربر برای نام محصول
        if ($userSettings->enable_name_update && !empty($product['name'])) {
            $data['name'] = $product['name'];

            Log::info('نام محصول برای batch update آماده شد', [
                'license_id' => $this->licenseId,
                'unique_id' => $product['unique_id'],
                'name' => $product['name']
            ]);
        }

        return $data;
    }

    /**
     * آماده‌سازی داده‌های محصول
     * DEPRECATED: این متد دیگر استفاده نمی‌شود چون از batch update استفاده می‌کنیم
     */
    /*
    private function prepareProductData($product, $userSettings)
    {
        $data = [
            'id' => $product['variation_id'] ?: $product['product_id'],
            'product_id' => $product['product_id'],
            'variation_id' => $product['variation_id'],
            'is_variation' => !empty($product['variation_id']),
            'meta_data' => [
                [
                    'key' => '_bim_unique_id',
                    'value' => $product['unique_id']
                ]
            ]
        ];

        // بررسی تنظیمات کاربر برای قیمت
        if ($userSettings->enable_price_update) {
            $data['regular_price'] = (string) $product['regular_price'];

            Log::info('قیمت محصول به‌روزرسانی می‌شود', [
                'license_id' => $this->licenseId,
                'product_id' => $product['product_id'],
                'variation_id' => $product['variation_id'] ?? null,
                'regular_price' => $product['regular_price']
            ]);
        }

        // بررسی تنظیمات کاربر برای موجودی
        if ($userSettings->enable_stock_update) {
            $data['stock_quantity'] = (int) $product['stock_quantity'];
            $data['manage_stock'] = true;
            $data['stock_status'] = (int) $product['stock_quantity'] > 0 ? 'instock' : 'outofstock';

            Log::info('موجودی محصول به‌روزرسانی می‌شود', [
                'license_id' => $this->licenseId,
                'product_id' => $product['product_id'],
                'variation_id' => $product['variation_id'] ?? null,
                'stock_quantity' => $product['stock_quantity']
            ]);
        }

        // بررسی تنظیمات کاربر برای نام محصول
        if ($userSettings->enable_name_update && !empty($product['name'])) {
            $data['name'] = $product['name'];

            Log::info('نام محصول به‌روزرسانی می‌شود', [
                'license_id' => $this->licenseId,
                'product_id' => $product['product_id'],
                'variation_id' => $product['variation_id'] ?? null,
                'name' => $product['name']
            ]);
        }

        return $data;
    }
    */

    /**
     * انجام batch update در WooCommerce
     */
    private function performBatchUpdate($productsToUpdate, $license, $wooApiKey)
    {
        try {
            Log::info('شروع batch update در WooCommerce', [
                'license_id' => $this->licenseId,
                'products_count' => count($productsToUpdate),
                'url' => $license->website_url . '/wp-json/wc/v3/products/unique/batch/update'
            ]);

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30,
                'connect_timeout' => 10
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth(
                $wooApiKey->api_key,
                $wooApiKey->api_secret
            )->put($license->website_url . '/wp-json/wc/v3/products/unique/batch/update', [
                'products' => $productsToUpdate
            ]);

            if ($response->successful()) {
                Log::info('Batch update با موفقیت انجام شد', [
                    'license_id' => $this->licenseId,
                    'products_updated' => count($productsToUpdate),
                    'response_status' => $response->status()
                ]);
            } else {
                Log::error('خطا در batch update', [
                    'license_id' => $this->licenseId,
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'products_count' => count($productsToUpdate)
                ]);
            }

        } catch (\Exception $e) {
            Log::error('خطا در انجام batch update: ' . $e->getMessage(), [
                'license_id' => $this->licenseId,
                'products_count' => count($productsToUpdate),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * انجام به‌روزرسانی در WooCommerce
     * DEPRECATED: این متد دیگر استفاده نمی‌شود چون از batch update استفاده می‌کنیم
     */
    /*
    private function performWooCommerceUpdate($woocommerce, $productsToUpdate)
    {
        try {
            // به‌روزرسانی یکی یکی برای جلوگیری از timeout
            foreach ($productsToUpdate as $product) {
                try {
                    // تشخیص نوع محصول و استفاده از endpoint مناسب
                    if ($product['is_variation'] && !empty($product['variation_id']) && !empty($product['product_id'])) {
                        // واریانت محصول - استفاده از endpoint واریانت
                        $endpoint = 'products/' . $product['product_id'] . '/variations/' . $product['variation_id'];

                        // حذف فیلدهای اضافی که برای endpoint واریانت ضروری نیست
                        $updateData = [
                            'regular_price' => $product['regular_price'],
                            'stock_quantity' => $product['stock_quantity'],
                            'meta_data' => $product['meta_data']
                        ];

                        Log::info('به‌روزرسانی واریانت محصول', [
                            'license_id' => $this->licenseId,
                            'product_id' => $product['product_id'],
                            'variation_id' => $product['variation_id'],
                            'endpoint' => $endpoint
                        ]);
                    } else {
                        // محصول اصلی - استفاده از endpoint معمولی
                        $endpoint = 'products/' . $product['id'];

                        $updateData = [
                            'regular_price' => $product['regular_price'],
                            'stock_quantity' => $product['stock_quantity'],
                            'meta_data' => $product['meta_data']
                        ];

                        Log::info('به‌روزرسانی محصول اصلی', [
                            'license_id' => $this->licenseId,
                            'product_id' => $product['id'],
                            'endpoint' => $endpoint
                        ]);
                    }

                    $response = $woocommerce->put($endpoint, $updateData);

                    Log::info('محصول با موفقیت به‌روزرسانی شد', [
                        'license_id' => $this->licenseId,
                        'product_id' => $product['id'],
                        'is_variation' => $product['is_variation'],
                        'endpoint' => $endpoint
                    ]);

                } catch (\Exception $e) {
                    Log::warning('خطا در به‌روزرسانی محصول منفرد', [
                        'license_id' => $this->licenseId,
                        'product_id' => $product['id'],
                        'is_variation' => $product['is_variation'] ?? false,
                        'variation_id' => $product['variation_id'] ?? null,
                        'parent_product_id' => $product['product_id'] ?? null,
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
    */
}
