# مستندات کامل API Traits

## مقدمه

این پروژه حاوی دو مجموعه trait مجزا برای مدیریت API های مختلف است:

1. **API Tantooo** - برای ارتباط با API حسابداری
2. **WordPress/WooCommerce** - برای مدیریت کامل WooCommerce

## ساختار پوشه‌ها

```
app/Traits/
├── ApiTantooo/
│   ├── TantoooApiTrait.php
│   └── README.md
├── WordPress/
│   ├── WooCommerceApiTrait.php
│   ├── WooCommerceProductTrait.php  
│   ├── WooCommerceSettingsTrait.php
│   ├── WordPressMasterTrait.php
│   └── README.md
└── PriceUnitConverter.php (موجود قبلی)
```

## API Tantooo

### ویژگی‌ها
- به‌روزرسانی محصولات در API حسابداری
- استفاده از آدرس وب‌سرویس از مدل License
- تبدیل واحد قیمت با استفاده از `woocommerce_price_unit`
- پشتیبانی کامل از Bearer Token

### نحوه استفاده
```php
use App\Traits\ApiTantooo\TantoooApiTrait;

class Controller extends BaseController 
{
    use TantoooApiTrait;
    
    public function updateProduct()
    {
        $settings = $this->getTantoooApiSettings($license);
        $result = $this->updateProductInTantoooApi(...);
    }
}
```

## WordPress/WooCommerce

### ویژگی‌ها
- مجموعه کامل برای مدیریت WooCommerce
- شامل تمامی متدهای موجود در پروژه
- قابلیت استفاده مجزا یا ترکیبی
- مدیریت کامل محصولات، سفارشات، دسته‌بندی‌ها

### نحوه استفاده

#### Master Trait (توصیه شده)
```php
use App\Traits\WordPress\WordPressMasterTrait;

class ProductStockController extends Controller
{
    use WordPressMasterTrait;
    
    // تمامی متدهای WooCommerce در دسترس است
    private function updateWordPressProducts($license, $products, $settings)
    {
        return $this->updateWooCommerceProducts($license, $products, $settings);
    }
}
```

#### Trait های جداگانه
```php
use App\Traits\WordPress\WooCommerceProductTrait;
use App\Traits\WordPress\WooCommerceSettingsTrait;

class ProductController extends Controller
{
    use WooCommerceProductTrait, WooCommerceSettingsTrait;
    
    // فقط متدهای محصولات و تنظیمات
}
```

## تنظیمات مشترک

### مدل License
- `website_url`: آدرس وب‌سایت (استفاده در هر دو API)
- `woocommerceApiKey`: رابطه با WooCommerce API

### مدل UserSetting
- `woocommerce_price_unit`: واحد قیمت (استفاده در هر دو API)
- `tantooo_api_key`: کلید API Tantooo
- `tantooo_bearer_token`: توکن Bearer برای API Tantooo
- `enable_stock_update`: فعال‌سازی به‌روزرسانی موجودی
- `enable_price_update`: فعال‌سازی به‌روزرسانی قیمت

## مثال پیاده‌سازی در کنترلر

```php
<?php

namespace App\Http\Controllers;

use App\Traits\ApiTantooo\TantoooApiTrait;
use App\Traits\WordPress\WordPressMasterTrait;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductStockController extends Controller
{
    use TantoooApiTrait, WordPressMasterTrait;

    public function getStockByUniqueId(Request $request)
    {
        try {
            $license = JWTAuth::parseToken()->authenticate();
            $userSettings = UserSetting::where('license_id', $license->id)->first();
            
            // دریافت محصولات از انبار
            $foundProducts = $this->getProductsFromWarehouse($request->unique_ids);
            
            // به‌روزرسانی در WordPress
            $wordpressResult = null;
            if (!empty($foundProducts)) {
                $wordpressResult = $this->updateWordPressProducts(
                    $license, 
                    $foundProducts, 
                    $userSettings
                );
            }
            
            // به‌روزرسانی در API Tantooo
            $tantoooResult = null;
            if (!empty($foundProducts)) {
                $tantoooSettings = $this->getTantoooApiSettings($license);
                if ($tantoooSettings) {
                    $tantoooProducts = [];
                    foreach ($foundProducts as $product) {
                        $tantoooProducts[] = $this->convertProductForTantoooApi(
                            $product['product_info'], 
                            $userSettings
                        );
                    }
                    
                    $tantoooResult = $this->updateMultipleProductsInTantoooApi(
                        $tantoooProducts,
                        $tantoooSettings['api_url'],
                        $tantoooSettings['api_key'],
                        $tantoooSettings['bearer_token']
                    );
                }
            }
            
            return response()->json([
                'success' => true,
                'data' => [
                    'found_products' => $foundProducts,
                    'wordpress_update' => $wordpressResult,
                    'tantooo_update' => $tantoooResult
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('خطا در استعلام موجودی', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'خطای سیستمی',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
```

## مزایای ساختار جدید

### 1. سازماندهی بهتر
- هر API در پوشه مجزا
- فایل‌های trait مجزا بر اساس عملکرد
- مستندات جداگانه برای هر بخش

### 2. قابلیت استفاده مجزا
- امکان استفاده از trait های مختلف در کنترلرهای مختلف
- عدم وابستگی غیرضروری بین API ها

### 3. مدیریت آسان
- اضافه کردن API جدید بدون تداخل
- نگهداری و به‌روزرسانی آسان‌تر
- تست مجزا هر API

### 4. انعطاف‌پذیری
- استفاده ترکیبی یا جداگانه
- تنظیمات مشترک بهینه
- قابلیت گسترش آسان

## نکات مهم

1. **عدم تغییر کنترلرها**: تمامی متدهای موجود حفظ شده‌اند
2. **عدم تغییر مدل‌ها**: هیچ تغییری در مدل‌های موجود نیاز نیست
3. **استفاده از License.website_url**: آدرس وب‌سرویس از همین فیلد استفاده می‌شود
4. **واحد قیمت مشترک**: از `woocommerce_price_unit` در هر دو API استفاده می‌شود
5. **سازگاری با کد موجود**: تمامی کدهای موجود بدون تغییر کار می‌کنند
