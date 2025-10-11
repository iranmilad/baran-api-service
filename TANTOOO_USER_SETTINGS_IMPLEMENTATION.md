# پیاده‌سازی تنظیمات کاربر در همگام‌سازی Tantooo

## خلاصه تغییرات

سیستم همگام‌سازی Tantooo حالا بر اساس تنظیمات کاربر (`user_settings`) تعیین می‌کند که کدام پارامترها (موجودی، قیمت، نام) باید به‌روزرسانی شوند.

## مسئله قبلی

پیش از این، سیستم همواره تمام پارامترها (موجودی، قیمت، نام محصول) را به‌روزرسانی می‌کرد، بدون در نظر گیری تنظیمات کاربر.

## راه‌حل پیاده‌سازی شده

### 1. اصلاح متد `updateProductInTantooo`

**فایل:** `app/Jobs/Tantooo/ProcessTantoooSyncRequest.php`

#### تغییرات اصلی:

1. **دریافت تنظیمات کاربر:**
```php
$userSettings = UserSetting::where('license_id', $license->id)->first();
$enableStockUpdate = $userSettings->enable_stock_update ?? false;
$enablePriceUpdate = $userSettings->enable_price_update ?? false;
$enableNameUpdate = $userSettings->enable_name_update ?? false;
```

2. **بررسی شرطی تنظیمات:**
```php
if (!$enableStockUpdate && !$enablePriceUpdate && !$enableNameUpdate) {
    return [
        'success' => true,
        'message' => 'هیچ تنظیم به‌روزرسانی فعال نیست - عملیات رد شد',
        'skipped' => true
    ];
}
```

3. **به‌روزرسانی شرطی موجودی:**
```php
if ($enableStockUpdate) {
    $stockResult = $this->updateProductStockWithToken($license, $itemId, (int)$stockQuantity);
}
```

4. **به‌روزرسانی شرطی اطلاعات محصول:**
```php
if ($enablePriceUpdate || $enableNameUpdate) {
    $finalTitle = $enableNameUpdate ? $title : null;
    $finalPrice = $enablePriceUpdate ? (float)$price : null;
    $infoResult = $this->updateProductInfoWithToken($license, $itemId, $finalTitle ?? '', $finalPrice ?? 0, $finalDiscount ?? 0);
}
```

## سناریوهای پشتیبانی شده

### 1. همه تنظیمات فعال
- `enable_stock_update = true`
- `enable_price_update = true`  
- `enable_name_update = true`
- **نتیجه:** تمام پارامترها به‌روزرسانی می‌شوند

### 2. فقط موجودی فعال
- `enable_stock_update = true`
- سایر تنظیمات `false`
- **نتیجه:** فقط `updateProductStockWithToken` فراخوانی می‌شود

### 3. فقط قیمت فعال  
- `enable_price_update = true`
- سایر تنظیمات `false`
- **نتیجه:** `updateProductInfoWithToken` با قیمت (بدون نام) فراخوانی می‌شود

### 4. فقط نام فعال
- `enable_name_update = true`
- سایر تنظیمات `false`
- **نتیجه:** `updateProductInfoWithToken` با نام (بدون قیمت) فراخوانی می‌شود

### 5. ترکیبات مختلف
- هر ترکیبی از تنظیمات پشتیبانی می‌شود
- فقط تنظیمات فعال اعمال می‌شوند

### 6. هیچ تنظیمی فعال نیست
- همه تنظیمات `false`
- **نتیجه:** هیچ API call انجام نمی‌شود، عملیات skip می‌شود

## API Calls شرطی

### موجودی (Stock Update):
```php
// فقط اگر enable_stock_update = true
$this->updateProductStockWithToken($license, $itemId, $stockQuantity);
```

**درخواست API:**
```json
{
    "fn": "change_count_sub_product",
    "code": "ItemId",
    "count": stockQuantity
}
```

### اطلاعات محصول (Product Info):
```php  
// فقط اگر enable_price_update یا enable_name_update = true
$this->updateProductInfoWithToken($license, $itemId, $title, $price, $discount);
```

**درخواست API:**
```json
{
    "fn": "update_product_info", 
    "code": "ItemId",
    "title": "title (اگر enable_name_update = true)",
    "price": price (اگر enable_price_update = true),
    "discount": discount (اگر enable_price_update = true)
}
```

## اعتبارسنجی داده‌ها

### موجودی:
- `stockQuantity >= 0` و عددی باشد
- در صورت نامعتبر بودن، خطا ثبت و عملیات رد می‌شود

### قیمت:
- `price > 0` و عددی باشد  
- در صورت نامعتبر بودن، فقط قیمت رد و سایر عملیات ادامه می‌یابد

### نام محصول:
- `title` خالی نباشد
- در صورت خالی بودن، فقط نام رد و سایر عملیات ادامه می‌یابد

## لاگ‌گذاری مفصل

### لاگ تنظیمات:
```php
Log::info('نتیجه نهایی به‌روزرسانی محصول بر اساس تنظیمات', [
    'license_id' => $license->id,
    'item_id' => $itemId,
    'user_settings' => [
        'enable_stock_update' => $enableStockUpdate,
        'enable_price_update' => $enablePriceUpdate, 
        'enable_name_update' => $enableNameUpdate
    ],
    'updates_performed' => array_keys($results),
    'settings_applied' => [
        'stock_update' => $enableStockUpdate,
        'price_update' => $enablePriceUpdate,
        'name_update' => $enableNameUpdate
    ]
]);
```

### لاگ عملیات:
```php
Log::info('به‌روزرسانی موجودی محصول', [
    'item_id' => $itemId,
    'stock_quantity' => $stockQuantity,
    'success' => $stockResult['success'] ?? false
]);
```

## ساختار پاسخ

```php
return [
    'success' => $allSuccessful && $hasAnyUpdate,
    'message' => 'محصول با موفقیت به‌روزرسانی شد بر اساس تنظیمات کاربر',
    'results' => [
        'stock_update' => $stockResult,    // اگر فعال باشد
        'info_update' => $infoResult       // اگر فعال باشد
    ],
    'settings_applied' => [
        'stock_update' => $enableStockUpdate,
        'price_update' => $enablePriceUpdate,
        'name_update' => $enableNameUpdate
    ]
];
```

## فایل‌های تغییر یافته

1. **`app/Jobs/Tantooo/ProcessTantoooSyncRequest.php`**
   - متد `updateProductInTantooo` کاملاً بازنویسی شد
   - منطق شرطی برای تنظیمات اضافه شد
   - لاگ‌گذاری مفصل اضافه شد

2. **`test_tantooo_user_settings.php`** (جدید)
   - تست‌های جامع برای تمام سناریوها
   - مستندات کامل عملکرد
   - مثال‌های عملی استفاده

## مزایای پیاده‌سازی

✅ **کنترل دقیق:** کاربران تعیین می‌کنند کدام پارامترها به‌روزرسانی شوند  
✅ **بهینه‌سازی:** فقط API callهای ضروری انجام می‌شوند  
✅ **انعطاف‌پذیری:** پشتیبانی از تمام ترکیبات تنظیمات  
✅ **ردیابی:** لاگ‌های مفصل برای debugging  
✅ **پایداری:** مدیریت خطای جامع برای تمام حالات

## بررسی عملکرد

برای تست عملکرد سیستم:

```bash
php test_tantooo_user_settings.php
```

این فایل تمام سناریوهای ممکن و ساختار درخواست‌های API را نمایش می‌دهد.
