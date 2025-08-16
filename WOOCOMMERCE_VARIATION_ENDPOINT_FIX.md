# رفع خطای WooCommerce Variation Update Endpoint

## خطای گزارش شده:
```
[2025-08-16 15:26:49] local.WARNING: خطا در به‌روزرسانی محصول منفرد 
{
    "license_id": 3,
    "product_id": "165946", 
    "error": "Error: برای دستکاری تغییرات محصول شما می بایست از نقطه پایانی /products/<product_id>/variations/<id> استفاده کنید. [woocommerce_rest_invalid_product_id]"
}
```

## علت خطا:
سیستم در حال تلاش برای به‌روزرسانی یک **واریانت محصول** از طریق endpoint محصول اصلی بود:
- ❌ **Endpoint اشتباه**: `/wp-json/wc/v3/products/{product_id}`
- ✅ **Endpoint صحیح**: `/wp-json/wc/v3/products/{product_id}/variations/{variation_id}`

## راه حل پیاده‌سازی شده:

### 🔧 **تغییرات در ProcessSingleProductBatch.php**:

#### 1. **اصلاح prepareProductData()**: 
```php
private function prepareProductData($product, $userSettings)
{
    return [
        'id' => $product['variation_id'] ?: $product['product_id'],
        'product_id' => $product['product_id'],           // ✅ اضافه شد
        'variation_id' => $product['variation_id'],       // ✅ اضافه شد  
        'is_variation' => !empty($product['variation_id']), // ✅ اضافه شد
        'regular_price' => (string) $product['regular_price'],
        'stock_quantity' => (int) $product['stock_quantity'],
        'meta_data' => [
            [
                'key' => '_bim_unique_id',
                'value' => $product['unique_id']
            ]
        ]
    ];
}
```

#### 2. **اصلاح performWooCommerceUpdate()**: 
```php
private function performWooCommerceUpdate($woocommerce, $productsToUpdate)
{
    foreach ($productsToUpdate as $product) {
        try {
            // تشخیص نوع محصول و استفاده از endpoint مناسب
            if ($product['is_variation'] && !empty($product['variation_id']) && !empty($product['product_id'])) {
                // ✅ واریانت محصول - endpoint صحیح
                $endpoint = 'products/' . $product['product_id'] . '/variations/' . $product['variation_id'];
                
                $updateData = [
                    'regular_price' => $product['regular_price'],
                    'stock_quantity' => $product['stock_quantity'],
                    'meta_data' => $product['meta_data']
                ];
            } else {
                // ✅ محصول اصلی - endpoint معمولی
                $endpoint = 'products/' . $product['id'];
                
                $updateData = [
                    'regular_price' => $product['regular_price'],
                    'stock_quantity' => $product['stock_quantity'],
                    'meta_data' => $product['meta_data']
                ];
            }

            $response = $woocommerce->put($endpoint, $updateData);
            
        } catch (\Exception $e) {
            // لاگ خطا با جزئیات کامل
        }
    }
}
```

## 📋 **Job های بررسی شده**:

| Job | وضعیت | توضیحات |
|-----|--------|----------|
| ✅ **ProcessSingleProductBatch** | اصلاح شد | endpoint صحیح برای واریانت‌ها |
| ✅ **SyncUniqueIds** | صحیح بود | قبلاً از endpoint درست استفاده می‌کرد |
| ✅ **UpdateWooCommerceProducts** | نیاز به بررسی ندارد | از batch endpoint سفارشی استفاده می‌کند |
| ✅ **BulkUpdateWooCommerceProducts** | نیاز به بررسی ندارد | از HTTP مستقیم استفاده نمی‌کند |

## 🎯 **نتیجه**:
- ✅ **واریانت‌ها**: اکنون از `/products/{parent_id}/variations/{variation_id}` استفاده می‌کنند
- ✅ **محصولات اصلی**: همچنان از `/products/{product_id}` استفاده می‌کنند
- ✅ **لاگ‌گیری بهتر**: مشخص می‌کند کدام endpoint استفاده شده

## 🔍 **لاگ‌های جدید**:

### واریانت محصول:
```
[INFO] به‌روزرسانی واریانت محصول
{
    "license_id": 3,
    "product_id": "165946",
    "variation_id": "165947", 
    "endpoint": "products/165946/variations/165947"
}
```

### محصول اصلی:
```
[INFO] به‌روزرسانی محصول اصلی
{
    "license_id": 3,
    "product_id": "165946",
    "endpoint": "products/165946"
}
```

## 🚨 **نکات مهم**:
1. **تشخیص خودکار**: سیستم خودکار تشخیص می‌دهد محصول واریانت است یا اصلی
2. **Data Structure**: فقط فیلدهای ضروری به endpoint واریانت ارسال می‌شود
3. **Error Handling**: خطاها با جزئیات کامل لاگ می‌شوند
4. **Backward Compatibility**: محصولات اصلی همچنان مثل قبل کار می‌کنند

این رفع مشکل باید خطای `woocommerce_rest_invalid_product_id` را برای واریانت‌ها حل کند.
