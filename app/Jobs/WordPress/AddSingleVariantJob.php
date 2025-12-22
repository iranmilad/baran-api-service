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

class AddSingleVariantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use BaranApiTrait, WooCommerceApiTrait, PriceUnitConverter;

    public $timeout = 300;
    public $tries = 3;

    protected $licenseId;
    protected $parentProductId;
    protected $variantId;
    protected $wcCategoryId;

    public function __construct($licenseId, $parentProductId, $variantId, $wcCategoryId = null)
    {
        $this->licenseId = $licenseId;
        $this->parentProductId = $parentProductId;
        $this->variantId = $variantId;
        $this->wcCategoryId = $wcCategoryId;
    }

    public function handle()
    {
        Log::info('=== شروع Job افزودن تک گونه ===', [
            'license_id' => $this->licenseId,
            'parent_product_id' => $this->parentProductId,
            'variant_id' => $this->variantId
        ]);

        try {
            $license = License::with(['user', 'woocommerceApiKey'])->findOrFail($this->licenseId);
            $parentProduct = Product::findOrFail($this->parentProductId);
            $variant = Product::findOrFail($this->variantId);

            // دریافت تنظیمات انبار
            $userSetting = $license->userSetting;
            $defaultWarehouseCode = $userSetting->default_warehouse_code ?? null;

            Log::info('تنظیمات انبار برای تک گونه', [
                'default_warehouse_code' => $defaultWarehouseCode
            ]);

            // دریافت اطلاعات از Baran
            $baranItemsRaw = $this->getBaranItemsByIds($license, [$variant->item_id]);

            if (empty($baranItemsRaw)) {
                throw new \Exception('اطلاعات گونه از Baran دریافت نشد');
            }

            // فیلتر و محاسبه موجودی
            $baranItem = $this->filterAndCalculateStock($baranItemsRaw, $defaultWarehouseCode);

            if (!$baranItem) {
                throw new \Exception('خطا در محاسبه موجودی گونه');
            }

            $itemInfo = $this->extractBaranProductInfo($baranItem);

            // استفاده از parent_id از Baran
            $parentUniqueId = $itemInfo['parent_id'] ?? $parentProduct->item_id;

            // بررسی وجود محصول مادر در WooCommerce با استفاده از endpoint سفارشی
            $parentCheckResult = $this->checkProductExistsByUniqueIdCustom($license, $parentUniqueId);

            // محصول مادر باید variation_id نداشته باشد (یا null یا 0 باشد)
            $isParentProduct = $parentCheckResult['exists'] &&
                               ($parentCheckResult['variation_id'] === null ||
                                $parentCheckResult['variation_id'] === 0 ||
                                empty($parentCheckResult['variation_id']));

            Log::info('Parent product existence check', [
                'parent_unique_id' => $parentUniqueId,
                'exists' => $parentCheckResult['exists'],
                'product_id' => $parentCheckResult['product_id'] ?? null,
                'variation_id' => $parentCheckResult['variation_id'] ?? null,
                'is_parent_product' => $isParentProduct
            ]);

            if (!$isParentProduct) {
                // محصول مادر وجود ندارد - باید مادر و این گونه را با هم ارسال کنیم
                Log::info('Parent product not found in WooCommerce, creating parent + first variant together', [
                    'parent_unique_id' => $parentUniqueId,
                    'variant_id' => $variant->item_id
                ]);

                // دریافت attributes از Baran برای محصول مادر
                $allVariants = Product::where('parent_id', $parentProduct->item_id)
                    ->where('license_id', $this->licenseId)
                    ->get();

                $variantIds = $allVariants->pluck('item_id')->toArray();
                $allBaranItemsRaw = $this->getBaranItemsByIds($license, $variantIds);

                // گروه‌بندی و فیلتر بر اساس انبار
                $groupedItems = $this->groupBaranItemsByItemId($allBaranItemsRaw);
                $allBaranItems = [];
                foreach ($groupedItems as $itemId => $itemStocks) {
                    $filteredItem = $this->filterAndCalculateStock($itemStocks, $defaultWarehouseCode);
                    if ($filteredItem) {
                        $allBaranItems[] = $filteredItem;
                    }
                }

                // ایجاد attributes map
                $attributesMap = $this->checkAndCreateAttributesInDatabase($license, $allBaranItems, $allVariants);

                // آماده‌سازی داده‌های parent
                $parentData = $this->prepareParentProductData($license, $parentProduct, $parentUniqueId, $attributesMap);

                // آماده‌سازی داده این گونه
                $variantProducts = $this->prepareSingleVariantData($license, $parentProduct, $parentUniqueId, $variant, $itemInfo, $attributesMap);

                // ارسال parent + variant در یک درخواست batch
                $batchProducts = array_merge([$parentData], $variantProducts);

                Log::info('Sending parent + first variant in single batch request', [
                    'parent_unique_id' => $parentUniqueId,
                    'variant_unique_id' => $variant->item_id,
                    'products_count' => count($batchProducts)
                ]);

                $batchResult = $this->createWooCommerceBatchProducts($license, $batchProducts);

                if (!$batchResult['success']) {
                    throw new \Exception('خطا در ایجاد محصول مادر و گونه: ' . ($batchResult['message'] ?? 'نامشخص'));
                }

                // بررسی خطاها
                if (isset($batchResult['data']['failed']) && !empty($batchResult['data']['failed'])) {
                    $errorMessages = array_map(function($failed) {
                        return "{$failed['unique_id']}: {$failed['error']}";
                    }, $batchResult['data']['failed']);
                    throw new \Exception('خطا در ایجاد محصول: ' . implode(', ', $errorMessages));
                }

                Log::info('Parent + variant created successfully', [
                    'parent_unique_id' => $parentUniqueId,
                    'variant_unique_id' => $variant->item_id,
                    'success_count' => count($batchResult['data']['success'] ?? [])
                ]);

                // ایجاد notification موفقیت
                if ($license->user_id) {
                    Notification::create([
                        'user_id' => $license->user_id,
                        'title' => 'گونه جدید اضافه شد',
                        'message' => 'محصول مادر "' . $parentProduct->item_name . '" و گونه "' . $variant->item_name . '" با موفقیت ایجاد شدند.',
                        'type' => 'success',
                        'is_read' => false,
                        'is_active' => true,
                        'data' => json_encode([
                            'parent_product_id' => $this->parentProductId,
                            'variant_id' => $this->variantId
                        ])
                    ]);
                }

                return;
            }

            // محصول مادر موجود است - فقط این گونه را اضافه می‌کنیم
            Log::info('Parent product exists, adding variant only', [
                'parent_unique_id' => $parentUniqueId,
                'parent_product_id' => $parentCheckResult['product_id']
            ]);

            // دریافت لیست variants موجود برای چک کردن تکراری نبودن
            $wcProductsResult = $this->getAllWooCommerceProductsByUniqueIds($license);

            if (!$wcProductsResult['success']) {
                throw new \Exception('خطا در دریافت محصولات از WooCommerce: ' . ($wcProductsResult['message'] ?? 'نامشخص'));
            }

            $wcProducts = $wcProductsResult['data'];
            $wcProductsMap = [];
            foreach ($wcProducts as $wcProduct) {
                $wcProductsMap[$wcProduct['unique_id']] = [
                    'product_id' => $wcProduct['product_id'],
                    'variation_id' => $wcProduct['variation_id'] ?? null,
                    'barcode' => $wcProduct['barcode'] ?? ''
                ];
            }

            // چک کردن اینکه این variant از قبل وجود دارد یا نه
            if (isset($wcProductsMap[$variant->item_id])) {
                Log::warning('Variant already exists in WooCommerce, skipping creation', [
                    'variant_unique_id' => $variant->item_id,
                    'product_id' => $wcProductsMap[$variant->item_id]['product_id'],
                    'variation_id' => $wcProductsMap[$variant->item_id]['variation_id']
                ]);

                // ایجاد Notification موفقیت (محصول از قبل وجود دارد)
                if ($license->user_id) {
                    Notification::create([
                        'user_id' => $license->user_id,
                        'title' => 'گونه از قبل وجود دارد',
                        'message' => 'گونه "' . $variant->item_name . '" قبلاً در WooCommerce ثبت شده است.',
                        'type' => 'info',
                        'is_read' => false,
                        'is_active' => true,
                        'data' => json_encode([
                            'variant_id' => $this->variantId,
                            'wc_product_id' => $wcProductsMap[$variant->item_id]['product_id'],
                            'wc_variation_id' => $wcProductsMap[$variant->item_id]['variation_id']
                        ])
                    ]);
                }

                return;
            }

            // این variant جدید است - آن را اضافه می‌کنیم
            Log::info('Adding new variant to existing parent', [
                'parent_unique_id' => $parentUniqueId,
                'variant_unique_id' => $variant->item_id
            ]);

            // ایجاد attributes برای این گونه
            $allVariants = collect([$variant]);
            $attributesMap = $this->checkAndCreateAttributesInDatabase($license, [$baranItem], $allVariants);

            // چک کردن و به‌روزرسانی attributes محصول مادر در WooCommerce در صورت نیاز
            $needsParentUpdate = $this->checkAndUpdateParentAttributes($license, $parentProduct, $parentUniqueId, $parentCheckResult['product_id'], $itemInfo, $attributesMap);

            if ($needsParentUpdate) {
                Log::info('Parent attributes updated with new properties', [
                    'parent_unique_id' => $parentUniqueId,
                    'parent_product_id' => $parentCheckResult['product_id']
                ]);
            }

            // آماده‌سازی فقط این variant
            $batchProducts = $this->prepareSingleVariantData($license, $parentProduct, $parentUniqueId, $variant, $itemInfo, $attributesMap);

            // ارسال به WooCommerce
            Log::info('Sending single variant to WooCommerce', [
                'products_count' => count($batchProducts),
                'variant_unique_id' => $batchProducts[0]['unique_id'] ?? 'N/A',
                'parent_unique_id' => $batchProducts[0]['parent_unique_id'] ?? 'N/A'
            ]);

            $batchResult = $this->createWooCommerceBatchProducts($license, $batchProducts);

            if (!$batchResult['success']) {
                throw new \Exception($batchResult['message'] ?? 'خطا در ایجاد گونه');
            }

            if (isset($batchResult['data']['failed']) && !empty($batchResult['data']['failed'])) {
                $errorMessages = array_map(function($failed) {
                    return "{$failed['unique_id']}: {$failed['error']}";
                }, $batchResult['data']['failed']);
                throw new \Exception('خطا در ایجاد گونه: ' . implode(', ', $errorMessages));
            }

            Log::info('=== Job با موفقیت تکمیل شد ===', [
                'variant_id' => $this->variantId,
                'success_count' => count($batchResult['data']['success'] ?? [])
            ]);

            // ایجاد Notification موفقیت
            if ($license->user_id) {
                Notification::create([
                    'user_id' => $license->user_id,
                    'title' => 'گونه با موفقیت ثبت شد',
                    'message' => 'گونه "' . $variant->item_name . '" با موفقیت در سیستم ثبت شد.',
                    'type' => 'success',
                    'is_read' => false,
                    'is_active' => true,
                    'data' => json_encode([
                        'variant_id' => $variant->id,
                        'variant_name' => $variant->item_name,
                        'parent_id' => $parentProduct->id,
                        'parent_name' => $parentProduct->item_name,
                        'license_id' => $this->licenseId
                    ])
                ]);
            }

        } catch (\Exception $e) {
            Log::error('خطا در Job افزودن گونه', [
                'error' => $e->getMessage(),
                'variant_id' => $this->variantId
            ]);

            // ایجاد Notification خطا
            try {
                $license = License::find($this->licenseId);
                $variant = Product::find($this->variantId);

                if ($license && $variant && $license->user_id) {
                    Notification::create([
                        'user_id' => $license->user_id,
                        'title' => 'خطا در ثبت گونه',
                        'message' => 'گونه "' . $variant->item_name . '" با خطا مواجه شد: ' . $e->getMessage(),
                        'type' => 'error',
                        'is_read' => false,
                        'is_active' => true,
                        'data' => json_encode([
                            'variant_id' => $variant->id,
                            'error' => $e->getMessage()
                        ])
                    ]);
                }
            } catch (\Exception $notifException) {
                Log::error('خطا در ایجاد notification', ['error' => $notifException->getMessage()]);
            }

            throw $e;
        }
    }

    protected function prepareSingleVariantData($license, $parentProduct, $parentUniqueId, $variant, $itemInfo, $attributesMap)
    {
        Log::info('prepareSingleVariantData called', [
            'variant_id' => $variant->id,
            'variant_item_id' => $variant->item_id,
            'parent_unique_id' => $parentUniqueId,
            'attributes_count' => count($attributesMap)
        ]);

        $products = [];

        // آماده‌سازی attributes برای variant
        $variationAttributes = [];

        foreach ($itemInfo['attributes'] as $attr) {
            $attributeName = $attr['name'];
            $attributeValue = $attr['value'];

            // چک کردن وجود در map
            if (!isset($attributesMap[$attributeName])) {
                Log::warning('Attribute not found in map (may be inactive)', [
                    'attribute_name' => $attributeName
                ]);
                continue;
            }

            $attributeData = $attributesMap[$attributeName];
            $dbAttribute = $attributeData['db_attribute'];
            $isVariation = $dbAttribute->is_variation ?? false;

            // فقط attributes با is_variation = true به variation اضافه می‌شوند
            if (!$isVariation) {
                Log::info('Skip کردن attribute غیر متغیر در variation (prepareSingleVariantData)', [
                    'attribute_name' => $attributeName,
                    'variant_item_id' => $variant->item_id,
                    'is_variation' => false
                ]);
                continue;
            }

            $attributeSlug = !empty($dbAttribute->slug) ? $dbAttribute->slug : $attributeName;

            // پیدا کردن property
            $property = $attributeData['properties'][$attributeValue] ?? null;

            if (!$property) {
                Log::warning('Property not found for attribute', [
                    'attribute_name' => $attributeName,
                    'property_value' => $attributeValue
                ]);
                continue;
            }

            // استفاده از name و slug پروپرتی از دیتابیس
            $propertyName = $property->name;
            $propertySlug = !empty($property->slug) ? $property->slug : $propertyName;

            $variationAttributes[] = [
                'name' => $attributeName,
                'slug' => $attributeSlug,
                'option' => $propertySlug  // استفاده از slug برای option گونه
            ];

            Log::info('افزودن variation attribute (prepareSingleVariantData)', [
                'attribute_name' => $attributeName,
                'attribute_slug' => $attributeSlug,
                'property_slug' => $propertySlug,
                'is_variation' => true
            ]);
        }

        $stockQuantity = $itemInfo['stock_quantity'] ?? 0;
        $stockStatus = $stockQuantity > 0 ? 'instock' : 'outofstock';

        // تبدیل قیمت بر اساس تنظیمات کاربر
        $userSetting = $license->userSetting;
        $regularPrice = (float)($itemInfo['price'] ?? $variant->price_amount ?? 0);
        if ($userSetting && $userSetting->rain_sale_price_unit && $userSetting->woocommerce_price_unit) {
            $regularPrice = $this->convertPriceUnit(
                $regularPrice,
                $userSetting->rain_sale_price_unit,
                $userSetting->woocommerce_price_unit
            );
        }

        $variationData = [
            'unique_id' => strtolower((string)$variant->item_id),
            'parent_unique_id' => strtolower((string)$parentUniqueId),
            'sku' => $variant->barcode ?? $variant->item_id,
            'regular_price' => (string)$regularPrice,
            'manage_stock' => true,
            'stock_quantity' => $stockQuantity,
            'stock_status' => $stockStatus,
            'status' => 'publish',
            'attributes' => $variationAttributes
        ];

        if (isset($itemInfo['sale_price']) && $itemInfo['sale_price'] > 0) {
            $salePrice = (float)$itemInfo['sale_price'];
            if ($userSetting && $userSetting->rain_sale_price_unit && $userSetting->woocommerce_price_unit) {
                $salePrice = $this->convertPriceUnit(
                    $salePrice,
                    $userSetting->rain_sale_price_unit,
                    $userSetting->woocommerce_price_unit
                );
            }
            $variationData['sale_price'] = (string)$salePrice;
        }

        if (!empty($itemInfo['description'])) {
            $variationData['description'] = $itemInfo['description'];
        }

        if (!empty($itemInfo['short_description'])) {
            $variationData['short_description'] = $itemInfo['short_description'];
        }

        $products[] = $variationData;

        return $products;
    }

    protected function prepareBatchProductsData($license, $parentProduct, $parentUniqueId, $baranItems, $variants, $attributesMap)
    {
        $products = [];

        // محصول parent - تفکیک بر اساس is_variation
        $wcAttributes = [];
        $customAttributes = [];

        foreach ($attributesMap as $attributeName => $data) {
            // استفاده از is_variation از دیتابیس برای تعیین نوع attribute
            $isVariation = $data['db_attribute']->is_variation ?? true;
            $attributeSlug = !empty($data['db_attribute']->slug) ? $data['db_attribute']->slug : $attributeName;

            if ($isVariation) {
                // جمع‌آوری slug های properties از دیتابیس برای options
                $propertyOptions = [];
                foreach ($data['properties'] as $value => $property) {
                    $propertySlug = !empty($property->slug) ? $property->slug : $property->name;
                    $propertyOptions[] = $propertySlug;
                }

                $wcAttributes[] = [
                    'name' => $attributeName,
                    'slug' => $attributeSlug,
                    'variation' => true,
                    'visible' => true,
                    'options' => $propertyOptions  // استفاده از slug های دیتابیس
                ];
            } else {
                foreach ($data['properties'] as $value => $property) {
                    $customAttributes[] = [
                        'key' => $attributeSlug,
                        'value' => $property->name  // استفاده از name پروپرتی
                    ];
                }
            }
        }

        $parentData = [
            'unique_id' => strtolower((string)$parentUniqueId),
            'name' => $parentProduct->item_name,
            'type' => 'variable',
            'sku' => $parentProduct->barcode ?? $parentUniqueId,
            'status' => 'draft',
            'manage_stock' => false,
            'stock_status' => 'instock',
            'attributes' => $wcAttributes
        ];

        if (!empty($customAttributes)) {
            $parentData['meta_data'] = $customAttributes;
        }

        if ($this->wcCategoryId) {
            $parentData['categories'] = [['id' => (int)$this->wcCategoryId]];
        }

        $products[] = $parentData;

        // محصولات variation
        foreach ($baranItems as $baranItem) {
            $itemInfo = $this->extractBaranProductInfo($baranItem);

            // اگر این item خودش parent است، skip کن
            if ($itemInfo['item_id'] === $itemInfo['parent_id']) {
                Log::info('Skipping item because it is the parent itself', [
                    'item_id' => $itemInfo['item_id']
                ]);
                continue;
            }

            $variant = $variants->firstWhere('item_id', $itemInfo['item_id']);

            if (!$variant) {
                Log::warning('Variant not found in database', [
                    'baran_item_id' => $itemInfo['item_id']
                ]);
                continue;
            }

            // استفاده از parent_id از Baran برای این variant
            $variantParentId = $itemInfo['parent_id'] ?? $parentUniqueId;

            if ($variantParentId !== $parentUniqueId) {
                Log::warning('Variant has different parent_id', [
                    'variant_item_id' => $itemInfo['item_id'],
                    'variant_parent_id' => $variantParentId,
                    'expected_parent_id' => $parentUniqueId
                ]);
            }

            $variationAttributes = [];
            foreach ($itemInfo['attributes'] as $attr) {
                $variationAttributes[] = [
                    'name' => $attr['name'],
                    'option' => $attr['value']
                ];
            }

            $stockQuantity = $itemInfo['stock_quantity'] ?? 0;
            $stockStatus = $stockQuantity > 0 ? 'instock' : 'outofstock';

            // تبدیل قیمت بر اساس تنظیمات کاربر
            $userSetting = $license->userSetting;
            $regularPrice = (float)($itemInfo['price'] ?? $variant->price_amount ?? 0);
            if ($userSetting && $userSetting->rain_sale_price_unit && $userSetting->woocommerce_price_unit) {
                $regularPrice = $this->convertPriceUnit(
                    $regularPrice,
                    $userSetting->rain_sale_price_unit,
                    $userSetting->woocommerce_price_unit
                );
            }

            $variationData = [
                'unique_id' => strtolower((string)$variant->item_id),
                'parent_unique_id' => strtolower((string)$variantParentId),
                'sku' => $variant->barcode ?? $variant->item_id,
                'regular_price' => (string)$regularPrice,
                'manage_stock' => true,
                'stock_quantity' => $stockQuantity,
                'stock_status' => $stockStatus,
                'status' => 'publish',
                'attributes' => $variationAttributes
            ];

            if (isset($itemInfo['sale_price']) && $itemInfo['sale_price'] > 0) {
                $salePrice = (float)$itemInfo['sale_price'];
                if ($userSetting && $userSetting->rain_sale_price_unit && $userSetting->woocommerce_price_unit) {
                    $salePrice = $this->convertPriceUnit(
                        $salePrice,
                        $userSetting->rain_sale_price_unit,
                        $userSetting->woocommerce_price_unit
                    );
                }
                $variationData['sale_price'] = (string)$salePrice;
            }

            if (!empty($itemInfo['description'])) {
                $variationData['description'] = $itemInfo['description'];
            }

            if (!empty($itemInfo['short_description'])) {
                $variationData['short_description'] = $itemInfo['short_description'];
            }

            $products[] = $variationData;
        }

        return $products;
    }

    /**
     * Wrapper برای چک کردن و به‌روزرسانی attributes محصول مادر
     */
    protected function checkAndUpdateParentAttributes($license, $parentProduct, $parentUniqueId, $wcParentProductId, $variantItemInfo, $attributesMap)
    {
        $woocommerceApiKey = $license->woocommerceApiKey;

        if (!$woocommerceApiKey) {
            Log::error('WooCommerce API key not found for license', [
                'license_id' => $license->id
            ]);
            return false;
        }

        return $this->checkAndUpdateParentAttributesInWooCommerce(
            $license->website_url,
            $woocommerceApiKey->consumer_key,
            $woocommerceApiKey->consumer_secret,
            $wcParentProductId,
            $parentUniqueId,
            $variantItemInfo,
            $attributesMap
        );
    }

    protected function checkAndCreateAttributesInDatabase($license, $baranItems, $variants)
    {
        $attributesMap = [];
        $parentProduct = null;

        Log::info('checkAndCreateAttributesInDatabase called', [
            'baran_items_count' => count($baranItems),
            'variants_count' => $variants->count(),
            'variant_ids' => $variants->pluck('item_id')->toArray()
        ]);

        // پیدا کردن محصول parent از اولین variant (parent_id برابر با item_id است)
        if ($variants->isNotEmpty()) {
            $firstVariant = $variants->first();
            $parentProduct = Product::where('license_id', $license->id)
                ->where('item_id', $firstVariant->parent_id)
                ->first();

            Log::info('Parent product found in AddSingleVariantJob:', [
                'parent_id' => $parentProduct ? $parentProduct->id : null,
                'parent_item_id' => $parentProduct ? $parentProduct->item_id : null
            ]);
        }

        // مرحله 1: جمع‌آوری تمام attributes و مقادیر آنها
        foreach ($baranItems as $baranItem) {
            $itemInfo = $this->extractBaranProductInfo($baranItem);

            // اگر این خود parent است، skip کنیم
            if ($itemInfo['item_id'] === $itemInfo['parent_id']) {
                continue;
            }

            // استفاده از مقایسه case-insensitive برای UUID
            $variant = $variants->first(function ($v) use ($itemInfo) {
                return strcasecmp($v->item_id, $itemInfo['item_id']) === 0;
            });

            if (!$variant) {
                Log::warning('Variant not found in Phase 1 (attribute collection)', [
                    'baran_item_id' => $itemInfo['item_id'],
                    'db_variant_ids' => $variants->pluck('item_id')->toArray()
                ]);
                continue;
            }

            foreach ($itemInfo['attributes'] as $attr) {
                $attributeName = $attr['name'];
                $attributeValue = $attr['value'];

                if (!isset($attributesMap[$attributeName])) {
                    $attributesMap[$attributeName] = [
                        'values' => [],
                        'db_attribute' => null,
                        'properties' => []
                    ];
                }

                if (!in_array($attributeValue, $attributesMap[$attributeName]['values'])) {
                    $attributesMap[$attributeName]['values'][] = $attributeValue;
                }
            }
        }

        // مرحله 2: دریافت یا ایجاد ProductAttribute از دیتابیس
        // نوع attribute (متغیر یا خصوصیت) از فیلد is_variation در دیتابیس خوانده می‌شود
        foreach ($attributesMap as $attributeName => $data) {
            $productAttribute = ProductAttribute::where('license_id', $license->id)
                ->where('name', $attributeName)
                ->first();

            if (!$productAttribute) {
                // اگر attribute جدید است، به صورت پیش‌فرض is_variation = false (خصوصیت)
                // مدیر سیستم می‌تواند بعداً آن را به متغیر تبدیل کند
                $productAttribute = ProductAttribute::create([
                    'license_id' => $license->id,
                    'name' => $attributeName,
                    'slug' => str_replace(' ', '-', $attributeName),
                    'is_variation' => false,
                    'is_active' => true,
                    'is_visible' => true,
                    'sort_order' => 0
                ]);

                Log::info('Created new product attribute', [
                    'attribute_name' => $attributeName,
                    'is_variation' => false,
                    'note' => 'Default created as property, admin can change to variation'
                ]);
            }

            // بررسی فعال بودن ویژگی
            if (!$productAttribute->is_active) {
                Log::info('Attribute is not active, skipping in AddSingleVariantJob', [
                    'attribute_name' => $attributeName,
                    'attribute_id' => $productAttribute->id
                ]);
                // حذف از map تا در مراحل بعدی پردازش نشود
                unset($attributesMap[$attributeName]);
                continue;
            }

            Log::info('Using attribute from database', [
                'attribute_name' => $attributeName,
                'is_variation' => $productAttribute->is_variation,
                'values_count' => count($data['values']),
                'values' => $data['values']
            ]);

            $attributesMap[$attributeName]['db_attribute'] = $productAttribute;
        }

        // مرحله 3: ایجاد properties و values
        Log::info('Starting Phase 3: Creating properties and values', [
            'total_baran_items' => count($baranItems)
        ]);

        foreach ($baranItems as $baranItem) {
            $itemInfo = $this->extractBaranProductInfo($baranItem);

            // اگر این خود parent است، skip کنیم
            if ($itemInfo['item_id'] === $itemInfo['parent_id']) {
                Log::info('Skipping parent item in Phase 3', [
                    'item_id' => $itemInfo['item_id']
                ]);
                continue;
            }

            // استفاده از مقایسه case-insensitive برای UUID
            $variant = $variants->first(function ($v) use ($itemInfo) {
                return strcasecmp($v->item_id, $itemInfo['item_id']) === 0;
            });

            if (!$variant) {
                Log::warning('Variant not found in Phase 3', [
                    'baran_item_id' => $itemInfo['item_id'],
                    'db_variant_ids' => $variants->pluck('item_id')->toArray()
                ]);
                continue;
            }

            Log::info('Processing variant in Phase 3', [
                'variant_id' => $variant->id,
                'variant_item_id' => $variant->item_id,
                'attributes_count' => count($itemInfo['attributes'])
            ]);

            foreach ($itemInfo['attributes'] as $attr) {
                $attributeName = $attr['name'];
                $attributeValue = $attr['value'];

                // چک کردن وجود attribute در map (اگر غیرفعال بود حذف شده)
                if (!isset($attributesMap[$attributeName])) {
                    Log::info('Attribute not in map (may be inactive), skipping', [
                        'attribute_name' => $attributeName
                    ]);
                    continue;
                }

                $productAttribute = $attributesMap[$attributeName]['db_attribute'];

                // بررسی وجود property - مقایسه با name نه value
                $productProperty = ProductProperty::where('product_attribute_id', $productAttribute->id)
                    ->where('name', $attributeValue)
                    ->first();

                if (!$productProperty) {
                    $productProperty = ProductProperty::create([
                        'product_attribute_id' => $productAttribute->id,
                        'name' => $attributeValue,  // مقدار از API
                        'value' => $attributeValue,  // همان مقدار
                        'slug' => str_replace(' ', '-', $attributeValue),
                        'is_active' => true,
                        'is_default' => false,
                        'sort_order' => 0
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

                // ذخیره یا به‌روزرسانی ارتباط در جدول product_attribute_values برای variant
                $existingValue = ProductAttributeValue::where('product_id', $variant->id)
                    ->where('product_attribute_id', $productAttribute->id)
                    ->where('product_property_id', $productProperty->id)
                    ->first();

                if (!$existingValue) {
                    ProductAttributeValue::create([
                        'product_id' => $variant->id,
                        'product_attribute_id' => $productAttribute->id,
                        'product_property_id' => $productProperty->id,
                        'sort_order' => 0
                    ]);

                    Log::info('Added attribute-property to variant', [
                        'variant_id' => $variant->id,
                        'attribute_name' => $attributeName,
                        'property_value' => $attributeValue
                    ]);
                }

                $attributesMap[$attributeName]['properties'][$attributeValue] = $productProperty;
            }
        }

        // اضافه کردن attributes به محصول parent
        // توجه: فقط یک رکورد برای هر attribute به parent اضافه می‌شود (اولین property)
        // چون unique constraint بر اساس product_id و product_attribute_id است
        if ($parentProduct && !empty($attributesMap)) {
            Log::info('Adding attributes to parent product in AddSingleVariantJob', [
                'parent_id' => $parentProduct->id,
                'parent_item_id' => $parentProduct->item_id,
                'attributes_count' => count($attributesMap)
            ]);

            foreach ($attributesMap as $attributeName => $data) {
                $productAttribute = $data['db_attribute'];

                // چک کردن آیا این attribute قبلاً به parent اضافه شده
                $existingValue = ProductAttributeValue::where('product_id', $parentProduct->id)
                    ->where('product_attribute_id', $productAttribute->id)
                    ->first();

                if (!$existingValue) {
                    // فقط اولین property را اضافه می‌کنیم
                    $firstProperty = reset($data['properties']);

                    if ($firstProperty) {
                        ProductAttributeValue::create([
                            'product_id' => $parentProduct->id,
                            'product_attribute_id' => $productAttribute->id,
                            'product_property_id' => $firstProperty->id,
                            'sort_order' => 0
                        ]);

                        Log::info('Added attribute to parent product in AddSingleVariantJob', [
                            'attribute_name' => $attributeName,
                            'property_name' => $firstProperty->name,
                            'note' => 'Only first property added due to unique constraint'
                        ]);
                    }
                } else {
                    Log::info('Attribute already exists for parent product, skipping', [
                        'attribute_name' => $attributeName,
                        'parent_id' => $parentProduct->id
                    ]);
                }
            }
        }

        return $attributesMap;
    }

    /**
     * آماده‌سازی داده محصول مادر برای ارسال به WooCommerce
     */
    protected function prepareParentProductData($license, $parentProduct, $parentUniqueId, $attributesMap)
    {
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

            $attributeSlug = !empty($dbAttribute->slug) ? $dbAttribute->slug : $attributeName;

            // جمع‌آوری name های properties از دیتابیس برای options
            $propertyOptions = [];
            foreach ($data['properties'] as $value => $property) {
                $propertyOptions[] = $property->name;  // استفاده از name پروپرتی
            }

            // همه attributes را به آرایه attributes اضافه کن (با variation مناسب)
            $wcAttributes[] = [
                'name' => $attributeName,
                'slug' => $attributeSlug,
                'variation' => $isVariation,  // استفاده از تنظیم دیتابیس
                'visible' => $dbAttribute->is_visible ?? true,  // استفاده از تنظیم دیتابیس
                'options' => $propertyOptions  // استفاده از name های پروپرتی
            ];

            Log::info('اضافه کردن attribute به parent (AddSingleVariantJob)', [
                'attribute_name' => $attributeName,
                'attribute_slug' => $attributeSlug,
                'is_variation' => $isVariation,
                'is_visible' => $dbAttribute->is_visible ?? true,
                'options_count' => count($propertyOptions)
            ]);
        }

        $parentData = [
            'unique_id' => $parentUniqueId,
            'name' => $parentProduct->item_name,
            'type' => 'variable',
            'sku' => $parentProduct->barcode ?? $parentUniqueId,
            'status' => 'draft',
            'manage_stock' => false,
            'stock_status' => 'instock',
            'attributes' => $wcAttributes
        ];

        if ($this->wcCategoryId) {
            $parentData['categories'] = [['id' => (int)$this->wcCategoryId]];
        }

        return $parentData;
    }

    /**
     * آماده‌سازی داده variants برای ارسال به WooCommerce (بدون parent)
     */
    protected function prepareVariantsData($license, $parentProduct, $parentUniqueId, $baranItems, $variants, $attributesMap, $wcProductsMap = [])
    {
        $products = [];

        // فقط محصولات variation
        foreach ($baranItems as $baranItem) {
            $itemInfo = $this->extractBaranProductInfo($baranItem);

            // اگر این item خودش parent است، skip کن
            if ($itemInfo['item_id'] === $itemInfo['parent_id']) {
                Log::info('Skipping item because it is the parent itself', [
                    'item_id' => $itemInfo['item_id']
                ]);
                continue;
            }

            $variant = $variants->firstWhere('item_id', $itemInfo['item_id']);

            if (!$variant) {
                Log::warning('Variant not found in database', [
                    'baran_item_id' => $itemInfo['item_id']
                ]);
                continue;
            }

            // چک کردن اینکه آیا این variant از قبل در WooCommerce وجود دارد
            if (!empty($wcProductsMap) && isset($wcProductsMap[$variant->item_id]) && $wcProductsMap[$variant->item_id]['variation_id'] !== null) {
                Log::info('Skipping variant that already exists in WooCommerce', [
                    'variant_unique_id' => $variant->item_id,
                    'product_id' => $wcProductsMap[$variant->item_id]['product_id'],
                    'variation_id' => $wcProductsMap[$variant->item_id]['variation_id']
                ]);
                continue;
            }

            // استفاده از parent_id از Baran برای این variant
            $variantParentId = $itemInfo['parent_id'] ?? $parentUniqueId;

            if ($variantParentId !== $parentUniqueId) {
                Log::warning('Variant has different parent_id', [
                    'variant_item_id' => $itemInfo['item_id'],
                    'variant_parent_id' => $variantParentId,
                    'expected_parent_id' => $parentUniqueId
                ]);
            }

            $variationAttributes = [];

            foreach ($itemInfo['attributes'] as $attr) {
                $attributeName = $attr['name'];
                $attributeValue = $attr['value'];

                if (!isset($attributesMap[$attributeName])) {
                    continue;
                }

                $attributeData = $attributesMap[$attributeName];
                $dbAttribute = $attributeData['db_attribute'];
                $isVariation = $dbAttribute->is_variation ?? false;

                // فقط attributes با is_variation = true به variation اضافه می‌شوند
                if (!$isVariation) {
                    Log::info('Skip کردن attribute غیر متغیر در variation (AddSingleVariantJob)', [
                        'attribute_name' => $attributeName,
                        'variant_item_id' => $variant->item_id,
                        'is_variation' => false
                    ]);
                    continue;
                }

                $attributeSlug = !empty($dbAttribute->slug) ? $dbAttribute->slug : $attributeName;

                $property = $attributeData['properties'][$attributeValue] ?? null;
                if (!$property) {
                    continue;
                }

                $propertyName = $property->name;
                $propertySlug = !empty($property->slug) ? $property->slug : $propertyName;

                $variationAttributes[] = [
                    'name' => $attributeName,
                    'slug' => $attributeSlug,
                    'option' => $propertySlug
                ];

                Log::info('افزودن variation attribute (AddSingleVariantJob)', [
                    'attribute_name' => $attributeName,
                    'attribute_slug' => $attributeSlug,
                    'property_slug' => $propertySlug,
                    'is_variation' => true
                ]);
            }

            $stockQuantity = $itemInfo['stock_quantity'] ?? 0;
            $stockStatus = $stockQuantity > 0 ? 'instock' : 'outofstock';

            // تبدیل قیمت بر اساس تنظیمات کاربر
            $userSetting = $license->userSetting;
            $regularPrice = (float)($itemInfo['price'] ?? $variant->price_amount ?? 0);
            if ($userSetting && $userSetting->rain_sale_price_unit && $userSetting->woocommerce_price_unit) {
                $regularPrice = $this->convertPriceUnit(
                    $regularPrice,
                    $userSetting->rain_sale_price_unit,
                    $userSetting->woocommerce_price_unit
                );
            }

            $variationData = [
                'unique_id' => $variant->item_id,
                'parent_unique_id' => $variantParentId,
                'sku' => $variant->barcode ?? $variant->item_id,
                'regular_price' => (string)$regularPrice,
                'manage_stock' => true,
                'stock_quantity' => $stockQuantity,
                'stock_status' => $stockStatus,
                'status' => 'publish',
                'attributes' => $variationAttributes
            ];

            if (isset($itemInfo['sale_price']) && $itemInfo['sale_price'] > 0) {
                $salePrice = (float)$itemInfo['sale_price'];
                if ($userSetting && $userSetting->rain_sale_price_unit && $userSetting->woocommerce_price_unit) {
                    $salePrice = $this->convertPriceUnit(
                        $salePrice,
                        $userSetting->rain_sale_price_unit,
                        $userSetting->woocommerce_price_unit
                    );
                }
                $variationData['sale_price'] = (string)$salePrice;
            }

            if (!empty($itemInfo['description'])) {
                $variationData['description'] = $itemInfo['description'];
            }

            if (!empty($itemInfo['short_description'])) {
                $variationData['short_description'] = $itemInfo['short_description'];
            }

            $products[] = $variationData;
        }

        return $products;
    }
}
