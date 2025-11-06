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
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AddSingleVariantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use BaranApiTrait, WooCommerceApiTrait;

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

            // دریافت اطلاعات از Baran
            $baranItems = $this->getBaranItemsByIds($license, [$variant->item_id]);

            if (empty($baranItems)) {
                throw new \Exception('اطلاعات گونه از Baran دریافت نشد');
            }

            $baranItem = $baranItems[0];
            $itemInfo = $this->extractBaranProductInfo($baranItem);

            // استفاده از parent_id از Baran
            $parentUniqueId = $itemInfo['parent_id'] ?? $parentProduct->item_id;

            // دریافت تمام محصولات موجود در WooCommerce
            $wcProductsResult = $this->getAllWooCommerceProductsByUniqueIds($license);

            if (!$wcProductsResult['success']) {
                throw new \Exception('خطا در دریافت محصولات از WooCommerce: ' . ($wcProductsResult['message'] ?? 'نامشخص'));
            }

            $wcProducts = $wcProductsResult['data'];

            // ایجاد mapping از unique_id به product_id و variation_id
            $wcProductsMap = [];
            foreach ($wcProducts as $wcProduct) {
                $wcProductsMap[$wcProduct['unique_id']] = [
                    'product_id' => $wcProduct['product_id'],
                    'variation_id' => $wcProduct['variation_id'] ?? null,
                    'barcode' => $wcProduct['barcode'] ?? ''
                ];
            }

            Log::info('WooCommerce products mapping', [
                'total_products' => count($wcProductsMap),
                'parent_unique_id' => $parentUniqueId,
                'parent_exists' => isset($wcProductsMap[$parentUniqueId])
            ]);

            // چک کردن اینکه آیا محصول مادر در WooCommerce وجود دارد
            $parentExists = isset($wcProductsMap[$parentUniqueId]) && $wcProductsMap[$parentUniqueId]['variation_id'] === null;

            if (!$parentExists) {
                // باید محصول مادر و تمام گونه‌ها را ایجاد کنیم
                Log::info('Parent product not found in WooCommerce, creating full variable product', [
                    'parent_unique_id' => $parentUniqueId
                ]);

                // دریافت تمام گونه‌ها
                $allVariants = Product::where('parent_id', $this->parentProductId)
                    ->where('license_id', $this->licenseId)
                    ->get();

                $variantIds = $allVariants->pluck('item_id')->toArray();
                $allBaranItems = $this->getBaranItemsByIds($license, $variantIds);

                Log::info('All variants from Baran', [
                    'count' => count($allBaranItems),
                    'variant_ids' => array_map(function($item) {
                        return [
                            'item_id' => $item['itemID'] ?? 'N/A',
                            'parent_id' => $item['parentID'] ?? 'N/A'
                        ];
                    }, $allBaranItems)
                ]);

                // ایجاد attributes map
                $attributesMap = $this->checkAndCreateAttributesInDatabase($license, $allBaranItems, $allVariants);

                // مرحله 1: ایجاد محصول مادر
                $parentData = $this->prepareParentProductData($license, $parentProduct, $parentUniqueId, $attributesMap);

                Log::info('Creating parent product first', [
                    'parent_unique_id' => $parentUniqueId,
                    'parent_data' => $parentData
                ]);

                $parentResult = $this->createWooCommerceBatchProducts($license, [$parentData]);

                if (!$parentResult['success']) {
                    throw new \Exception('خطا در ایجاد محصول مادر: ' . ($parentResult['message'] ?? 'نامشخص'));
                }

                if (isset($parentResult['data']['failed']) && !empty($parentResult['data']['failed'])) {
                    $errorMessages = array_map(function($failed) {
                        return "Parent {$failed['unique_id']}: {$failed['error']}";
                    }, $parentResult['data']['failed']);
                    throw new \Exception('خطا در ایجاد محصول مادر: ' . implode(', ', $errorMessages));
                }

                Log::info('Parent product created successfully', [
                    'parent_unique_id' => $parentUniqueId
                ]);

                // مرحله 2: آماده‌سازی و ارسال variants (فقط variants جدید)
                $batchProducts = $this->prepareVariantsData($license, $parentProduct, $parentUniqueId, $allBaranItems, $allVariants, $attributesMap, $wcProductsMap);

                Log::info('Variants prepared for batch', [
                    'products_count' => count($batchProducts)
                ]);

                // اگر همه variants از قبل وجود داشته باشند
                if (empty($batchProducts)) {
                    Log::info('All variants already exist in WooCommerce, nothing to create');

                    if ($license->user_id) {
                        Notification::create([
                            'user_id' => $license->user_id,
                            'title' => 'محصول متغیر ایجاد شد',
                            'message' => 'محصول مادر "' . $parentProduct->item_name . '" ایجاد شد. تمام گونه‌ها از قبل وجود داشتند.',
                            'type' => 'success',
                            'is_read' => false,
                            'is_active' => true,
                            'data' => json_encode([
                                'parent_product_id' => $this->parentProductId
                            ])
                        ]);
                    }
                    return;
                }

            } else {
                // فقط این گونه را اضافه می‌کنیم
                Log::info('Parent product exists in WooCommerce, adding single variant', [
                    'parent_unique_id' => $parentUniqueId,
                    'parent_product_id' => $wcProductsMap[$parentUniqueId]['product_id']
                ]);

                // چک کردن اینکه آیا این variant از قبل وجود دارد (با استفاده از item_id دیتابیس)
                if (isset($wcProductsMap[$variant->item_id]) && $wcProductsMap[$variant->item_id]['variation_id'] !== null) {
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

                // ایجاد attributes برای این گونه
                $attributesMap = $this->checkAndCreateAttributesInDatabase($license, [$baranItem], collect([$variant]));

                // آماده‌سازی فقط این variant
                $batchProducts = $this->prepareSingleVariantData($license, $parentProduct, $parentUniqueId, $variant, $itemInfo, $attributesMap);

                Log::info('Single variant prepared', [
                    'products_count' => count($batchProducts),
                    'variant_unique_id' => $batchProducts[0]['unique_id'] ?? 'N/A',
                    'parent_unique_id' => $batchProducts[0]['parent_unique_id'] ?? 'N/A'
                ]);
            }

            // ارسال به WooCommerce
            Log::info('Sending batch to WooCommerce', [
                'products_count' => count($batchProducts),
                'products' => $batchProducts
            ]);

            $batchResult = $this->createWooCommerceBatchProducts($license, $batchProducts);

            if (!$batchResult['success']) {
                throw new \Exception($batchResult['message'] ?? 'خطا در ایجاد محصول');
            }

            if (isset($batchResult['data']['failed']) && !empty($batchResult['data']['failed'])) {
                $errorMessages = array_map(function($failed) {
                    return "Product {$failed['unique_id']}: {$failed['error']}";
                }, $batchResult['data']['failed']);
                throw new \Exception('خطا: ' . implode(', ', $errorMessages));
            }

            if (empty($batchResult['data']['success'])) {
                throw new \Exception('هیچ محصولی ایجاد نشد');
            }

            Log::info('=== Job با موفقیت تکمیل شد ===', [
                'success_count' => count($batchResult['data']['success'])
            ]);

            // ایجاد Notification
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
            $variationAttributes[] = [
                'name' => $attr['name'],
                'option' => $attr['value']
            ];
        }

        $stockQuantity = $itemInfo['stock_quantity'] ?? 0;
        $stockStatus = $stockQuantity > 0 ? 'instock' : 'outofstock';

        $variationData = [
            'unique_id' => $variant->item_id,
            'parent_unique_id' => $parentUniqueId,
            'sku' => $variant->barcode ?? $variant->item_id,
            'regular_price' => (string)($itemInfo['price'] ?? $variant->price_amount ?? '0'),
            'manage_stock' => true,
            'stock_quantity' => $stockQuantity,
            'stock_status' => $stockStatus,
            'status' => 'publish',
            'attributes' => $variationAttributes
        ];

        if (isset($itemInfo['sale_price']) && $itemInfo['sale_price'] > 0) {
            $variationData['sale_price'] = (string)$itemInfo['sale_price'];
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

        // محصول parent
        $wcAttributes = [];
        foreach ($attributesMap as $attributeName => $data) {
            // استفاده از is_variation از دیتابیس برای تعیین نوع attribute
            $isVariation = $data['db_attribute']->is_variation ?? true;

            $wcAttributes[] = [
                'name' => $attributeName,
                'variation' => $isVariation,  // متغیر یا ثابت
                'visible' => true,
                'options' => $data['values']
            ];
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

            $variationData = [
                'unique_id' => $variant->item_id,
                'parent_unique_id' => $variantParentId,
                'sku' => $variant->barcode ?? $variant->item_id,
                'regular_price' => (string)($itemInfo['price'] ?? $variant->price_amount ?? '0'),
                'manage_stock' => true,
                'stock_quantity' => $stockQuantity,
                'stock_status' => $stockStatus,
                'status' => 'publish',
                'attributes' => $variationAttributes
            ];

            if (isset($itemInfo['sale_price']) && $itemInfo['sale_price'] > 0) {
                $variationData['sale_price'] = (string)$itemInfo['sale_price'];
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

    protected function checkAndCreateAttributesInDatabase($license, $baranItems, $variants)
    {
        $attributesMap = [];

        Log::info('checkAndCreateAttributesInDatabase called', [
            'baran_items_count' => count($baranItems),
            'variants_count' => $variants->count(),
            'variant_ids' => $variants->pluck('item_id')->toArray()
        ]);

        // مرحله 1: جمع‌آوری تمام attributes و مقادیر آنها
        foreach ($baranItems as $baranItem) {
            $itemInfo = $this->extractBaranProductInfo($baranItem);

            // اگر این خود parent است، skip کنیم
            if ($itemInfo['item_id'] === $itemInfo['parent_id']) {
                continue;
            }

            $variant = $variants->firstWhere('item_id', $itemInfo['item_id']);

            if (!$variant) {
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
                    'is_variation' => false,  // پیش‌فرض: خصوصیت
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

            $variant = $variants->firstWhere('item_id', $itemInfo['item_id']);

            if (!$variant) {
                Log::warning('Variant not found in Phase 3', [
                    'baran_item_id' => $itemInfo['item_id']
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

                $productAttribute = $attributesMap[$attributeName]['db_attribute'];

                $productProperty = ProductProperty::where('product_attribute_id', $productAttribute->id)
                    ->where('value', $attributeValue)
                    ->first();

                if (!$productProperty) {
                    $productProperty = ProductProperty::create([
                        'product_attribute_id' => $productAttribute->id,
                        'name' => $attributeValue,
                        'value' => $attributeValue,
                        'slug' => str_replace(' ', '-', $attributeValue),
                        'is_active' => true,
                        'is_default' => false,
                        'sort_order' => 0
                    ]);
                }

                $existingValue = ProductAttributeValue::where('product_id', $variant->id)
                    ->where('product_attribute_id', $productAttribute->id)
                    ->first();

                if (!$existingValue) {
                    ProductAttributeValue::create([
                        'product_id' => $variant->id,
                        'product_attribute_id' => $productAttribute->id,
                        'product_property_id' => $productProperty->id,
                        'value' => $attributeValue,
                        'display_value' => $attributeValue,
                        'sort_order' => 0
                    ]);
                }

                $attributesMap[$attributeName]['properties'][$attributeValue] = $productProperty;
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
            // استفاده از is_variation از دیتابیس برای تعیین نوع attribute
            $isVariation = $data['db_attribute']->is_variation ?? true;

            $wcAttributes[] = [
                'name' => $attributeName,
                'variation' => $isVariation,  // متغیر یا ثابت
                'visible' => true,
                'options' => $data['values']
            ];
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
                $variationAttributes[] = [
                    'name' => $attr['name'],
                    'option' => $attr['value']
                ];
            }

            $stockQuantity = $itemInfo['stock_quantity'] ?? 0;
            $stockStatus = $stockQuantity > 0 ? 'instock' : 'outofstock';

            $variationData = [
                'unique_id' => $variant->item_id,
                'parent_unique_id' => $variantParentId,
                'sku' => $variant->barcode ?? $variant->item_id,
                'regular_price' => (string)($itemInfo['price'] ?? $variant->price_amount ?? '0'),
                'manage_stock' => true,
                'stock_quantity' => $stockQuantity,
                'stock_status' => $stockStatus,
                'status' => 'publish',
                'attributes' => $variationAttributes
            ];

            if (isset($itemInfo['sale_price']) && $itemInfo['sale_price'] > 0) {
                $variationData['sale_price'] = (string)$itemInfo['sale_price'];
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
