<?php

namespace App\Jobs\WordPress;

use App\Models\Product;
use App\Jobs\ProcessImplicitProductInserts;
use App\Jobs\RetryFailedProductInserts;
use App\Jobs\ProcessOrphanProductVariants;
use App\Jobs\WordPress\SyncWooCommerceProducts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * این کلاس مسئول پردازش تغییرات محصولات و ذخیره‌سازی آنها در دیتابیس است
 * پس از پردازش، این کلاس یک کار دیگر (SyncWooCommerceProducts) را برای همگام‌سازی با ووکامرس برنامه‌ریزی می‌کند
 *
 * توجه: این جاب مخصوص WordPress است زیرا محصولات را به WooCommerce سینک می‌کند
 */
class ProcessProductChanges implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3; // تعداد تلاش‌های مجدد
    public $timeout = 50; // کاهش زمان‌بندی اجرا به 50 ثانیه
    public $maxExceptions = 3; // حداکثر تعداد خطاها
    public $backoff = [15, 30, 60]; // کاهش زمان انتظار بین تلاش‌های مجدد (ثانیه)

    protected $changes;
    protected $license_id;

    /**
     * ایجاد یک نمونه جدید از کار
     *
     * @param array $changes تغییرات محصولات برای پردازش
     * @param int $license_id شناسه لایسنس
     * @return void
     */
    public function __construct(array $changes, $license_id)
    {
        $this->changes = $changes;
        $this->license_id = $license_id;
        $this->onQueue('products');
    }

    /**
     * اجرای کار
     *
     * @return void
     */
    public function handle()
    {
        Log::info('شروع پردازش تغییرات محصولات', [
            'changes_count' => count($this->changes),
            'license_id' => $this->license_id
        ]);

        DB::beginTransaction();

        try {
            $now = now();
            $productsToCreate = [];
            $productsToUpdate = [];
            $variantsToCreate = [];
            $variantsToDelete = [];
            $updateIds = [];
            $parentProducts = []; // لیست محصولات مادر
            $childProducts = []; // لیست محصولات متغیر
            $implicitInserts = []; // درج‌های ضمنی
            $failedInserts = []; // درج‌های ناموفق
            $orphanVariants = []; // فرزندان بی‌پدر

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

            // پردازش هر تغییر و دسته‌بندی محصولات
            $this->processChanges($now, $existingProducts, $parentProducts, $childProducts, $productsToCreate, $productsToUpdate, $variantsToCreate, $variantsToDelete, $updateIds, $implicitInserts);

            // حذف واریانت‌های قدیمی در یک عملیات
            $this->deleteOldVariants($variantsToDelete);

            // به‌روزرسانی محصولات موجود در یک عملیات
            $processedUpdates = $this->updateExistingProducts($productsToUpdate);

            // ایجاد محصولات جدید در یک عملیات
            $processedInserts = $this->createNewProducts($parentProducts, $childProducts, $productsToCreate);

            // ایجاد واریانت‌های جدید در یک عملیات
            $processedVariants = $this->createNewVariants($variantsToCreate);

            DB::commit();

            Log::info('پایان پردازش تغییرات محصولات', [
                'updated' => count($processedUpdates),
                'inserted' => count($processedInserts),
                'variants' => count($processedVariants)
            ]);

            // ارسال محصولات به صف همگام‌سازی ووکامرس
            $this->dispatchToWooCommerceSync($processedUpdates, $processedInserts, $processedVariants);

            // ارسال درج‌های ضمنی به کیو جداگانه
            if (!empty($implicitInserts)) {
                ProcessImplicitProductInserts::dispatch($implicitInserts, $this->license_id);
            }

            // ارسال درج‌های ناموفق به کیو جداگانه (retry)
            if (!empty($failedInserts)) {
                RetryFailedProductInserts::dispatch($failedInserts, $this->license_id);
            }

            // ارسال فرزندان بی‌پدر به کیو جداگانه با تأخیر 30 ثانیه‌ای
            if (!empty($orphanVariants)) {
                ProcessOrphanProductVariants::dispatch($orphanVariants, $this->license_id)
                    ->delay(now()->addSeconds(30));

                Log::info('فرزندان بی‌پدر برای پردازش بعدی ارسال شدند', [
                    'count' => count($orphanVariants),
                    'delay_seconds' => 30
                ]);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('خطا در پردازش تغییرات محصول: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * پردازش تغییرات محصولات و دسته‌بندی آنها
     *
     * @param \Carbon\Carbon $now
     * @param \Illuminate\Support\Collection $existingProducts
     * @param array &$parentProducts
     * @param array &$childProducts
     * @param array &$productsToCreate
     * @param array &$productsToUpdate
     * @param array &$variantsToCreate
     * @param array &$variantsToDelete
     * @param array &$updateIds
     * @param array &$implicitInserts
     * @return void
     */
    protected function processChanges($now, $existingProducts, &$parentProducts, &$childProducts, &$productsToCreate, &$productsToUpdate, &$variantsToCreate, &$variantsToDelete, &$updateIds, &$implicitInserts)
    {
        foreach ($this->changes as $change) {
            $productData = $change['product'];
            $changeType = $change['change_type'];

            // بررسی وجود ItemName - تنها فیلد اجباری
            if (empty($productData['ItemName'])) {
                Log::warning('نام محصول (ItemName) وجود ندارد - محصول رد می‌شود', [
                    'barcode' => $productData['Barcode'] ?? null,
                    'item_id' => $productData['ItemId'] ?? null,
                    'item_name' => $productData['ItemName'] ?? null
                ]);
                continue;
            }

            // استفاده از barcode موجود یا null
            $barcode = !empty($productData['Barcode']) ? $productData['Barcode'] : null;

            if ($barcode === null) {
                Log::info('محصول بدون barcode دریافت شد', [
                    'item_name' => $productData['ItemName'],
                    'item_id' => $productData['ItemId'] ?? null
                ]);
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
                'is_variant' => isset($productData['is_variant']) ? (bool)$productData['is_variant'] : false,
                'parent_id' => (!empty($productData['parent_id']) && trim($productData['parent_id']) !== '') ? $productData['parent_id'] : null,
                'last_sync_at' => $now,
                'license_id' => $this->license_id,
                'created_at' => $now,
                'updated_at' => $now
            ];

            // جداسازی محصولات مادر و متغیر برای مرتب‌سازی بعدی
            if ($productData['is_variant'] === true) {
                if (empty($productData['parent_id']) || trim($productData['parent_id']) === '') {
                    // این یک محصول مادر است (is_variant=true و parent_id خالی)
                    Log::info('تشخیص محصول مادر (variable product)', [
                        'barcode' => $barcode,
                        'parent_id_original' => $change['product']['parent_id'] ?? 'not_set',
                        'parent_id_processed' => $productData['parent_id']
                    ]);
                    $this->processParentProduct($productData, $barcode, $changeType, $existingProducts, $parentProducts, $productsToUpdate, $updateIds, $variantsToDelete);
                } else {
                    // این یک محصول متغیر است (is_variant=true و parent_id پر)
                    Log::info('تشخیص محصول واریانت (variation)', [
                        'barcode' => $barcode,
                        'parent_id' => $productData['parent_id']
                    ]);
                    $this->processChildProduct($productData, $barcode, $changeType, $existingProducts, $childProducts, $productsToUpdate, $updateIds);
                }
            } else {
                // محصول معمولی (is_variant=false)
                $this->processRegularProduct($productData, $barcode, $changeType, $existingProducts, $productsToCreate, $productsToUpdate, $updateIds, $variantsToDelete);
            }

            // آماده‌سازی داده‌های واریانت
            $this->processProductVariants($productData, $now, $variantsToCreate);

            // بررسی وجود محصول مادر برای محصولات متغیر
            $this->checkParentProductExistence($productData);
        }
    }

    /**
     * پردازش محصول مادر (is_variant=true و parent_id خالی)
     *
     * @param array $productData
     * @param string $barcode
     * @param string $changeType
     * @param \Illuminate\Support\Collection $existingProducts
     * @param array &$parentProducts
     * @param array &$productsToUpdate
     * @param array &$updateIds
     * @param array &$variantsToDelete
     * @return void
     */
    protected function processParentProduct($productData, $barcode, $changeType, $existingProducts, &$parentProducts, &$productsToUpdate, &$updateIds, &$variantsToDelete)
    {
        if ($changeType === 'insert') {
            Log::info("محصول مادر برای درج: {$barcode}");
            $parentProducts[] = $productData;
        } else {
            // پردازش برای به‌روزرسانی
            $existingProduct = $existingProducts->get($barcode);
            if ($existingProduct) {
                $productData['id'] = $existingProduct->id;
                $productsToUpdate[] = $productData;
                $updateIds[] = $existingProduct->id;

                if (!empty($productData['Attributes'])) {
                    $variantsToDelete[] = $existingProduct->item_id;
                }
            } else {
                // اگر محصول مادر برای به‌روزرسانی یافت نشد، به لیست درج اضافه می‌کنیم
                Log::info("محصول مادر با بارکد {$barcode} برای به‌روزرسانی یافت نشد، به عنوان محصول جدید درج می‌شود");
                $parentProducts[] = $productData;
            }
        }
    }

    /**
     * پردازش محصول متغیر (is_variant=true و parent_id پر)
     *
     * @param array $productData
     * @param string $barcode
     * @param string $changeType
     * @param \Illuminate\Support\Collection $existingProducts
     * @param array &$childProducts
     * @param array &$productsToUpdate
     * @param array &$updateIds
     * @return void
     */
    protected function processChildProduct($productData, $barcode, $changeType, $existingProducts, &$childProducts, &$productsToUpdate, &$updateIds)
    {
        if ($changeType === 'insert') {
            Log::info("محصول متغیر برای درج: {$barcode}, parent_id: {$productData['parent_id']}");
            $childProducts[] = $productData;
        } else {
            // پردازش برای به‌روزرسانی
            $existingProduct = $existingProducts->get($barcode);
            if ($existingProduct) {
                $productData['id'] = $existingProduct->id;
                $productsToUpdate[] = $productData;
                $updateIds[] = $existingProduct->id;
            } else {
                // اگر محصول متغیر برای به‌روزرسانی یافت نشد، به لیست درج اضافه می‌کنیم
                Log::info("محصول متغیر با بارکد {$barcode} برای به‌روزرسانی یافت نشد، به عنوان محصول جدید درج می‌شود");
                $childProducts[] = $productData;
            }
        }
    }

    /**
     * پردازش محصول معمولی (is_variant=false)
     *
     * @param array $productData
     * @param string $barcode
     * @param string $changeType
     * @param \Illuminate\Support\Collection $existingProducts
     * @param array &$productsToCreate
     * @param array &$productsToUpdate
     * @param array &$updateIds
     * @param array &$variantsToDelete
     * @return void
     */
    protected function processRegularProduct($productData, $barcode, $changeType, $existingProducts, &$productsToCreate, &$productsToUpdate, &$updateIds, &$variantsToDelete)
    {
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
                    $variantsToDelete[] = $existingProduct->item_id;
                }
            }
        } else {
            $existingProduct = $existingProducts->get($barcode);
            if ($existingProduct) {
                $productData['id'] = $existingProduct->id;
                $productsToUpdate[] = $productData;
                $updateIds[] = $existingProduct->id;

                if (!empty($productData['Attributes'])) {
                    $variantsToDelete[] = $existingProduct->item_id;
                }
            } else {
                // اگر محصول برای به‌روزرسانی یافت نشد، به insert تغییر می‌دهیم
                Log::info("محصول با بارکد {$barcode} برای به‌روزرسانی یافت نشد، به عنوان محصول جدید درج می‌شود");
                $productsToCreate[] = $productData;
            }
        }
    }

    /**
     * پردازش واریانت‌های محصول
     * اگر barcode نداشته باشد، null ذخیره می‌شود
     *
     * @param array $productData
     * @param \Carbon\Carbon $now
     * @param array &$variantsToCreate
     * @return void
     */
    protected function processProductVariants($productData, $now, &$variantsToCreate)
    {
        if (!empty($productData['Attributes'])) {
            foreach ($productData['Attributes'] as $variant) {
                // بررسی وجود ItemName - تنها فیلد اجباری برای واریانت
                if (empty($variant['ItemName'])) {
                    Log::warning('نام واریانت (ItemName) وجود ندارد - واریانت رد می‌شود', [
                        'barcode' => $variant['Barcode'] ?? null,
                        'parent_item_id' => $productData['item_id'] ?? null
                    ]);
                    continue;
                }

                // استفاده از barcode موجود یا null
                $variantBarcode = !empty($variant['Barcode']) ? $variant['Barcode'] : null;

                if ($variantBarcode === null) {
                    Log::info('واریانت بدون barcode دریافت شد', [
                        'variant_name' => $variant['ItemName'],
                        'parent_item_id' => $productData['item_id'] ?? null
                    ]);
                }

                $variantsToCreate[] = [
                    'barcode' => $variantBarcode,
                    'item_name' => $variant['ItemName'],
                    'price_amount' => (int)($variant['PriceAmount'] ?? 0),
                    'price_after_discount' => (int)($variant['PriceAfterDiscount'] ?? 0),
                    'total_count' => (int)($variant['TotalCount'] ?? 0),
                    'stock_id' => $variant['StockID'] ?? null,
                    'parent_id' => !empty($productData['item_id']) ? $productData['item_id'] : null,
                    'is_variant' => true,
                    'last_sync_at' => $now,
                    'license_id' => $this->license_id,
                    'created_at' => $now,
                    'updated_at' => $now
                ];
            }
        }
    }

    /**
     * بررسی وجود محصول مادر برای محصولات متغیر
     * توجه: این متد فقط برای لاگ‌گذاری است و مانع درج محصول نمی‌شود
     *
     * @param array $productData
     * @return void
     */
    protected function checkParentProductExistence($productData)
    {
        if ($productData['is_variant'] && !empty($productData['parent_id'])) {
            // بررسی وجود محصول مادر
            $parentExists = Product::where('item_id', $productData['parent_id'])
                ->where('license_id', $this->license_id)
                ->exists();

            if (!$parentExists) {
                // لاگ اطلاعاتی: محصول مادر هنوز درج نشده است
                // توجه: این مانع درج محصول فرزند نمی‌شود
                Log::info("محصول فرزند درج می‌شود (محصول مادر هنوز درج نشده)", [
                    'barcode' => $productData['barcode'],
                    'parent_item_id' => $productData['parent_id'],
                    'note' => 'محصول فرزند بدون توجه به وجود محصول مادر درج می‌شود'
                ]);
            } else {
                Log::info("محصول فرزند با محصول مادر موجود مرتبط می‌شود", [
                    'barcode' => $productData['barcode'],
                    'parent_item_id' => $productData['parent_id']
                ]);
            }
        }
    }

    /**
     * حذف واریانت‌های قدیمی
     *
     * @param array $variantsToDelete
     * @return array
     */
    protected function deleteOldVariants($variantsToDelete)
    {
        if (!empty($variantsToDelete)) {
            Product::whereIn('parent_id', $variantsToDelete)
                   ->where('is_variant', true)
                   ->delete();
            Log::info('واریانت‌های قدیمی حذف شدند', [
                'count' => count($variantsToDelete)
            ]);
        }

        return $variantsToDelete;
    }

    /**
     * به‌روزرسانی محصولات موجود
     *
     * @param array $productsToUpdate
     * @return array محصولات به‌روزرسانی شده
     */
    protected function updateExistingProducts($productsToUpdate)
    {
        if (empty($productsToUpdate)) {
            return [];
        }

        $updateQuery = "UPDATE products SET ";
        $updateFields = [];
        $fields = ['barcode', 'item_name', 'item_id', 'price_amount', 'price_after_discount',
                 'total_count', 'stock_id', 'is_variant', 'parent_id', 'last_sync_at',
                 'license_id', 'updated_at', 'created_at'];

        foreach ($fields as $field) {
            $updateFields[] = "`$field` = ?";
        }

        $updateQuery .= implode(", ", $updateFields);
        $updateQuery .= " WHERE id = ?";

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
                $product['parent_id'],
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

        return $productsToUpdate;
    }

    /**
     * ایجاد محصولات جدید
     * استفاده از updateOrCreate برای جلوگیری از خطای duplicate key
     * محصولات فرزند حتی بدون وجود محصول مادر درج می‌شوند
     *
     * @param array $parentProducts
     * @param array $childProducts
     * @param array $productsToCreate
     * @return array محصولات ایجاد شده
     */
    protected function createNewProducts($parentProducts, $childProducts, $productsToCreate)
    {
        if (empty($parentProducts) && empty($childProducts) && empty($productsToCreate)) {
            return [];
        }

        // ترکیب همه محصولات برای درج
        $allProducts = array_merge($parentProducts, $productsToCreate, $childProducts);
        $createdProducts = [];

        // مرتب‌سازی محصولات برای اطمینان از ایجاد محصولات مادر قبل از محصولات متغیر
        // توجه: این فقط بهینه‌سازی است، نه الزامی - محصولات فرزند در هر صورت درج می‌شوند
        usort($allProducts, function($a, $b) {
            // اگر یکی parent_id دارد و دیگری ندارد، آنکه parent_id ندارد اول است
            if (empty($a['parent_id']) && !empty($b['parent_id'])) return -1;
            if (!empty($a['parent_id']) && empty($b['parent_id'])) return 1;
            return 0;
        });

        // درج به صورت تک تک برای اطمینان از ترتیب صحیح و مدیریت خطاها
        foreach ($allProducts as $product) {
            try {
                // تعیین کلید یکتا برای updateOrCreate
                // اگر barcode موجود باشد از barcode استفاده می‌شود
                // در غیر این صورت از item_id استفاده می‌شود
                if (!empty($product['barcode'])) {
                    $uniqueKey = [
                        'barcode' => $product['barcode'],
                        'license_id' => $this->license_id
                    ];
                } elseif (!empty($product['item_id'])) {
                    $uniqueKey = [
                        'item_id' => $product['item_id'],
                        'license_id' => $this->license_id
                    ];
                } else {
                    // اگر نه barcode نه item_id وجود ندارد، از item_name استفاده می‌کنیم
                    $uniqueKey = [
                        'item_name' => $product['item_name'],
                        'license_id' => $this->license_id
                    ];
                }

                // استفاده از updateOrCreate برای جلوگیری از خطای duplicate key
                // حتی اگر محصول مادر وجود نداشته باشد، محصول فرزند درج می‌شود
                $createdProduct = Product::updateOrCreate($uniqueKey, $product);

                $createdProducts[] = $product;

                Log::info('محصول ایجاد شد', [
                    'barcode' => $product['barcode'] ?? null,
                    'item_id' => $product['item_id'] ?? null,
                    'is_variant' => $product['is_variant'],
                    'parent_id' => $product['parent_id'] ?? null,
                    'has_parent' => !empty($product['parent_id']),
                    'unique_key' => array_keys($uniqueKey)[0]
                ]);
            } catch (\Exception $innerException) {
                Log::error('خطا در ایجاد محصول', [
                    'barcode' => $product['barcode'] ?? null,
                    'item_id' => $product['item_id'] ?? null,
                    'parent_id' => $product['parent_id'] ?? null,
                    'error' => $innerException->getMessage(),
                    'trace' => $innerException->getTraceAsString()
                ]);
            }
        }

        Log::info('محصولات جدید با موفقیت ایجاد شدند', [
            'count' => count($allProducts),
            'parents' => count($parentProducts),
            'children' => count($childProducts),
            'regular' => count($productsToCreate)
        ]);

        return $createdProducts;
    }

    /**
     * ایجاد واریانت‌های جدید
     *
     * @param array $variantsToCreate
     * @return array واریانت‌های ایجاد شده
     */
    protected function createNewVariants($variantsToCreate)
    {
        if (empty($variantsToCreate)) {
            return [];
        }

        $createdVariants = [];

        // مرتب‌سازی واریانت‌ها بر اساس parent_id
        usort($variantsToCreate, function($a, $b) {
            // اگر یکی parent_id دارد و دیگری ندارد، آنکه parent_id ندارد اول است
            if (empty($a['parent_id']) && !empty($b['parent_id'])) return -1;
            if (!empty($a['parent_id']) && empty($b['parent_id'])) return 1;
            return 0;
        });

        // درج به صورت تک تک برای اطمینان از ترتیب صحیح
        foreach ($variantsToCreate as $variant) {
            try {
                // تعیین کلید یکتا برای updateOrCreate
                // اگر barcode موجود باشد از barcode استفاده می‌شود
                // در غیر این صورت از ترکیب parent_id و item_name استفاده می‌شود
                if (!empty($variant['barcode'])) {
                    $uniqueKey = [
                        'barcode' => $variant['barcode'],
                        'license_id' => $this->license_id
                    ];
                } else {
                    $uniqueKey = [
                        'parent_id' => $variant['parent_id'] ?? null,
                        'item_name' => $variant['item_name'],
                        'license_id' => $this->license_id
                    ];
                }

                $createdVariant = Product::updateOrCreate($uniqueKey, $variant);

                $createdVariants[] = $variant;

                Log::info('واریانت ایجاد شد', [
                    'barcode' => $variant['barcode'] ?? null,
                    'item_name' => $variant['item_name'],
                    'parent_id' => $variant['parent_id'] ?? null,
                    'unique_key' => array_keys($uniqueKey)[0]
                ]);
            } catch (\Exception $innerException) {
                Log::error('خطا در ایجاد واریانت', [
                    'barcode' => $variant['barcode'] ?? null,
                    'item_name' => $variant['item_name'] ?? null,
                    'error' => $innerException->getMessage()
                ]);
            }
        }

        Log::info('واریانت‌های جدید با موفقیت ایجاد شدند', [
            'count' => count($variantsToCreate)
        ]);

        return $createdVariants;
    }

    /**
     * ارسال محصولات به صف همگام‌سازی ووکامرس
     *
     * @param array $updatedProducts
     * @param array $newProducts
     * @param array $newVariants
     * @return void
     */
    protected function dispatchToWooCommerceSync($updatedProducts, $newProducts, $newVariants)
    {
        if (!empty($updatedProducts)) {
            SyncWooCommerceProducts::dispatch($updatedProducts, $this->license_id, 'update')
                ->onQueue('woocommerce-sync')
                ->delay(now()->addSeconds(5));
        }

        if (!empty($newProducts) || !empty($newVariants)) {
            $allNewProducts = array_merge($newProducts, $newVariants);

            SyncWooCommerceProducts::dispatch($allNewProducts, $this->license_id, 'insert')
                ->onQueue('woocommerce-sync')
                ->delay(now()->addSeconds(10));
        }
    }

    /**
     * رسیدگی به خطاهای کار
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error('خطا در پردازش صف تغییرات محصولات: ' . $exception->getMessage());
    }
}
