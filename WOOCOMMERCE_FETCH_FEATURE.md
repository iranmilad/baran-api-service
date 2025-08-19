# 🔄 اصلاح ProcessInvoice برای دریافت کامل سفارش از WooCommerce

## 🎯 هدف:
قبل از پردازش فاکتور، ابتدا اطلاعات کامل سفارش از WooCommerce API دریافت شده و در ستون `order_data` ذخیره شود.

## 🔧 تغییرات انجام‌شده:

### 1. اضافه شدن تابع `fetchCompleteOrderFromWooCommerce()`
```php
private function fetchCompleteOrderFromWooCommerce()
{
    // دریافت سفارش کامل از WooCommerce API
    // ذخیره در order_data 
    // به‌روزرسانی customer_mobile
}
```

### 2. اضافه شدن تابع `processWooCommerceOrderData()`
```php
private function processWooCommerceOrderData($wooOrderData)
{
    // تبدیل داده‌های خام WooCommerce به فرمت استاندارد
    // استخراج items با unique_id از meta_data
    // پردازش اطلاعات مشتری
    // سازماندهی داده‌های سفارش
}
```

### 3. اضافه شدن تابع `extractMobileFromOrderData()`
```php
private function extractMobileFromOrderData($orderData)
{
    // استخراج و استاندارد کردن شماره موبایل
}
```

## 📊 فرآیند جدید:

### مرحله 1: دریافت از WooCommerce
```
Invoice Job شروع می‌شود
    ↓
دریافت سفارش کامل از WooCommerce API
    ↓
پردازش و تبدیل داده‌ها
    ↓
ذخیره در order_data
    ↓
ادامه پردازش معمول
```

### مرحله 2: مزایای حاصل
- ✅ **اطلاعات کامل**: دریافت تمام جزئیات سفارش
- ✅ **unique_id صحیح**: استخراج از meta_data محصولات
- ✅ **اطلاعات به‌روز**: آخرین وضعیت سفارش از WooCommerce
- ✅ **جزئیات کامل مشتری**: آدرس، تلفن، ایمیل
- ✅ **اطلاعات پرداخت**: روش پرداخت، مبالغ دقیق

## 🔍 استخراج داده‌ها:

### Items (محصولات):
```php
// از line_items WooCommerce
foreach ($wooOrderData['line_items'] as $lineItem) {
    // استخراج unique_id از meta_data
    foreach ($lineItem['meta_data'] as $meta) {
        if ($meta['key'] === '_bim_unique_id') {
            $uniqueId = $meta['value'];
        }
    }
}
```

### Customer (مشتری):
```php
// از billing و shipping WooCommerce
$customer = [
    'first_name' => $wooOrderData['billing']['first_name'],
    'mobile' => $wooOrderData['billing']['phone'],
    'address' => $wooOrderData['billing']['address_1'],
    // ...
];
```

## 📋 ساختار نهایی order_data:

```json
{
    "items": [
        {
            "unique_id": "4b51d99a-8775-4873-b0f7-3a7d7b4e23eb",
            "sku": "46861",
            "quantity": 2,
            "price": 13000000,
            "name": "شمعدان لاله بزرگ بی رنگ",
            "total": 26000000
        }
    ],
    "customer": {
        "first_name": "تبسم",
        "last_name": "ایلخان",
        "mobile": "09902847992",
        "address": {...}
    },
    "payment_method": "WC_AsanPardakht",
    "total": "51200000",
    "shipping_total": "2200000",
    "status": "processing",
    "woo_raw_data": {...}
}
```

## 🔧 مدیریت خطا:

### در صورت عدم دسترسی به WooCommerce:
- ✅ لاگ warning
- ✅ ادامه با داده‌های موجود
- ✅ عدم متوقف شدن فرآیند

### در صورت خطا در API:
- ✅ لاگ خطا با جزئیات
- ✅ ادامه پردازش با داده‌های اولیه
- ✅ حفظ پایداری سیستم

## 🎯 نتیجه:
حالا هر فاکتور قبل از پردازش، اطلاعات کامل و به‌روز سفارش را از WooCommerce دریافت می‌کند و بر اساس آن پردازش انجام می‌دهد.

## 🚀 آماده برای تست:
فاکتور جدید ارسال کنید تا فرآیند جدید اجرا شود.
