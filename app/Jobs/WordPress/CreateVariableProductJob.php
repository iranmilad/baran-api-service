<?php

namespace App\Jobs\WordPress;

use App\Models\License;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductProperty;
use App\Models\ProductAttributeValue;
use App\Models\Notification;
use App\Traits\Baran\BaranApiTrait;
use App\Traits\WordPress\WooCommerceApiTrait;
use App\Traits\PriceUnitConverter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateVariableProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use BaranApiTrait, WooCommerceApiTrait, PriceUnitConverter;

    public $timeout = 300; // 5 دقیقه timeout
    public $tries = 3; // تعداد تلاش مجدد
    public $maxExceptions = 2;
    public $backoff = [60, 180, 300]; // زمان تاخیر بین تلاش‌های مجدد (ثانیه)

    protected $licenseId;
    protected $parentProductId;
    protected $wcCategoryId;

    /**
     * تبدیل فاصله به خط تیره بدون تبدیل به انگلیسی
     */
    protected function convertSpacesToDashes($text)
    {
        return str_replace(' ', '-', $text);
    }

    /**
     * تعیین اینکه آیا یک attribute باید variation باشد یا نه
     *
     * @param string $attributeName نام attribute
     * @return bool
     */
    protected function shouldBeVariationAttribute($attributeName)
    {
        // لیست attributes که باید variation باشند
        $variationAttributes = [
            'رنگ',
            'سایز',
            'اندازه',
            'Size',
            'Color',
            'سایز لباس',
            'سایز کفش'
        ];

        // بررسی اینکه آیا نام attribute در لیست variation attributes هست
        return in_array($attributeName, $variationAttributes);
    }

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId, $parentProductId, $wcCategoryId)
    {
        $this->licenseId = $licenseId;
        $this->parentProductId = $parentProductId;
        $this->wcCategoryId = $wcCategoryId;
        $this->onQueue('woocommerce-variable-product'); // صف اختصاصی برای محصولات متغیر
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        Log::info('=== شروع Job ایجاد محصول متغیر ===', [
            'license_id' => $this->licenseId,
            'parent_product_id' => $this->parentProductId,
            'job_id' => $this->job->getJobId()
        ]);

        try {
            $license = License::with(['user', 'woocommerceApiKey'])->findOrFail($this->licenseId);
            $parentProduct = Product::findOrFail($this->parentProductId);

            Log::info('License loaded', [
                'license_id' => $license->id,
                'user_id' => $license->user_id,
                'has_user' => $license->user ? 'yes' : 'no'
            ]);

            // گرفتن فرزندان (variants) - parent_id برابر است با item_id محصول parent
            $variants = Product::where('parent_id', $parentProduct->item_id)
                ->where('license_id', $this->licenseId)
                ->get();

            if ($variants->isEmpty()) {
                throw new \Exception('این محصول متغیر فاقد گونه است.');
            }

            // حذف parent از لیست variants (اگر به اشتباه اضافه شده باشد)
            $variants = $variants->reject(function ($variant) use ($parentProduct) {
                return $variant->item_id === $parentProduct->item_id;
            });

            if ($variants->isEmpty()) {
                throw new \Exception('این محصول متغیر فاقد گونه واقعی است.');
            }

            Log::info('Variants loaded', [
                'variants_count' => $variants->count(),
                'variant_ids' => $variants->pluck('item_id')->toArray(),
                'first_three_variants' => $variants->take(3)->map(function($v) {
                    return ['id' => $v->id, 'item_id' => $v->item_id, 'parent_id' => $v->parent_id];
                })->toArray()
            ]);

            // استعلام از Baran برای دریافت attributes
            $variantIds = $variants->pluck('item_id')->toArray();
            Log::info('Sending variant IDs to Baran API', [
                'variant_ids' => $variantIds,
                'count' => count($variantIds)
            ]);

            $baranItemsRaw = $this->getBaranItemsByIds($license, $variantIds);

            if (empty($baranItemsRaw)) {
                throw new \Exception('اطلاعات گونه‌ها از Baran دریافت نشد.');
            }

            // گروه‌بندی بر اساس itemID (چون ممکن است هر آیتم در چند انبار باشد)
            $groupedItems = $this->groupBaranItemsByItemId($baranItemsRaw);

            // دریافت تنظیمات انبار
            $userSetting = $license->userSetting;
            $defaultWarehouseCode = $userSetting->default_warehouse_code ?? null;

            // تبدیل از JSON اگر رشته است
            if (is_string($defaultWarehouseCode) && !empty($defaultWarehouseCode)) {
                $decoded = json_decode($defaultWarehouseCode, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $defaultWarehouseCode = $decoded;
                }
            }

            Log::info('تنظیمات انبار', [
                'default_warehouse_code' => $defaultWarehouseCode,
                'grouped_items_count' => count($groupedItems)
            ]);

            // فیلتر و تجمیع موجودی برای هر آیتم بر اساس تنظیمات
            $baranItems = [];
            foreach ($groupedItems as $itemId => $itemStocks) {
                if (empty($defaultWarehouseCode)) {
                    // اگر خالی باشد، موجودی از همه انبارها تجمیع شود
                    $totalQuantity = array_sum(array_column($itemStocks, 'stockQuantity'));
                    $result = $itemStocks[0];
                    $result['stockQuantity'] = $totalQuantity;

                    if (count($itemStocks) > 1) {
                        $warehouseNames = array_column($itemStocks, 'stockName');
                        $result['stockName'] = implode(' + ', array_slice($warehouseNames, 0, 3)) . (count($warehouseNames) > 3 ? '...' : '');
                    }

                    Log::info('موجودی از مجموع تمام انبارها محاسبه شد', [
                        'item_id' => strtolower($itemId),
                        'warehouses_count' => count($itemStocks),
                        'total_quantity' => $totalQuantity
                    ]);

                    $baranItems[] = $result;
                } else {
                    // اگر لیست انبارهای مجاز تنظیم شده، فقط از آنها تجمیع کن
                    $allowedWarehouses = is_array($defaultWarehouseCode) ? $defaultWarehouseCode : [$defaultWarehouseCode];

                    $filteredStocks = array_filter($itemStocks, function($stock) use ($allowedWarehouses) {
                        return in_array($stock['stockID'] ?? '', $allowedWarehouses);
                    });

                    if (empty($filteredStocks)) {
                        Log::warning('هیچ موجودی در انبار(های) مجاز یافت نشد', [
                            'item_id' => strtolower($itemId),
                            'allowed_warehouses' => $allowedWarehouses
                        ]);
                        continue;
                    }

                    $totalQuantity = array_sum(array_column($filteredStocks, 'stockQuantity'));
                    $result = reset($filteredStocks);
                    $result['stockQuantity'] = $totalQuantity;

                    if (count($filteredStocks) > 1) {
                        $warehouseNames = array_column($filteredStocks, 'stockName');
                        $result['stockName'] = implode(' + ', $warehouseNames);
                    }

                    Log::info('موجودی از انبار(های) مجاز محاسبه شد', [
                        'item_id' => strtolower($itemId),
                        'warehouses_count' => count($filteredStocks),
                        'total_quantity' => $totalQuantity
                    ]);

                    $baranItems[] = $result;
                }
            }

            Log::info('Baran items received in job', [
                'parent_item_id' => $parentProduct->item_id,
                'items_count' => count($baranItems),
                'first_three_baran_items' => array_slice(array_map(function($item) {
                    return [
                        'itemID' => $item['itemID'] ?? null,
                        'parentID' => $item['parentID'] ?? null,
                        'itemName' => $item['itemName'] ?? null
                    ];
                }, $baranItems), 0, 3)
            ]);

            // بررسی اینکه آیا attributes وجود دارند
            $hasAttributes = false;
            foreach ($baranItems as $baranItem) {
                if (!empty($baranItem['attributes'])) {
                    $hasAttributes = true;
                    break;
                }
            }

            if (!$hasAttributes) {
                throw new \Exception('گونه‌ها فاقد ویژگی (attributes) هستند.');
            }

            // بررسی و ایجاد attributes در دیتابیس - صرفاً با دیتابیس کار می‌کنیم
            // فقط attributes فعال (is_active=true) و متغیر (is_variation=true) استفاده می‌شوند
            $attributesMap = $this->checkAndCreateAttributesInDatabase($license, $baranItems, $variants);

            Log::info('Attributes prepared from database only', [
                'attributes_count' => count($attributesMap),
                'note' => 'Only active and variation attributes will be sent to WooCommerce'
            ]);

            // آماده‌سازی محصولات برای batch API
            $batchProducts = $this->prepareBatchProductsData($license, $parentProduct, $baranItems, $variants, $attributesMap);

            Log::info('Batch products prepared', [
                'products_count' => count($batchProducts),
                'parent' => $batchProducts[0] ?? null
            ]);

            // ارسال به WooCommerce
            $batchResult = $this->createWooCommerceBatchProducts($license, $batchProducts);

            if (!$batchResult['success']) {
                throw new \Exception($batchResult['message'] ?? 'خطا در ایجاد محصولات دسته‌ای');
            }

            // بررسی آرایه failed
            if (isset($batchResult['data']['failed']) && !empty($batchResult['data']['failed'])) {
                // فیلتر کردن خطاهای "محصول قبلاً ثبت شده"
                $realErrors = array_filter($batchResult['data']['failed'], function($failed) {
                    return !str_contains($failed['error'], 'قبلاً ثبت شده است') &&
                           !str_contains($failed['error'], 'already registered');
                });

                // اگر تمام خطاها مربوط به محصول قبلاً ثبت شده باشند، به جای exception، لاگ بگیریم
                if (empty($realErrors)) {
                    Log::warning('Parent product already exists in WooCommerce', [
                        'failed_items' => $batchResult['data']['failed']
                    ]);
                    // ادامه می‌دهیم چون محصول موجود است
                } else {
                    $errorMessages = array_map(function($failed) {
                        return "Product {$failed['unique_id']}: {$failed['error']}";
                    }, $realErrors);

                    throw new \Exception('برخی محصولات با خطا مواجه شدند: ' . implode(', ', $errorMessages));
                }
            }

            // بررسی آرایه success (اگر هیچ موفقیتی نبود و تمام خطاها واقعی بودند)
            $hasSuccessfulProducts = !empty($batchResult['data']['success']);
            $hasOnlyDuplicateErrors = isset($batchResult['data']['failed']) &&
                                      !empty($batchResult['data']['failed']) &&
                                      empty($realErrors ?? []);

            if (!$hasSuccessfulProducts && !$hasOnlyDuplicateErrors) {
                throw new \Exception('هیچ محصولی با موفقیت ایجاد نشد.');
            }

            // اگر محصول قبلاً موجود بود، پیام مناسب لاگ شود
            if ($hasOnlyDuplicateErrors) {
                Log::info('=== Job completed - Parent product already exists ===', [
                    'parent_product_id' => $parentProduct->id,
                    'message' => 'محصول والد قبلاً در WooCommerce ایجاد شده است'
                ]);
            } else {
                Log::info('=== Job با موفقیت تکمیل شد ===', [
                    'success_count' => count($batchResult['data']['success']),
                    'products' => $batchResult['data']['success']
                ]);
            }

            // ایجاد Notification برای کاربر
            if ($license->user_id) {
                // تعیین پیام بر اساس اینکه محصول جدید است یا قبلاً وجود داشت
                if ($hasOnlyDuplicateErrors) {
                    $title = 'محصول متغیر قبلاً ثبت شده است';
                    $message = 'محصول "' . $parentProduct->item_name . '" قبلاً در WooCommerce ایجاد شده است. برای به‌روزرسانی از گزینه سینک استفاده کنید.';
                } else {
                    $title = 'محصول متغیر با موفقیت ثبت شد';
                    $successCount = count($batchResult['data']['success']);
                    $message = 'محصول "' . $parentProduct->item_name . '" با ' . $successCount . ' گونه با موفقیت در سیستم ثبت شد.';
                }

                Notification::create([
                    'user_id' => $license->user_id,
                    'title' => $title,
                    'message' => $message,
                    'type' => $hasOnlyDuplicateErrors ? 'info' : 'success',
                    'is_read' => false,
                    'is_active' => true,
                    'data' => json_encode([
                        'product_id' => $parentProduct->id,
                        'product_name' => $parentProduct->item_name,
                        'variants_count' => $hasSuccessfulProducts ? count($batchResult['data']['success']) : 0,
                        'license_id' => $this->licenseId,
                        'already_exists' => $hasOnlyDuplicateErrors
                    ])
                ]);

                Log::info('Notification created for user', [
                    'user_id' => $license->user_id,
                    'product_name' => $parentProduct->item_name
                ]);
            } else {
                Log::warning('Cannot create notification - no user_id in license', [
                    'license_id' => $this->licenseId
                ]);
            }

        } catch (\Exception $e) {
            Log::error('خطا در Job ایجاد محصول متغیر', [
                'error' => $e->getMessage(),
                'license_id' => $this->licenseId,
                'parent_product_id' => $this->parentProductId,
                'trace' => $e->getTraceAsString()
            ]);

            // ایجاد Notification خطا برای کاربر
            try {
                $license = License::find($this->licenseId);
                $parentProduct = Product::find($this->parentProductId);

                if ($license && $parentProduct && $license->user_id) {
                    Notification::create([
                        'user_id' => $license->user_id,
                        'title' => 'خطا در ثبت محصول متغیر',
                        'message' => 'محصول "' . $parentProduct->item_name . '" با خطا مواجه شد: ' . $e->getMessage(),
                        'type' => 'error',
                        'is_read' => false,
                        'is_active' => true,
                        'data' => json_encode([
                            'product_id' => $parentProduct->id,
                            'product_name' => $parentProduct->item_name,
                            'error' => $e->getMessage(),
                            'license_id' => $this->licenseId
                        ])
                    ]);

                    Log::info('Error notification created for user', [
                        'user_id' => $license->user_id,
                        'product_name' => $parentProduct->item_name
                    ]);
                }
            } catch (\Exception $notifException) {
                Log::error('خطا در ایجاد notification', ['error' => $notifException->getMessage()]);
            }

            // ارسال مجدد به صف در صورت خطا
            throw $e;
        }
    }

    /**
     * بررسی و ایجاد attributes و properties در دیتابیس
     */
    protected function checkAndCreateAttributesInDatabase($license, $baranItems, $variants)
    {
        $attributesMap = [];
        $parentProduct = null;

        Log::info('=== شروع بررسی و ایجاد Attributes در دیتابیس (Job) ===');

        // پیدا کردن محصول parent از اولین variant (parent_id برابر با item_id است)
        if ($variants->isNotEmpty()) {
            $firstVariant = $variants->first();
            $parentProduct = Product::where('license_id', $license->id)
                ->where('item_id', $firstVariant->parent_id)
                ->first();

            Log::info('Parent product found in job:', [
                'parent_id' => $parentProduct ? $parentProduct->id : null,
                'parent_item_id' => $parentProduct ? $parentProduct->item_id : null
            ]);
        }

        foreach ($baranItems as $index => $baranItem) {
            $itemInfo = $this->extractBaranProductInfo($baranItem);

            if ($index === 0) {
                Log::info('First Baran item info extracted', [
                    'item_id' => $itemInfo['item_id'],
                    'parent_id' => $itemInfo['parent_id'],
                    'item_name' => $itemInfo['item_name'],
                    'attributes_count' => count($itemInfo['attributes'])
                ]);
            }

            // سعی در پیدا کردن variant با item_id (case-insensitive)
            $variant = $variants->first(function ($v) use ($itemInfo) {
                return strcasecmp($v->item_id, $itemInfo['item_id']) === 0;
            });

            // اگر پیدا نشد، سعی کن با parent_id پیدا کنی (case-insensitive)
            if (!$variant && $itemInfo['parent_id']) {
                $variant = $variants->first(function ($v) use ($itemInfo) {
                    return strcasecmp($v->item_id, $itemInfo['parent_id']) === 0;
                });
            }

            if (!$variant) {
                if ($index === 0) {
                    Log::warning('First variant not found - debugging info', [
                        'baran_item_id' => $itemInfo['item_id'],
                        'baran_parent_id' => $itemInfo['parent_id'],
                        'available_variant_ids' => $variants->pluck('item_id')->toArray()
                    ]);
                }
                Log::warning('Variant not found in job', [
                    'baran_item_id' => $itemInfo['item_id'],
                    'baran_parent_id' => $itemInfo['parent_id']
                ]);
                continue;
            }

            foreach ($itemInfo['attributes'] as $attr) {
                $attributeName = $attr['name'];
                $attributeValue = $attr['value'];

                // بررسی وجود attribute در دیتابیس
                $productAttribute = ProductAttribute::where('license_id', $license->id)
                    ->where('name', $attributeName)
                    ->first();

                // اگر وجود نداشت، ایجاد کن
                if (!$productAttribute) {
                    // تعیین اینکه آیا این attribute باید variation باشد یا نه
                    $isVariation = $this->shouldBeVariationAttribute($attributeName);

                    $productAttribute = ProductAttribute::create([
                        'license_id' => $license->id,
                        'name' => $attributeName,
                        'slug' => $this->convertSpacesToDashes($attributeName),
                        'is_variation' => $isVariation,
                        'is_active' => true,
                        'is_visible' => true,
                        'sort_order' => 0
                    ]);

                    Log::info('Created new attribute in database (Job)', [
                        'attribute_name' => $attributeName,
                        'is_variation' => $isVariation,
                        'license_id' => $license->id
                    ]);
                }

                // بررسی فعال بودن ویژگی
                if (!$productAttribute->is_active) {
                    Log::info('Attribute is not active, skipping', [
                        'attribute_name' => $attributeName,
                        'attribute_id' => $productAttribute->id
                    ]);
                    continue;
                }

                // بررسی وجود property - مقایسه با name نه value
                $productProperty = ProductProperty::where('product_attribute_id', $productAttribute->id)
                    ->where('name', $attributeValue)
                    ->first();

                if (!$productProperty) {
                    $productProperty = ProductProperty::create([
                        'product_attribute_id' => $productAttribute->id,
                        'name' => $attributeValue,  // مقدار از API
                        'slug' => $this->convertSpacesToDashes($attributeValue),
                        'value' => $attributeValue,  // همان مقدار
                        'is_active' => true,
                        'sort_order' => 0
                    ]);

                    Log::info('Created new property in database (Job)', [
                        'property_value' => $attributeValue,
                        'attribute_name' => $attributeName
                    ]);
                }

                // بررسی فعال بودن پروپرتی
                if (!$productProperty->is_active) {
                    Log::info('Property is not active, skipping', [
                        'attribute_name' => $attributeName,
                        'property_value' => $attributeValue,
                        'property_id' => $productProperty->id
                    ]);
                    continue;
                }

                // اضافه کردن به نقشه
                if (!isset($attributesMap[$attributeName])) {
                    $attributesMap[$attributeName] = [
                        'db_attribute' => $productAttribute,
                        'values' => [],
                        'properties' => []
                    ];
                }

                if (!in_array($attributeValue, $attributesMap[$attributeName]['values'])) {
                    $attributesMap[$attributeName]['values'][] = $attributeValue;
                    // اضافه کردن property به نقشه
                    if (!isset($attributesMap[$attributeName]['properties'][$attributeValue])) {
                        $attributesMap[$attributeName]['properties'][$attributeValue] = $productProperty;
                    }
                }

                // ذخیره ارتباط attribute و property با variant در جدول product_attribute_values
                $existingVariantValue = ProductAttributeValue::where('product_id', $variant->id)
                    ->where('product_attribute_id', $productAttribute->id)
                    ->where('product_property_id', $productProperty->id)
                    ->first();

                if (!$existingVariantValue) {
                    ProductAttributeValue::create([
                        'product_id' => $variant->id,
                        'product_attribute_id' => $productAttribute->id,
                        'product_property_id' => $productProperty->id,
                        'sort_order' => 0
                    ]);

                    Log::info('Added attribute-property to variant', [
                        'variant_id' => $variant->id,
                        'variant_item_id' => $variant->item_id,
                        'attribute_name' => $attributeName,
                        'property_value' => $attributeValue
                    ]);
                }
            }
        }

        // اضافه کردن attributes به محصول parent
        if ($parentProduct && !empty($attributesMap)) {
            Log::info('Adding attributes to parent product in job', [
                'parent_id' => $parentProduct->id,
                'parent_item_id' => $parentProduct->item_id,
                'attributes_count' => count($attributesMap)
            ]);

            foreach ($attributesMap as $attributeName => $data) {
                $productAttribute = $data['db_attribute'];

                // تعیین slug ویژگی: اگر موجود باشد از آن استفاده کن، وگرنه از name استفاده کن
                $attributeSlug = !empty($productAttribute->slug) ? $productAttribute->slug : $productAttribute->name;

                // اگر slug خالی بود، آن را در دیتابیس ذخیره کن
                if (empty($productAttribute->slug)) {
                    $productAttribute->update(['slug' => $attributeSlug]);
                    Log::info('Updated attribute slug in job', [
                        'attribute_id' => $productAttribute->id,
                        'attribute_name' => $productAttribute->name,
                        'attribute_slug' => $attributeSlug
                    ]);
                }

                // برای هر value، یک ارتباط به parent اضافه کن
                foreach ($data['properties'] as $value => $property) {
                    // بررسی وجود بر اساس product_id و product_attribute_id (unique constraint)
                    $existingValue = ProductAttributeValue::where('product_id', $parentProduct->id)
                        ->where('product_attribute_id', $productAttribute->id)
                        ->first();

                    if (!$existingValue) {
                        ProductAttributeValue::create([
                            'product_id' => $parentProduct->id,
                            'product_attribute_id' => $productAttribute->id,
                            'product_property_id' => $property->id,
                            'sort_order' => 0
                        ]);

                        Log::info('Added attribute to parent product in job', [
                            'attribute_name' => $attributeName,
                            'attribute_slug' => $attributeSlug,
                            'property_name' => $property->name
                        ]);
                    }
                }
            }
        }

        return $attributesMap;
    }

    /**
     * بررسی و اطمینان از وجود attributes و terms مورد نیاز
     */
    protected function ensureAttributesAndTermsExist($license, $attributesMap, $attributesResult)
    {
        if (!$attributesResult['success']) {
            throw new \Exception('خطا در دریافت attributes: ' . $attributesResult['message']);
        }

        $existingAttributes = $attributesResult['data'] ?? [];

        // ایجاد map از attributes موجود برای جستجوی سریع (بر اساس name و slug)
        $existingAttributesMap = [];
        $existingAttributesBySlug = [];
        foreach ($existingAttributes as $attr) {
            $existingAttributesMap[$attr['name']] = $attr;
            if (isset($attr['slug'])) {
                $existingAttributesBySlug[$attr['slug']] = $attr;
            }
        }

        Log::info('لیست attributes موجود دریافت شد', [
            'existing_count' => count($existingAttributes),
            'needed_count' => count($attributesMap),
            'existing_names' => array_column($existingAttributes, 'name')
        ]);

        foreach ($attributesMap as $attributeName => $data) {
            $attributeSlug = $this->convertSpacesToDashes($attributeName);

            // بررسی وجود attribute - ابتدا با name، سپس با slug
            $existingAttribute = null;
            if (isset($existingAttributesMap[$attributeName])) {
                $existingAttribute = $existingAttributesMap[$attributeName];
            } elseif (isset($existingAttributesBySlug[$attributeSlug])) {
                $existingAttribute = $existingAttributesBySlug[$attributeSlug];
            }

            if (!$existingAttribute) {
                // attribute وجود ندارد - باید ایجاد شود
                Log::info('Attribute جدید نیاز به ایجاد دارد', [
                    'attribute_name' => $attributeName,
                    'attribute_slug' => $attributeSlug
                ]);

                $createResult = $this->createWooCommerceAttribute($license, [
                    'name' => $attributeName,
                    'slug' => $attributeSlug, // فاصله‌ها به خط تیره تبدیل شده بدون تبدیل به انگلیسی
                    'type' => 'select',
                    'order_by' => 'menu_order',
                    'has_archives' => false
                ]);

                if (!$createResult['success']) {
                    throw new \Exception("خطا در ایجاد attribute '{$attributeName}': " . $createResult['message']);
                }

                // اضافه کردن به map موجود
                $existingAttributesMap[$attributeName] = [
                    'name' => $attributeName,
                    'slug' => $attributeSlug,
                    'id' => $createResult['data']['id'] ?? null
                ];

                Log::info('Attribute جدید ایجاد شد', [
                    'attribute_name' => $attributeName,
                    'attribute_slug' => $attributeSlug
                ]);
            } else {
                Log::info('Attribute قبلاً وجود داشت', [
                    'attribute_name' => $attributeName,
                    'existing_name' => $existingAttribute['name'],
                    'existing_slug' => $existingAttribute['slug'] ?? 'N/A'
                ]);
            }

            // بررسی terms - WooCommerce در batch خودش terms را ایجاد می‌کند
            foreach ($data['values'] as $termValue) {
                Log::info('Term در batch ایجاد خواهد شد', [
                    'attribute_name' => $attributeName,
                    'term_value' => $termValue
                ]);
            }
        }

        Log::info('بررسی attributes و terms کامل شد');
    }

    /**
     * اطمینان از وجود attributes در WooCommerce (تابع قدیمی - deprecated)
     */
    protected function ensureAttributesExistInWooCommerce($license, &$attributesMap)
    {
        $totalAttributes = count($attributesMap);
        $currentIndex = 0;

        foreach ($attributesMap as $attributeName => &$data) {
            $currentIndex++;

            Log::info("بررسی ویژگی {$currentIndex}/{$totalAttributes} در WooCommerce", [
                'attribute_name' => $attributeName
            ]);

            // بررسی وجود attribute در WooCommerce
            $wcAttributeResult = $this->findOrCreateWooCommerceAttribute($license, $attributeName);

            if (!$wcAttributeResult['success']) {
                throw new \Exception("خطا در ایجاد ویژگی '{$attributeName}' در WooCommerce: " . $wcAttributeResult['message']);
            }

            $data['wc_attribute'] = $wcAttributeResult['data'];

            Log::info('Attribute ensured in WooCommerce (Job)', [
                'attribute_name' => $attributeName,
                'wc_attribute_id' => $data['wc_attribute']['id']
            ]);

            // بررسی و ایجاد properties (terms) در WooCommerce
            $totalTerms = count($data['values']);
            $termIndex = 0;

            foreach ($data['values'] as $value) {
                $termIndex++;

                Log::info("بررسی term {$termIndex}/{$totalTerms} برای ویژگی '{$attributeName}'", [
                    'term_value' => $value
                ]);

                $termResult = $this->findOrCreateWooCommerceAttributeTerm(
                    $license,
                    $data['wc_attribute']['id'],
                    $value
                );

                if (!$termResult['success']) {
                    throw new \Exception("خطا در ایجاد term '{$value}' برای ویژگی '{$attributeName}': " . $termResult['message']);
                }

                $data['wc_terms'][$value] = $termResult['data'];
            }

            Log::info('Ensured attribute exists in WooCommerce (Job)', [
                'attribute_name' => $attributeName,
                'terms_count' => count($data['values'])
            ]);
        }
    }

    /**
     * آماده‌سازی داده‌های batch
     */
    protected function prepareBatchProductsData($license, $parentProduct, $baranItems, $variants, $attributesMap)
    {
        $products = [];

        // جمع‌آوری تمام options برای هر attribute
        $attributeOptions = [];
        foreach ($attributesMap as $attributeName => $data) {
            $attributeOptions[$attributeName] = $data['values'];
        }

        // محصول parent - تفکیک attributes بر اساس is_variation
        $wcAttributes = [];

        foreach ($attributesMap as $attributeName => $data) {
            $dbAttribute = $data['db_attribute'];

            // بررسی is_variation و is_active از دیتابیس
            $isVariation = $dbAttribute->is_variation ?? false;
            $isActive = $dbAttribute->is_active ?? true;

            // فقط attributes فعال را پردازش کن
            if (!$isActive) {
                continue;
            }

            // استفاده از slug ویژگی از دیتابیس
            $attributeSlug = !empty($dbAttribute->slug) ? $dbAttribute->slug : $dbAttribute->name;

            // اضافه کردن پیشوند pa_ برای taxonomy attributes (استاندارد WooCommerce)
            // اگر قبلاً pa_ ندارد، اضافه کن
            if (!str_starts_with($attributeSlug, 'pa_')) {
                $attributeSlug = 'pa_' . $attributeSlug;
            }

            // جمع‌آوری name های properties از دیتابیس برای options
            $propertyOptions = [];
            foreach ($data['properties'] as $value => $property) {
                // در محصول مادر همیشه از name استفاده می‌کنیم نه slug
                $propertyOptions[] = $property->name;
            }

            // همه attributes را به آرایه attributes اضافه کن (با variation مناسب)
            $wcAttributes[] = [
                'name' => $attributeName,  // نام اصلی ویژگی از دیتابیس
                'slug' => $attributeSlug,  // با پیشوند pa_
                'variation' => $isVariation,  // استفاده از تنظیم دیتابیس
                'visible' => $dbAttribute->is_visible ?? true,  // استفاده از تنظیم دیتابیس
                'options' => $propertyOptions  // استفاده از name های properties
            ];

            Log::info('اضافه کردن attribute به parent', [
                'attribute_name' => $attributeName,
                'attribute_slug' => $attributeSlug,
                'is_variation' => $isVariation,
                'is_visible' => $dbAttribute->is_visible ?? true,
                'options_count' => count($propertyOptions),
                'options' => $propertyOptions
            ]);
        }

        $parentProductData = [
            'unique_id' => $parentProduct->item_id,
            'name' => $parentProduct->item_name,
            'type' => 'variable',
            'sku' => $parentProduct->barcode ?? $parentProduct->item_id,
            'status' => 'draft',
            'manage_stock' => false,
            'stock_status' => 'instock',
            'categories' => [
                ['id' => (int)$this->wcCategoryId]
            ],
            'attributes' => $wcAttributes
        ];

        $products[] = $parentProductData;

        Log::info('شروع پردازش variations', [
            'baran_items_count' => count($baranItems),
            'variants_count' => $variants->count()
        ]);

        // محصولات variation
        foreach ($baranItems as $baranItem) {
            $itemInfo = $this->extractBaranProductInfo($baranItem);

            Log::info('بررسی Baran item', [
                'item_id' => $itemInfo['item_id'] ?? null,
                'parent_id' => $itemInfo['parent_id'] ?? null,
                'has_attributes' => !empty($itemInfo['attributes']),
                'attributes_count' => count($itemInfo['attributes'] ?? [])
            ]);

            // Skip items without attributes (parent products, not variations)
            if (empty($itemInfo['attributes'])) {
                Log::info('Skip کردن item بدون attributes', [
                    'item_id' => $itemInfo['item_id'] ?? null
                ]);
                continue;
            }

            // Case-insensitive matching برای پیدا کردن variant
            $variant = $variants->first(function ($v) use ($itemInfo) {
                return strcasecmp($v->item_id, $itemInfo['item_id']) === 0;
            });

            if (!$variant && $itemInfo['parent_id']) {
                $variant = $variants->first(function ($v) use ($itemInfo) {
                    return strcasecmp($v->item_id, $itemInfo['parent_id']) === 0;
                });
            }

            if (!$variant) {
                Log::warning('Variant پیدا نشد', [
                    'item_id' => $itemInfo['item_id'] ?? null,
                    'parent_id' => $itemInfo['parent_id'] ?? null,
                    'available_variant_ids' => $variants->pluck('item_id')->take(5)->toArray()
                ]);
                continue;
            }

            Log::info('Variant پیدا شد', [
                'variant_item_id' => $variant->item_id,
                'variant_name' => $variant->item_name
            ]);

            // استفاده از قیمت‌های دیتابیس اگر موجود باشند (به‌روزتر از Baran API)
            if ($variant->price_amount > 0) {
                $itemInfo['price'] = $variant->price_amount;
            }
            if (isset($variant->price_after_discount) && $variant->price_after_discount > 0) {
                $itemInfo['sale_price'] = $variant->price_after_discount;
                // محاسبه درصد تخفیف
                if ($variant->price_amount > 0) {
                    $itemInfo['current_discount'] = (($variant->price_amount - $variant->price_after_discount) / $variant->price_amount) * 100;
                }
            }

            Log::info('قیمت‌های نهایی برای variation', [
                'variant_item_id' => $variant->item_id,
                'price_from_db' => $variant->price_amount,
                'price_after_discount_from_db' => $variant->price_after_discount,
                'price_final' => $itemInfo['price'],
                'sale_price_final' => $itemInfo['sale_price'],
                'discount_final' => $itemInfo['current_discount']
            ]);

            // آماده‌سازی attributes برای variation
            $variationAttributes = [];

            foreach ($itemInfo['attributes'] as $attr) {
                $attributeData = $attributesMap[$attr['name']] ?? null;
                if (!$attributeData) {
                    Log::warning('Attribute not found in map', [
                        'attribute_name' => $attr['name'],
                        'variant_item_id' => $variant->item_id
                    ]);
                    continue;
                }

                $dbAttribute = $attributeData['db_attribute'];
                $isVariation = $dbAttribute->is_variation ?? false;

                // فقط attributes با is_variation = true به variation اضافه می‌شوند
                if (!$isVariation) {
                    Log::info('Skip کردن attribute غیر متغیر در variation', [
                        'attribute_name' => $attr['name'],
                        'variant_item_id' => $variant->item_id,
                        'is_variation' => false
                    ]);
                    continue;
                }

                // استفاده از slug ویژگی (اگر موجود باشد)
                $attributeSlug = !empty($dbAttribute->slug) ? $dbAttribute->slug : $dbAttribute->name;

                // اضافه کردن پیشوند pa_ برای taxonomy attributes (استاندارد WooCommerce)
                if (!str_starts_with($attributeSlug, 'pa_')) {
                    $attributeSlug = 'pa_' . $attributeSlug;
                }

                // پیدا کردن property برای استفاده از نام و slug آن
                $property = $attributeData['properties'][$attr['value']] ?? null;

                if (!$property) {
                    Log::warning('Property not found for attribute', [
                        'attribute_name' => $attr['name'],
                        'property_value' => $attr['value'],
                        'variant_item_id' => $variant->item_id
                    ]);
                    continue;
                }

                // استفاده از name پروپرتی از دیتابیس (در صورت فعال بودن)
                $propertyName = $property->name;
                $propertySlug = !empty($property->slug) ? $property->slug : $propertyName;

                // name باید نام اصلی attribute باشد، option باید slug property باشد
                $variationAttributes[] = [
                    'name' => $attr['name'],  // نام اصلی ویژگی از Baran API
                    'slug' => $attributeSlug,  // با پیشوند pa_
                    'option' => $propertySlug  // استفاده از slug برای option
                ];

                Log::info('افزودن variation attribute', [
                    'attribute_name' => $attr['name'],
                    'attribute_slug' => $attributeSlug,
                    'property_name' => $propertyName,
                    'property_slug' => $propertySlug,
                    'is_variation' => true,
                    'option_value' => $propertySlug
                ]);
            }

            Log::info('Variation attributes prepared', [
                'variant_item_id' => $variant->item_id,
                'variation_attributes_count' => count($variationAttributes)
            ]);

            $stockStatus = $itemInfo['stock_quantity'] > 0 ? 'instock' : 'outofstock';

            // تبدیل قیمت بر اساس تنظیمات کاربر
            $userSetting = $license->userSetting;
            $regularPrice = (float)$itemInfo['price'];
            if ($userSetting && $userSetting->rain_sale_price_unit && $userSetting->woocommerce_price_unit) {
                $regularPrice = $this->convertPriceUnit(
                    $regularPrice,
                    $userSetting->rain_sale_price_unit,
                    $userSetting->woocommerce_price_unit
                );
            }

            $variationData = [
                'unique_id' => $variant->item_id,
                'parent_unique_id' => $parentProduct->item_id,
                'sku' => $itemInfo['barcode'] ?? $variant->item_id,
                'regular_price' => (string)$regularPrice,
                'manage_stock' => true,
                'stock_quantity' => (int)$itemInfo['stock_quantity'],
                'stock_status' => $stockStatus,
                'status' => 'publish',
                'attributes' => $variationAttributes
            ];

            // بررسی و اضافه کردن قیمت تخفیف
            Log::info('بررسی قیمت تخفیف برای variation', [
                'item_id' => $itemInfo['item_id'],
                'current_discount' => $itemInfo['current_discount'],
                'sale_price' => $itemInfo['sale_price'],
                'price' => $itemInfo['price'],
                'condition_discount' => $itemInfo['current_discount'] > 0,
                'condition_sale_price' => $itemInfo['sale_price'] > 0,
                'condition_less_than' => $itemInfo['sale_price'] < $itemInfo['price']
            ]);

            if ($itemInfo['current_discount'] > 0 && $itemInfo['sale_price'] > 0 && $itemInfo['sale_price'] < $itemInfo['price']) {
                $salePrice = (float)$itemInfo['sale_price'];
                if ($userSetting && $userSetting->rain_sale_price_unit && $userSetting->woocommerce_price_unit) {
                    $salePrice = $this->convertPriceUnit(
                        $salePrice,
                        $userSetting->rain_sale_price_unit,
                        $userSetting->woocommerce_price_unit
                    );
                }
                $variationData['sale_price'] = (string)$salePrice;

                Log::info('قیمت تخفیف اضافه شد', [
                    'item_id' => $itemInfo['item_id'],
                    'original_sale_price' => $itemInfo['sale_price'],
                    'converted_sale_price' => $salePrice
                ]);
            } else {
                Log::warning('قیمت تخفیف اضافه نشد - شرایط برآورده نشد', [
                    'item_id' => $itemInfo['item_id']
                ]);
            }

            if (!empty($itemInfo['description'])) {
                $variationData['description'] = $itemInfo['description'];
            }

            if (!empty($itemInfo['short_description'])) {
                $variationData['short_description'] = $itemInfo['short_description'];
            }

            $products[] = $variationData;

            Log::info('Variation اضافه شد به products', [
                'variant_item_id' => $variant->item_id,
                'sku' => $variationData['sku'],
                'total_products_count' => count($products)
            ]);
        }

        Log::info('پایان پردازش variations', [
            'total_products_in_batch' => count($products),
            'parent_count' => 1,
            'variations_count' => count($products) - 1
        ]);

        return $products;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception)
    {
        Log::error('Job ایجاد محصول متغیر با شکست مواجه شد', [
            'license_id' => $this->licenseId,
            'parent_product_id' => $this->parentProductId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }
}
