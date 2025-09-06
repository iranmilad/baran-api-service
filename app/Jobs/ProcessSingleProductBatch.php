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
    protected $uniqueIds;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 2;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public $backoff = [10, 30];

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId, $uniqueIds)
    {
        $this->licenseId = $licenseId;
        $this->uniqueIds = $uniqueIds;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        log::info('شروع پردازش batch محصولات', [
            'license_id' => $this->licenseId,
            'unique_ids_count' => count($this->uniqueIds)
        ]);
        try {
            $license = License::with(['userSetting', 'woocommerceApiKey', 'user'])->find($this->licenseId);
            if (!$license || !$license->isActive()) {
                Log::warning('فرآیند متوقف شد - لایسنس نامعتبر یا غیرفعال', [
                    'license_id' => $this->licenseId,
                    'license_found' => !is_null($license),
                    'license_active' => $license ? $license->isActive() : false
                ]);
                return;
            }

            $userSettings = $license->userSetting;
            $wooApiKey = $license->woocommerceApiKey;
            $user = $license->user;

            if (!$userSettings || !$wooApiKey || !$user) {
                Log::warning('فرآیند متوقف شد - تنظیمات کاربر، کلید API یا کاربر یافت نشد', [
                    'license_id' => $this->licenseId,
                    'user_settings_found' => !is_null($userSettings),
                    'woo_api_key_found' => !is_null($wooApiKey),
                    'user_found' => !is_null($user)
                ]);
                return;
            }

            // گام 1: دریافت اطلاعات محصولات از Baran
            $baranProducts = $this->getRainProducts($this->uniqueIds, $user);

            if (empty($baranProducts)) {
                Log::error('هیچ محصولی از API باران دریافت نشد', [
                    'license_id' => $this->licenseId,
                    'unique_ids_count' => count($this->uniqueIds),
                    'unique_ids' => $this->uniqueIds
                ]);
                return;
            }

            // گام 2: به‌روزرسانی در WooCommerce
            $this->updateWooCommerceProducts($baranProducts, $license, $userSettings, $wooApiKey);

        } catch (\Exception $e) {
            Log::error('خطا در پردازش batch محصولات: ' . $e->getMessage(), [
                'license_id' => $this->licenseId
            ]);
            throw $e;
        }
    }

    /**
     * دریافت اطلاعات محصولات از Baran API
     */
    private function getRainProducts($uniqueIds, $user)
    {
        try {
            if (!$user->warehouse_api_url || !$user->warehouse_api_username || !$user->warehouse_api_password) {
                Log::warning('فرآیند متوقف شد - اطلاعات API انبار کامل نیست', [
                    'license_id' => $this->licenseId,
                    'warehouse_api_url_exists' => !empty($user->warehouse_api_url),
                    'warehouse_api_username_exists' => !empty($user->warehouse_api_username),
                    'warehouse_api_password_exists' => !empty($user->warehouse_api_password)
                ]);
                return [];
            }

            // دریافت لایسنس با تنظیمات برای دسترسی به default_warehouse_code
            $license = License::with('userSetting')->find($this->licenseId);
            $defaultWarehouseCode = $license && $license->userSetting ? $license->userSetting->default_warehouse_code : '';

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user->warehouse_api_username . ':' . $user->warehouse_api_password)
            ])->post($user->warehouse_api_url . '/api/itemlist/GetItemsByIds', $uniqueIds);

            if (!$response->successful()) {
                Log::warning('فرآیند متوقف شد - درخواست API باران ناموفق', [
                    'license_id' => $this->licenseId,
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'unique_ids_count' => count($uniqueIds)
                ]);

                // اگر خطای 500 بود، لاگ کن و ادامه نده
                if ($response->status() == 500) {
                    Log::error('خطای 500 در درخواست Baran API', [
                        'license_id' => $this->licenseId,
                        'status' => $response->status()
                    ]);
                }
                return [];
            }

            $allItems = $response->json() ?? [];

            if (empty($allItems)) {
                Log::warning('فرآیند متوقف شد - هیچ محصولی از API باران بازگردانده نشد', [
                    'license_id' => $this->licenseId,
                    'unique_ids_count' => count($uniqueIds),
                    'response_structure' => is_array($allItems) ? 'array' : gettype($allItems)
                ]);
                return [];
            }
            $filteredProducts = [];

            foreach ($allItems as $item) {
                // اگر default_warehouse_code تنظیم شده، فقط آیتم‌های مربوط به آن انبار را نگه دار
                if (!empty($defaultWarehouseCode)) {
                    if (isset($item['stockID']) && $item['stockID'] === $defaultWarehouseCode) {
                        $filteredProducts[] = [
                            'ItemID' => $item['itemID'],
                            'Barcode' => $item['barcode'],
                            'Name' => $item['itemName'],
                            'Price' => $item['salePrice'],
                            'CurrentDiscount' => $item['currentDiscount'],
                            'StockQuantity' => $item['stockQuantity'],
                            'StockID' => $item['stockID']
                        ];
                    }
                } else {
                    // اگر default_warehouse_code تنظیم نشده، همه آیتم‌ها را نگه دار
                    $filteredProducts[] = [
                        'ItemID' => $item['itemID'],
                        'Barcode' => $item['barcode'],
                        'Name' => $item['itemName'],
                        'Price' => $item['salePrice'],
                        'CurrentDiscount' => $item['currentDiscount'],
                        'StockQuantity' => $item['stockQuantity'],
                        'StockID' => $item['stockID']
                    ];
                }
            }

            return $filteredProducts;

        } catch (\Exception $e) {
            Log::error('خطا در درخواست Baran API: ' . $e->getMessage(), [
                'license_id' => $this->licenseId
            ]);
            return [];
        }
    }

    /**
     * به‌روزرسانی محصولات در WooCommerce
     */
    private function updateWooCommerceProducts($baranProducts, $license, $userSettings, $wooApiKey)
    {
        try {
            if (empty($baranProducts)) {
                Log::warning('فرآیند متوقف شد - هیچ محصولی از باران برای به‌روزرسانی دریافت نشد', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            // آماده‌سازی محصولات برای batch update
            $productsToUpdate = [];
            foreach ($baranProducts as $baranProduct) {
                // محاسبه قیمت نهایی با تخفیف (currentDiscount درصد تخفیف است)
                $finalPrice = $baranProduct["Price"];
                if (isset($baranProduct["CurrentDiscount"]) && $baranProduct["CurrentDiscount"] > 0) {
                    $discountAmount = ($baranProduct["Price"] * $baranProduct["CurrentDiscount"]) / 100;
                    $finalPrice = $baranProduct["Price"] - $discountAmount;
                }

                $productData = $this->prepareProductDataForBatchUpdate([
                    'unique_id' => $baranProduct["ItemID"],
                    'barcode' => $baranProduct["Barcode"],
                    'name' => $baranProduct["Name"],
                    'regular_price' => $finalPrice, // قیمت نهایی (با تخفیف در صورت وجود)
                    'stock_quantity' => $baranProduct["StockQuantity"], // موجودی از Baran API
                ], $userSettings);

                if (!empty($productData)) {
                    $productsToUpdate[] = $productData;
                }
            }

            if (empty($productsToUpdate)) {
                Log::warning('فرآیند متوقف شد - هیچ محصولی برای به‌روزرسانی در ووکامرس آماده نشد', [
                    'license_id' => $this->licenseId,
                    'baran_products_count' => count($baranProducts)
                ]);
                return;
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
        }

        // بررسی تنظیمات کاربر برای موجودی
        if ($userSettings->enable_stock_update) {
            $stockQuantity = (int) $product['stock_quantity'];
            $data['stock_quantity'] = $stockQuantity;
            $data['manage_stock'] = true;
            $data['stock_status'] = $stockQuantity > 0 ? 'instock' : 'outofstock';
        }

        // بررسی تنظیمات کاربر برای نام محصول
        if ($userSettings->enable_name_update && !empty($product['name'])) {
            $data['name'] = $product['name'];
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
            if (empty($productsToUpdate)) {
                Log::warning('فرآیند متوقف شد - هیچ محصولی برای batch update وجود ندارد', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

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

            if (!$response->successful()) {
                Log::warning('فرآیند متوقف شد - batch update ناموفق', [
                    'license_id' => $this->licenseId,
                    'status_code' => $response->status(),
                    'products_count' => count($productsToUpdate),
                    'response_body' => $response->body()
                ]);
                return;
            }

            Log::info('batch update موفقیت‌آمیز', [
                'license_id' => $this->licenseId,
                'products_count' => count($productsToUpdate)
            ]);

        } catch (\Exception $e) {
            Log::error('فرآیند متوقف شد - خطا در انجام batch update: ' . $e->getMessage(), [
                'license_id' => $this->licenseId,
                'products_count' => count($productsToUpdate),
                'exception_line' => $e->getLine(),
                'exception_file' => $e->getFile()
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
