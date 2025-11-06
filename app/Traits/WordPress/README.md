# WordPress/WooCommerce API Traits Documentation

## مقدمه

این مجموعه trait ها برای مدیریت کامل API WordPress و WooCommerce طراحی شده‌اند و تمامی قابلیت‌های مورد نیاز برای همگام‌سازی محصولات، سفارشات و دسته‌بندی‌ها را فراهم می‌کنند.

## ساختار پوشه

```
app/Traits/WordPress/
├── WooCommerceApiTrait.php          # متدهای اصلی WooCommerce API
├── WordPressMasterTrait.php         # trait اصلی ترکیبی
└── README.md                        # مستندات
```

## فهرست کامل توابع WooCommerceApiTrait

### 🔸 **مدیریت محصولات (Products)**

#### دریافت محصولات
- `getWooCommerceProducts($websiteUrl, $apiKey, $apiSecret, $params = [])` - دریافت فهرست محصولات
- `getWooCommerceProduct($websiteUrl, $apiKey, $apiSecret, $productId)` - دریافت محصول خاص
- `getWooCommerceProductsBySku($websiteUrl, $apiKey, $apiSecret, $sku)` - جستجو با SKU
- `getWooCommerceProductByUniqueId($websiteUrl, $apiKey, $apiSecret, $uniqueId)` - جستجو با UniqueID
- `getWooCommerceProductsByParentUniqueId($websiteUrl, $apiKey, $apiSecret, $parentUniqueId)` - دریافت محصولات فرزند
- `getWooCommerceProductsWithUniqueIds($websiteUrl, $apiKey, $apiSecret)` - دریافت محصولات همراه UniqueID
- `getAllWooCommerceProducts($woocommerce, $perPage = 100)` - دریافت تمام محصولات
- `getAllWooCommerceProductBarcodes($license)` - دریافت تمام بارکدها
- `getAllProductBarcodes($license, $wooApiKey, $startTime, $maxExecutionTime)` - دریافت بارکدها با مدیریت زمان

#### به‌روزرسانی محصولات
- `updateWooCommerceProduct($websiteUrl, $apiKey, $apiSecret, $productId, $updateData)` - به‌روزرسانی محصول خاص
- `updateWooCommerceProducts($license, $foundProducts, $userSettings)` - به‌روزرسانی دسته‌ای محصولات
- `updateWooCommerceBatchProducts($license, $products)` - به‌روزرسانی batch محصولات
- `updateWooCommerceBatchProductsByUniqueId($websiteUrl, $apiKey, $apiSecret, $batchData)` - به‌روزرسانی با UniqueID
- `updateWooCommerceProductsBatch($woocommerce, $products)` - به‌روزرسانی batch با WooCommerce Client

#### بررسی وجود محصولات
- `checkProductExistsInWooCommerce($license, $wooApiKey, $product)` - بررسی وجود محصول
- `checkWooCommerceProductsExistence($license, $uniqueIds)` - بررسی وجود چند محصول

#### درج محصولات جدید
- `insertWooCommerceBatchProducts($license, $products)` - درج دسته‌ای محصولات جدید
- `createWooCommerceSimpleProduct($license, $productData)` - ایجاد محصول ساده جدید
- `createWooCommerceVariableProduct($license, $productData)` - ایجاد محصول متغیر (variable product)
- `createWooCommerceVariation($license, $parentProductId, $variationData)` - ایجاد تنوع (variation) برای محصول متغیر

### 🔸 **مدیریت ویژگی‌ها و اصطلاحات (Attributes & Terms)**

- `getWooCommerceAttributes($license)` - دریافت فهرست تمام ویژگی‌ها (attributes)
- `createWooCommerceAttribute($license, $attributeName)` - ایجاد ویژگی جدید
- `getWooCommerceAttributeTerms($license, $attributeId)` - دریافت اصطلاحات یک ویژگی
- `createWooCommerceAttributeTerm($license, $attributeId, $termName)` - ایجاد اصطلاح جدید برای ویژگی
- `findOrCreateWooCommerceAttribute($license, $attributeName)` - پیدا کردن یا ایجاد ویژگی
- `findOrCreateWooCommerceAttributeTerm($license, $attributeId, $termName)` - پیدا کردن یا ایجاد اصطلاح

