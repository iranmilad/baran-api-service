<?php

namespace App\Jobs\WordPress;

use App\Models\Product;
use App\Jobs\ProcessImplicitProductInserts;
use App\Jobs\RetryFailedProductInserts;
use App\Jobs\ProcessOrphanProductVariants;
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
            $parentProducts = [];
            $childProducts = [];
            $implicitInserts = [];
            $failedInserts = [];
            $orphanVariants = [];

            // جمع‌آوری تمام بارکدها برای یک کوئری
            $allBarcodes = collect($this->changes)
                ->pluck('product.Barcode')
                ->filter()
                ->values()
                ->toArray();

            // جمع‌آوری ترکیب‌های item_id + stock_id برای چک کردن یونیک
            $itemStockCombinations = collect($this->changes)
                ->map(function($change) {
                    return [
                        'item_id' => $change['product']['ItemId'] ?? null,
                        'stock_id' => $change['product']['StockID'] ?? null,
                        'barcode' => $change['product']['Barcode'] ?? null,
                    ];
                })
                ->filter(function($combo) {
                    return !empty($combo['item_id']) && !empty($combo['stock_id']);
                })
                ->values()
                ->toArray();

            // دریافت تمام محصولات موجود بر اساس ترکیب item_id + stock_id + license_id
            $existingProducts = collect();

            if (!empty($itemStockCombinations)) {
                $query = Product::where('license_id', $this->license_id)
                    ->select(['id', 'barcode', 'item_id', 'stock_id', 'is_variant', 'parent_id'])
                    ->where(function($q) use ($itemStockCombinations) {
                        foreach ($itemStockCombinations as $combo) {
                            $q->orWhere(function($subQ) use ($combo) {
                                $subQ->where('item_id', $combo['item_id'])
                                     ->where('stock_id', $combo['stock_id']);
                            });
                        }
                    });

                $existingProducts = $query->get();

                // ایجاد key ترکیبی برای دسترسی سریع
                $existingProducts = $existingProducts->keyBy(function($product) {
                    return $product->item_id . '_' . $product->stock_id;
                });
            }

            // پردازش هر تغییر و دسته‌بندی محصولات
            $this->processChanges($now, $existingProducts, $parentProducts, $childProducts, $productsToCreate, $productsToUpdate, $variantsToCreate, $variantsToDelete, $updateIds, $implicitInserts);

            // به‌روزرسانی محصولات موجود در یک عملیات
            $processedUpdates = !empty($productsToUpdate) ? $this->updateExistingProducts($productsToUpdate) : [];

            // ایجاد محصولات جدید در یک عملیات
            $processedInserts = $this->createNewProducts($parentProducts, $childProducts, $productsToCreate);

            // ایجاد واریانت‌های جدید در یک عملیات
            $processedVariants = !empty($variantsToCreate) ? $this->createNewVariants($variantsToCreate) : [];

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
                ProcessImplicitProductInserts::dispatch($implicitInserts, $this->license_id)
                    ->onQueue('implicit-inserts');
            }

            // ارسال درج‌های ناموفق به کیو جداگانه (retry)
            if (!empty($failedInserts)) {
                RetryFailedProductInserts::dispatch($failedInserts, $this->license_id)
                    ->onQueue('failed-inserts');
            }

            // ارسال فرزندان بی‌پدر به کیو جداگانه با تأخیر 30 ثانیه‌ای
            if (!empty($orphanVariants)) {
                ProcessOrphanProductVariants::dispatch($orphanVariants, $this->license_id)
                    ->onQueue('orphan-variants')
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

                if (!empty($productData['attributes'])) {
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
        // ایجاد کلید ترکیبی برای جستجو
        $compositeKey = $productData['item_id'] . '_' . $productData['stock_id'];
        $existingProduct = $existingProducts->get($compositeKey);

        if ($changeType === 'insert') {
            if (!$existingProduct) {
                Log::info("محصول متغیر برای درج: {$barcode}, parent_id: {$productData['parent_id']}");
                $childProducts[] = $productData;
            } else {
                // محصول وجود دارد، به update تبدیل می‌شود
                Log::info("محصول متغیر با کلید ترکیبی موجود است، به update تبدیل می‌شود", [
                    'barcode' => $barcode,
                    'item_id' => $productData['item_id'],
                    'stock_id' => $productData['stock_id']
                ]);
                $productData['id'] = $existingProduct->id;
                $productsToUpdate[] = $productData;
                $updateIds[] = $existingProduct->id;
            }
        } else {
            // پردازش برای به‌روزرسانی
            if ($existingProduct) {
                $productData['id'] = $existingProduct->id;
                $productsToUpdate[] = $productData;
                $updateIds[] = $existingProduct->id;
            } else {
                // اگر محصول متغیر برای به‌روزرسانی یافت نشد، به لیست درج اضافه می‌کنیم
                Log::info("محصول متغیر با کلید ترکیبی {$compositeKey} برای به‌روزرسانی یافت نشد، به عنوان محصول جدید درج می‌شود");
                $childProducts[] = $productData;
            }
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
        // ایجاد کلید ترکیبی برای جستجو
        $compositeKey = $productData['item_id'] . '_' . $productData['stock_id'];
        $existingProduct = $existingProducts->get($compositeKey);

        if ($changeType === 'insert') {
            // بررسی وجود محصول قبل از اضافه کردن به لیست insert
            if (!$existingProduct) {
                $productsToCreate[] = $productData;
            } else {
                // اگر محصول وجود داشت، به update تغییر می‌دهیم
                Log::info("محصول با کلید ترکیبی موجود است، به update تبدیل می‌شود", [
                    'barcode' => $barcode,
                    'item_id' => $productData['item_id'],
                    'stock_id' => $productData['stock_id']
                ]);
                $productData['id'] = $existingProduct->id;
                $productsToUpdate[] = $productData;
                $updateIds[] = $existingProduct->id;
            }
        } else {
            if ($existingProduct) {
                $productData['id'] = $existingProduct->id;
                $productsToUpdate[] = $productData;
                $updateIds[] = $existingProduct->id;
            } else {
                // اگر محصول برای به‌روزرسانی یافت نشد، به insert تغییر می‌دهیم
                Log::info("محصول با کلید ترکیبی {$compositeKey} برای به‌روزرسانی یافت نشد، به عنوان محصول جدید درج می‌شود");
                $productsToCreate[] = $productData;
            }
        }
    }

    /**
     * پردازش واریانت‌های محصول
     *
     * @param array $productData
     * @param \Carbon\Carbon $now
     * @param array &$variantsToCreate
     * @return void
     */
    protected function processProductVariants($productData, $now, &$variantsToCreate)
    {
        if (!empty($productData['attributes'])) {
            foreach ($productData['attributes'] as $variant) {
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
                // اگر محصول مادر هنوز در پایگاه داده وجود ندارد، لاگ هشدار می‌گذاریم
                Log::warning("محصول مادر با item_id = {$productData['parent_id']} یافت نشد", [
                    'barcode' => $productData['barcode'],
                    'parent_item_id' => $productData['parent_id']
                ]);
            } else {
                Log::info("محصول متغیر با محصول مادر مرتبط شد", [
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
     * به‌روزرسانی محصولات موجود با استفاده از Bulk Update
     *
     * @param array $productsToUpdate
     * @return array محصولات به‌روزرسانی شده
     */
    protected function updateExistingProducts($productsToUpdate)
    {
        if (empty($productsToUpdate)) {
            return [];
        }

        // استفاده از upsert برای به‌روزرسانی گروهی بهینه
        $updateData = [];
        foreach ($productsToUpdate as $product) {
            $updateData[] = [
                'id' => $product['id'],
                'barcode' => $product['barcode'],
                'item_name' => $product['item_name'],
                'item_id' => $product['item_id'],
                'price_amount' => $product['price_amount'],
                'price_after_discount' => $product['price_after_discount'],
                'total_count' => $product['total_count'],
                'stock_id' => $product['stock_id'],
                'is_variant' => $product['is_variant'],
                'parent_id' => $product['parent_id'],
                'last_sync_at' => $product['last_sync_at'],
                'license_id' => $product['license_id'],
                'updated_at' => $product['updated_at'],
            ];
        }

        // به‌روزرسانی دسته‌ای با استفاده از CASE WHEN
        $ids = array_column($updateData, 'id');
        $cases = [];
        $params = [];

        foreach (['item_name', 'item_id', 'price_amount', 'price_after_discount', 'total_count', 'stock_id', 'is_variant', 'parent_id', 'last_sync_at', 'updated_at'] as $field) {
            $whenClauses = [];
            foreach ($updateData as $data) {
                $whenClauses[] = "WHEN ? THEN ?";
                $params[] = $data['id'];
                $params[] = $data[$field];
            }
            $cases[] = "`$field` = CASE `id` " . implode(' ', $whenClauses) . " END";
        }

        $sql = "UPDATE products SET " . implode(', ', $cases) . " WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
        $params = array_merge($params, $ids);

        DB::update($sql, $params);

        Log::info('محصولات با موفقیت به‌روزرسانی شدند', [
            'count' => count($productsToUpdate)
        ]);

        return $productsToUpdate;
    }

    /**
     * ایجاد محصولات جدید با استفاده از Bulk Insert
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

        $createdProducts = [];

        // مرحله 1: درج محصولات مادر و معمولی (بدون parent_id)
        $parentsAndRegular = array_merge($parentProducts, $productsToCreate);
        if (!empty($parentsAndRegular)) {
            // چک کردن محصولات موجود قبل از درج
            $existingKeys = DB::table('products')
                ->where('license_id', $this->license_id)
                ->whereIn(DB::raw("CONCAT(item_id, '_', stock_id)"),
                    array_map(function($p) {
                        return $p['item_id'] . '_' . $p['stock_id'];
                    }, $parentsAndRegular)
                )
                ->pluck(DB::raw("CONCAT(item_id, '_', stock_id)"))
                ->toArray();

            // فیلتر کردن محصولاتی که قبلاً وجود ندارند
            $productsToInsert = array_filter($parentsAndRegular, function($product) use ($existingKeys) {
                $key = $product['item_id'] . '_' . $product['stock_id'];
                return !in_array($key, $existingKeys);
            });

            if (!empty($productsToInsert)) {
                try {
                    DB::table('products')->insert($productsToInsert);
                    $createdProducts = array_merge($createdProducts, $productsToInsert);

                    Log::info('محصولات مادر و معمولی ایجاد شدند', [
                        'count' => count($productsToInsert),
                        'skipped' => count($parentsAndRegular) - count($productsToInsert)
                    ]);
                } catch (\Exception $e) {
                    Log::error('خطا در درج دسته‌ای محصولات مادر', [
                        'error' => $e->getMessage()
                    ]);

                    // Fallback: درج تک تک با insertOrIgnore
                    foreach ($productsToInsert as $product) {
                        try {
                            $inserted = DB::table('products')
                                ->where('item_id', $product['item_id'])
                                ->where('stock_id', $product['stock_id'])
                                ->where('license_id', $product['license_id'])
                                ->doesntExist();

                            if ($inserted) {
                                DB::table('products')->insert($product);
                                $createdProducts[] = $product;
                            }
                        } catch (\Exception $innerException) {
                            Log::error('خطا در ایجاد محصول', [
                                'barcode' => $product['barcode'],
                                'item_id' => $product['item_id'],
                                'stock_id' => $product['stock_id'],
                                'error' => $innerException->getMessage()
                            ]);
                        }
                    }
                }
            } else {
                Log::info('تمام محصولات مادر و معمولی قبلاً وجود دارند', [
                    'count' => count($parentsAndRegular)
                ]);
            }
        }

        // مرحله 2: درج محصولات متغیر (با parent_id)
        if (!empty($childProducts)) {
            // چک کردن محصولات موجود قبل از درج
            $existingKeys = DB::table('products')
                ->where('license_id', $this->license_id)
                ->whereIn(DB::raw("CONCAT(item_id, '_', stock_id)"),
                    array_map(function($p) {
                        return $p['item_id'] . '_' . $p['stock_id'];
                    }, $childProducts)
                )
                ->pluck(DB::raw("CONCAT(item_id, '_', stock_id)"))
                ->toArray();

            // فیلتر کردن محصولاتی که قبلاً وجود ندارند
            $productsToInsert = array_filter($childProducts, function($product) use ($existingKeys) {
                $key = $product['item_id'] . '_' . $product['stock_id'];
                return !in_array($key, $existingKeys);
            });

            if (!empty($productsToInsert)) {
                try {
                    DB::table('products')->insert($productsToInsert);
                    $createdProducts = array_merge($createdProducts, $productsToInsert);

                    Log::info('محصولات متغیر ایجاد شدند', [
                        'count' => count($productsToInsert),
                        'skipped' => count($childProducts) - count($productsToInsert)
                    ]);
                } catch (\Exception $e) {
                    Log::error('خطا در درج دسته‌ای محصولات متغیر', [
                        'error' => $e->getMessage()
                    ]);

                    // Fallback: درج تک تک با چک
                    foreach ($productsToInsert as $product) {
                        try {
                            $exists = DB::table('products')
                                ->where('item_id', $product['item_id'])
                                ->where('stock_id', $product['stock_id'])
                                ->where('license_id', $product['license_id'])
                                ->exists();

                            if (!$exists) {
                                DB::table('products')->insert($product);
                                $createdProducts[] = $product;
                            }
                        } catch (\Exception $innerException) {
                            Log::error('خطا در ایجاد محصول متغیر', [
                                'barcode' => $product['barcode'],
                                'item_id' => $product['item_id'],
                                'stock_id' => $product['stock_id'],
                                'error' => $innerException->getMessage()
                            ]);
                        }
                    }
                }
            } else {
                Log::info('تمام محصولات متغیر قبلاً وجود دارند', [
                    'count' => count($childProducts)
                ]);
            }
        }

        Log::info('محصولات جدید با موفقیت ایجاد شدند', [
            'total_count' => count($createdProducts),
            'parents' => count($parentProducts),
            'children' => count($childProducts),
            'regular' => count($productsToCreate)
        ]);

        return $createdProducts;
    }

    /**
     * ایجاد واریانت‌های جدید با استفاده از Bulk Insert
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

        // چک کردن واریانت‌های موجود قبل از درج
        $existingKeys = DB::table('products')
            ->where('license_id', $this->license_id)
            ->whereIn(DB::raw("CONCAT(item_id, '_', stock_id)"),
                array_map(function($v) {
                    return ($v['item_id'] ?? '') . '_' . ($v['stock_id'] ?? '');
                }, $variantsToCreate)
            )
            ->pluck(DB::raw("CONCAT(item_id, '_', stock_id)"))
            ->toArray();

        // فیلتر کردن واریانت‌هایی که قبلاً وجود ندارند
        $variantsToInsert = array_filter($variantsToCreate, function($variant) use ($existingKeys) {
            $key = ($variant['item_id'] ?? '') . '_' . ($variant['stock_id'] ?? '');
            return !in_array($key, $existingKeys);
        });

        if (!empty($variantsToInsert)) {
            try {
                // درج دسته‌ای واریانت‌ها
                DB::table('products')->insert($variantsToInsert);
                $createdVariants = $variantsToInsert;

                Log::info('واریانت‌های جدید با موفقیت ایجاد شدند', [
                    'count' => count($variantsToInsert),
                    'skipped' => count($variantsToCreate) - count($variantsToInsert)
                ]);
            } catch (\Exception $e) {
                Log::error('خطا در درج دسته‌ای واریانت‌ها', [
                    'error' => $e->getMessage()
                ]);

                // Fallback: درج تک تک در صورت خطا با چک
                foreach ($variantsToInsert as $variant) {
                    try {
                        $exists = DB::table('products')
                            ->where('item_id', $variant['item_id'])
                            ->where('stock_id', $variant['stock_id'])
                            ->where('license_id', $variant['license_id'])
                            ->exists();

                        if (!$exists) {
                            DB::table('products')->insert($variant);
                            $createdVariants[] = $variant;
                        }
                    } catch (\Exception $innerException) {
                        Log::error('خطا در ایجاد واریانت', [
                            'barcode' => $variant['barcode'],
                            'item_id' => $variant['item_id'] ?? null,
                            'stock_id' => $variant['stock_id'] ?? null,
                            'error' => $innerException->getMessage()
                        ]);
                    }
                }
            }
        } else {
            Log::info('تمام واریانت‌ها قبلاً وجود دارند', [
                'count' => count($variantsToCreate)
            ]);
        }

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
        // بررسی تیک به‌روزرسانی برای عملیات update
        if (!empty($updatedProducts)) {
            $license = \App\Models\License::with('userSetting')->find($this->license_id);
            $userSettings = $license->userSetting;

            // فقط اگر حداقل یکی از تیک‌های به‌روزرسانی فعال باشد
            if ($userSettings && (
                $userSettings->enable_price_update ||
                $userSettings->enable_stock_update ||
                $userSettings->enable_name_update
            )) {
                SyncWooCommerceProducts::dispatch($updatedProducts, $this->license_id, 'update')
                    ->onQueue('woocommerce-sync')
                    ->delay(now()->addSeconds(5));

                Log::info('محصولات به‌روزرسانی شده به صف WooCommerce ارسال شد', [
                    'count' => count($updatedProducts),
                    'license_id' => $this->license_id
                ]);
            } else {
                Log::info('به‌روزرسانی WooCommerce غیرفعال است - محصولات ارسال نشد', [
                    'count' => count($updatedProducts),
                    'license_id' => $this->license_id
                ]);
            }
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
