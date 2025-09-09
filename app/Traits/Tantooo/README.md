# API Tantooo Trait Documentation

## مقدمه

این trait برای ارتباط با API Tantooo (API حسابداری) طراحی شده است و امکان به‌روزرسانی اطلاعات محصولات شامل نام، قیمت و درصد تخفیف را فراهم می‌کند.

## ساختار پوشه

```
app/Traits/Tantooo/
├── TantoooApiTrait.php
```

## ویژگی‌های کلیدی

- ✅ به‌روزرسانی محصول تکی
- ✅ به‌روزرسانی دسته‌ای محصولات  
- ✅ تبدیل واحد قیمت (ریال/تومان)
- ✅ محاسبه قیمت با تخفیف
- ✅ مدیریت خطا و لاگ کامل
- ✅ پشتیبانی از Bearer Token
- ✅ استفاده از آدرس وب‌سرویس از مدل License

## نحوه استفاده

### 1. اضافه کردن Trait

```php
<?php

namespace App\Http\Controllers;

use App\Traits\Tantooo\TantoooApiTrait;

class YourController extends Controller
{
    use TantoooApiTrait;
    
    // متدهای کنترلر...
}
```

### 2. تنظیمات مورد نیاز

در جدول `user_settings` فیلدهای زیر نیاز است:

- `tantooo_api_key`: کلید API Tantooo
- `tantooo_bearer_token`: توکن Bearer (اختیاری)
- `woocommerce_price_unit`: واحد قیمت

آدرس API از `license.website_url` + `/accounting_api` استفاده می‌شود.

## متدهای موجود

### updateProductInTantoooApi()

به‌روزرسانی یک محصول در API Tantooo

```php
$result = $this->updateProductInTantoooApi(
    'کد یکتا',           // کد محصول
    'نام محصول',         // نام محصول  
    1791000,            // قیمت
    2,                  // درصد تخفیف
    $apiUrl,            // آدرس API
    $apiKey,            // کلید API
    $bearerToken        // توکن Bearer
);
```

### updateMultipleProductsInTantoooApi()

به‌روزرسانی چندین محصول

```php
$products = [
    [
        'code' => 'PROD001',
        'title' => 'محصول اول',
        'price' => 150000,
        'discount' => 5
    ]
];

$result = $this->updateMultipleProductsInTantoooApi(
    $products,
    $apiUrl,
    $apiKey,
    $bearerToken
);
```

### getTantoooApiSettings()

دریافت تنظیمات API از لایسنس

```php
$settings = $this->getTantoooApiSettings($license);
// برمی‌گرداند:
// [
//     'api_url' => 'https://example.com/accounting_api',
//     'api_key' => 'key...',
//     'bearer_token' => 'token...'
// ]
```

### convertProductForTantoooApi()

تبدیل اطلاعات محصول برای API Tantooo

```php
$productInfo = [
    'code' => 'PROD001',
    'name' => 'محصول تست',
    'sellPrice' => 1000000
];

$converted = $this->convertProductForTantoooApi($productInfo, $userSettings);
```

### calculateDiscountedPrice()

محاسبه قیمت با تخفیف

```php
$finalPrice = $this->calculateDiscountedPrice(1000000, 15);
// نتیجه: 850000
```

## مثال کامل

```php
<?php

namespace App\Http\Controllers;

use App\Traits\ApiTantooo\TantoooApiTrait;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductController extends Controller
{
    use TantoooApiTrait;
    
    public function updateProductInTantooo(Request $request)
    {
        $license = JWTAuth::parseToken()->authenticate();
        
        // دریافت تنظیمات API
        $settings = $this->getTantoooApiSettings($license);
        
        if (!$settings) {
            return response()->json([
                'success' => false,
                'message' => 'تنظیمات API Tantooo یافت نشد'
            ]);
        }
        
        // به‌روزرسانی محصول
        $result = $this->updateProductInTantoooApi(
            $request->code,
            $request->title,
            $request->price,
            $request->discount ?? 0,
            $settings['api_url'],
            $settings['api_key'],
            $settings['bearer_token']
        );
        
        return response()->json($result);
    }
}
```

## تبدیل واحد قیمت

سیستم از تنظیمات `woocommerce_price_unit` برای تبدیل واحد قیمت استفاده می‌کند:

- **ریال به تومان**: تقسیم بر 10
- **تومان به ریال**: ضرب در 10

## ساختار پاسخ API

```json
{
    "success": true,
    "message": "محصول با موفقیت در API Tantooo به‌روزرسانی شد",
    "data": {
        // پاسخ API Tantooo
    }
}
```

## لاگ‌گیری

تمامی فعالیت‌ها در لاگ Laravel ثبت می‌شوند:

```php
Log::info('محصول با موفقیت در API Tantooo به‌روزرسانی شد', [
    'product_code' => $code,
    'api_response' => $responseData
]);
```
