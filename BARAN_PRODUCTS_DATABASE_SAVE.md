# ذخیره‌سازی اطلاعات محصولات باران در دیتابیس

## خلاصه تغییرات

پس از دریافت اطلاعات محصولات از API باران، اطلاعات **قبل از به‌روزرسانی در Tantooo**، در جدول `products` دیتابیس ذخیره می‌شود.

## فرآیند

```
ProcessTantoooSyncRequest Job
│
├─ 1. دریافت کدهای محصولات
│
├─ 2. دریافت اطلاعات از API باران
│   └─ Response: آرایه‌ای از محصولات با قیمت، موجودی، انبار و...
│
├─ 3. ذخیره‌سازی در دیتابیس ✨ (جدید)
│   ├─ برای هر محصول:
│   │  ├─ جستجوی محصول موجود (بر اساس license_id + item_id)
│   │  ├─ اگر موجود: به‌روزرسانی
│   │  └─ اگر جدید: ایجاد رکورد جدید
│   │
│   └─ نتایج:
│      ├─ saved_count: تعداد محصولات جدید
│      ├─ updated_count: تعداد محصولات به‌روزرسانی شده
│      └─ error_count: تعداد خطاها
│
└─ 4. به‌روزرسانی در Tantooo
    ├─ استفاده از اطلاعات ذخیره شده
    ├─ تنظیم موجودی
    ├─ تنظیم قیمت
    └─ تنظیم نام محصول
```

## متد `saveBaranProductsToDatabase`

### موقعیت
- **فایل:** `app/Jobs/Tantooo/ProcessTantoooSyncRequest.php`
- **جایی که فراخوانی می‌شود:** قبل از متد `processAndUpdateProductsFromBaran`

### پارامترها
```php
protected function saveBaranProductsToDatabase($license, $baranProducts)
```

- `$license` - شی License کاربر
- `$baranProducts` - آرایه‌ای از اطلاعات محصولات دریافت شده از باران

### نتیجه بازگشتی
```php
[
    'success' => true,
    'data' => [
        'saved_count' => 50,          // محصولات جدید ایجاد شده
        'updated_count' => 150,       // محصولات موجود به‌روزرسانی شده
        'total_processed' => 200,     // کل محصولات پردازش شده
        'errors' => []                // خطاهای ایجاد شده
    ],
    'message' => 'محصولات با موفقیت ذخیره شدند (50 جدید، 150 به‌روزرسانی شده)'
]
```

## ستون‌های ذخیره‌سازی

| ستون | منبع داده | توضیح |
|------|-----------|-------|
| `id` | DB | شناسه اولیه |
| `license_id` | $license->id | شناسه لایسنس |
| `item_id` | baranProduct['itemID'] | شناسه یکتای محصول |
| `item_name` | baranProduct['itemName'] | نام محصول |
| `barcode` | baranProduct['barcode'] | کد بارکد |
| `price_amount` | baranProduct['salePrice'] | قیمت فروش |
| `price_after_discount` | baranProduct['priceAfterDiscount'] | قیمت با تخفیف |
| `total_count` | baranProduct['stockQuantity'] | موجودی |
| `stock_id` | baranProduct['stockID'] | شناسه انبار |
| `department_name` | baranProduct['departmentName'] | دسته‌بندی |
| `is_variant` | false | نشانه واریانت |
| `last_sync_at` | now() | آخرین زمان همگام‌سازی |
| `created_at` | DB | زمان ایجاد |
| `updated_at` | DB | زمان آخرین به‌روزرسانی |

## منطق ذخیره‌سازی

### برای هر محصول:

```php
// 1. جستجوی محصول موجود
$product = Product::where('license_id', $license->id)
    ->where('item_id', $itemId)
    ->first();

// 2. بررسی وجود
if ($product) {
    // اگر موجود: به‌روزرسانی
    $product->update([
        'item_name' => $itemName,
        'barcode' => $barcode,
        'price_amount' => $priceAmount,
        'price_after_discount' => $priceAfterDiscount,
        'total_count' => $totalCount,
        'stock_id' => $stockId,
        'department_name' => $departmentName,
        'last_sync_at' => now()
    ]);
    $updatedCount++;
} else {
    // اگر جدید: ایجاد
    Product::create([
        'license_id' => $license->id,
        'item_id' => $itemId,
        'item_name' => $itemName,
        'barcode' => $barcode,
        'price_amount' => $priceAmount,
        'price_after_discount' => $priceAfterDiscount,
        'total_count' => $totalCount,
        'stock_id' => $stockId,
        'department_name' => $departmentName,
        'is_variant' => false,
        'last_sync_at' => now()
    ]);
    $savedCount++;
}
```

