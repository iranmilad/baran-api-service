# Jobs Refactoring - WooCommerce API Traits Integration

## خلاصه تغییرات

این فایل تغییرات انجام شده در Job های مربوط به ووکامرس برای استفاده از traits را مستند می‌کند.

## تغییرات انجام شده

### 1. FetchAndDivideProducts Job

#### قبل از Refactoring:
- استفاده مستقیم از `Automattic\WooCommerce\Client`
- متد `getAllProductBarcodes` در خود Job
- کد تکراری برای ارتباط با API ووکامرس

#### بعد از Refactoring:
- **اضافه شده**: `use WordPressMasterTrait`
- **حذف شده**: `use Automattic\WooCommerce\Client`
- **حذف شده**: متد `getAllProductBarcodes` از Job
- **استفاده**: از `getAllWooCommerceProductBarcodes()` trait

```php
// قبل
$allBarcodes = $this->getAllProductBarcodes($license, $wooApiKey, $startTime, $maxExecutionTime);

// بعد
$allBarcodes = $this->getAllWooCommerceProductBarcodes($license);
```

### 2. BulkInsertWooCommerceProducts Job

#### قبل از Refactoring:
- استفاده مستقیم از `Http` facade برای درخواست‌های API
- کد تکراری برای authentication
- مدیریت پاسخ API در خود Job

#### بعد از Refactoring:
- **اضافه شده**: `use WordPressMasterTrait`
- **تغییر**: استفاده از `checkWooCommerceProductsExistence()` trait
- **تغییر**: استفاده از `insertWooCommerceBatchProducts()` trait (جزئی)

```php
// قبل
$response = Http::withOptions([...])->withBasicAuth(...)->get(...);

// بعد
$checkResult = $this->checkWooCommerceProductsExistence($license, $uniqueIds);
```

## متدهای جدید اضافه شده به WooCommerceApiTrait

### 1. getAllWooCommerceProductBarcodes()
```php
protected function getAllWooCommerceProductBarcodes($license)
```
- دریافت تمام barcodes محصولات از ووکامرس
- مدیریت خطاها و logging
- بازگشت آرایه barcodes

### 2. checkWooCommerceProductsExistence()
```php
protected function checkWooCommerceProductsExistence($license, $uniqueIds)
```
- بررسی وجود محصولات بر اساس unique_ids
- پشتیبانی از حالت 404 (not found)
- بازگشت structured result

### 3. insertWooCommerceBatchProducts()
```php
protected function insertWooCommerceBatchProducts($license, $products)
```
- درج دسته‌ای محصولات در ووکامرس
- مدیریت authentication خودکار
- بازگشت structured result

### 4. insertWooCommerceProductVariations()
```php
protected function insertWooCommerceProductVariations($license, $parentWooId, $variations)
```
- درج واریانت‌های محصول
- مدیریت endpoint مخصوص variations
- بازگشت structured result

## فایل‌های تغییر یافته

### FetchAndDivideProducts.php
- ✅ حذف dependency به `Automattic\WooCommerce\Client`
- ✅ اضافه شدن `WordPressMasterTrait`
- ✅ حذف متد `getAllProductBarcodes`
- ✅ استفاده از trait method

### BulkInsertWooCommerceProducts.php
- ✅ اضافه شدن `WordPressMasterTrait`
- ✅ تغییر بخش بررسی وجود محصولات
- 🔄 refactoring متدهای درج (در حال انجام)

### WooCommerceApiTrait.php
- ✅ اضافه شدن 4 متد جدید برای API ووکامرس
- ✅ مستندسازی کامل متدها
- ✅ پشتیبانی از structured results

## مزایای این تغییرات

1. **حذف Dependencies**: عدم وابستگی به `Automattic\WooCommerce\Client`
2. **قابلیت استفاده مجدد**: متدهای API در تمام Job ها قابل استفاده
3. **مدیریت خطای یکسان**: همه API های ووکامرس خطاها را یکسان مدیریت می‌کنند
4. **Testing بهتر**: متدهای trait قابل تست مستقل هستند
5. **سازمان‌دهی بهتر**: تمام API های ووکامرس در یک مکان
6. **کد تمیزتر**: حذف کد تکراری از Job ها

## تغییرات در حال انجام

- ✅ **FetchAndDivideProducts**: کامل شده
- ✅ **BulkInsertWooCommerceProducts**: بخشی تکمیل، ادامه دارد  
- ✅ **BulkUpdateWooCommerceProducts**: کامل شده
- ⏳ **سایر Job های ووکامرس**: در صف بررسی

## آخرین تغییرات

### 3. BulkUpdateWooCommerceProducts Job

#### قبل از Refactoring:
- استفاده مستقیم از `Http` facade
- کد پیچیده برای retry mechanism
- authentication دستی

#### بعد از Refactoring:
- **اضافه شده**: `use WordPressMasterTrait`
- **حذف شده**: `use Illuminate\Support\Facades\Http`
- **استفاده**: از `updateWooCommerceBatchProducts()` trait

```php
// قبل
$httpClient = Http::withOptions([...])->retry(...)->withBasicAuth(...);
$response = $httpClient->put(...);

// بعد  
$updateResult = $this->updateWooCommerceBatchProducts($license, $chunk);
```

### متد جدید اضافه شده

#### updateWooCommerceBatchProducts()
```php
protected function updateWooCommerceBatchProducts($license, $products)
```
- به‌روزرسانی دسته‌ای محصولات در ووکامرس
- retry mechanism داخلی
- structured result format
- authentication خودکار

## structured Result Format

تمام متدهای trait یک فرمت یکسان برای نتیجه دارند:

```php
[
    'success' => true/false,
    'status' => HTTP_STATUS_CODE,
    'data' => RESPONSE_DATA,
    'body' => RAW_RESPONSE,
    'error' => ERROR_MESSAGE (در صورت خطا)
]
```

## نتیجه

Job های ووکامرس حالا از traits استفاده می‌کنند و کد تمیزتر و قابل نگهداری‌تری دارند. تمام API های ووکامرس در یک مکان متمرکز شده‌اند.

---
تاریخ: 5 سپتامبر 2025
