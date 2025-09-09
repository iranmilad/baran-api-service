# WooCommerceApiKeyController Refactoring

## خلاصه تغییرات

این فایل تغییرات انجام شده در `WooCommerceApiKeyController` برای استفاده از traits ووکامرس را مستند می‌کند.

## تغییرات انجام شده

### 1. اضافه کردن Trait
- اضافه شدن `use WordPressMasterTrait;` به کنترلر
- Import `App\Traits\WordPress\WordPressMasterTrait`

### 2. ایجاد متد جدید در Trait
- اضافه شدن متد `validateWooCommerceApiCredentials()` به `WooCommerceApiTrait`
- این متد تست اتصال ووکامرس با کلید و رمز API مشخص را انجام می‌دهد

### 3. Refactoring کنترلر
- حذف کد مستقیم API ووکامرس از متد `store()`
- حذف کد مستقیم API ووکامرس از متد `validate()`
- استفاده از متد trait برای تست اتصال

## فایل‌های تغییر یافته

### WooCommerceApiKeyController.php
- **اضافه شده**: `use WordPressMasterTrait`
- **تغییر**: متد `store()` حالا از `validateWooCommerceApiCredentials()` استفاده می‌کند
- **تغییر**: متد `validate()` حالا از `validateWooCommerceApiCredentials()` استفاده می‌کند
- **حذف شده**: کد مستقیم Http facade برای تست اتصال

### WooCommerceApiTrait.php
- **اضافه شده**: متد `validateWooCommerceApiCredentials()`
- **قابلیت‌ها**: تست اتصال، دریافت system_status، مدیریت خطاها

## متد جدید اضافه شده

### validateWooCommerceApiCredentials()

```php
protected function validateWooCommerceApiCredentials($siteUrl, $apiKey, $apiSecret)
```

**پارامترها:**
- `$siteUrl`: آدرس سایت ووکامرس
- `$apiKey`: کلید API ووکامرس
- `$apiSecret`: رمز API ووکامرس

**بازگشت:**
```php
[
    'success' => true/false,
    'message' => 'پیام نتیجه',
    'system_status' => [...] // در صورت موفقیت
]
```

## API های ووکامرس منتقل شده

### قبل از Refactoring:
```php
$response = Http::withBasicAuth($request->api_key, $request->api_secret)
    ->get($request->site_url . '/wp-json/wc/v3/system_status');
```

### بعد از Refactoring:
```php
$connectionTest = $this->validateWooCommerceApiCredentials(
    $request->site_url,
    $request->api_key,
    $request->api_secret
);
```

## مزایای این تغییرات

1. **قابلیت استفاده مجدد**: متد validation حالا در سایر کنترلرها قابل استفاده است
2. **مدیریت خطای بهتر**: خطاهای API به شکل یکسان مدیریت می‌شوند
3. **تست پذیری بهتر**: متد trait به راحتی قابل تست است
4. **کد تمیزتر**: حذف کد تکراری در کنترلر
5. **سازمان‌دهی بهتر**: تمام API های ووکامرس در traits مربوطه قرار دارند

## بررسی سایر کنترلرها

### ProductSyncController.php
- **نتیجه بررسی**: هیچ API مستقیم ووکامرس استفاده نمی‌کند
- **وضعیت**: فقط Job ها را dispatch می‌کند، نیازی به تغییر ندارد

## نتیجه

تمام API های مستقیم ووکامرس از `WooCommerceApiKeyController` به traits مربوطه منتقل شده‌اند. کنترلر حالا از متدهای trait استفاده می‌کند و کد تمیزتر و قابل نگهداری‌تر شده است.

---
تاریخ: 5 سپتامبر 2025