## لاگ‌گذاری

### لاگ شروع:
```
INFO: شروع ذخیره‌سازی اطلاعات محصولات باران
{
    'license_id': 123,
    'total_products': 200
}
```

### لاگ برای هر محصول:
```
DEBUG: محصول جدید ذخیره شد
{
    'item_id': '2e0f60f7-e40e-4e7c-8e82-00624bc154e1',
    'barcode': '123456789012',
    'action': 'created'
}
```

### لاگ پایان:
```
INFO: تکمیل ذخیره‌سازی محصولات باران
{
    'license_id': 123,
    'total_products': 200,
    'saved_count': 50,
    'updated_count': 150,
    'error_count': 0
}
```

## مدیریت خطا

- اگر `itemID` موجود نباشد، محصول رد می‌شود
- اگر خطای دیتابیسی ایجاد شود، خطا ثبت و ادامه می‌یابد
- تمام خطاها در آرایه `errors` ثبت می‌شوند
- متد کلی به موفقیت ادامه می‌یابد حتی با وجود خطاهای جزئی

## مثال استفاده

```php
// در ProcessTantoooSyncRequest::handle()

// دریافت اطلاعات از باران
$baranResult = $this->getUpdatedProductInfoFromBaran($license, $productCodes);

// ذخیره‌سازی در دیتابیس
$saveResult = $this->saveBaranProductsToDatabase(
    $license,
    $baranResult['data']['products']
);

if (!$saveResult['success']) {
    Log::warning('خطا در ذخیره اطلاعات', [
        'message' => $saveResult['message']
    ]);
} else {
    Log::info('اطلاعات ذخیره شد', [
        'saved_count' => $saveResult['data']['saved_count'],
        'updated_count' => $saveResult['data']['updated_count']
    ]);
}

// سپس به‌روزرسانی در Tantooo
$updateResult = $this->processAndUpdateProductsFromBaran($license, $allProducts, $baranResult['data']['products']);
```

## مزایای این رویکرد

1. **تاریخچه‌گذاری:** ثبت تغییرات قیمت و موجودی
2. **سرعت:** دسترسی سریع بدون درخواست API
3. **آفلاین:** عملکرد بدون اتصال API
4. **ردیابی:** دانستن زمان آخرین به‌روزرسانی
5. **مقایسه:** مقایسه اطلاعات قبل و بعد
6. **احصائیات:** تحلیل روند تغییرات

## بررسی داده‌ها

### SQL برای دیدن محصولات ذخیره شده:
```sql
SELECT * FROM products 
WHERE license_id = 123 
ORDER BY last_sync_at DESC;
```

### SQL برای محصولات جدید امروز:
```sql
SELECT * FROM products 
WHERE license_id = 123 
AND DATE(created_at) = CURDATE()
ORDER BY created_at DESC;
```

### SQL برای محصولات به‌روزرسانی شده:
```sql
SELECT * FROM products 
WHERE license_id = 123 
AND DATE(updated_at) = CURDATE()
AND DATE(created_at) != CURDATE()
ORDER BY updated_at DESC;
```

## فایل‌های اصلاح شده

1. **`app/Jobs/Tantooo/ProcessTantoooSyncRequest.php`**
   - اضافه کردن متد `saveBaranProductsToDatabase`
   - فراخوانی متد قبل از `processAndUpdateProductsFromBaran`
   - لاگ‌گذاری مفصل برای نتایج

2. **`test_save_baran_products.php`** (جدید)
   - تست و مستندات ذخیره‌سازی
   - مثال‌های عملی
   - ساختار داده‌های ذخیره شده

## نکات مهم

- ✅ هر محصول بر اساس `license_id + item_id` منحصر است
- ✅ تاریخ `last_sync_at` برای هر به‌روزرسانی تنظیم می‌شود
- ✅ محصولات جدید با `is_variant = false` ایجاد می‌شوند
- ✅ تمام خطاها ثبت و پایش می‌شوند
- ✅ عملیات ادامه می‌یابد حتی با وجود خطاهای جزئی
