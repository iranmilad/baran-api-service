<?php

namespace App\Jobs\WordPress;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\License;
use App\Models\Product;
use App\Models\UserSetting;
use App\Models\WooCommerceApiKey;
use App\Traits\WordPress\WordPressMasterTrait;
use App\Jobs\WordPress\BulkInsertWooCommerceProducts;
use App\Jobs\WordPress\BulkUpdateWooCommerceProducts;

class SyncWooCommerceProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WordPressMasterTrait;

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

            // دریافت دسته‌بندی‌های ووکامرس فقط برای عملیات insert
            $categories = [];
            if ($this->operation === 'insert') {
                $categories = $this->fetchWooCommerceCategories($license, $wooApiKey);
                Log::info('دسته‌بندی‌های ووکامرس برای insert دریافت شد', [
                    'categories_count' => count($categories),
                    'license_id' => $this->license_id
                ]);
            } else {
                Log::info('دریافت دسته‌بندی برای عملیات update رد شد', [
                    'operation' => $this->operation,
                    'license_id' => $this->license_id
                ]);
            }

            // آماده‌سازی داده‌ها برای ارسال به API
            $preparedProducts = $this->prepareProductsData($userSettings, $categories);

            // تغییر اندازه دسته به 50 محصول
            $batchSize = 50;
            $chunks = array_chunk($preparedProducts, $batchSize);

            foreach ($chunks as $index => $chunk) {
                // ارسال به صف مناسب بر اساس نوع عملیات
                if ($this->operation === 'insert') {
                    BulkInsertWooCommerceProducts::dispatch($chunk, $this->license_id, $batchSize)
                        ->onQueue('woocommerce-insert')
                        ->delay(now()->addSeconds($index * 5));
                } else {
                    BulkUpdateWooCommerceProducts::dispatch($chunk, $this->license_id, $batchSize)
                        ->onQueue('woocommerce-update')
                        ->delay(now()->addSeconds($index * 5));
                }
            }

            Log::info('محصولات با موفقیت به صف ووکامرس اضافه شدند', [
                'operation' => $this->operation,
                'total_products' => count($this->products),
                'total_batches' => count($chunks),
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
            $result = $this->getWooCommerceProductCategories(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret
            );

            if ($result['success']) {
                $categories = collect($result['data'])->keyBy('name')->toArray();
            } else {
                Log::error('خطا در دریافت دسته‌بندی‌های ووکامرس: ' . $result['message']);
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
                return null;
            }

            $isVariant = $item['is_variant'] ?? false;
            $parentId = (!empty($item['parent_id']) && trim($item['parent_id']) !== '') ? $item['parent_id'] : null;

            // REMOVED: Status updates are not allowed during regular updates
            // تعیین status بر اساس نوع محصول - فقط برای insert
            // $status = 'draft'; // پیش‌فرض همیشه draft
            // if ($isVariant && !empty($parentId)) {
            //     // فقط واریانت‌هایی که والد دارند منتشر می‌شوند
            //     $status = 'publish';
            // }
            // کالای مادر و محصولات ساده همیشه draft می‌مانند

            $data = [
                'unique_id' => $item['ItemID'] ?? $item['item_id'] ?? $item['ItemId'] ?? null,
                'sku' => $item['Barcode'] ?? $item['barcode'] ?? '',
                'item_id' => $item['ItemID'] ?? $item['item_id'] ?? $item['ItemId'] ?? null,
                'barcode' => $item['Barcode'] ?? $item['barcode'] ?? '',
                'is_variant' => $isVariant,
                'parent_id' => $parentId
            ];

            // Status فقط برای insert عملیات تنظیم شود
            if ($this->operation === 'insert') {
                $status = 'draft'; // پیش‌فرض همیشه draft
                if ($isVariant && !empty($parentId)) {
                    // فقط واریانت‌هایی که والد دارند منتشر می‌شوند
                    $status = 'publish';
                }
                $data['status'] = $status;
            }

            // Add brand information if available
            // REMOVED: Brand updates are not allowed during regular updates
            // if (!empty($item['brand_id'])) {
            //     $data['brand_id'] = $item['brand_id'];
            // }

            // if (!empty($item['brand'])) {
            //     $data['brand'] = $item['brand'];
            // }

            // For inserts, name is always required by WooCommerce API
            // For updates, we respect the enable_name_update setting
            if ($this->operation === 'insert' || $userSettings->enable_name_update) {
                $data['name'] = $item['Name'] ?? $item['name'] ?? $item['item_name'] ?? $item['ItemName'] ?? '';
            }

            // برای درج، همیشه قیمت و موجودی را شامل کن
            if ($this->operation === 'insert' || $userSettings->enable_price_update) {
                // استفاده از ساختار صحیح RainSale API
                $regularPrice = (float)($item['Price'] ?? $item['price'] ?? $item['price_amount'] ?? $item['PriceAmount'] ?? 0);
                $data['regular_price'] = (string)$regularPrice;

                // بررسی PriceAfterDiscount برای تنظیم قیمت تخفیف‌دار
                $priceAfterDiscount = (float)($item['PriceAfterDiscount'] ?? $item['price_after_discount'] ?? 0);

                if ($priceAfterDiscount > 0 && $priceAfterDiscount < $regularPrice) {
                    // اگر PriceAfterDiscount وجود دارد و کمتر از قیمت اصلی است، از آن به عنوان قیمت تخفیف استفاده می‌شود
                    $data['sale_price'] = (string)$priceAfterDiscount;
                } else {
                    // محاسبه قیمت با تخفیف از CurrentDiscount (برای پشتیبانی از روش قدیمی)
                    $currentDiscount = (float)($item['CurrentDiscount'] ?? $item['current_discount'] ?? 0);
                    if ($currentDiscount > 0 && $regularPrice > 0) {
                        $salePrice = $regularPrice - ($regularPrice * $currentDiscount / 100);
                        $data['sale_price'] = (string)$salePrice;
                    } else {
                        // اگر هیچ تخفیفی وجود ندارد، sale_price را خالی می‌کنیم تا از WooCommerce حذف شود
                        $data['sale_price'] = '';
                    }
                }
            }

            if ($this->operation === 'insert' || $userSettings->enable_stock_update) {
                // دریافت موجودی از مدل Product محلی به جای API باران
                $itemId = $item['ItemID'] ?? $item['item_id'] ?? $item['ItemId'] ?? null;
                $stockQuantity = 0;

                if ($itemId) {
                    $localProduct = Product::where('item_id', $itemId)
                        ->where('license_id', $this->license_id)
                        ->first();

                    if ($localProduct) {
                        $stockQuantity = (int)$localProduct->total_count;
                    }
                }

                $data['stock_quantity'] = $stockQuantity;
                $data['stock_status'] = $stockQuantity > 0 ? 'instock' : 'outofstock';
                $data['manage_stock'] = true;
            }

            // اضافه کردن دسته‌بندی فقط برای عملیات insert
            if ($this->operation === 'insert' && !empty($categories)) {
                $departmentName = $item['department_name'] ?? $item['DepartmentName'] ?? null;
                if (!empty($departmentName) && isset($categories[$departmentName])) {
                    $data['category_id'] = $categories[$departmentName]['id'];
                    Log::info('دسته‌بندی برای محصول تنظیم شد', [
                        'barcode' => $data['barcode'],
                        'department_name' => $departmentName,
                        'category_id' => $data['category_id']
                    ]);
                }
            }

            // تعیین نوع محصول بر اساس is_variant و parent_id
            // REMOVED: Product type updates are not allowed during regular updates
            // $isVariant = $item['is_variant'] ?? false;
            // $parentId = $data['parent_id']; // استفاده از parent_id که از قبل پردازش شده

            // REMOVED: Product type classification is not allowed during regular updates
            // if ($isVariant) {
            //     if ($parentId) {
            //         // این یک واریانت است
            //         $data['type'] = 'variation';
            //         Log::info('نوع محصول: variation', [
            //             'barcode' => $data['barcode'],
            //             'parent_id' => $parentId
            //         ]);
            //     } else {
            //         // این یک محصول مادر است
            //         $data['type'] = 'variable';
            //         Log::info('نوع محصول: variable (parent)', [
            //             'barcode' => $data['barcode']
            //         ]);
            //     }
            // } else {
            //     // محصول ساده
            //     $data['type'] = 'simple';
            //     Log::info('نوع محصول: simple', [
            //         'barcode' => $data['barcode']
            //     ]);
            // }

            Log::info('آماده‌سازی داده محصول برای ووکامرس', [
                'barcode' => $data['barcode'],
                'name' => $data['name'] ?? 'no_name',
                'operation' => $this->operation,
                'fields_updated' => array_keys($data)
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