### 🔸 **مدیریت تنوعات (Variations)**

- `getWooCommerceProductVariations($websiteUrl, $apiKey, $apiSecret, $productId, $params = [])` - دریافت تنوعات محصول
- `getWooCommerceProductVariation($websiteUrl, $apiKey, $apiSecret, $productId, $variationId)` - دریافت تنوع خاص
- `updateWooCommerceProductVariation($websiteUrl, $apiKey, $apiSecret, $productId, $variationId, $updateData)` - به‌روزرسانی تنوع
- `insertWooCommerceProductVariations($license, $parentWooId, $variations)` - درج تنوعات جدید

### 🔸 **مدیریت دسته‌بندی‌ها (Categories)**

- `getWooCommerceProductCategories($websiteUrl, $apiKey, $apiSecret, $params = [])` - دریافت دسته‌بندی‌ها
- `createWooCommerceCategory($websiteUrl, $apiKey, $apiSecret, $categoryData)` - ایجاد دسته‌بندی جدید
- `fetchWooCommerceCategories($license, WooCommerceApiKey $wooApiKey)` - دریافت دسته‌بندی‌ها (متد قدیمی)

### 🔸 **مدیریت سفارشات (Orders)**

- `getWooCommerceOrder($websiteUrl, $apiKey, $apiSecret, $orderId)` - دریافت سفارش
- `updateWooCommerceOrder($websiteUrl, $apiKey, $apiSecret, $orderId, $updateData)` - به‌روزرسانی سفارش
- `fetchCompleteOrderFromWooCommerce($license, $wooApiKey, $orderId)` - دریافت کامل سفارش
- `processWooCommerceOrderData($wooOrderData)` - پردازش داده‌های سفارش

### 🔸 **مدیریت UniqueID ها**

- `batchUpdateWooCommerceUniqueIdsBySku($websiteUrl, $apiKey, $apiSecret, $batchData)` - به‌روزرسانی UniqueID با SKU
- `deleteWooCommerceUniqueIds($websiteUrl, $apiKey, $apiSecret, $uniqueIds)` - حذف UniqueID های نامعتبر
- `deleteInvalidUniqueIds($license, $wooApiKey, $invalidUniqueIds)` - حذف UniqueID های نامعتبر (متد قدیمی)

### 🔸 **توابع کمکی و تنظیمات**

- `getWooCommerceApiSettings($license)` - دریافت تنظیمات API
- `validateWooCommerceApiCredentials($siteUrl, $apiKey, $apiSecret)` - اعتبارسنجی API
- `convertProductForWooCommerce($productInfo, $userSettings = null)` - تبدیل محصول برای WooCommerce

## نمونه استفاده از توابع جدید

### 1. دریافت محصولات با فیلتر

```php
// دریافت محصولات یک دسته خاص
$result = $this->getWooCommerceProducts(
    $license->website_url,
    $wooApiKey->api_key,
    $wooApiKey->api_secret,
    [
        'category' => 25,
        'per_page' => 50,
        'status' => 'publish'
    ]
);
```

### 2. به‌روزرسانی دسته‌ای با UniqueID

```php
$batchData = [
    [
        'unique_id' => 'PROD001',
        'stock_quantity' => 50,
        'regular_price' => '25000'
    ],
    [
        'unique_id' => 'PROD002', 
        'stock_quantity' => 30,
        'regular_price' => '35000'
    ]
];

$result = $this->updateWooCommerceBatchProductsByUniqueId(
    $license->website_url,
    $wooApiKey->api_key,
    $wooApiKey->api_secret,
    $batchData
);
```

### 3. مدیریت تنوعات محصول

```php
// دریافت تنوعات محصول
$variations = $this->getWooCommerceProductVariations(
    $license->website_url,
    $wooApiKey->api_key,
    $wooApiKey->api_secret,
    $parentProductId,
    ['per_page' => 100]
);

// به‌روزرسانی تنوع خاص
$updateResult = $this->updateWooCommerceProductVariation(
    $license->website_url,
    $wooApiKey->api_key,
    $wooApiKey->api_secret,
    $parentProductId,
    $variationId,
    [
        'stock_quantity' => 25,
        'regular_price' => '15000'
    ]
);
```

