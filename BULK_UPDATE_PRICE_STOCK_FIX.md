# رفع مسئله عدم به‌روزرسانی قیمت و موجودی در Bulk Update

## مسئله گزارش شده:
پس از درخواست به‌روزرسانی کلی، موجودی و قیمت کالاها به‌روزرسانی نمی‌شدند حتی اگر در تنظیمات کاربر به‌روزرسانی موجودی یا قیمت فعال بود.

## علت‌های شناسایی شده:

### 1. **فیلدهای اشتباه در BulkUpdateWooCommerceProducts**:
❌ **قبل از اصلاح**:
```php
// فیلدهای اشتباه
$regularPrice = (float)($productData['regular_price'] ?? 0);
$stockQuantity = (int)($productData['stock_quantity'] ?? 0);
```

✅ **بعد از اصلاح**:
```php
// فیلدهای صحیح RainSale API
$regularPrice = (float)($productData['price_amount'] ?? $productData['PriceAmount'] ?? $productData['regular_price'] ?? 0);
$stockQuantity = (int)($productData['total_count'] ?? $productData['TotalCount'] ?? $productData['CurrentUnitCount'] ?? $productData['stock_quantity'] ?? 0);
```

### 2. **عدم بررسی تنظیمات کاربر در ProcessSingleProductBatch**:
❌ **قبل از اصلاح**: همیشه قیمت و موجودی به‌روزرسانی می‌شد
✅ **بعد از اصلاح**: بررسی `enable_price_update` و `enable_stock_update`

## تغییرات اعمال شده:

### 🔧 **BulkUpdateWooCommerceProducts.php**:

#### 1. **اصلاح فیلدهای قیمت**:
```php
if ($userSetting->enable_price_update) {
    // استفاده از فیلدهای صحیح RainSale API
    $regularPrice = (float)($productData['price_amount'] ?? $productData['PriceAmount'] ?? $productData['regular_price'] ?? 0);
    $salePrice = (float)($productData['price_after_discount'] ?? $productData['PriceAfterDiscount'] ?? $productData['sale_price'] ?? 0);
    
    if ($regularPrice > 0) {
        $data['regular_price'] = (string)$this->convertPriceUnit(
            $regularPrice,
            $userSetting->rain_sale_price_unit,
            $userSetting->woocommerce_price_unit
        );
    }
}
```

#### 2. **اصلاح فیلدهای موجودی**:
```php
if ($userSetting->enable_stock_update) {
    $stockQuantity = (int)($productData['total_count'] ?? $productData['TotalCount'] ?? $productData['CurrentUnitCount'] ?? $productData['stock_quantity'] ?? 0);
    
    $data['manage_stock'] = true;
    $data['stock_quantity'] = $stockQuantity;
    $data['stock_status'] = $stockQuantity > 0 ? 'instock' : 'outofstock';
}
```

#### 3. **لاگ‌گیری تشخیصی**:
```php
Log::info('آماده‌سازی داده‌های محصول برای به‌روزرسانی', [
    'enable_price_update' => $userSetting->enable_price_update,
    'enable_stock_update' => $userSetting->enable_stock_update,
    'received_fields' => array_keys($productData)
]);
```

### 🔧 **ProcessSingleProductBatch.php**:

#### 1. **بررسی تنظیمات کاربر**:
```php
private function prepareProductData($product, $userSettings)
{
    $data = [/* داده‌های پایه */];

    // بررسی تنظیمات قیمت
    if ($userSettings->enable_price_update) {
        $data['regular_price'] = (string) $product['regular_price'];
    }

    // بررسی تنظیمات موجودی
    if ($userSettings->enable_stock_update) {
        $data['stock_quantity'] = (int) $product['stock_quantity'];
        $data['manage_stock'] = true;
        $data['stock_status'] = (int) $product['stock_quantity'] > 0 ? 'instock' : 'outofstock';
    }

    // بررسی تنظیمات نام
    if ($userSettings->enable_name_update && !empty($product['name'])) {
        $data['name'] = $product['name'];
    }
}
```

## 📊 **نتایج مورد انتظار**:

### ✅ **حالت‌های مختلف تنظیمات**:

| تنظیم | وضعیت | نتیجه |
|-------|---------|--------|
| `enable_price_update = true` | ✅ فعال | قیمت‌ها به‌روزرسانی می‌شوند |
| `enable_stock_update = true` | ✅ فعال | موجودی‌ها به‌روزرسانی می‌شوند |
| `enable_name_update = true` | ✅ فعال | نام‌ها به‌روزرسانی می‌شوند |
| همه تنظیمات `false` | ❌ غیرفعال | هیچ به‌روزرسانی انجام نمی‌شود |

### 🔍 **لاگ‌های تشخیصی جدید**:

#### قیمت:
```
[INFO] قیمت محصول به‌روزرسانی می‌شود
{
    "product_id": "12345",
    "regular_price": "150000"
}
```

#### موجودی:
```
[INFO] موجودی محصول به‌روزرسانی می‌شود
{
    "product_id": "12345", 
    "stock_quantity": 25
}
```

#### داده‌های آماده شده:
```
[INFO] داده‌های نهایی آماده شده برای WooCommerce
{
    "prepared_data": {
        "unique_id": "ITEM-123",
        "regular_price": "150000",
        "stock_quantity": 25,
        "manage_stock": true
    }
}
```

## 🚨 **نکات مهم**:
1. **سازگاری با فیلدهای مختلف**: سیستم از چندین نام فیلد پشتیبانی می‌کند
2. **لاگ‌گیری کامل**: همه مراحل به‌روزرسانی لاگ می‌شوند
3. **احترام به تنظیمات**: فقط فیلدهای مجاز به‌روزرسانی می‌شوند
4. **تبدیل واحد قیمت**: قیمت‌ها به واحد صحیح تبدیل می‌شوند

حالا سیستم باید قیمت و موجودی را بر اساس تنظیمات کاربر درست به‌روزرسانی کند! 🎉
