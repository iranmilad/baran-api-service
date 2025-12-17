<?php

namespace App\Jobs\WordPress;

use App\Models\License;
use App\Models\UserSetting;
use App\Models\WooCommerceApiKey;
use App\Traits\PriceUnitConverter;
use App\Traits\WordPress\WordPressMasterTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class BulkInsertWooCommerceProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, PriceUnitConverter, WordPressMasterTrait;

    public $tries = 5;
    public $timeout = 180;
    public $maxExceptions = 3;
    public $backoff = [60, 120, 300, 600, 1200];

    protected $products;
    protected $license_id;
    protected $batchSize = 10;

    public function __construct(array $products, int $license_id, int $batchSize = 10)
    {
        $this->products = $products;
        $this->license_id = $license_id;
        $this->batchSize = $batchSize;
        $this->onQueue('woocommerce-insert');

        // فیلدهای یک محصول نمونه را لاگ می‌کنیم برای عیب‌یابی
        if (count($products) > 0) {
            Log::info('نمونه محصول دریافتی در job', [
                'field_keys' => array_keys($products[0]),
                'has_ItemName' => isset($products[0]['ItemName']),
                'has_item_name' => isset($products[0]['item_name']),
                'ItemName_value' => $products[0]['ItemName'] ?? 'not set',
                'license_id' => $license_id
            ]);
        }
    }

    public function handle()
    {
        try {
            $license = License::with(['user', 'userSetting', 'woocommerceApiKey'])->find($this->license_id);

            if (!$license || !$license->isActive()) {
                Log::error('لایسنس معتبر نیست یا منقضی شده است', [
                    'license_id' => $this->license_id
                ]);
                return;
            }

            // یک بررسی اضافه میکنیم برای تشخیص محصولات بدون نام
            // و ثبت در لاگ جهت عیب‌یابی
            if (count($this->products) > 0) {
                foreach ($this->products as $index => $product) {
                    // Check if we need to recover item name from the original products array
                    if (!isset($product['name']) && !isset($product['ItemName']) && !isset($product['item_name'])) {
                        Log::warning('محصول بدون نام در ابتدای پردازش - بررسی مقادیر تمام فیلدها:', [
                            'product_sku' => $product['sku'] ?? $product['Barcode'] ?? 'unknown',
                            'all_fields' => array_keys($product),
                            'license_id' => $this->license_id
                        ]);
                    }
                }
            }

            $userSetting = $license->userSetting;
            if (!$userSetting) {
                throw new \Exception('تنظیمات کاربر یافت نشد');
            }

            $wooCommerceApiKey = $license->woocommerceApiKey;
            if (!$wooCommerceApiKey || !$wooCommerceApiKey->api_key || !$wooCommerceApiKey->api_secret) {
                throw new \Exception('کلیدهای API ووکامرس یافت نشد یا نامعتبر است');
            }

            if (!$userSetting->enable_new_product) {
                throw new \Exception('دسترسی درج محصول جدید فعال نیست');
            }

            $productsToCreate = [];
            $existingProducts = [];
            $uniqueIds = collect($this->products)->pluck('item_id')->filter()->toArray();

            if (empty($uniqueIds)) {
                Log::error('هیچ شناسه یکتای معتبری برای پردازش وجود ندارد', [
                    'license_id' => $this->license_id,
                    'products' => $this->products
                ]);
                return;
            }

            // بررسی وجود محصولات در ووکامرس
            $checkResult = $this->checkWooCommerceProductsExistence($license, $uniqueIds);

            if (!$checkResult['success']) {
                Log::error('خطا در بررسی وجود محصولات در ووکامرس', [
                    'error' => $checkResult['error'] ?? 'نامشخص'
                ]);
                return;
            }

            $responseData = $checkResult['data'];

            // بررسی حالت not_found
            if ($checkResult['status'] === 404 && isset($responseData['code']) && $responseData['code'] === 'not_found') {
                Log::info('هیچ محصولی با این شناسه‌های یکتا یافت نشد، در حال درج محصولات جدید...', [
                    'license_id' => $this->license_id,
                    'unique_ids' => $uniqueIds
                ]);

                $productsToCreate = $this->products;
            } else if ($checkResult['status'] === 200) {
                $wooProducts = $responseData['data'] ?? [];
                $productMap = collect($wooProducts)->keyBy('unique_id')->toArray();

                foreach ($this->products as $product) {
                    if (empty($product['item_id'])) {
                        Log::warning('شناسه یکتای محصول خالی است', [
                            'product' => $product
                        ]);
                        continue;
                    }

                    if (isset($productMap[$product['item_id']])) {
                        $existingProducts[] = [
                            'sku' => $product['sku'],
                            'unique_id' => $product['item_id'],
                            'id' => $productMap[$product['item_id']]['product_id']
                        ];
                        Log::info('محصول با این شناسه یکتا قبلاً در ووکامرس وجود دارد:', [
                            'license_id' => $this->license_id,
                            'unique_id' => $product['item_id'],
                            'sku' => $product['sku'],
                            'woo_id' => $productMap[$product['item_id']]['product_id']
                        ]);
                    } else {
                        $productsToCreate[] = $product;
                    }
                }
            } else {
                Log::error('خطا در بررسی وجود محصولات در ووکامرس', [
                    'license_id' => $this->license_id,
                    'response' => $checkResult['body'] ?? 'نامشخص'
                ]);
                return;
            }

            if (!empty($existingProducts)) {
                Log::info('محصولات موجود در ووکامرس:', [
                    'license_id' => $this->license_id,
                    'existing_products' => $existingProducts,
                    'count' => count($existingProducts)
                ]);
            }

            if (!empty($productsToCreate)) {
                Log::info('محصولات جدید در حال درج:', [
                    'license_id' => $this->license_id,
                    'unique_ids' => collect($productsToCreate)->pluck('item_id')->toArray(),
                    'count' => count($productsToCreate)
                ]);

                // آماده‌سازی و ترتیب‌دهی محصولات (محصول مادر اول، سپس واریانت‌ها)
                $allPreparedProducts = [];

                foreach ($productsToCreate as $product) {
                    // ابتدا محصول را آماده کن
                    $preparedProduct = $this->prepareProductData($product, $userSetting);
                    $allPreparedProducts[] = $preparedProduct;
                }

                // ترتیب‌دهی: محصولات مادر (variable) اول، سپس واریانت‌ها
                usort($allPreparedProducts, function($a, $b) {
                    $typeA = $a['type'] ?? 'simple';
                    $typeB = $b['type'] ?? 'simple';

                    // محصولات variable اول
                    if ($typeA === 'variable' && $typeB !== 'variable') {
                        return -1;
                    }
                    if ($typeB === 'variable' && $typeA !== 'variable') {
                        return 1;
                    }

                    return 0;
                });

                Log::info('محصولات آماده شده برای درج یکجا', [
                    'total_products' => count($allPreparedProducts),
                    'license_id' => $this->license_id,
                    'product_types' => collect($allPreparedProducts)->groupBy('type')->map(function($items) {
                        return count($items);
                    })->toArray()
                ]);

                // درج همه محصولات در یک batch
                $this->insertAllProductsBatch($allPreparedProducts, $license, $wooCommerceApiKey);

                Log::info('درج دسته‌ای محصولات تکمیل شد', [
                    'total_products' => count($allPreparedProducts),
                    'license_id' => $this->license_id
                ]);
            } else {
                Log::info('هیچ محصول جدیدی برای درج وجود ندارد', [
                    'license_id' => $this->license_id,
                    'total_checked' => count($this->products),
                    'existing_count' => count($existingProducts)
                ]);
            }

        } catch (\Exception $e) {
            Log::error('خطا در پردازش دسته‌ای محصولات: ' . $e->getMessage(), [
                'license_id' => $this->license_id
            ]);
            throw $e;
        }
    }

    /**
     * آماده‌سازی داده‌های محصول برای ووکامرس
     */
    protected function prepareProductData($product, UserSetting $userSetting): array
    {
        // تبدیل به آرایه اگر آبجکت است
        $productData = is_array($product) ? $product : (array)$product;

        // لاگ کردن داده‌های ورودی برای عیب‌یابی
        Log::info('پردازش محصول در prepareProductData', [
            'original_data' => $productData,
            'parent_id_check' => [
                'parent_id' => $productData['parent_id'] ?? 'not_set',
                'is_empty' => empty($productData['parent_id'] ?? null),
                'is_trim_empty' => trim($productData['parent_id'] ?? '') === ''
            ]
        ]);

        // مپینگ نام‌های مختلف فیلدها (کیمل کیس و آندراسکور)
        $itemId = $productData['item_id'] ?? $productData['ItemId'] ?? null;
        $barcode = $productData['barcode'] ?? $productData['Barcode'] ?? '';
        $itemName = $productData['item_name'] ?? $productData['ItemName'] ?? $productData['name'] ?? '';
        $departmentName = $productData['department_name'] ?? $productData['DepartmentName'] ?? null;
        $priceAmount = $productData['price_amount'] ?? $productData['PriceAmount'] ?? $productData['regular_price'] ?? 0;
        $priceAfterDiscount = $productData['price_after_discount'] ?? $productData['PriceAfterDiscount'] ?? null;
        $isVariant = $productData['is_variant'] ?? $productData['IsVariant'] ?? false;
        $parentId = $productData['parent_id'] ?? $productData['ParentId'] ?? null;
        $discountPercentage = $productData['discount_percentage'] ?? $productData['DiscountPercentage'] ?? 0;
        $priceIncreasePercentage = $productData['price_increase_percentage'] ?? $productData['PriceIncreasePercentage'] ?? 0;
        $stockId = $productData['stock_id'] ?? $productData['StockID'] ?? null;

        // دریافت موجودی از انبارهای تنظیم‌شده یا تمام انبارها
        $totalCount = 0;
        $warehouseInfo = [];

        if ($itemId) {
            // دریافت کدهای انبارهای فعال
            $defaultWarehouseCodes = [];
            if (!empty($userSetting->default_warehouse_code)) {
                $rawCode = $userSetting->default_warehouse_code;

                // سعی برای تجزیه JSON
                if (is_string($rawCode)) {
                    // اگر JSON array است
                    if (substr(trim($rawCode), 0, 1) === '[') {
                        $decoded = json_decode($rawCode, true);
                        if (is_array($decoded)) {
                            $defaultWarehouseCodes = array_filter(array_map(function($code) {
                                return strtolower(trim(stripslashes((string)$code)));
                            }, $decoded));
                        }
                    } else {
                        // اگر رشته‌ای است با کاما یا semicolon جدا شده
                        $defaultWarehouseCodes = array_filter(
                            array_map(function($code) {
                                return strtolower(trim($code));
                            }, preg_split('/[,;]/', $rawCode))
                        );
                    }
                } elseif (is_array($rawCode)) {
                    $defaultWarehouseCodes = array_filter(array_map(function($code) {
                        return strtolower(trim(stripslashes((string)$code)));
                    }, $rawCode));
                }
            }

            // نوشتن کوئری برای دریافت موجودی
            $query = \App\Models\Product::where('item_id', $itemId)
                ->where('license_id', $this->license_id);

            // اگر انبارهای خاصی تنظیم شده‌اند، فقط از آن‌ها استفاده کن
            if (!empty($defaultWarehouseCodes)) {
                $query->whereIn(\DB::raw('LOWER(stock_id)'), $defaultWarehouseCodes);
            }

            $localProducts = $query->get();

            foreach ($localProducts as $product) {
                $totalCount += (int)$product->total_count;
                $warehouseInfo[] = [
                    'stock_id' => $product->stock_id,
                    'quantity' => (int)$product->total_count
                ];
            }

            // لاگ تجمیع موجودی برای مدیران
            Log::info('تجمیع موجودی انبار برای ووکامرس', [
                'barcode' => $barcode,
                'item_id' => $itemId,
                'warehouses' => $warehouseInfo,
                'total_quantity' => $totalCount,
                'warehouse_filter' => !empty($defaultWarehouseCodes) ? 'configured' : 'all',
                'configured_warehouses' => $defaultWarehouseCodes,
                'raw_default_warehouse_code' => $userSetting->default_warehouse_code ?? 'null',
                'license_id' => $this->license_id,
                'operation' => 'insert',
                'timestamp' => now()->toDateTimeString()
            ]);
        }

        // اگر TotalCount در درخواست وجود دارد و موجودی از انبارها پیدا نشد، از درخواست استفاده کن
        if ($totalCount === 0) {
            $totalCount = (int)($productData['total_count'] ?? $productData['TotalCount'] ?? $productData['stock_quantity'] ?? 0);
        }

        $data = [
            'unique_id' => (string)$itemId,
            'sku' => (string)$barcode,
            'status' => 'draft', // پیش‌فرض draft، بعداً تغییر می‌کند
            'manage_stock' => true,
            'stock_quantity' => (int)$totalCount,
            'stock_status' => (int)$totalCount > 0 ? 'instock' : 'outofstock'
        ];

        // اضافه کردن اطلاعات واریانت اگر موجود باشد
        if ($isVariant) {
            $data['is_variant'] = true;

            // بررسی parent_id - اگر خالی باشد (null، empty string، یا فقط whitespace) یعنی محصول مادر است
            if (!empty($parentId) && trim($parentId) !== '') {
                // این یک واریانت است که والد دارد - منتشر می‌شود
                $data['parent_unique_id'] = $parentId; // استفاده از parent_unique_id به جای parent_id
                $data['type'] = 'variable'; // تغییر از variation به variable
                $data['status'] = 'publish'; // فقط واریانت‌ها منتشر می‌شوند

                // حذف parent_id چون از parent_unique_id استفاده می‌کنیم
                unset($data['parent_id']);

                Log::info('محصول متغیر (واریانت) با شناسه یکتای والد - منتشر شده', [
                    'barcode' => $barcode,
                    'parent_unique_id' => $parentId,
                    'type' => 'variable',
                    'status' => 'publish'
                ]);
            } else {
                // محصول متغیر بدون والد، یعنی خود محصول مادر است (variable product) - همیشه پیش‌نویس می‌ماند
                $data['type'] = 'variable';
                $data['status'] = 'draft'; // کالای مادر همیشه پیش‌نویس
                Log::info('محصول متغیر مادر (parent product) - پیش‌نویس', [
                    'barcode' => $barcode,
                    'item_id' => $itemId,
                    'type' => 'variable',
                    'status' => 'draft',
                    'parent_id_received' => $parentId
                ]);
            }
        } else {
            // محصول عادی (غیر متغیر) - همیشه پیش‌نویس درج می‌شود
            $data['type'] = 'simple';
            $data['status'] = 'draft'; // محصولات ساده هم پیش‌نویس درج می‌شوند
            Log::info('محصول ساده - پیش‌نویس', [
                'barcode' => $barcode,
                'type' => 'simple',
                'status' => 'draft'
            ]);
        }

        // name is a required field for WooCommerce API product creation
        // تشخیص واریانت بر اساس متغیرهای محلی
        $isChildVariant = $isVariant && !empty($parentId) && trim($parentId) !== '';

        Log::info('تشخیص نوع محصول برای تنظیم name/description', [
            'barcode' => $barcode,
            'isVariant' => $isVariant,
            'parentId' => $parentId,
            'isChildVariant' => $isChildVariant,
            'itemName' => $itemName
        ]);

        if ($isChildVariant) {
            // برای واریانت‌ها (محصولاتی که parent_id دارند)، نام را در description قرار می‌دهیم
            $data['description'] = !empty($itemName) ?
                $itemName :
                'واریانت ' . $barcode; // توضیحات پیش‌فرض برای واریانت‌ها

            // name را خالی می‌گذاریم یا نام کوتاه می‌دهیم چون WooCommerce خودکار تنظیم می‌کند
            $data['name'] = $barcode; // فقط بارکد به عنوان نام

            Log::info('تنظیم description برای واریانت', [
                'barcode' => $barcode,
                'description' => $data['description']
            ]);
        } else {
            // برای محصولات مادر (variable) و ساده (simple)
            $data['name'] = !empty($itemName) ?
                $itemName :
                'محصول ' . $barcode; // نام پیش‌فرض برای محصولات مادر

            Log::info('تنظیم name برای محصول مادر/ساده', [
                'barcode' => $barcode,
                'name' => $data['name']
            ]);
        }        // We'll still respect the setting for updates, but for inserts, name is required
        // This just adds a flag to track if name updates are enabled
        $enableNameUpdate = $userSetting->enable_name_update;

        // برای درج محصولات جدید، همیشه قیمت و موجودی را تنظیم کن
        $regularPrice = $this->calculateFinalPrice(
            (float)$priceAmount,
            0,
            (float)$priceIncreasePercentage
        );

        $convertedRegularPrice = $this->convertPriceUnit(
            $regularPrice,
            $userSetting->rain_sale_price_unit,
            $userSetting->woocommerce_price_unit
        );

        $data['regular_price'] = (string)$convertedRegularPrice;

        Log::info('تبدیل قیمت عادی', [
            'barcode' => $barcode,
            'original_price' => $priceAmount,
            'after_increase' => $regularPrice,
            'from_unit' => $userSetting->rain_sale_price_unit,
            'to_unit' => $userSetting->woocommerce_price_unit,
            'converted_price' => $convertedRegularPrice
        ]);

        // محاسبه قیمت تخفیف‌دار با اولویت PriceAfterDiscount
        $salePriceToSet = null;

        // اولویت اول: اگر PriceAfterDiscount ارسال شده و معتبر است
        if (!empty($priceAfterDiscount) && (float)$priceAfterDiscount > 0 && (float)$priceAfterDiscount < (float)$priceAmount) {
            $salePriceToSet = $this->calculateFinalPrice(
                (float)$priceAfterDiscount,
                0,
                (float)$priceIncreasePercentage
            );

            Log::info('استفاده از PriceAfterDiscount برای محصول', [
                'barcode' => $barcode,
                'priceAmount' => $priceAmount,
                'priceAfterDiscount' => $priceAfterDiscount,
                'salePriceToSet' => $salePriceToSet
            ]);
        }
        // اولویت دوم: اگر CurrentDiscount وجود دارد
        elseif (!empty($discountPercentage) && (float)$discountPercentage > 0) {
            $salePriceToSet = $this->calculateFinalPrice(
                (float)$priceAmount,
                (float)$discountPercentage,
                (float)$priceIncreasePercentage
            );

            Log::info('استفاده از CurrentDiscount برای محصول', [
                'barcode' => $barcode,
                'priceAmount' => $priceAmount,
                'discountPercentage' => $discountPercentage,
                'salePriceToSet' => $salePriceToSet
            ]);
        }

        // تنظیم sale_price در صورتی که محاسبه شده باشد
        if ($salePriceToSet !== null && $salePriceToSet > 0) {
            $convertedSalePrice = $this->convertPriceUnit(
                $salePriceToSet,
                $userSetting->rain_sale_price_unit,
                $userSetting->woocommerce_price_unit
            );

            $data['sale_price'] = (string)$convertedSalePrice;

            Log::info('تنظیم sale_price برای محصول', [
                'barcode' => $barcode,
                'original_sale_price' => $salePriceToSet,
                'from_unit' => $userSetting->rain_sale_price_unit,
                'to_unit' => $userSetting->woocommerce_price_unit,
                'converted_sale_price' => $convertedSalePrice
            ]);
        } else {
            // اگر تخفیفی وجود ندارد، sale_price را خالی می‌کنیم
            $data['sale_price'] = '';

            Log::info('تخفیفی وجود ندارد، sale_price خالی است', [
                'barcode' => $barcode
            ]);
        }

        // اضافه کردن دسته‌بندی ووکامرس بر اساس department_name
        if (!empty($departmentName) && !empty($productData['category_id'])) {
            $data['categories'] = [['id' => $productData['category_id']]];
        }

        // برای واریانت‌ها، attributes را اصلاً ارسال نمی‌کنیم
        // واریانت‌ها attributes را از محصول مادر به ارث می‌برند

        // حذف description و short_description از درخواست درج
        // تا کاربر خودش آن‌ها را در WooCommerce تنظیم کند

        // تنظیم status به publish به جای draft
        $data['status'] = 'publish';

        // لاگ نهایی برای بررسی داده‌های ارسالی
        Log::info('داده‌های نهایی محصول برای ووکامرس', [
            'barcode' => $barcode,
            'name' => $data['name'],
            'description' => $data['description'] ?? 'not_set', // اضافه کردن description به لاگ
            'type' => $data['type'] ?? 'not_set',
            'parent_unique_id' => $data['parent_unique_id'] ?? null,
            'regular_price' => $data['regular_price'] ?? null,
            'stock_quantity' => $data['stock_quantity'] ?? null,
            'has_attributes' => isset($data['attributes']),
            'is_child_variant' => $isChildVariant // اضافه کردن متغیر تشخیص واریانت
        ]);

        return $data;
    }

    /**
     * محاسبه قیمت نهایی با اعمال تخفیف و افزایش قیمت
     */
    protected function calculateFinalPrice(float $basePrice, float $discountPercentage, float $increasePercentage): float
    {
        $price = $basePrice;

        if ($discountPercentage > 0) {
            $price -= ($price * $discountPercentage / 100);
        }

        if ($increasePercentage > 0) {
            $price += ($price * $increasePercentage / 100);
        }

        return round($price, 2);
    }

    /**
     * پردازش پاسخ API
     */
    /**
     * پردازش پاسخ برای یک دسته از محصولات
     *
     * @param \Illuminate\Http\Client\Response $response
     * @param array $products
     * @param string $operation
     * @return array
     */
    protected function processChunkResponse($response, $products, $operation)
    {
        $result = [
            'successful' => 0,
            'failed' => 0,
            'errors' => []
        ];

        try {
            // پردازش پاسخ موفق یا ناموفق
            if (!$response->successful()) {
                // لاگ کردن کد وضعیت و متن پاسخ
                Log::error('پاسخ ناموفق از API ووکامرس دریافت شد', [
                    'status_code' => $response->status(),
                    'response_body' => $response->body(),
                    'license_id' => $this->license_id,
                    'operation' => $operation
                ]);

                // تلاش برای رمزگشایی پاسخ JSON
                $errorData = null;
                try {
                    $errorData = json_decode($response->body(), true);

                    // بررسی خطاهای مربوط به فیلدهای ضروری
                    if ($response->status() === 400 && isset($errorData['message'])) {
                        $errorMessage = $errorData['message'];
                        // چک کردن خطاهای مربوط به فیلدهای اجباری
                        if (strpos($errorMessage, 'یکی از ویژگی‌های لازم') !== false ||
                            strpos($errorMessage, 'required property') !== false) {

                            Log::error('خطای فیلد اجباری در درخواست API:', [
                                'message' => $errorMessage,
                                'products_sample' => array_slice($products, 0, 3),
                                'license_id' => $this->license_id
                            ]);
                        }
                    }
                } catch (\Exception $jsonError) {
                    Log::error('خطا در رمزگشایی پاسخ JSON: ' . $jsonError->getMessage(), [
                        'response_body' => $response->body()
                    ]);
                }

                if (isset($errorData['failed']) && is_array($errorData['failed'])) {
                    foreach ($errorData['failed'] as $failed) {
                        $result['errors'][] = [
                            'unique_id' => $failed['unique_id'] ?? 'نامشخص',
                            'error' => $failed['error'] ?? 'خطای نامشخص'
                        ];
                        $result['failed']++;
                    }
                } else {
                    // اگر ساختار خطا متفاوت است، کل پاسخ را به عنوان خطا در نظر می‌گیریم
                    $errorMessage = $response->body();

                    // بررسی خطاهای خاص و قابل شناسایی
                    if ($response->status() === 400 && isset($errorData['message'])) {
                        $errorMessage = $errorData['message'];

                        // خطای فیلدهای اجباری
                        if (strpos($errorMessage, 'یکی از ویژگی‌های لازم') !== false ||
                            strpos($errorMessage, 'required property') !== false) {

                            // اضافه کردن اطلاعات تشخیصی بیشتر
                            $errorMessage .= ' - بررسی کنید تمامی فیلدهای اجباری مانند name وجود داشته باشند';
                        }
                    }

                    $result['errors'][] = [
                        'error' => $errorMessage,
                        'status' => $response->status()
                    ];
                    $result['failed'] = count($products);
                }

                return $result;
            }

            // تلاش برای رمزگشایی پاسخ JSON
            $responseData = null;
            try {
                $responseData = json_decode($response->body(), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('JSON error: ' . json_last_error_msg());
                }
            } catch (\Exception $jsonError) {
                Log::error('خطا در رمزگشایی پاسخ JSON: ' . $jsonError->getMessage(), [
                    'response_body' => substr($response->body(), 0, 1000), // فقط 1000 کاراکتر اول
                    'license_id' => $this->license_id
                ]);

                $result['errors'][] = [
                    'error' => 'خطا در رمزگشایی پاسخ JSON: ' . $jsonError->getMessage(),
                    'partial_response' => substr($response->body(), 0, 1000)
                ];
                $result['failed'] = count($products);

                return $result;
            }

            // بررسی ساختار پاسخ
            if (!is_array($responseData)) {
                Log::error('پاسخ API ووکامرس به فرمت مورد انتظار نیست', [
                    'response_type' => gettype($responseData),
                    'partial_response' => substr($response->body(), 0, 1000),
                    'license_id' => $this->license_id
                ]);

                $result['errors'][] = [
                    'error' => 'پاسخ API ووکامرس به فرمت مورد انتظار نیست',
                    'response_type' => gettype($responseData)
                ];
                $result['failed'] = count($products);

                return $result;
            }

            // پردازش موارد موفق
            if (isset($responseData['success']) && is_array($responseData['success'])) {
                $result['successful'] = count($responseData['success']);

                // لاگ جزئیات موارد موفق
                Log::info('محصولات با موفقیت درج شدند', [
                    'count' => $result['successful'],
                    'first_few' => array_slice($responseData['success'], 0, 5),
                    'license_id' => $this->license_id
                ]);
            } else {
                Log::warning('کلید "success" در پاسخ API ووکامرس وجود ندارد یا آرایه نیست', [
                    'license_id' => $this->license_id,
                    'response_keys' => array_keys($responseData)
                ]);
            }

            // پردازش موارد ناموفق
            if (isset($responseData['failed']) && is_array($responseData['failed'])) {
                foreach ($responseData['failed'] as $failed) {
                    $result['errors'][] = [
                        'unique_id' => $failed['unique_id'] ?? 'نامشخص',
                        'error' => $failed['error'] ?? 'خطای نامشخص'
                    ];
                    $result['failed']++;
                }

                // لاگ جزئیات موارد ناموفق
                if ($result['failed'] > 0) {
                    Log::warning('برخی محصولات درج نشدند', [
                        'count' => $result['failed'],
                        'errors' => array_slice($result['errors'], 0, 5), // نمایش 5 خطای اول
                        'license_id' => $this->license_id
                    ]);
                }
            }

            // ثبت اطلاعات زمان پاسخگویی
            $responseTime = $response->transferStats ? $response->transferStats->getTransferTime() : null;

            Log::info('نتیجه ' . ($operation === 'insert' ? 'درج' : 'به‌روزرسانی') . ' دسته محصولات:', [
                'license_id' => $this->license_id,
                'total_count' => count($products),
                'successful_count' => $result['successful'],
                'failed_count' => $result['failed'],
                'response_time' => $responseTime
            ]);

            return $result;

        } catch (\Exception $e) {
            // در صورت بروز هر گونه خطا در پردازش پاسخ
            Log::error('خطا در پردازش پاسخ API: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'license_id' => $this->license_id
            ]);

            $result['errors'][] = [
                'error' => 'خطا در پردازش پاسخ: ' . $e->getMessage()
            ];
            $result['failed'] = count($products);

            return $result;
        }
    }    protected function handleResponse($response, $products, $operation)
    {
        if (!$response->successful()) {
            $errorData = json_decode($response->body(), true);
            $errorMessage = 'خطا در ' . ($operation === 'insert' ? 'درج' : 'به‌روزرسانی') . ' محصولات: ';

            if (isset($errorData[$operation === 'insert' ? 'create' : 'update']) && is_array($errorData[$operation === 'insert' ? 'create' : 'update'])) {
                foreach ($errorData[$operation === 'insert' ? 'create' : 'update'] as $index => $result) {
                    if (isset($result['error'])) {
                        $productBarcode = $products[$index]['barcode'] ?? 'نامشخص';
                        $errorMessage .= sprintf(
                            "بارکد: %s - کد خطا: %s - پیام: %s | ",
                            $productBarcode,
                            $result['error']['code'] ?? 'نامشخص',
                            $result['error']['message'] ?? 'خطای نامشخص'
                        );
                    }
                }
            } else {
                $errorMessage .= $response->body();
            }

            throw new \Exception($errorMessage);
        }

        // لاگ کردن نتیجه عملیات
        $responseData = json_decode($response->body(), true);
        $successfulOperations = [];
        $failedOperations = [];

        if (isset($responseData[$operation === 'insert' ? 'create' : 'update']) && is_array($responseData[$operation === 'insert' ? 'create' : 'update'])) {
            foreach ($responseData[$operation === 'insert' ? 'create' : 'update'] as $index => $result) {
                if (isset($result['error'])) {
                    $failedOperations[] = [
                        'barcode' => $products[$index]['barcode'] ?? 'نامشخص',
                        'code' => $result['error']['code'] ?? 'نامشخص',
                        'message' => $result['error']['message'] ?? 'خطای نامشخص'
                    ];
                } else {
                    $successfulOperations[] = [
                        'id' => $result['id'] ?? 'نامشخص',
                        'barcode' => $result['barcode'] ?? 'نامشخص'
                    ];
                }
            }
        }

        Log::info('نتیجه ' . ($operation === 'insert' ? 'درج' : 'به‌روزرسانی') . ' دسته محصولات:', [
            'license_id' => $this->license_id,
            'total_count' => count($products),
            'successful_count' => count($successfulOperations),
            'failed_count' => count($failedOperations),
            'successful_operations' => $successfulOperations,
            'failed_operations' => $failedOperations,
            'response_time' => $response->transferStats->getTransferTime()
        ]);

        // اگر خطایی وجود داشت، آن را به صف خطا ارسال می‌کنیم
        if (!empty($failedOperations)) {
            foreach ($failedOperations as $failedOperation) {
                Log::error('خطا در ' . ($operation === 'insert' ? 'درج' : 'به‌روزرسانی') . ' محصول:', [
                    'license_id' => $this->license_id,
                    'barcode' => $failedOperation['barcode'],
                    'error_code' => $failedOperation['code'],
                    'error_message' => $failedOperation['message']
                ]);
            }
        }
    }

    /**
     * درج همه محصولات (مادر و واریانت‌ها) در یک batch
     */
    protected function insertAllProductsBatch(array $allProducts, $license, $wooCommerceApiKey): void
    {
        try {
            Log::info('درج دسته‌ای همه محصولات', [
                'total_count' => count($allProducts),
                'license_id' => $this->license_id
            ]);

            // تقسیم به chunks
            $chunks = array_chunk($allProducts, $this->batchSize);

            // آمارهای کلی
            $totalSuccessful = 0;
            $totalFailed = 0;

            foreach ($chunks as $index => $chunk) {
                // لاگ کردن داده‌های ارسالی به WooCommerce
                Log::info('ارسال محصولات به WooCommerce API', [
                    'chunk_index' => $index,
                    'products_count' => count($chunk),
                    'sample_product' => $chunk[0] ?? null,
                    'license_id' => $this->license_id,
                    'endpoint' => $license->website_url . '/wp-json/wc/v3/products/unique/batch',
                    'product_types' => collect($chunk)->groupBy('type')->map(function($items) {
                        return count($items);
                    })->toArray()
                ]);

                $response = Http::withOptions([
                    'timeout' => 180,
                    'connect_timeout' => 60
                ])->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ])->withBasicAuth(
                    $wooCommerceApiKey->api_key,
                    $wooCommerceApiKey->api_secret
                )->post($license->website_url . '/wp-json/wc/v3/products/unique/batch', [
                    'products' => $chunk
                ]);

                if ($response->successful()) {
                    $responseData = $response->json();

                    // شمارش موفقیت‌ها و خطاها
                    $successCount = 0;
                    $errorCount = 0;
                    $successfulProducts = [];
                    $failedProducts = [];

                    if (isset($responseData['create']) && is_array($responseData['create'])) {
                        foreach ($responseData['create'] as $createdProduct) {
                            if (isset($createdProduct['unique_id']) && isset($createdProduct['id'])) {
                                $successCount++;
                                $successfulProducts[] = [
                                    'unique_id' => $createdProduct['unique_id'],
                                    'woo_id' => $createdProduct['id'],
                                    'sku' => $createdProduct['sku'] ?? '',
                                    'type' => $createdProduct['type'] ?? 'unknown',
                                    'parent_unique_id' => $createdProduct['parent_unique_id'] ?? null
                                ];

                                Log::info('محصول با موفقیت درج شد', [
                                    'unique_id' => $createdProduct['unique_id'],
                                    'woo_id' => $createdProduct['id'],
                                    'sku' => $createdProduct['sku'] ?? '',
                                    'type' => $createdProduct['type'] ?? 'unknown',
                                    'parent_unique_id' => $createdProduct['parent_unique_id'] ?? null,
                                    'chunk_index' => $index,
                                    'license_id' => $this->license_id
                                ]);

                            } else if (isset($createdProduct['error'])) {
                                $errorCount++;
                                $failedProducts[] = [
                                    'unique_id' => $createdProduct['unique_id'] ?? 'نامشخص',
                                    'sku' => $createdProduct['sku'] ?? 'نامشخص',
                                    'error_code' => $createdProduct['error']['code'] ?? 'نامشخص',
                                    'error_message' => $createdProduct['error']['message'] ?? 'خطای نامشخص'
                                ];

                                Log::error('خطا در درج محصول', [
                                    'unique_id' => $createdProduct['unique_id'] ?? 'نامشخص',
                                    'sku' => $createdProduct['sku'] ?? 'نامشخص',
                                    'error_code' => $createdProduct['error']['code'] ?? 'نامشخص',
                                    'error_message' => $createdProduct['error']['message'] ?? 'خطای نامشخص',
                                    'chunk_index' => $index,
                                    'license_id' => $this->license_id
                                ]);
                            }
                        }
                    }

                    // لاگ خلاصه نتیجه chunk
                    Log::info('نتیجه درج chunk محصولات', [
                        'chunk_index' => $index,
                        'total_products' => count($chunk),
                        'successful_count' => $successCount,
                        'failed_count' => $errorCount,
                        'success_rate' => $successCount > 0 ? round(($successCount / count($chunk)) * 100, 2) . '%' : '0%',
                        'license_id' => $this->license_id,
                        'successful_products' => $successfulProducts,
                        'failed_products' => $failedProducts
                    ]);

                    // اضافه کردن به آمار کلی
                    $totalSuccessful += $successCount;
                    $totalFailed += $errorCount;
                } else {
                    Log::error('خطا در درج دسته‌ای محصولات', [
                        'response' => $response->body(),
                        'status' => $response->status(),
                        'chunk_index' => $index,
                        'products_count' => count($chunk),
                        'license_id' => $this->license_id
                    ]);

                    // اضافه کردن به آمار خطا
                    $totalFailed += count($chunk);
                }

                // تاخیر بین درخواست‌ها
                if ($index < count($chunks) - 1) {
                    sleep(2);
                }
            }

            // لاگ خلاصه نهایی کل عملیات درج
            Log::info('خلاصه نهایی درج دسته‌ای محصولات', [
                'license_id' => $this->license_id,
                'total_products_attempted' => count($allProducts),
                'total_successful' => $totalSuccessful,
                'total_failed' => $totalFailed,
                'success_rate' => count($allProducts) > 0 ? round(($totalSuccessful / count($allProducts)) * 100, 2) . '%' : '0%',
                'total_chunks' => count($chunks),
                'product_types_summary' => collect($allProducts)->groupBy('type')->map(function($items) {
                    return count($items);
                })->toArray()
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در درج دسته‌ای همه محصولات: ' . $e->getMessage(), [
                'license_id' => $this->license_id
            ]);
        }
    }

    /**
     * درج محصولات مادر و ساده (متد قدیمی - حفظ شده برای سازگاری)
     *
     * @param array $parentProducts
     * @param License $license
     * @param WooCommerceApiKey $wooCommerceApiKey
     * @return array نقشه محصولات با ID های ووکامرس
     */
    protected function insertParentProducts(array $parentProducts, $license, $wooCommerceApiKey): array
    {
        $parentProductsMap = [];

        try {
            Log::info('درج محصولات مادر و ساده', [
                'count' => count($parentProducts),
                'license_id' => $this->license_id
            ]);

            // تقسیم به chunks
            $chunks = array_chunk($parentProducts, $this->batchSize);

            foreach ($chunks as $index => $chunk) {
                // لاگ کردن داده‌های ارسالی به WooCommerce
                Log::info('ارسال محصولات به WooCommerce API', [
                    'chunk_index' => $index,
                    'products_count' => count($chunk),
                    'sample_product' => $chunk[0] ?? null,
                    'license_id' => $this->license_id,
                    'endpoint' => $license->website_url . '/wp-json/wc/v3/products/unique/batch'
                ]);

                $response = Http::withOptions([
                    'timeout' => 180,
                    'connect_timeout' => 60
                ])->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ])->withBasicAuth(
                    $wooCommerceApiKey->api_key,
                    $wooCommerceApiKey->api_secret
                )->post($license->website_url . '/wp-json/wc/v3/products/unique/batch', [
                    'products' => $chunk
                ]);

                if ($response->successful()) {
                    $responseData = $response->json();
                    if (isset($responseData['create'])) {
                        foreach ($responseData['create'] as $createdProduct) {
                            if (isset($createdProduct['unique_id']) && isset($createdProduct['id'])) {
                                $parentProductsMap[$createdProduct['unique_id']] = [
                                    'id' => $createdProduct['id'],
                                    'sku' => $createdProduct['sku'] ?? ''
                                ];

                                Log::info('محصول مادر درج شد', [
                                    'unique_id' => $createdProduct['unique_id'],
                                    'woo_id' => $createdProduct['id'],
                                    'sku' => $createdProduct['sku'] ?? ''
                                ]);
                            }
                        }
                    }
                } else {
                    Log::error('خطا در درج محصولات مادر', [
                        'response' => $response->body(),
                        'status' => $response->status()
                    ]);
                }

                // تاخیر بین درخواست‌ها
                if ($index < count($chunks) - 1) {
                    sleep(2);
                }
            }

        } catch (\Exception $e) {
            Log::error('خطا در درج محصولات مادر: ' . $e->getMessage());
        }

        return $parentProductsMap;
    }

    /**
     * درج واریانت‌های محصولات
     *
     * @param array $variationProducts
     * @param array $parentProductsMap
     * @param License $license
     * @param WooCommerceApiKey $wooCommerceApiKey
     */
    protected function insertVariations(array $variationProducts, array $parentProductsMap, $license, $wooCommerceApiKey): void
    {
        try {
            Log::info('درج واریانت‌های محصولات', [
                'variations_count' => count($variationProducts),
                'parent_products_count' => count($parentProductsMap),
                'license_id' => $this->license_id
            ]);

            // گروه‌بندی واریانت‌ها بر اساس parent_id
            $variationsByParent = [];
            foreach ($variationProducts as $variation) {
                $parentId = $variation['parent_id'] ?? null;

                if (!$parentId || !isset($parentProductsMap[$parentId])) {
                    Log::warning('محصول مادر برای واریانت یافت نشد', [
                        'variation_sku' => $variation['sku'] ?? '',
                        'variation_unique_id' => $variation['unique_id'] ?? '',
                        'parent_id' => $parentId,
                        'available_parents' => array_keys($parentProductsMap)
                    ]);
                    continue;
                }

                $parentWooId = $parentProductsMap[$parentId]['id'];

                // آماده‌سازی داده‌های واریانت
                $variationData = $variation;
                unset($variationData['parent_id']); // parent_id در URL قرار می‌گیرد
                unset($variationData['type']); // واریانت‌ها نیازی به type ندارند
                unset($variationData['attributes']); // واریانت‌ها attributes را از محصول مادر به ارث می‌برند

                // گروه‌بندی بر اساس parent WooCommerce ID
                if (!isset($variationsByParent[$parentWooId])) {
                    $variationsByParent[$parentWooId] = [];
                }
                $variationsByParent[$parentWooId][] = $variationData;
            }

            // درج واریانت‌ها برای هر محصول مادر با batch
            foreach ($variationsByParent as $parentWooId => $variations) {
                $this->insertVariationsBatch($variations, $parentWooId, $license, $wooCommerceApiKey);
                // تاخیر بین درج واریانت‌های محصولات مختلف
                sleep(2);
            }

        } catch (\Exception $e) {
            Log::error('خطا در درج واریانت‌ها: ' . $e->getMessage());
        }
    }

    /**
     * درج دسته‌ای واریانت‌ها برای یک محصول مادر
     */
    protected function insertVariationsBatch(array $variations, int $parentWooId, $license, $wooCommerceApiKey): void
    {
        try {
            // تقسیم به chunks کوچکتر برای واریانت‌ها
            $chunks = array_chunk($variations, min($this->batchSize, 5));

            foreach ($chunks as $index => $chunk) {
                // لاگ کردن داده‌های ارسالی
                Log::info('ارسال واریانت‌ها به WooCommerce API', [
                    'parent_woo_id' => $parentWooId,
                    'chunk_index' => $index,
                    'variations_count' => count($chunk),
                    'sample_variation' => $chunk[0] ?? null,
                    'license_id' => $this->license_id,
                    'endpoint' => $license->website_url . "/wp-json/wc/v3/products/{$parentWooId}/variations/batch"
                ]);

                $response = Http::withOptions([
                    'timeout' => 180,
                    'connect_timeout' => 60
                ])->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ])->withBasicAuth(
                    $wooCommerceApiKey->api_key,
                    $wooCommerceApiKey->api_secret
                )->post($license->website_url . "/wp-json/wc/v3/products/{$parentWooId}/variations/batch", [
                    'create' => $chunk
                ]);

                if ($response->successful()) {
                    $responseData = $response->json();
                    if (isset($responseData['create'])) {
                        foreach ($responseData['create'] as $createdVariation) {
                            Log::info('واریانت با موفقیت درج شد', [
                                'parent_woo_id' => $parentWooId,
                                'variation_woo_id' => $createdVariation['id'] ?? '',
                                'variation_sku' => $createdVariation['sku'] ?? '',
                                'variation_unique_id' => $createdVariation['unique_id'] ?? ''
                            ]);
                        }
                    }
                } else {
                    Log::error('خطا در درج دسته‌ای واریانت‌ها', [
                        'parent_woo_id' => $parentWooId,
                        'response' => $response->body(),
                        'status' => $response->status(),
                        'variations_count' => count($chunk)
                    ]);
                }

                // تاخیر بین chunks
                if ($index < count($chunks) - 1) {
                    sleep(1);
                }
            }

        } catch (\Exception $e) {
            Log::error('خطا در درج دسته‌ای واریانت‌ها: ' . $e->getMessage(), [
                'parent_woo_id' => $parentWooId
            ]);
        }
    }

    /**
     * استخراج attribute از نام واریانت
     *
     * @param string $name
     * @return string
     */
    protected function extractVariationAttribute(string $name, string $type = 'size'): string
    {
        if ($type === 'size') {
            // تلاش برای استخراج سایز از نام محصول
            if (preg_match('/سایز\s*([XLS]+|[\d]+)/u', $name, $matches)) {
                return trim($matches[1]);
            }

            if (preg_match('/size\s*([XLS]+|[\d]+)/i', $name, $matches)) {
                return trim($matches[1]);
            }

            // الگوهای مختلف برای سایز
            if (preg_match('/(XS|S|M|L|XL|XXL)/i', $name, $matches)) {
                return strtoupper($matches[1]);
            }

            // اگر سایز پیدا نشد، سایز پیش‌فرض
            return 'M';
        }

        if ($type === 'color') {
            // تلاش برای استخراج رنگ از نام محصول
            $colors = [
                'قرمز' => ['قرمز', 'red', 'سرخ'],
                'آبی' => ['آبی', 'blue', 'ابی'],
                'سیاه' => ['سیاه', 'black', 'مشکی'],
                'سفید' => ['سفید', 'white', 'سپید'],
                'سبز' => ['سبز', 'green'],
                'زرد' => ['زرد', 'yellow'],
                'نارنجی' => ['نارنجی', 'orange'],
                'بنفش' => ['بنفش', 'purple', 'موو'],
                'طوسی' => ['طوسی', 'gray', 'grey', 'خاکستری']
            ];

            foreach ($colors as $colorName => $patterns) {
                foreach ($patterns as $pattern) {
                    if (stripos($name, $pattern) !== false) {
                        return $colorName;
                    }
                }
            }

            // اگر رنگ پیدا نشد، رنگ پیش‌فرض
            return 'سفید';
        }

        // اگر نوع مشخص نشده، از آخرین بخش نام استفاده کن
        $parts = explode('-', $name);
        return trim(end($parts));
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception)
    {
        Log::error('خطا در پردازش صف درج دسته‌ای محصولات: ' . $exception->getMessage(), [
            'license_id' => $this->license_id
        ]);
    }
}