### 4. مدیریت دسته‌بندی‌ها

```php
// دریافت تمام دسته‌بندی‌ها
$categories = $this->getWooCommerceProductCategories(
    $license->website_url,
    $wooApiKey->api_key,
    $wooApiKey->api_secret,
    ['per_page' => 100]
);

// ایجاد دسته‌بندی جدید
$newCategory = $this->createWooCommerceCategory(
    $license->website_url,
    $wooApiKey->api_key,
    $wooApiKey->api_secret,
    [
        'name' => 'دسته جدید',
        'description' => 'توضیحات دسته'
    ]
);
```

### 5. جستجو و شناسایی محصولات

```php
// جستجو با SKU
$productBySku = $this->getWooCommerceProductsBySku(
    $license->website_url,
    $wooApiKey->api_key,
    $wooApiKey->api_secret,
    'SKU001'
);

// جستجو با UniqueID
$productByUniqueId = $this->getWooCommerceProductByUniqueId(
    $license->website_url,
    $wooApiKey->api_key,
    $wooApiKey->api_secret,
    'UNIQUE001'
);

// دریافت محصولات فرزند
$childProducts = $this->getWooCommerceProductsByParentUniqueId(
    $license->website_url,
    $wooApiKey->api_key,
    $wooApiKey->api_secret,
    'PARENT001'
);
```

### 6. ایجاد محصولات متغیر (Variable Products)

```php
// ایجاد محصول متغیر والد
$parentProductData = [
    'name' => 'تی‌شرت',
    'sku' => 'TSHIRT001',
    'type' => 'variable',
    'categories' => [['id' => 25]],
    'attributes' => [
        [
            'id' => 1, // attribute ID برای رنگ
            'name' => 'رنگ',
            'position' => 0,
            'visible' => true,
            'variation' => true,
            'options' => ['قرمز', 'آبی', 'سبز']
        ],
        [
            'id' => 2, // attribute ID برای سایز
            'name' => 'سایز',
            'position' => 1,
            'visible' => true,
            'variation' => true,
            'options' => ['S', 'M', 'L', 'XL']
        ]
    ]
];

$parentResult = $this->createWooCommerceVariableProduct($license, $parentProductData);

if ($parentResult['success']) {
    $parentProduct = $parentResult['data'];
    
    // ایجاد تنوعات
    $variationData = [
        'sku' => 'TSHIRT001-RED-M',
        'regular_price' => '25000',
        'stock_quantity' => 10,
        'manage_stock' => true,
        'attributes' => [
            [
                'id' => 1,
                'name' => 'رنگ',
                'option' => 'قرمز'
            ],
            [
                'id' => 2,
                'name' => 'سایز',
                'option' => 'M'
            ]
        ]
    ];
    
    $variationResult = $this->createWooCommerceVariation(
        $license, 
        $parentProduct['id'], 
        $variationData
    );
}
```

### 7. مدیریت ویژگی‌ها و اصطلاحات

```php
// پیدا کردن یا ایجاد ویژگی
$attrResult = $this->findOrCreateWooCommerceAttribute($license, 'رنگ');

if ($attrResult['success']) {
    $attribute = $attrResult['data'];
    
    // پیدا کردن یا ایجاد اصطلاح
    $termResult = $this->findOrCreateWooCommerceAttributeTerm(
        $license, 
        $attribute['id'], 
        'قرمز'
    );
    
    if ($termResult['success']) {
        $term = $termResult['data'];
        echo "Term ID: {$term['id']}, Name: {$term['name']}";
    }
}

// دریافت تمام ویژگی‌ها
$attributesResult = $this->getWooCommerceAttributes($license);

if ($attributesResult['success']) {
    foreach ($attributesResult['data'] as $attr) {
        echo "{$attr['name']} (ID: {$attr['id']})\n";
        
        // دریافت اصطلاحات این ویژگی
        $termsResult = $this->getWooCommerceAttributeTerms($license, $attr['id']);
        
        if ($termsResult['success']) {
            foreach ($termsResult['data'] as $term) {
                echo "  - {$term['name']}\n";
            }
        }
    }
}
```

