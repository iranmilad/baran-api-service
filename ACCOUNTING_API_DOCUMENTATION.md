# AccountingApiTrait Documentation

این trait برای ارتباط با API حسابداری فروشگاه طراحی شده است و امکان به‌روزرسانی اطلاعات محصولات شامل نام، قیمت و درصد تخفیف را فراهم می‌کند.

## ویژگی‌های کلیدی

- ✅ به‌روزرسانی محصول تکی
- ✅ به‌روزرسانی دسته‌ای محصولات
- ✅ تبدیل واحد قیمت (ریال/تومان)
- ✅ محاسبه قیمت با تخفیف
- ✅ مدیریت خطا و لاگ کامل
- ✅ پشتیبانی از Bearer Token

## ساختار API

API حسابداری با ساختار زیر کار می‌کند:

```bash
curl --location 'https://03535.ir/accounting_api' \
--header 'X-API-KEY: f3a7c8e45d912b6a19e6f2e7b0843c9d' \
--header 'Content-Type: application/json' \
--header 'Authorization: Bearer TOKEN' \
--data '{
    "fn": "update_product_info",
    "code": "کد یکتا",
    "title": "دامن طرح دار نخی 188600",
    "price": 1791000,
    "discount": 2
}'
```

## نصب و راه‌اندازی

### 1. اضافه کردن Trait به Controller

```php
<?php

namespace App\Http\Controllers;

use App\Traits\AccountingApiTrait;

class YourController extends Controller
{
    use AccountingApiTrait;
    
    // متدهای کنترلر...
}
```

### 2. تنظیم فیلدهای دیتابیس

در جدول `user_settings` فیلدهای زیر اضافه شده است:

```sql
ALTER TABLE user_settings ADD COLUMN accounting_api_url VARCHAR(255) NULL;
ALTER TABLE user_settings ADD COLUMN accounting_api_key VARCHAR(255) NULL;  
ALTER TABLE user_settings ADD COLUMN accounting_bearer_token TEXT NULL;
ALTER TABLE user_settings ADD COLUMN accounting_price_unit ENUM('rial', 'toman') DEFAULT 'toman';
```

### 3. اجرای Migration

```bash
php artisan migrate
```

## متدهای Trait

### 1. به‌روزرسانی محصول تکی

```php
$result = $this->updateProductInAccountingApi(
    $code,           // کد یکتای محصول
    $title,          // نام محصول
    $price,          // قیمت محصول
    $discount,       // درصد تخفیف (اختیاری)
    $apiUrl,         // آدرس API
    $apiKey,         // کلید API
    $bearerToken     // توکن Bearer (اختیاری)
);
```

### 2. به‌روزرسانی چندین محصول

```php
$products = [
    [
        'code' => 'PROD001',
        'title' => 'محصول اول',
        'price' => 150000,
        'discount' => 5
    ],
    [
        'code' => 'PROD002', 
        'title' => 'محصول دوم',
        'price' => 250000,
        'discount' => 10
    ]
];

$result = $this->updateMultipleProductsInAccountingApi(
    $products,
    $apiUrl,
    $apiKey,
    $bearerToken
);
```

### 3. دریافت تنظیمات API

```php
$settings = $this->getAccountingApiSettings($license);
// برمی‌گرداند:
// [
//     'api_url' => 'https://...',
//     'api_key' => 'key...',
//     'bearer_token' => 'token...'
// ]
```

### 4. تبدیل محصول برای API حسابداری

```php
$productInfo = [
    'code' => 'PROD001',
    'name' => 'محصول تست',
    'sellPrice' => 1000000,
    'discount' => 5
];

$convertedProduct = $this->convertProductForAccountingApi($productInfo, $userSettings);
```

### 5. محاسبه قیمت با تخفیف

```php
$originalPrice = 1000000;
$discountPercent = 15;

$finalPrice = $this->calculateDiscountedPrice($originalPrice, $discountPercent);
// نتیجه: 850000
```

## استفاده در ProductStockController

در `ProductStockController` این trait به صورت خودکار اجرا می‌شود و پس از به‌روزرسانی موجودی در وردپرس، محصولات در API حسابداری نیز به‌روزرسانی می‌شوند:

```php
// در متد updateWordPressProducts
$accountingUpdateResult = $this->updateProductsInAccountingApi($license, $foundProducts, $userSettings);
```

## تست API

### 1. تست از طریق Route

```bash
POST /api/v1/products/test-accounting-api
```

با پارامترهای:
```json
{
    "code": "TEST001",
    "title": "محصول تست",
    "price": 150000,
    "discount": 5
}
```

### 2. تست مستقل

فایل `test_accounting_api.php` نمونه کاملی از تست‌های مختلف ارائه می‌دهد.

## مدیریت خطا

تمامی متدها نتایج را در فرمت زیر برمی‌گردانند:

```php
[
    'success' => true/false,
    'message' => 'پیام نتیجه',
    'data' => [], // در صورت موفقیت
    'error' => 'جزئیات خطا', // در صورت خطا
    'updated_count' => 0 // تعداد محصولات به‌روزرسانی شده
]
```

## تبدیل واحد قیمت

سیستم به صورت خودکار قیمت‌ها را بر اساس تنظیمات کاربر تبدیل می‌کند:

- **ریال به تومان**: تقسیم بر 10
- **تومان به ریال**: ضرب در 10

## لاگ‌گیری

تمامی فعالیت‌ها در لاگ Laravel ثبت می‌شوند:

```php
Log::info('محصول با موفقیت در API حسابداری به‌روزرسانی شد', [
    'product_code' => $code,
    'api_response' => $responseData
]);
```

## مثال کامل استفاده

```php
<?php

namespace App\Http\Controllers;

use App\Traits\AccountingApiTrait;

class MyController extends Controller
{
    use AccountingApiTrait;
    
    public function updateProduct($license, $productData)
    {
        // دریافت تنظیمات API
        $settings = $this->getAccountingApiSettings($license);
        
        if (!$settings) {
            return ['success' => false, 'message' => 'تنظیمات API یافت نشد'];
        }
        
        // تبدیل محصول
        $convertedProduct = $this->convertProductForAccountingApi($productData);
        
        // به‌روزرسانی در API حسابداری
        return $this->updateProductInAccountingApi(
            $convertedProduct['code'],
            $convertedProduct['title'],
            $convertedProduct['price'],
            $convertedProduct['discount'],
            $settings['api_url'],
            $settings['api_key'],
            $settings['bearer_token']
        );
    }
}
```

## نکات مهم

1. **اجباری بودن فیلدها**: فیلدهای `code`، `title` و `price` اجباری هستند
2. **تایم‌اوت**: تایم‌اوت پیش‌فرض 60 ثانیه است
3. **SSL**: تایید SSL غیرفعال است (`verify => false`)
4. **Bearer Token**: اختیاری است و در صورت وجود اضافه می‌شود
5. **تبدیل قیمت**: بر اساس تنظیمات `rain_sale_price_unit` و `accounting_price_unit` انجام می‌شود

## رفع مشکلات متداول

### خطای اتصال API
- بررسی کنید URL صحیح باشد
- کلید API معتبر باشد
- Bearer Token در صورت نیاز تنظیم شده باشد

### خطای اعتبارسنجی
- تمامی فیلدهای اجباری ارسال شده باشند
- نوع داده‌ها صحیح باشد (price باید numeric باشد)

### خطای تبدیل قیمت
- تنظیمات واحد قیمت در `user_settings` درست باشد
- فیلد `sellPrice` در داده محصول موجود باشد
