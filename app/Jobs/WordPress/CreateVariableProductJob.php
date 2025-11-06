<?php

namespace App\Jobs\WordPress;

use App\Models\License;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductProperty;
use App\Models\Notification;
use App\Traits\Baran\BaranApiTrait;
use App\Traits\WordPress\WooCommerceApiTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateVariableProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use BaranApiTrait, WooCommerceApiTrait;

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

            // گرفتن فرزندان (variants)
            $variants = Product::where('parent_id', $parentProduct->id)
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
                'variant_ids' => $variants->pluck('item_id')->toArray()
            ]);

            // استعلام از Baran برای دریافت attributes
            $variantIds = $variants->pluck('item_id')->toArray();
            $baranItems = $this->getBaranItemsByIds($license, $variantIds);

            if (empty($baranItems)) {
                throw new \Exception('اطلاعات گونه‌ها از Baran دریافت نشد.');
            }

            Log::info('Baran items received in job', [
                'parent_item_id' => $parentProduct->item_id,
                'items_count' => count($baranItems)
            ]);

            // بررسی اینکه آیا attributes وجود دارند
            $hasAttributes = false;
            foreach ($baranItems as $baranItem) {
                if (!empty($baranItem['Attributes'])) {
                    $hasAttributes = true;
                    break;
                }
            }

            if (!$hasAttributes) {
                throw new \Exception('گونه‌ها فاقد ویژگی (attributes) هستند.');
            }

            // بررسی و ایجاد attributes در دیتابیس (فقط برای ذخیره محلی)
            $attributesMap = $this->checkAndCreateAttributesInDatabase($license, $baranItems, $variants);

            Log::info('Attributes created in database', [
                'attributes_count' => count($attributesMap)
            ]);

            // دریافت لیست attributes موجود در WooCommerce
            $attributesResult = $this->getWooCommerceAttributes($license);

            // بررسی و ایجاد attributes و terms مورد نیاز
            $this->ensureAttributesAndTermsExist($license, $attributesMap, $attributesResult);

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
                $errorMessages = array_map(function($failed) {
                    return "Product {$failed['unique_id']}: {$failed['error']}";
                }, $batchResult['data']['failed']);

                throw new \Exception('برخی محصولات با خطا مواجه شدند: ' . implode(', ', $errorMessages));
            }

            // بررسی آرایه success
            if (empty($batchResult['data']['success'])) {
                throw new \Exception('هیچ محصولی با موفقیت ایجاد نشد.');
            }

            Log::info('=== Job با موفقیت تکمیل شد ===', [
                'success_count' => count($batchResult['data']['success']),
                'products' => $batchResult['data']['success']
            ]);

            // ایجاد Notification برای کاربر
            if ($license->user_id) {
                Notification::create([
                    'user_id' => $license->user_id,
                    'title' => 'محصول متغیر با موفقیت ثبت شد',
                    'message' => 'محصول "' . $parentProduct->item_name . '" با ' . count($batchResult['data']['success']) . ' گونه با موفقیت در سیستم ثبت شد.',
                    'type' => 'success',
                    'is_read' => false,
                    'is_active' => true,
                    'data' => json_encode([
                        'product_id' => $parentProduct->id,
                        'product_name' => $parentProduct->item_name,
                        'variants_count' => count($batchResult['data']['success']),
                        'license_id' => $this->licenseId
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

        Log::info('=== شروع بررسی و ایجاد Attributes در دیتابیس (Job) ===');

        foreach ($baranItems as $index => $baranItem) {
            $itemInfo = $this->extractBaranProductInfo($baranItem);

            // سعی در پیدا کردن variant با item_id
            $variant = $variants->firstWhere('item_id', $itemInfo['item_id']);

            // اگر پیدا نشد، سعی کن با parent_id پیدا کنی
            if (!$variant && $itemInfo['parent_id']) {
                $variant = $variants->firstWhere('item_id', $itemInfo['parent_id']);
            }

            if (!$variant) {
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
                        'slug' => $this->convertSpacesToDashes($attributeName), // نگهداری نام فارسی با تبدیل فاصله به -
                        'is_variation' => $isVariation, // بر اساس نام attribute
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

                // بررسی وجود property
                $productProperty = ProductProperty::where('product_attribute_id', $productAttribute->id)
                    ->where('value', $attributeValue)
                    ->first();

                if (!$productProperty) {
                    $productProperty = ProductProperty::create([
                        'product_attribute_id' => $productAttribute->id,
                        'name' => $attributeValue,
                        'slug' => $this->convertSpacesToDashes($attributeValue), // نگهداری نام فارسی با تبدیل فاصله به -
                        'value' => $attributeValue,
                        'is_active' => true,
                        'sort_order' => 0
                    ]);

                    Log::info('Created new property in database (Job)', [
                        'property_value' => $attributeValue,
                        'attribute_name' => $attributeName
                    ]);
                }

                // اضافه کردن به نقشه
                if (!isset($attributesMap[$attributeName])) {
                    $attributesMap[$attributeName] = [
                        'db_attribute' => $productAttribute,
                        'values' => []
                    ];
                }

                if (!in_array($attributeValue, $attributesMap[$attributeName]['values'])) {
                    $attributesMap[$attributeName]['values'][] = $attributeValue;
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

            // بررسی is_variation از دیتابیس
            $isVariation = $dbAttribute->is_variation ?? false;

            $wcAttributes[] = [
                'name' => $attributeName,                 // نام فارسی attribute
                'variation' => $isVariation,              // از دیتابیس
                'visible' => $dbAttribute->is_visible ?? true,
                'options' => $data['values']              // مقادیر فارسی
            ];

            Log::info('تنظیم attribute برای محصول parent', [
                'attribute_name' => $attributeName,
                'is_variation' => $isVariation,
                'is_visible' => $dbAttribute->is_visible ?? true,
                'values_count' => count($data['values'])
            ]);
        }

        $products[] = [
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

        // محصولات variation
        foreach ($baranItems as $baranItem) {
            $itemInfo = $this->extractBaranProductInfo($baranItem);

            // Skip items without attributes (parent products, not variations)
            if (empty($itemInfo['attributes'])) {
                continue;
            }

            $variant = $variants->firstWhere('item_id', $itemInfo['item_id']);
            if (!$variant && $itemInfo['parent_id']) {
                $variant = $variants->firstWhere('item_id', $itemInfo['parent_id']);
            }

            if (!$variant) {
                continue;
            }

            // آماده‌سازی attributes برای variation - فقط attributes با is_variation = true
            $variationAttributes = [];
            foreach ($itemInfo['attributes'] as $attr) {
                $attributeData = $attributesMap[$attr['name']] ?? null;
                if ($attributeData) {
                    $dbAttribute = $attributeData['db_attribute'];
                    $isVariation = $dbAttribute->is_variation ?? false;

                    // فقط attributes با is_variation = true به variation اضافه می‌شوند
                    if ($isVariation) {
                        $variationAttributes[] = [
                            'name' => $attr['name'],      // نام فارسی attribute
                            'option' => $attr['value']    // مقدار فارسی
                        ];

                        Log::info('افزودن variation attribute', [
                            'attribute_name' => $attr['name'],
                            'attribute_value' => $attr['value'],
                            'is_variation' => true
                        ]);
                    } else {
                        Log::info('رد کردن attribute (خصوصیت عادی است)', [
                            'attribute_name' => $attr['name'],
                            'attribute_value' => $attr['value'],
                            'is_variation' => false
                        ]);
                    }
                }
            }

            Log::info('Variation attributes prepared', [
                'variant_item_id' => $variant->item_id,
                'attributes_count' => count($variationAttributes),
                'attributes' => $variationAttributes
            ]);

            $stockStatus = $itemInfo['stock_quantity'] > 0 ? 'instock' : 'outofstock';

            $variationData = [
                'unique_id' => $variant->item_id,
                'parent_unique_id' => $parentProduct->item_id,
                'sku' => $itemInfo['barcode'] ?? $variant->item_id,
                'regular_price' => (string)$itemInfo['price'],
                'manage_stock' => true,
                'stock_quantity' => (int)$itemInfo['stock_quantity'],
                'stock_status' => $stockStatus,
                'status' => 'publish',
                'attributes' => $variationAttributes
            ];

            if ($itemInfo['sale_price'] > 0 && $itemInfo['sale_price'] < $itemInfo['price']) {
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
