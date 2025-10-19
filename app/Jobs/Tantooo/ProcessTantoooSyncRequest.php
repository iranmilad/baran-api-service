<?php

namespace App\Jobs\Tantooo;

use App\Models\License;
use App\Models\UserSetting;
use App\Models\Product;
use App\Traits\Tantooo\TantoooApiTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTantoooSyncRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TantoooApiTrait;

    protected $licenseId;
    protected $syncId;
    protected $insertProducts;
    protected $updateProducts;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 600; // 10 دقیقه

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId, $syncId, $insertProducts = [], $updateProducts = [])
    {
        $this->licenseId = $licenseId;
        $this->syncId = $syncId;
        $this->insertProducts = $insertProducts ?? [];
        $this->updateProducts = $updateProducts ?? [];
        $this->onQueue('tantooo-sync');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);

        try {
            Log::info('شروع پردازش درخواست همگام‌سازی Tantooo', [
                'license_id' => $this->licenseId,
                'sync_id' => $this->syncId,
                'insert_count' => count($this->insertProducts),
                'update_count' => count($this->updateProducts)
            ]);

            // بررسی لایسنس
            $license = License::with(['user', 'userSetting'])->find($this->licenseId);
            if (!$license || !$license->isActive()) {
                $this->logError('لایسنس معتبر نیست یا منقضی شده است');
                return;
            }

            // بررسی تنظیمات کاربر
            $userSetting = UserSetting::where('license_id', $license->id)->first();
            if (!$userSetting) {
                $this->logError('تنظیمات کاربر یافت نشد');
                return;
            }

            // بررسی تنظیمات API Tantooo
            $tantoooSettings = $this->getTantoooApiSettings($license);
            if (!$tantoooSettings) {
                $this->logError('تنظیمات API Tantooo یافت نشد');
                return;
            }

            // تفکیک محصولات insert و update
            // محصولات جدید بدون فیلتر موجودی قبول شوند
            // محصولات برای آپدیت بدون فیلتر موجودی قبول شوند (برای پشتیبانی UPDATE → INSERT)
            $insertProducts = $this->insertProducts;
            $updateProducts = $this->updateProducts;

            // ترکیب: تمام INSERT + تمام UPDATE (بدون فیلتر موجودی)
            // توضیح: اگر محصول UPDATE در دیتابیس وجود نداشته باشد، به صورت خودکار INSERT می‌شود
            $productsToProcess = array_merge($insertProducts, $updateProducts);

            if (empty($productsToProcess)) {
                $this->logError('هیچ محصولی برای پردازش یافت نشد');
                return;
            }

            Log::info('تقسیم محصولات برای پردازش', [
                'license_id' => $this->licenseId,
                'sync_id' => $this->syncId,
                'insert_products' => count($insertProducts),
                'update_products' => count($updateProducts),
                'total_to_process' => count($productsToProcess),
                'note' => 'محصولات UPDATE بدون موجودی نیز پردازش می‌شوند - اگر در دیتابیس نباشند INSERT خواهند شد'
            ]);

            // استخراج کدهای محصولات
            $productCodes = $this->extractProductCodes($productsToProcess);

            if (empty($productCodes)) {
                $this->logError('کدهای محصولات قابل استخراج نیست');
                return;
            }

            Log::info('کدهای محصولات استخراج شد', [
                'license_id' => $this->licenseId,
                'sync_id' => $this->syncId,
                'total_products' => count($productsToProcess),
                'extracted_codes' => count($productCodes),
                'sample_codes' => array_slice($productCodes, 0, 5)
            ]);

            // دریافت اطلاعات به‌روز از باران
            $baranResult = $this->getUpdatedProductInfoFromBaran($license, $productCodes);

            if (!$baranResult['success']) {
                $this->logError('خطا در دریافت اطلاعات از باران: ' . $baranResult['message']);
                return;
            }

            Log::info('اطلاعات از باران دریافت شد', [
                'license_id' => $this->licenseId,
                'sync_id' => $this->syncId,
                'total_requested' => $baranResult['data']['total_requested'],
                'total_received' => $baranResult['data']['total_received']
            ]);

            // ذخیره اطلاعات محصولات در دیتابیس قبل از به‌روزرسانی
            $saveResult = $this->saveBaranProductsToDatabase(
                $license,
                $baranResult['data']['products']
            );

            if (!$saveResult['success']) {
                Log::warning('خطا در ذخیره اطلاعات محصولات باران', [
                    'license_id' => $this->licenseId,
                    'sync_id' => $this->syncId,
                    'message' => $saveResult['message']
                ]);
            } else {
                Log::info('اطلاعات محصولات با موفقیت در دیتابیس ذخیره شد', [
                    'license_id' => $this->licenseId,
                    'sync_id' => $this->syncId,
                    'saved_count' => $saveResult['data']['saved_count'],
                    'updated_count' => $saveResult['data']['updated_count']
                ]);
            }

            // پردازش و به‌روزرسانی محصولات
            // استفاده از محصولات گروه‌بندی‌شده (یکپارچه‌شده) برای جستجوی صحیح
            $updateResult = $this->processAndUpdateProductsFromBaran(
                $license,
                $productsToProcess,  // تمام محصولات برای پردازش (INSERT + UPDATE با موجودی)
                $saveResult['data']['grouped_products'] ?? $baranResult['data']['products']
            );

            if (!$updateResult['success']) {
                $this->logError('خطا در پردازش محصولات: ' . $updateResult['message']);
                return;
            }

            $executionTime = microtime(true) - $startTime;

            // لاگ نتیجه نهایی
            Log::info('تکمیل پردازش درخواست همگام‌سازی Tantooo', [
                'license_id' => $this->licenseId,
                'sync_id' => $this->syncId,
                'total_processed' => $updateResult['data']['total_processed'],
                'success_count' => $updateResult['data']['success_count'],
                'error_count' => $updateResult['data']['error_count'],
                'execution_time' => $executionTime,
                'baran_data' => [
                    'total_requested' => $baranResult['data']['total_requested'],
                    'total_received' => $baranResult['data']['total_received']
                ]
            ]);

            // ذخیره نتیجه در Cache برای دریافت بعدی
            $this->saveSyncResult([
                'success' => true,
                'sync_id' => $this->syncId,
                'total_processed' => $updateResult['data']['total_processed'],
                'success_count' => $updateResult['data']['success_count'],
                'error_count' => $updateResult['data']['error_count'],
                'baran_data' => [
                    'total_requested' => $baranResult['data']['total_requested'],
                    'total_received' => $baranResult['data']['total_received']
                ],
                'tantooo_update_result' => $updateResult['data']['tantooo_update_result'],
                'errors' => $updateResult['data']['errors'],
                'execution_time' => $executionTime,
                'completed_at' => now()
            ]);

        } catch (\Exception $e) {
            $this->logError('خطا در پردازش همگام‌سازی: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            // ذخیره خطا در Cache
            $this->saveSyncResult([
                'success' => false,
                'sync_id' => $this->syncId,
                'error' => $e->getMessage(),
                'failed_at' => now()
            ]);

            throw $e;
        }
    }

    /**
     * ثبت خطا با جزئیات کامل
     */
    protected function logError($message, $extraData = [])
    {
        Log::error($message, array_merge([
            'license_id' => $this->licenseId,
            'sync_id' => $this->syncId,
            'job_class' => self::class
        ], $extraData));
    }

    /**
     * ذخیره نتیجه همگام‌سازی در Cache
     */
    protected function saveSyncResult($result)
    {
        $cacheKey = "tantooo_sync_result_{$this->syncId}";

        // ذخیره برای 24 ساعت
        \Illuminate\Support\Facades\Cache::put($cacheKey, $result, now()->addHours(24));

        Log::info('نتیجه همگام‌سازی در Cache ذخیره شد', [
            'sync_id' => $this->syncId,
            'cache_key' => $cacheKey
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('شکست در پردازش همگام‌سازی Tantooo', [
            'license_id' => $this->licenseId,
            'sync_id' => $this->syncId,
            'insert_count' => count($this->insertProducts),
            'update_count' => count($this->updateProducts),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // ذخیره نتیجه شکست در Cache
        $this->saveSyncResult([
            'success' => false,
            'sync_id' => $this->syncId,
            'error' => $exception->getMessage(),
            'failed_at' => now(),
            'attempts' => $this->attempts()
        ]);
    }

    /**
     * پردازش و به‌روزرسانی محصولات با داده‌های باران
     */
    protected function processAndUpdateProductsFromBaran($license, $allProducts, $baranProducts)
    {
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        $tantoooUpdateResult = [];

        // ایجاد نقشه سریع برای جستجو: itemID => productData
        $baranProductMap = [];
        foreach ($baranProducts as $baranItem) {
            $baranItemId = $baranItem['itemID'] ?? $baranItem['ItemID'] ?? null;
            if ($baranItemId) {
                $baranProductMap[$baranItemId] = $baranItem;
            }
        }

        Log::info('شروع پردازش محصولات Tantooo', [
            'license_id' => $license->id,
            'total_products_to_process' => count($allProducts),
            'unique_baran_items' => count($baranProductMap),
            'baran_products_count' => count($baranProducts),
            'sample_input_product' => count($allProducts) > 0 ? [
                'ItemId' => $allProducts[0]['ItemId'] ?? null,
                'Barcode' => $allProducts[0]['Barcode'] ?? null,
                'ItemName' => $allProducts[0]['ItemName'] ?? null
            ] : null,
            'sample_baran_product' => count($baranProducts) > 0 ? [
                'itemID' => $baranProducts[0]['itemID'] ?? null,
                'itemName' => $baranProducts[0]['itemName'] ?? null
            ] : null
        ]);

        foreach ($allProducts as $index => $product) {
            $itemId = $product['ItemId'] ?? null;
            $barcode = $product['Barcode'] ?? null;

            if (!$itemId) {
                $errorCount++;
                $errors[] = [
                    'code' => $itemId,
                    'message' => 'ItemId محصول یافت نشد'
                ];
                continue;
            }

            if (!$barcode) {
                $errorCount++;
                $errors[] = [
                    'code' => $itemId,
                    'message' => 'Barcode محصول یافت نشد'
                ];
                continue;
            }

            // جستجوی محصول در نقشه باران
            $baranProduct = $baranProductMap[$itemId] ?? null;

            if (!$baranProduct) {
                Log::warning('محصول در داده‌های باران یافت نشد', [
                    'requested_item_id' => $itemId,
                    'requested_barcode' => $barcode,
                    'requested_item_name' => $product['ItemName'] ?? null,
                    'available_items_in_baran' => array_keys($baranProductMap),
                    'available_items_count' => count($baranProductMap),
                    'total_items_to_process' => count($allProducts)
                ]);

                // بررسی وجود محصول در دیتابیس
                $existingProduct = Product::where('item_id', $itemId)->first();

                if ($existingProduct) {
                    // اگر محصول در دیتابیس وجود داشته باشد، از آن استفاده کنید
                    $baranProduct = [
                        'itemID' => $itemId,
                        'itemName' => $existingProduct->item_name ?? $product['ItemName'] ?? 'نامشخص',
                        'CurrentUnitCount' => $existingProduct->total_count ?? 0,
                        'salePrice' => $existingProduct->price_amount ?? $product['PriceAmount'] ?? 0,
                        'priceAfterDiscount' => $existingProduct->price_after_discount ?? $product['PriceAfterDiscount'] ?? 0
                    ];

                    Log::info('محصول در دیتابیس یافت شد و داده‌های موجود استفاده شد', [
                        'item_id' => $itemId,
                        'barcode' => $barcode,
                        'existing_stock' => $existingProduct->total_count,
                        'source' => 'database'
                    ]);
                } else {
                    // اگر محصول در دیتابیس وجود نداشته باشد، آن را ایجاد کنید
                    Log::info('محصول در دیتابیس یافت نشد، درخواست INSERT اجرا می‌شود', [
                        'item_id' => $itemId,
                        'barcode' => $barcode,
                        'requested_item_name' => $product['ItemName'] ?? null
                    ]);

                    try {
                        $newProduct = Product::create([
                            'license_id' => $license->id,
                            'item_id' => $itemId,
                            'item_name' => $product['ItemName'] ?? 'نامشخص',
                            'barcode' => $barcode,
                            'price_amount' => (int)($product['PriceAmount'] ?? 0),
                            'price_after_discount' => (int)($product['PriceAfterDiscount'] ?? 0),
                            'total_count' => (int)($product['TotalCount'] ?? 0),
                            'stock_id' => $product['StockID'] ?? null,
                            'department_name' => $product['DepartmentName'] ?? '',
                            'is_variant' => false,
                            'last_sync_at' => now()
                        ]);

                        // استفاده از داده‌های محصول جدید برای ادامه پردازش
                        $baranProduct = [
                            'itemID' => $itemId,
                            'itemName' => $newProduct->item_name,
                            'CurrentUnitCount' => $newProduct->total_count ?? 0,
                            'salePrice' => $newProduct->price_amount ?? 0,
                            'priceAfterDiscount' => $newProduct->price_after_discount ?? 0
                        ];

                        Log::info('محصول جدید با موفقیت ایجاد شد', [
                            'item_id' => $itemId,
                            'barcode' => $barcode,
                            'product_id' => $newProduct->id,
                            'initial_stock' => $newProduct->total_count
                        ]);

                    } catch (\Exception $ex) {
                        $errorCount++;
                        $errors[] = [
                            'code' => $itemId,
                            'message' => 'خطا در ایجاد محصول: ' . $ex->getMessage()
                        ];
                        Log::error('خطا در ایجاد محصول جدید', [
                            'item_id' => $itemId,
                            'barcode' => $barcode,
                            'error' => $ex->getMessage()
                        ]);
                        continue;
                    }
                }
            }

            // به‌روزرسانی محصول در Tantooo با استفاده از اطلاعات باران
            try {
                Log::info('شروع به‌روزرسانی محصول', [
                    'item_id' => $itemId,
                    'barcode' => $barcode,
                    'product_name' => $product['ItemName'] ?? 'نامشخص',
                    'baran_stock_quantity' => $baranProduct['CurrentUnitCount'] ?? $baranProduct['stockQuantity'] ?? 0,
                    'baran_item_found' => true
                ]);

                $updateRes = $this->updateProductInTantooo($license, $product, $baranProduct);
                $tantoooUpdateResult[$itemId] = $updateRes;

                if ($updateRes['success']) {
                    $successCount++;
                    Log::info('محصول با موفقیت به‌روزرسانی شد', [
                        'item_id' => $itemId,
                        'barcode' => $barcode,
                        'stock_updated' => $baranProduct['CurrentUnitCount'] ?? $baranProduct['stockQuantity'] ?? 0
                    ]);
                } else {
                    $errorCount++;
                    $errors[] = [
                        'code' => $itemId,
                        'message' => $updateRes['message'] ?? 'خطا در به‌روزرسانی محصول'
                    ];
                    Log::error('خطا در به‌روزرسانی محصول', [
                        'item_id' => $itemId,
                        'barcode' => $barcode,
                        'error_message' => $updateRes['message'] ?? 'نامشخص'
                    ]);
                }
            } catch (\Exception $ex) {
                $errorCount++;
                $errors[] = [
                    'code' => $itemId,
                    'message' => $ex->getMessage()
                ];
                Log::error('استثنا در به‌روزرسانی محصول', [
                    'item_id' => $itemId,
                    'barcode' => $barcode,
                    'exception' => $ex->getMessage(),
                    'trace' => $ex->getTraceAsString()
                ]);
            }
        }

        // لاگ نتیجه نهایی پردازش
        Log::info('نتیجه نهایی پردازش محصولات Tantooo', [
            'license_id' => $license->id,
            'total_processed' => count($allProducts),
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors_detail' => $errors
        ]);

        return [
            'success' => $errorCount === 0,
            'data' => [
                'total_processed' => count($allProducts),
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'tantooo_update_result' => $tantoooUpdateResult,
                'errors' => $errors
            ],
            'message' => $errorCount === 0 ? 'همه محصولات با موفقیت به‌روزرسانی شدند' : 'برخی محصولات با خطا مواجه شدند'
        ];
    }

    /**
     * ذخیره اطلاعات محصولات دریافت شده از باران در دیتابیس
     *
     * @param \App\Models\License $license
     * @param array $baranProducts اطلاعات محصولات از باران
     * @return array نتیجه ذخیره‌سازی
     */
    protected function saveBaranProductsToDatabase($license, $baranProducts)
    {
        try {
            $savedCount = 0;
            $updatedCount = 0;
            $errors = [];

            Log::info('شروع ذخیره‌سازی اطلاعات محصولات باران', [
                'license_id' => $license->id,
                'total_products' => count($baranProducts),
                'sample_products' => array_slice($baranProducts, 0, 3)
            ]);

            // گروه‌بندی محصولات بر اساس itemID
            // منطق: اگر یک itemID برای انبارهای مختلف وجود داشته باشد، موجودی‌ها جمع شود
            $groupedProducts = [];

            foreach ($baranProducts as $baranProduct) {
                $itemId = $baranProduct['itemID'] ?? $baranProduct['ItemID'] ?? null;

                if (!$itemId) {
                    $errors[] = [
                        'barcode' => $baranProduct['barcode'] ?? $baranProduct['Barcode'] ?? 'نامشخص',
                        'message' => 'ItemID محصول یافت نشد'
                    ];
                    continue;
                }

                if (!isset($groupedProducts[$itemId])) {
                    // اولین دفعه مشاهده این itemID
                    $groupedProducts[$itemId] = [
                        'itemID' => $itemId,
                        'itemName' => $baranProduct['itemName'] ?? $baranProduct['ItemName'] ?? $baranProduct['Name'] ?? '',
                        'barcode' => $baranProduct['barcode'] ?? $baranProduct['Barcode'] ?? null,
                        'salePrice' => $baranProduct['salePrice'] ?? $baranProduct['Price'] ?? $baranProduct['PriceAmount'] ?? 0,
                        'priceAfterDiscount' => $baranProduct['priceAfterDiscount'] ?? $baranProduct['PriceAfterDiscount'] ?? 0,
                        'totalQuantity' => 0, // موجودی جمع‌شده
                        'stocks' => [], // لیست انبارها
                        'departmentName' => $baranProduct['departmentName'] ?? $baranProduct['DepartmentName'] ?? '',
                        'firstStockId' => $baranProduct['StockID'] ?? $baranProduct['stockID'] ?? $baranProduct['stock_id'] ?? null
                    ];
                }

                // اضافه کردن موجودی
                $stockQuantity = (float)($baranProduct['TotalCount'] ?? $baranProduct['totalCount'] ?? $baranProduct['CurrentUnitCount'] ?? $baranProduct['stockQuantity'] ?? 0);
                $groupedProducts[$itemId]['totalQuantity'] += $stockQuantity;

                // ثبت انبار و موجودی آن
                $groupedProducts[$itemId]['stocks'][] = [
                    'stockID' => $baranProduct['StockID'] ?? $baranProduct['stockID'] ?? null,
                    'stockName' => $baranProduct['stockName'] ?? 'نامشخص',
                    'quantity' => $stockQuantity
                ];

                Log::debug('اضافه‌کردن رکورد موجودی برای گروه‌بندی', [
                    'item_id' => $itemId,
                    'stock_name' => $baranProduct['stockName'] ?? 'نامشخص',
                    'quantity' => $stockQuantity,
                    'cumulative_quantity' => $groupedProducts[$itemId]['totalQuantity']
                ]);
            }

            // اکنون از محصولات گروه‌بندی شده استفاده کنید
            foreach ($groupedProducts as $itemId => $productData) {
                try {
                    // بررسی وجود محصول بر اساس item_id
                    $product = Product::where('item_id', $itemId)->first();

                    if ($product) {
                        // به‌روزرسانی محصول موجود
                        $product->update([
                            'license_id' => $license->id,
                            'item_name' => $productData['itemName'],
                            'barcode' => $productData['barcode'],
                            'price_amount' => (int)$productData['salePrice'],
                            'price_after_discount' => (int)$productData['priceAfterDiscount'],
                            'total_count' => (int)$productData['totalQuantity'], // موجودی جمع‌شده
                            'stock_id' => $productData['firstStockId'],
                            'department_name' => $productData['departmentName'],
                            'last_sync_at' => now()
                        ]);
                        $updatedCount++;

                        Log::info('محصول به‌روزرسانی شد', [
                            'license_id' => $license->id,
                            'item_id' => $itemId,
                            'barcode' => $productData['barcode'],
                            'total_quantity' => $productData['totalQuantity'],
                            'warehouse_count' => count($productData['stocks']),
                            'stocks_detail' => $productData['stocks'],
                            'action' => 'updated'
                        ]);
                    } else {
                        // ایجاد محصول جدید
                        Product::create([
                            'license_id' => $license->id,
                            'item_id' => $itemId,
                            'item_name' => $productData['itemName'],
                            'barcode' => $productData['barcode'],
                            'price_amount' => (int)$productData['salePrice'],
                            'price_after_discount' => (int)$productData['priceAfterDiscount'],
                            'total_count' => (int)$productData['totalQuantity'], // موجودی جمع‌شده
                            'stock_id' => $productData['firstStockId'],
                            'department_name' => $productData['departmentName'],
                            'is_variant' => false,
                            'last_sync_at' => now()
                        ]);
                        $savedCount++;

                        Log::info('محصول جدید ذخیره شد', [
                            'license_id' => $license->id,
                            'item_id' => $itemId,
                            'barcode' => $productData['barcode'],
                            'total_quantity' => $productData['totalQuantity'],
                            'warehouse_count' => count($productData['stocks']),
                            'stocks_detail' => $productData['stocks'],
                            'action' => 'created'
                        ]);
                    }
                } catch (\Exception $ex) {
                    $errors[] = [
                        'item_id' => $itemId,
                        'barcode' => $productData['barcode'] ?? 'نامشخص',
                        'message' => $ex->getMessage()
                    ];

                    Log::error('خطا در ذخیره‌سازی محصول', [
                        'license_id' => $license->id,
                        'item_id' => $itemId,
                        'barcode' => $productData['barcode'] ?? 'نامشخص',
                        'error' => $ex->getMessage()
                    ]);
                }
            }


            Log::info('تکمیل ذخیره‌سازی محصولات باران', [
                'license_id' => $license->id,
                'total_raw_records' => count($baranProducts),
                'unique_items_count' => count($groupedProducts),
                'saved_count' => $savedCount,
                'updated_count' => $updatedCount,
                'error_count' => count($errors),
                'summary' => "تعداد رکوردهای خام: " . count($baranProducts) . ", محصولات یکتا: " . count($groupedProducts),
                'grouped_items_keys' => array_keys($groupedProducts),
                'first_grouped_item' => count($groupedProducts) > 0 ? reset($groupedProducts) : null
            ]);

            // تبدیل محصولات گروه‌بندی‌شده برای استفاده در processAndUpdateProductsFromBaran
            $processedGroupedProducts = [];
            foreach ($groupedProducts as $itemId => $productData) {
                $processedGroupedProducts[] = [
                    'itemID' => $productData['itemID'],
                    'itemName' => $productData['itemName'],
                    'barcode' => $productData['barcode'],
                    'salePrice' => $productData['salePrice'],
                    'priceAfterDiscount' => $productData['priceAfterDiscount'],
                    'stockQuantity' => $productData['totalQuantity'], // موجودی جمع‌شده
                    'departmentName' => $productData['departmentName'],
                    'stocks' => $productData['stocks']
                ];
            }

            return [
                'success' => true,
                'data' => [
                    'saved_count' => $savedCount,
                    'updated_count' => $updatedCount,
                    'total_processed' => count($groupedProducts),
                    'total_raw_records' => count($baranProducts),
                    'grouped_products' => $processedGroupedProducts, // محصولات یکپارچه‌شده برای استفاده در update
                    'errors' => $errors
                ],
                'message' => "محصولات با موفقیت ذخیره شدند ($savedCount جدید، $updatedCount به‌روزرسانی شده)"
            ];

        } catch (\Exception $e) {
            Log::error('خطا در فرآیند ذخیره‌سازی محصولات', [
                'license_id' => $license->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در ذخیره‌سازی محصولات: ' . $e->getMessage()
            ];
        }
    }

    /**
     * به‌روزرسانی محصول در Tantooo با استفاده از اطلاعات باران
     *
     * @param \App\Models\License $license
     * @param array $product محصول اصلی از درخواست
     * @param array $baranProduct اطلاعات به‌روز از باران
     * @return array نتیجه به‌روزرسانی
     */
    protected function updateProductInTantooo($license, $product, $baranProduct)
    {
        try {
            // دریافت تنظیمات کاربر
            $userSettings = UserSetting::where('license_id', $license->id)->first();
            if (!$userSettings) {
                return [
                    'success' => false,
                    'message' => 'تنظیمات کاربر یافت نشد'
                ];
            }

            // استخراج اطلاعات محصول بر اساس ساختار جدید
            $itemId = $product['ItemId'] ?? null; // شناسه یکتای محصول
            $barcode = $product['Barcode'] ?? null; // کد بارکد

            if (empty($itemId)) {
                return [
                    'success' => false,
                    'message' => 'ItemId محصول یافت نشد'
                ];
            }

            // بررسی کدام تنظیمات فعال هستند
            $enableStockUpdate = $userSettings->enable_stock_update ?? false;
            $enablePriceUpdate = $userSettings->enable_price_update ?? false;
            $enableNameUpdate = $userSettings->enable_name_update ?? false;

            // اگر هیچ تنظیمی فعال نباشد، هیچ کاری انجام نده
            if (!$enableStockUpdate && !$enablePriceUpdate && !$enableNameUpdate) {
                Log::info('هیچ تنظیم به‌روزرسانی فعال نیست', [
                    'license_id' => $license->id,
                    'item_id' => $itemId,
                    'enable_stock_update' => $enableStockUpdate,
                    'enable_price_update' => $enablePriceUpdate,
                    'enable_name_update' => $enableNameUpdate
                ]);

                return [
                    'success' => true,
                    'message' => 'هیچ تنظیم به‌روزرسانی فعال نیست - عملیات رد شد',
                    'skipped' => true
                ];
            }

            $results = [];
            $hasAnyUpdate = false;

            // به‌روزرسانی موجودی (اگر فعال باشد)
            if ($enableStockUpdate) {
                $stockQuantity = $baranProduct['CurrentUnitCount'] ?? $baranProduct['stockQuantity'] ?? $baranProduct['TotalCount'] ?? $product['TotalCount'] ?? 0;

                if (is_numeric($stockQuantity) && $stockQuantity >= 0) {
                    $stockResult = $this->updateProductStockWithToken($license, $barcode, (int)$stockQuantity);
                    $results['stock_update'] = $stockResult;
                    $hasAnyUpdate = true;

                    Log::info('به‌روزرسانی موجودی محصول', [
                        'item_id' => $itemId,
                        'stock_quantity' => $stockQuantity,
                        'success' => $stockResult['success'] ?? false
                    ]);
                } else {
                    $results['stock_update'] = [
                        'success' => false,
                        'message' => 'مقدار موجودی نامعتبر است'
                    ];
                }
            }

            // به‌روزرسانی قیمت و نام (اگر فعال باشند)
            if ($enablePriceUpdate || $enableNameUpdate) {
                // استخراج اطلاعات قیمت
                $price = 0;
                $discountPercent = 0;

                if ($enablePriceUpdate) {
                    $price = $baranProduct['salePrice'] ?? $baranProduct['Price'] ?? $product['PriceAmount'] ?? 0;

                    // محاسبه تخفیف
                    $currentDiscount = $baranProduct['currentDiscount'] ?? $baranProduct['DiscountPercentage'] ?? 0;
                    $priceAfterDiscount = $product['PriceAfterDiscount'] ?? null;

                    if ($currentDiscount > 0) {
                        $discountPercent = $currentDiscount;
                    } elseif ($priceAfterDiscount && $priceAfterDiscount > 0 && $price > 0) {
                        $discountPercent = (($price - $priceAfterDiscount) / $price) * 100;
                    }

                    if (!is_numeric($price) || $price < 0) {
                        $results['price_update'] = [
                            'success' => false,
                            'message' => 'قیمت محصول نامعتبر است'
                        ];
                        $enablePriceUpdate = false; // غیرفعال کردن به‌روزرسانی قیمت
                    }
                }

                // استخراج نام محصول
                $title = '';
                if ($enableNameUpdate) {
                    $title = $baranProduct['itemName'] ?? $baranProduct['Name'] ?? $product['ItemName'] ?? '';

                    if (empty($title)) {
                        $results['name_update'] = [
                            'success' => false,
                            'message' => 'نام محصول یافت نشد'
                        ];
                        $enableNameUpdate = false; // غیرفعال کردن به‌روزرسانی نام
                    }
                }

                // ارسال درخواست به‌روزرسانی اطلاعات محصول (اگر هنوز فعال باشند)
                if ($enablePriceUpdate || $enableNameUpdate) {
                    // اگر فقط قیمت فعال است، نام خالی ارسال نکن
                    $finalTitle = $enableNameUpdate ? $title : null;
                    $finalPrice = $enablePriceUpdate ? (float)$price : null;
                    $finalDiscount = $enablePriceUpdate ? (float)$discountPercent : null;

                    // فراخوانی API برای به‌روزرسانی اطلاعات
                    if ($finalTitle !== null || $finalPrice !== null) {
                        $infoResult = $this->updateProductInfoWithToken(
                            $license,
                            $itemId,
                            $finalTitle ?? '',
                            $finalPrice ?? 0,
                            $finalDiscount ?? 0
                        );

                        $results['info_update'] = $infoResult;
                        $hasAnyUpdate = true;

                        Log::info('به‌روزرسانی اطلاعات محصول', [
                            'item_id' => $itemId,
                            'title_updated' => $enableNameUpdate,
                            'price_updated' => $enablePriceUpdate,
                            'title' => $finalTitle,
                            'price' => $finalPrice,
                            'discount' => $finalDiscount,
                            'success' => $infoResult['success'] ?? false
                        ]);
                    }
                }
            }

            // تجمیع نتایج
            $allSuccessful = true;
            $messages = [];

            foreach ($results as $type => $result) {
                if (!($result['success'] ?? false)) {
                    $allSuccessful = false;
                    $messages[] = $result['message'] ?? "خطا در $type";
                }
            }

            // لاگ نهایی تنظیمات و نتایج
            Log::info('نتیجه نهایی به‌روزرسانی محصول بر اساس تنظیمات', [
                'license_id' => $license->id,
                'item_id' => $itemId,
                'user_settings' => [
                    'enable_stock_update' => $enableStockUpdate,
                    'enable_price_update' => $enablePriceUpdate,
                    'enable_name_update' => $enableNameUpdate
                ],
                'updates_performed' => array_keys($results),
                'all_successful' => $allSuccessful,
                'has_any_update' => $hasAnyUpdate,
                'results' => $results
            ]);

            return [
                'success' => $allSuccessful && $hasAnyUpdate,
                'message' => $allSuccessful
                    ? 'محصول با موفقیت به‌روزرسانی شد بر اساس تنظیمات کاربر'
                    : 'برخی به‌روزرسانی‌ها با خطا مواجه شدند: ' . implode(', ', $messages),
                'results' => $results,
                'settings_applied' => [
                    'stock_update' => $enableStockUpdate,
                    'price_update' => $enablePriceUpdate,
                    'name_update' => $enableNameUpdate
                ]
            ];

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی محصول Tantooo', [
                'license_id' => $license->id,
                'item_id' => $itemId ?? 'نامشخص',
                'barcode' => $barcode ?? 'نامشخص',
                'used_code' => $itemId, // کدی که به Tantooo ارسال شده
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در به‌روزرسانی محصول: ' . $e->getMessage()
            ];
        }
    }
}
