<?php

namespace App\Jobs\WordPress;

use App\Models\License;
use App\Models\Product;
use App\Traits\WordPress\WordPressMasterTrait;
use App\Traits\PriceUnitConverter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ProcessSingleProductBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WordPressMasterTrait, PriceUnitConverter;

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

            Log::info('شروع فیلتر کردن محصولات', [
                'license_id' => $this->licenseId,
                'all_items_count' => count($allItems),
                'default_warehouse_code' => $defaultWarehouseCode,
                'warehouse_filter_enabled' => !empty($defaultWarehouseCode)
            ]);

            // گروه‌بندی محصولات بر اساس itemID
            $groupedItems = [];
            $warehouseBreakdown = []; // برای لاگ

            foreach ($allItems as $item) {
                $itemId = $item['itemID'];
                $stockId = $item['stockID'] ?? null;

                // اگر default_warehouse_code تنظیم شده، فقط آیتم‌های مربوط به انبارهای کنفیگ شده را در نظر بگیر
                if (!empty($defaultWarehouseCode)) {
                    // تجزیه JSON یا comma-separated warehouse codes
                    $warehouseCodes = [];
                    if (is_string($defaultWarehouseCode)) {
                        if (substr(trim($defaultWarehouseCode), 0, 1) === '[') {
                            $decoded = json_decode($defaultWarehouseCode, true);
                            $warehouseCodes = is_array($decoded) ? $decoded : [];
                        } else {
                            $warehouseCodes = array_filter(array_map('trim', preg_split('/[,;]/', $defaultWarehouseCode)));
                        }
                    }

                    // بررسی اینکه آیا این انبار در لیست کنفیگ شده انبارها است
                    if (!empty($warehouseCodes) && !in_array($stockId, $warehouseCodes)) {
                        continue; // این آیتم را نادیده بگیر
                    }
                }

                // اگر آیتم جدید است، آن را اضافه کن
                if (!isset($groupedItems[$itemId])) {
                    $groupedItems[$itemId] = [
                        'ItemID' => $item['itemID'],
                        'Barcode' => $item['barcode'],
                        'Name' => $item['itemName'],
                        'Price' => $item['salePrice'],
                        'CurrentDiscount' => $item['currentDiscount'],
                        'StockQuantity' => 0, // شروع با صفر
                        'StockID' => $stockId, // اولین stockID
                        'WarehouseBreakdown' => [] // برای لاگ
                    ];
                    $warehouseBreakdown[$itemId] = [];
                }

                // موجودی را جمع کن
                $quantity = (float)$item['stockQuantity'];
                $groupedItems[$itemId]['StockQuantity'] += $quantity;
                $groupedItems[$itemId]['WarehouseBreakdown'][] = [
                    'stock_id' => $stockId,
                    'quantity' => $quantity
                ];
            }

            // تبدیل گروه‌بندی شده به آرایه و لاگ تفکیک انبارها
            $filteredProducts = [];
            foreach ($groupedItems as $itemId => $item) {
                Log::info('تجمیع موجودی انبار برای bulk-sync', [
                    'item_id' => $itemId,
                    'barcode' => $item['Barcode'],
                    'warehouses' => $item['WarehouseBreakdown'],
                    'total_quantity' => $item['StockQuantity'],
                    'license_id' => $this->licenseId,
                    'timestamp' => now()->toDateTimeString()
                ]);

                // حذف WarehouseBreakdown از محصول برای ارسال به WooCommerce
                unset($item['WarehouseBreakdown']);
                $filteredProducts[] = $item;
            }

            Log::info('نتیجه گروه‌بندی محصولات', [
                'license_id' => $this->licenseId,
                'original_count' => count($allItems),
                'grouped_count' => count($filteredProducts),
                'default_warehouse_code' => $defaultWarehouseCode,
                'warehouse_filter_enabled' => !empty($defaultWarehouseCode)
            ]);

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
                $salePrice = null;

                if (isset($baranProduct["CurrentDiscount"]) && $baranProduct["CurrentDiscount"] > 0) {
                    $discountAmount = ($baranProduct["Price"] * $baranProduct["CurrentDiscount"]) / 100;
                    $finalPrice = $baranProduct["Price"] - $discountAmount;
                    $salePrice = $finalPrice;
                }

                // تبدیل قیمت به واحد ووکامرس
                $convertedPrice = $this->convertPriceUnit(
                    $finalPrice,
                    $userSettings->rain_sale_price_unit,
                    $userSettings->woocommerce_price_unit
                );

                $convertedSalePrice = null;
                if ($salePrice !== null) {
                    $convertedSalePrice = $this->convertPriceUnit(
                        $salePrice,
                        $userSettings->rain_sale_price_unit,
                        $userSettings->woocommerce_price_unit
                    );
                }

                $productData = $this->prepareProductDataForBatchUpdate([
                    'unique_id' => $baranProduct["ItemID"],
                    'barcode' => $baranProduct["Barcode"],
                    'name' => $baranProduct["Name"],
                    'regular_price' => $convertedPrice,
                    'sale_price' => $convertedSalePrice,
                    'stock_quantity' => $baranProduct["StockQuantity"],
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

            // اگر sale_price داریم، آن را هم اضافه کن
            if (isset($product['sale_price']) && $product['sale_price'] !== null && $product['sale_price'] > 0) {
                $data['sale_price'] = (string) $product['sale_price'];
            } else {
                // اگر تخفیفی نیست، sale_price را خالی کن
                $data['sale_price'] = '';
            }
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

            Log::info('شروع batch update در WooCommerce', [
                'license_id' => $this->licenseId,
                'products_count' => count($productsToUpdate)
            ]);

            // تست اتصال WooCommerce قبل از batch update
            $connectionTest = $this->validateWooCommerceApiCredentials(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret
            );

            if (!$connectionTest['success']) {
                Log::error('اتصال به WooCommerce ناموفق قبل از batch update', [
                    'license_id' => $this->licenseId,
                    'error' => $connectionTest['message']
                ]);
                return;
            }

            // آماده‌سازی داده‌های batch update
            $batchData = [
                'products' => $productsToUpdate
            ];

            // استفاده از trait برای batch update
            $result = $this->updateWooCommerceBatchProductsByUniqueId(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                $batchData
            );

            if ($result['success']) {
                Log::info('Batch update با موفقیت انجام شد', [
                    'license_id' => $this->licenseId,
                    'products_updated' => count($productsToUpdate),
                    'message' => $result['message']
                ]);
            } else {
                Log::error('خطا در batch update', [
                    'license_id' => $this->licenseId,
                    'error' => $result['message'],
                    'products_count' => count($productsToUpdate)
                ]);
            }

        } catch (\Exception $e) {
            Log::error('فرآیند متوقف شد - خطا در انجام batch update: ' . $e->getMessage(), [
                'license_id' => $this->licenseId,
                'products_count' => count($productsToUpdate),
                'exception_line' => $e->getLine(),
                'exception_file' => $e->getFile(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