### 8. ایجاد محصول ساده

```php
$productData = [
    'name' => 'کیف دستی',
    'sku' => 'BAG001',
    'regular_price' => '150000',
    'stock_quantity' => 25,
    'manage_stock' => true,
    'categories' => [['id' => 30]]
];

$result = $this->createWooCommerceSimpleProduct($license, $productData);

if ($result['success']) {
    echo "محصول با موفقیت ایجاد شد. ID: {$result['data']['id']}";
} else {
    echo "خطا: {$result['message']}";
}
```

## توابع استفاده شده در Livewire Components

### ProductsManager.php
- `createWooCommerceSimpleProduct()` - ایجاد محصولات ساده
- `createWooCommerceVariableProduct()` - ایجاد محصولات متغیر
- `createWooCommerceVariation()` - ایجاد تنوعات محصولات
- `findOrCreateWooCommerceAttribute()` - مدیریت ویژگی‌های محصولات
- `findOrCreateWooCommerceAttributeTerm()` - مدیریت اصطلاحات ویژگی‌ها

## توابع استفاده شده در Job های مختلف

### ProcessInvoice.php
- `getWooCommerceOrder()`
- `updateWooCommerceOrder()`

### ProcessProductBatch.php
- `updateWooCommerceBatchProducts()`

### ProcessProductPage.php
- `getWooCommerceProducts()`
- `getWooCommerceProductVariations()`

### ProcessSingleProductBatch.php
- `updateWooCommerceBatchProductsByUniqueId()`

### ProcessSkuBatch.php
- `batchUpdateWooCommerceUniqueIdsBySku()`

### PublishParentProduct.php
- `getWooCommerceProductsByParentUniqueId()`
- `getWooCommerceProductByUniqueId()`
- `updateWooCommerceProduct()`

### SyncCategories.php
- `getWooCommerceProductCategories()`
- `createWooCommerceCategory()`

### SyncProductFromRainSale.php
- `getWooCommerceProductsBySku()`
- `updateWooCommerceProduct()`

### SyncUniqueIds.php
- `getWooCommerceProduct()`
- `getWooCommerceProductVariation()`
- `updateWooCommerceProduct()`
- `updateWooCommerceProductVariation()`
- `getWooCommerceProducts()`
- `getWooCommerceProductVariations()`
- `deleteWooCommerceUniqueIds()`

### SyncWooCommerceProducts.php
- `getWooCommerceProductCategories()`

### UpdateWooCommerceProducts.php
- `getWooCommerceProductsWithUniqueIds()`
- `updateWooCommerceBatchProductsByUniqueId()`

### UpdateWooCommerceStockByCategoryJob.php
- `getWooCommerceProductCategories()`
- `getWooCommerceProducts()`
- `getWooCommerceProductVariations()`
- `updateWooCommerceBatchProductsByUniqueId()`

## پاسخ استاندارد توابع

همه توابع پاسخ یکنواخت برمی‌گردانند:

```json
{
    "success": true|false,
    "message": "پیام نتیجه",
    "data": {},           // در صورت موفقیت
    "error": "جزئیات خطا", // در صورت خطا
    "status_code": 200    // کد HTTP
}
```

## نکات مهم

1. **پارامترها**: همه توابع جدید پارامتر `$websiteUrl`, `$apiKey`, `$apiSecret` می‌گیرند
2. **SSL**: تایید SSL غیرفعال است (`verify => false`)
3. **Timeout**: پیش‌فرض 30 ثانیه برای درخواست‌ها
4. **خطا**: تمامی خطاها لاگ و برگردانده می‌شوند
5. **UniqueID**: سیستم از فیلد `unique_id` برای شناسایی محصولات استفاده می‌کند

## Migration از Client به Traits

### قبل (استفاده از Automattic\WooCommerce\Client):
```php
$woocommerce = new Client($url, $key, $secret, ['version' => 'wc/v3']);
$response = $woocommerce->get('products');
```

### بعد (استفاده از Traits):
```php
$result = $this->getWooCommerceProducts($url, $key, $secret);
if ($result['success']) {
    $products = $result['data'];
}
```
