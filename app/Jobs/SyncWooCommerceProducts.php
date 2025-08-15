<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\License;
use App\Models\UserSetting;
use App\Models\WooCommerceApiKey;

class SyncWooCommerceProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120;
    public $maxExceptions = 3;
    public $backoff = [30, 60, 120];

    protected $products;
    protected $license_id;
    protected $operation;

    /**
     * Create a new job instance.
     *
     * @param array $products
     * @param int $license_id
     * @param string $operation نوع عملیات (insert, update, upsert)
     * @return void
     */
    public function __construct(array $products, $license_id, string $operation)
    {
        $this->products = $products;
        $this->license_id = $license_id;
        $this->operation = $operation;
        $this->onQueue('woocommerce-sync');
        Log::info('SyncWooCommerceProducts job created', [
            'products_count' => count($products),
            'operation' => $operation
        ]);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            // دریافت لایسنس و تنظیمات مربوطه
            $license = License::with(['userSetting', 'woocommerceApiKey'])->find($this->license_id);

            if (!$license || !$license->isActive()) {
                Log::info('لایسنس معتبر نیست یا منقضی شده است', [
                    'license_id' => $this->license_id
                ]);
                return;
            }

            $userSettings = $license->userSetting;
            $wooApiKey = $license->woocommerceApiKey;

            if (!$userSettings || !$wooApiKey) {
                Log::info('تنظیمات کاربر یا کلید API ووکامرس یافت نشد', [
                    'license_id' => $this->license_id
                ]);
                return;
            }

            // بررسی فعال بودن همگام‌سازی برای عملیات مورد نظر
            if (!$this->shouldSyncOperation($userSettings, $this->operation)) {
                Log::info('همگام‌سازی برای این عملیات غیرفعال است', [
                    'operation' => $this->operation,
                    'license_id' => $this->license_id
                ]);
                return;
            }

            // دریافت دسته‌بندی‌های ووکامرس فقط یک بار
            $categories = $this->fetchWooCommerceCategories($license, $wooApiKey);

            // آماده‌سازی داده‌ها برای ارسال به API
            $preparedProducts = $this->prepareProductsData($userSettings, $categories);

            // تغییر اندازه دسته به 10 محصول
            $batchSize = 10;
            $chunks = array_chunk($preparedProducts, $batchSize);

            foreach ($chunks as $index => $chunk) {
                // لاگ کردن بارکدهای هر دسته
                $chunkBarcodes = collect($chunk)->pluck('sku')->toArray();
                Log::info('ارسال دسته به صف ووکامرس:', [
                    'batch_number' => $index + 1,
                    'total_batches' => count($chunks),
                    'unique_ids' => $chunkBarcodes,
                    'count' => count($chunkBarcodes),
                    'operation' => $this->operation,
                    'license_id' => $this->license_id
                ]);

                // ارسال به صف مناسب بر اساس نوع عملیات
                if ($this->operation === 'insert') {
                    BulkInsertWooCommerceProducts::dispatch($chunk, $this->license_id, $batchSize)
                        ->onQueue('woocommerce-insert')
                        ->delay(now()->addSeconds($index * 15));
                } else {
                    BulkUpdateWooCommerceProducts::dispatch($chunk, $this->license_id, $batchSize)
                        ->onQueue('woocommerce-update')
                        ->delay(now()->addSeconds($index * 15));
                }
            }

            Log::info('محصولات با موفقیت به صف ووکامرس اضافه شدند', [
                'operation' => $this->operation,
                'total_products' => count($this->products),
                'total_batches' => count($chunks),
                'batch_size' => $batchSize,
                'license_id' => $this->license_id
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در ارسال به صف ووکامرس: ' . $e->getMessage(), [
                'operation' => $this->operation,
                'license_id' => $this->license_id
            ]);
            throw $e;
        }
    }

    /**
     * بررسی اینکه آیا عملیات مورد نظر باید همگام‌سازی شود
     *
     * @param UserSetting $settings
     * @param string $operation
     * @return bool
     */
    protected function shouldSyncOperation(UserSetting $settings, string $operation): bool
    {
        switch ($operation) {
            case 'insert':
                return $settings->enable_new_product;
            case 'update':
                return $settings->enable_price_update ||
                       $settings->enable_stock_update ||
                       $settings->enable_name_update;
            case 'upsert':
                return $settings->enable_new_product ||
                       $settings->enable_price_update ||
                       $settings->enable_stock_update ||
                       $settings->enable_name_update;
            default:
                return false;
        }
    }

    /**
     * دریافت دسته‌بندی‌های ووکامرس
     *
     * @param License $license
     * @param WooCommerceApiKey $wooApiKey
     * @return array
     */
    protected function fetchWooCommerceCategories(License $license, WooCommerceApiKey $wooApiKey): array
    {
        $categories = [];
        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->withBasicAuth(
                $wooApiKey->api_key,
                $wooApiKey->api_secret
            )->get($license->website_url . '/wp-json/wc/v3/products/categories');

            if ($response->successful()) {
                $categories = collect($response->json())->keyBy('name')->toArray();
            }
        } catch (\Exception $e) {
            Log::error('خطا در دریافت دسته‌بندی‌های ووکامرس: ' . $e->getMessage());
        }

        return $categories;
    }

    /**
     * آماده‌سازی داده‌های محصولات برای ارسال به API
     *
     * @param UserSetting $userSettings
     * @param array $categories
     * @return array
     */
    protected function prepareProductsData(UserSetting $userSettings, array $categories): array
    {
        $preparedProducts = collect($this->products)->map(function ($item) use ($userSettings, $categories) {
            if (empty($item['barcode'])) {
                Log::warning('فیلد barcode برای محصول وجود ندارد', [
                    'item' => $item
                ]);
                return null;
            }

            $data = [
                'unique_id' => $item['item_id'] ?? $item['ItemId'] ?? null,
                'sku' => $item['barcode'] ?? $item['Barcode'] ?? '',
                'item_id' => $item['item_id'] ?? $item['ItemId'] ?? null,
                'status' => 'draft',
                'barcode' => $item['barcode'] ?? $item['Barcode'] ?? '',
                'is_variant' => $item['is_variant'] ?? false,
                'parent_id' => (!empty($item['parent_id']) && trim($item['parent_id']) !== '') ? $item['parent_id'] : null
            ];

            // Add brand information if available
            if (!empty($item['brand_id'])) {
                $data['brand_id'] = $item['brand_id'];
            }

            if (!empty($item['brand'])) {
                $data['brand'] = $item['brand'];
            }

            // For inserts, name is always required by WooCommerce API
            // For updates, we respect the enable_name_update setting
            if ($this->operation === 'insert' || $userSettings->enable_name_update) {
                $data['name'] = $item['name'] ?? $item['item_name'] ?? $item['ItemName'] ?? '';
            }

            // برای درج، همیشه قیمت و موجودی را شامل کن
            if ($this->operation === 'insert' || $userSettings->enable_price_update) {
                $data['regular_price'] = (string)($item['price_amount'] ?? $item['PriceAmount'] ?? 0);
                if (!empty($item['price_after_discount'] ?? $item['PriceAfterDiscount']) && ($item['price_after_discount'] ?? $item['PriceAfterDiscount']) > 0) {
                    $data['sale_price'] = (string)($item['price_after_discount'] ?? $item['PriceAfterDiscount']);
                }
            }

            if ($this->operation === 'insert' || $userSettings->enable_stock_update) {
                $data['stock_quantity'] = (int)($item['total_count'] ?? $item['TotalCount'] ?? 0);
                $data['stock_status'] = ($item['total_count'] ?? $item['TotalCount'] ?? 0) > 0 ? 'instock' : 'outofstock';
                $data['manage_stock'] = true;
            }

            // اگر department_name وجود داشت و در دسته‌بندی‌ها بود، category_id را اضافه کن
            if (!empty($item['department_name'] ?? $item['DepartmentName']) && isset($categories[$item['department_name'] ?? $item['DepartmentName']])) {
                $data['category_id'] = $categories[$item['department_name'] ?? $item['DepartmentName']]['id'];
            }

            // تعیین نوع محصول بر اساس is_variant و parent_id
            $isVariant = $item['is_variant'] ?? false;
            $parentId = $data['parent_id']; // استفاده از parent_id که از قبل پردازش شده

            if ($isVariant) {
                if ($parentId) {
                    // این یک واریانت است
                    $data['type'] = 'variation';
                    Log::info('نوع محصول: variation', [
                        'barcode' => $data['barcode'],
                        'parent_id' => $parentId
                    ]);
                } else {
                    // این یک محصول مادر است
                    $data['type'] = 'variable';
                    Log::info('نوع محصول: variable (parent)', [
                        'barcode' => $data['barcode']
                    ]);
                }
            } else {
                // محصول ساده
                $data['type'] = 'simple';
                Log::info('نوع محصول: simple', [
                    'barcode' => $data['barcode']
                ]);
            }

            Log::info('آماده‌سازی داده محصول برای ووکامرس', [
                'barcode' => $data['barcode'],
                'type' => $data['type'],
                'is_variant' => $isVariant,
                'parent_id' => $parentId,
                'name' => $data['name'] ?? 'no_name',
                'operation' => $this->operation
            ]);

            return $data;
        })->filter()->values()->toArray();

        // مرتب‌سازی محصولات: محصولات مادر (variable) ابتدا، سپس محصولات ساده، و در آخر واریانت‌ها
        usort($preparedProducts, function($a, $b) {
            $aIsVariant = $a['is_variant'] ?? false;
            $bIsVariant = $b['is_variant'] ?? false;

            // بررسی parent_id با در نظر گیری string خالی
            $aParentId = !empty($a['parent_id']) && trim($a['parent_id']) !== '';
            $bParentId = !empty($b['parent_id']) && trim($b['parent_id']) !== '';

            // اگر هیچکدام variant نیستند، ترتیب فرقی ندارد
            if (!$aIsVariant && !$bIsVariant) {
                return 0;
            }

            // اگر a محصول مادر است (variant=true, parent_id=empty) و b نیست
            if ($aIsVariant && !$aParentId && (!$bIsVariant || $bParentId)) {
                return -1; // a اول باشد
            }

            // اگر b محصول مادر است (variant=true, parent_id=empty) و a نیست
            if ($bIsVariant && !$bParentId && (!$aIsVariant || $aParentId)) {
                return 1; // b اول باشد
            }

            // اگر a واریانت است (variant=true, parent_id=filled) و b محصول مادر یا ساده
            if ($aIsVariant && $aParentId && (!$bIsVariant || !$bParentId)) {
                return 1; // a آخر باشد
            }

            // اگر b واریانت است (variant=true, parent_id=filled) و a محصول مادر یا ساده
            if ($bIsVariant && $bParentId && (!$aIsVariant || !$aParentId)) {
                return -1; // b آخر باشد
            }

            return 0; // در غیر این صورت ترتیب فرقی ندارد
        });

        return $preparedProducts;
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error('خطا در همگام‌سازی محصولات با ووکامرس: ' . $exception->getMessage(), [
            'operation' => $this->operation,
            'license_id' => $this->license_id
        ]);
    }
}
