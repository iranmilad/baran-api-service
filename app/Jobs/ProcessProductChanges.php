<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\License;
use App\Models\UserSetting;
use App\Models\WooCommerceApiKey;

class ProcessProductChanges implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3; // تعداد تلاش‌های مجدد
    public $timeout = 120; // زمان‌بندی اجرا (ثانیه)
    public $maxExceptions = 3; // حداکثر تعداد خطاها
    public $backoff = [30, 60, 120]; // زمان انتظار بین تلاش‌های مجدد (ثانیه)

    protected $changes;
    protected $license_id;
    public function __construct(array $changes, $license_id)
    {
        $this->changes = $changes;
        $this->license_id = $license_id;
        $this->onQueue('products'); // نام صف
        Log::info('ProcessProductChanges job created');

    }

    public function handle()
    {
        try {
            DB::beginTransaction();

            $now = now();
            $productsToCreate = [];
            $productsToUpdate = [];
            $variantsToCreate = [];
            $variantsToDelete = [];
            $updateIds = [];

            // جمع‌آوری تمام بارکدها برای یک کوئری
            $allBarcodes = collect($this->changes)
                ->pluck('product.Barcode')
                ->filter()
                ->values()
                ->toArray();

            // دریافت تمام محصولات موجود در یک کوئری
            $existingProducts = Product::whereIn('barcode', $allBarcodes)
                ->get()
                ->keyBy('barcode');

            foreach ($this->changes as $change) {
                $productData = $change['product'];
                $barcode = $productData['Barcode'];
                $changeType = $change['change_type'];

                if (empty($barcode) || empty($productData['ItemName'])) {
                    Log::warning('داده‌های ضروری محصول وجود ندارد', [
                        'barcode' => $barcode ?? null,
                        'item_name' => $productData['ItemName'] ?? null
                    ]);
                    continue;
                }

                $productData = [
                    'barcode' => $barcode,
                    'item_name' => $productData['ItemName'],
                    'item_id' => $productData['ItemId'] ?? null,
                    'price_amount' => (int)($productData['PriceAmount'] ?? 0),
                    'price_after_discount' => (int)($productData['PriceAfterDiscount'] ?? 0),
                    'total_count' => (int)($productData['TotalCount'] ?? 0),
                    'stock_id' => $productData['StockID'] ?? null,
                    'department_name' => $productData['DepartmentName'] ?? null,
                    'is_variant' => !empty($productData['Attributes']),
                    'last_sync_at' => $now,
                    'license_id' => $this->license_id,
                    'updated_at' => $now,
                    'created_at' => $now
                ];

                if ($changeType === 'insert') {
                    // بررسی وجود محصول قبل از اضافه کردن به لیست insert
                    if (!$existingProducts->has($barcode)) {
                        $productsToCreate[] = $productData;
                    } else {
                        // اگر محصول وجود داشت، به update تغییر می‌دهیم
                        $existingProduct = $existingProducts->get($barcode);
                        $productData['id'] = $existingProduct->id;
                        $productsToUpdate[] = $productData;
                        $updateIds[] = $existingProduct->id;

                        if (!empty($productData['Attributes'])) {
                            $variantsToDelete[] = $existingProduct->id;
                        }
                    }
                } else {
                    $existingProduct = $existingProducts->get($barcode);
                    if ($existingProduct) {
                        $productData['id'] = $existingProduct->id;
                        $productsToUpdate[] = $productData;
                        $updateIds[] = $existingProduct->id;

                        if (!empty($productData['Attributes'])) {
                            $variantsToDelete[] = $existingProduct->id;
                        }
                    } else {
                        // اگر محصول برای به‌روزرسانی یافت نشد، به insert تغییر می‌دهیم
                        Log::info("محصول با بارکد {$barcode} برای به‌روزرسانی یافت نشد، به عنوان محصول جدید درج می‌شود");
                        $productsToCreate[] = $productData;
                    }
                }

                // آماده‌سازی داده‌های واریانت
                if (!empty($productData['Attributes'])) {
                    foreach ($productData['Attributes'] as $variant) {
                        if (empty($variant['Barcode']) || empty($variant['ItemName'])) {
                            continue;
                        }

                        $variantsToCreate[] = [
                            'barcode' => $variant['Barcode'],
                            'item_name' => $variant['ItemName'],
                            'price_amount' => (int)($variant['PriceAmount'] ?? 0),
                            'price_after_discount' => (int)($variant['PriceAfterDiscount'] ?? 0),
                            'total_count' => (int)($variant['TotalCount'] ?? 0),
                            'stock_id' => $variant['StockID'] ?? null,
                            'parent_id' => $changeType === 'update' ? $existingProduct->id : null,
                            'is_variant' => true,
                            'last_sync_at' => $now,
                            'license_id' => $this->license_id,
                            'created_at' => $now,
                            'updated_at' => $now
                        ];
                    }
                }
            }

            try {
                // حذف واریانت‌های قدیمی در یک عملیات
                if (!empty($variantsToDelete)) {
                    Product::whereIn('parent_id', $variantsToDelete)->delete();
                    Log::info('واریانت‌های قدیمی حذف شدند', [
                        'count' => count($variantsToDelete)
                    ]);
                }

                // به‌روزرسانی محصولات موجود در یک عملیات
                if (!empty($productsToUpdate)) {
                    $updateQuery = "UPDATE products SET ";
                    $updateFields = [];
                    $updateValues = [];

                    // ساخت بخش SET کوئری
                    $fields = ['barcode', 'item_name', 'item_id', 'price_amount', 'price_after_discount',
                             'total_count', 'stock_id', 'is_variant', 'last_sync_at',
                             'license_id', 'updated_at', 'created_at'];

                    foreach ($fields as $field) {
                        $updateFields[] = "`$field` = ?";
                    }

                    $updateQuery .= implode(", ", $updateFields);

                    // اضافه کردن شرط WHERE
                    $updateQuery .= " WHERE id = ?";

                    // اجرای کوئری برای هر محصول
                    foreach ($productsToUpdate as $product) {
                        $values = [
                            $product['barcode'],
                            $product['item_name'],
                            $product['item_id'],
                            $product['price_amount'],
                            $product['price_after_discount'],
                            $product['total_count'],
                            $product['stock_id'],
                            $product['is_variant'],
                            $product['last_sync_at'],
                            $product['license_id'],
                            $product['updated_at'],
                            $product['created_at'],
                            $product['id'] // برای شرط WHERE
                        ];

                        DB::update($updateQuery, $values);
                    }

                    Log::info('محصولات با موفقیت به‌روزرسانی شدند', [
                        'count' => count($productsToUpdate)
                    ]);

                    // ارسال به صف ووکامرس برای به‌روزرسانی
                    $this->dispatchWooCommerceJobs($productsToUpdate, 'update');
                }

                // ایجاد محصولات جدید در یک عملیات
                if (!empty($productsToCreate)) {
                    try {
                        Product::insert($productsToCreate);
                        Log::info('محصولات جدید با موفقیت ایجاد شدند', [
                            'count' => count($productsToCreate)
                        ]);

                        // ارسال به صف ووکامرس برای درج
                        $this->dispatchWooCommerceJobs($productsToCreate, 'insert');

                    } catch (\Exception $e) {
                        // اگر خطای duplicate key رخ داد، محصولات را یکی یکی درج می‌کنیم
                        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                            Log::warning('خطای duplicate key در درج دسته‌ای، در حال درج تک به تک...');
                            foreach ($productsToCreate as $product) {
                                try {
                                    $createdProduct = Product::updateOrCreate(
                                        [
                                            'barcode' => $product['barcode'],
                                            'item_id' => $product['item_id']
                                        ],
                                        $product
                                    );
                                    // ارسال به صف ووکامرس برای درج/به‌روزرسانی
                                    $this->dispatchWooCommerceJob($createdProduct, 'upsert');
                                } catch (\Exception $innerE) {
                                    Log::error('خطا در درج محصول: ' . $innerE->getMessage(), [
                                        'barcode' => $product['barcode'],
                                        'item_id' => $product['item_id']
                                    ]);
                                }
                            }
                        } else {
                            throw $e;
                        }
                    }
                }

                // ایجاد واریانت‌های جدید در یک عملیات
                if (!empty($variantsToCreate)) {
                    try {
                        Product::insert($variantsToCreate);
                        Log::info('واریانت‌های جدید با موفقیت ایجاد شدند', [
                            'count' => count($variantsToCreate)
                        ]);
                    } catch (\Exception $e) {
                        // اگر خطای duplicate key رخ داد، واریانت‌ها را یکی یکی درج می‌کنیم
                        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                            Log::warning('خطای duplicate key در درج دسته‌ای واریانت‌ها، در حال درج تک به تک...');
                            foreach ($variantsToCreate as $variant) {
                                try {
                                    Product::updateOrCreate(
                                        [
                                            'barcode' => $variant['barcode'],
                                            'item_id' => $variant['item_id']
                                        ],
                                        $variant
                                    );
                                } catch (\Exception $innerE) {
                                    Log::error('خطا در درج واریانت: ' . $innerE->getMessage(), [
                                        'barcode' => $variant['barcode'],
                                        'item_id' => $variant['item_id']
                                    ]);
                                }
                            }
                        } else {
                            throw $e;
                        }
                    }
                }

                DB::commit();
                Log::info('تمام عملیات با موفقیت انجام شد');

            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('خطا در عملیات دیتابیس: ' . $e->getMessage());
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('خطا در پردازش تغییرات محصول: ' . $e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('خطا در پردازش صف تغییرات محصولات: ' . $exception->getMessage());
    }

    /**
     * ارسال محصولات به صف ووکامرس
     *
     * @param array $products
     * @param string $operation نوع عملیات (insert, update, upsert)
     * @return void
     */
    protected function dispatchWooCommerceJobs(array $products, string $operation)
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
            if (!$this->shouldSyncOperation($userSettings, $operation)) {
                Log::info('همگام‌سازی برای این عملیات غیرفعال است', [
                    'operation' => $operation,
                    'license_id' => $this->license_id
                ]);
                return;
            }

            // در متد dispatchWooCommerceJobs، قبل از ارسال محصولات به ووکامرس:
            // دریافت دسته‌بندی‌های ووکامرس فقط یک بار
            $categories = [];
            try {
                $license = License::with(['userSetting', 'woocommerceApiKey'])->find($this->license_id);
                if ($license && $license->woocommerceApiKey) {
                    $wooApiKey = $license->woocommerceApiKey;
                    $response = \Illuminate\Support\Facades\Http::withHeaders([
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ])->withBasicAuth(
                        $wooApiKey->api_key,
                        $wooApiKey->api_secret
                    )->get($license->website_url . '/wp-json/wc/v3/products/categories');
                    if ($response->successful()) {
                        $categories = collect($response->json())->keyBy('name');
                    }
                }
            } catch (\Exception $e) {
                Log::error('خطا در دریافت دسته‌بندی‌های ووکامرس: ' . $e->getMessage());
            }
            // سپس هنگام آماده‌سازی داده محصول برای ووکامرس:
            // اگر department_name وجود داشت و در دسته‌بندی‌ها بود، category_id را اضافه کن
            foreach ($products as &$product) {
                if (!empty($product['department_name']) && isset($categories[$product['department_name']])) {
                    $product['category_id'] = $categories[$product['department_name']]['id'];
                }
            }

            // تغییر اندازه دسته به 10 محصول
            $batchSize = 10;
            $chunks = array_chunk($products, $batchSize);

            foreach ($chunks as $index => $chunk) {
                // لاگ کردن بارکدهای هر دسته
                $chunkBarcodes = collect($chunk)->pluck('barcode')->toArray();
                Log::info('ارسال دسته به صف ووکامرس:', [
                    'batch_number' => $index + 1,
                    'total_batches' => count($chunks),
                    'unique_ids' => $chunkBarcodes,
                    'count' => count($chunkBarcodes),
                    'operation' => $operation,
                    'license_id' => $this->license_id
                ]);

                // آماده‌سازی داده‌ها برای ارسال به API
                $preparedProducts = collect($chunk)->map(function ($item) use ($userSettings, $operation) {
                    if (empty($item['barcode'])) {
                        Log::warning('فیلد barcode برای محصول وجود ندارد', [
                            'item' => $item
                        ]);
                        return null;
                    }

                    $data = [
                        'unique_id' => $item['item_id'],
                        'sku' => $item['barcode'],
                        'item_id' => $item['item_id'],
                        'status' => 'draft',
                        'barcode' => $item['barcode']
                    ];

                    // For inserts, name is always required by WooCommerce API
                    // For updates, we respect the enable_name_update setting
                    if ($operation === 'insert' || $userSettings->enable_name_update) {
                        $data['name'] = $item['name'] ?? $item['item_name'];

                        // Log name value for debugging
                        Log::info('Setting product name for ' . $operation, [
                            'operation' => $operation,
                            'name_value' => $data['name'],
                            'enable_name_update' => $userSettings->enable_name_update,
                            'barcode' => $item['barcode']
                        ]);
                    }

                    if ($userSettings->enable_price_update) {
                        $data['regular_price'] = (string)$item['price_amount'];
                        if ($item['price_after_discount'] > 0) {
                            $data['sale_price'] = (string)$item['price_after_discount'];
                        }
                    }

                    if ($userSettings->enable_stock_update) {
                        $data['stock_quantity'] = (int)$item['total_count'];
                        $data['stock_status'] = $item['total_count'] > 0 ? 'instock' : 'outofstock';
                        $data['manage_stock'] = true;
                    }

                    return $data;
                })->filter()->toArray();

                // ارسال به صف مناسب بر اساس نوع عملیات
                if ($operation === 'insert') {
                    BulkInsertWooCommerceProducts::dispatch($preparedProducts, $this->license_id, $batchSize)
                        ->onQueue('woocommerce-insert')
                        ->delay(now()->addSeconds($index * 15));
                } else {
                    BulkUpdateWooCommerceProducts::dispatch($preparedProducts, $this->license_id, $batchSize)
                        ->onQueue('woocommerce-update')
                        ->delay(now()->addSeconds($index * 15));
                }
            }

            Log::info('محصولات با موفقیت به صف ووکامرس اضافه شدند', [
                'operation' => $operation,
                'total_products' => count($products),
                'total_batches' => count($chunks),
                'batch_size' => $batchSize,
                'license_id' => $this->license_id,
                'all_barcodes' => collect($products)->pluck('barcode')->toArray()
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در ارسال به صف ووکامرس: ' . $e->getMessage(), [
                'operation' => $operation,
                'license_id' => $this->license_id
            ]);
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
     * ارسال یک محصول به صف ووکامرس
     *
     * @param Product $product
     * @param string $operation
     * @return void
     */
    protected function dispatchWooCommerceJob($product, string $operation)
    {
        try {
            // بررسی وجود و اعتبار لایسنس
            $license = License::with(['userSetting', 'woocommerceApiKey'])->find($this->license_id);

            if (!$license || !$license->isActive() || !$license->userSetting || !$license->woocommerceApiKey) {
                Log::info('لایسنس یا تنظیمات مربوطه یافت نشد', [
                    'license_id' => $this->license_id
                ]);
                return;
            }

            // بررسی فعال بودن همگام‌سازی
            if (!$this->shouldSyncOperation($license->userSetting, $operation)) {
                return;
            }

            UpdateWooCommerceProducts::dispatch($product)
                ->onQueue('woocommerce')
                ->delay(now()->addSeconds(2));

            Log::info('محصول به صف ووکامرس اضافه شد', [
                'barcode' => $product['barcode'],
                'operation' => $operation,
                'license_id' => $this->license_id,
                'item_name' => $product['item_name'],
                'price_amount' => $product['price_amount'],
                'total_count' => $product['total_count']
            ]);
        } catch (\Exception $e) {
            Log::error('خطا در ارسال محصول به صف ووکامرس: ' . $e->getMessage(), [
                'barcode' => $product['barcode'],
                'operation' => $operation,
                'license_id' => $this->license_id
            ]);
        }
    }
}
