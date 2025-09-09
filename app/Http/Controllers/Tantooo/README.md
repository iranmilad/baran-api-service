# Tantooo API Documentation

این فولدر شامل کنترلرها و API های مربوط به سیستم Tantooo است.

## ساختار کنترلرها

### TantoooApiKeyController
مدیریت کلیدهای API و اعتبارسنجی اتصال به سیستم Tantooo

**Routes:**
- `POST /v1/tantooo/register-api-key` - ثبت کلید API جدید
- `POST /v1/tantooo/validate-api-key` - اعتبارسنجی کلید API

### TantoooProductController  
مدیریت محصولات و همگام‌سازی با سیستم Tantooo

**Routes:**
- `POST /v1/tantooo/products/update` - به‌روزرسانی یک محصول
- `POST /v1/tantooo/products/update-multiple` - به‌روزرسانی چندین محصول
- `POST /v1/tantooo/products/update-stock` - به‌روزرسانی موجودی محصول
- `POST /v1/tantooo/products/update-info` - به‌روزرسانی اطلاعات محصول (نام، قیمت، تخفیف)
- `POST /v1/tantooo/products/sync-from-baran` - همگام‌سازی از انبار باران
- `POST /v1/tantooo/products/sync` - همگام‌سازی محصولات با Tantooo
- `POST /v1/tantooo/products/bulk-sync` - همگام‌سازی دسته‌ای محصولات
- `GET /v1/tantooo/settings` - دریافت تنظیمات Tantooo

### TantoooDataController  
مدیریت دریافت اطلاعات اصلی از سیستم Tantooo (دسته‌بندی‌ها، رنگ‌ها، سایزها)

#### Routes:
- `GET /v1/tantooo/data/main` - دریافت همه اطلاعات اصلی
- `GET /v1/tantooo/data/categories` - دریافت دسته‌بندی‌ها
- `GET /v1/tantooo/data/colors` - دریافت رنگ‌ها  
- `GET /v1/tantooo/data/sizes` - دریافت سایزها
- `POST /v1/tantooo/data/refresh-token` - تجدید توکن

### TantoooSyncController
مدیریت همگام‌سازی و مانیتورینگ عملیات

**Routes:**
- `POST /v1/tantooo/sync-settings` - تنظیمات همگام‌سازی
- `POST /v1/tantooo/trigger-sync` - شروع همگام‌سازی
- `POST /v1/tantooo/test-connection` - تست اتصال
- `GET /v1/tantooo/sync-status/{syncId}` - وضعیت همگام‌سازی

## Trait استفاده شده

### TantoooApiTrait
شامل متدهای مشترک برای کار با API Tantooo:
- `updateProductInTantoooApi()` - به‌روزرسانی محصول
- `updateMultipleProductsInTantoooApi()` - به‌روزرسانی چندین محصول
- `getTantoooApiSettings()` - دریافت تنظیمات API
- `convertProductForTantoooApi()` - تبدیل فرمت محصول

## نمونه استفاده

### ثبت کلید API
```bash
curl -X POST /api/v1/tantooo/register-api-key \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "serial_key": "LICENSE_KEY",
    "site_url": "https://example.com",
    "api_key": "ZEEN_API_KEY",
    "bearer_token": "BEARER_TOKEN",
    "api_url": "https://tantooo-api.example.com"
  }'
```

### به‌روزرسانی محصول
```bash
curl -X POST /api/v1/tantooo/products/update \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "code": "PRODUCT_CODE",
    "title": "نام محصول",
    "price": 100000,
    "discount": 10
  }'
```

### همگام‌سازی از انبار
```bash
curl -X POST /api/v1/tantooo/products/sync-from-baran \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "unique_ids": ["ID1", "ID2", "ID3"]
  }'
### همگام‌سازی از انبار
```bash
curl -X POST /api/v1/tantooo/products/sync-from-baran \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "unique_ids": ["ID1", "ID2", "ID3"]
  }'
```

### همگام‌سازی محصولات (مطابق ورودی WooCommerce)
```bash
curl -X POST /api/v1/tantooo/products/sync \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "update": [
      {
        "Barcode": "BARCODE_1",
        "Title": "نام محصول اول",
        "Price": 100000,
        "Stock": 50
      }
    ],
    "insert": [
      {
        "Barcode": "BARCODE_2", 
        "Title": "نام محصول دوم",
        "Price": 150000,
        "Stock": 30
      }
    ]
  }'
```

### همگام‌سازی دسته‌ای (مطابق ورودی WooCommerce)
```bash
curl -X POST /api/v1/tantooo/products/bulk-sync \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "barcodes": ["BARCODE1", "BARCODE2", "BARCODE3"]
  }'
```

#### همگام‌سازی همه محصولات
```bash
curl -X POST /api/v1/tantooo/products/bulk-sync \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}'
```

### به‌روزرسانی موجودی محصول

#### به‌روزرسانی موجودی یک محصول
```bash
curl -X POST /api/v1/tantooo/products/update-stock \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "code": "ddd",
    "count": 3
  }'
```

### به‌روزرسانی اطلاعات محصول

#### به‌روزرسانی نام، قیمت و تخفیف محصول
```bash
curl -X POST /api/v1/tantooo/products/update-info \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "code": "ddd",
    "title": "دامن طرح دار نخی 188600",
    "price": 1791000,
    "discount": 2
  }'
```

### دریافت اطلاعات اصلی Tantooo

#### دریافت همه اطلاعات اصلی
```bash
curl -X GET /api/v1/tantooo/data/main \
  -H "Authorization: Bearer TOKEN"
```

#### دریافت دسته‌بندی‌ها
```bash
curl -X GET /api/v1/tantooo/data/categories \
  -H "Authorization: Bearer TOKEN"
```

#### دریافت رنگ‌ها
```bash
curl -X GET /api/v1/tantooo/data/colors \
  -H "Authorization: Bearer TOKEN"
```

#### دریافت سایزها
```bash
curl -X GET /api/v1/tantooo/data/sizes \
  -H "Authorization: Bearer TOKEN"
```

#### تجدید توکن
```bash
curl -X POST /api/v1/tantooo/data/refresh-token \
  -H "Authorization: Bearer TOKEN"
```

### نمونه پاسخ API

#### دریافت اطلاعات اصلی
```json
{
  "success": true,
  "message": "اطلاعات اصلی با موفقیت دریافت شد",
  "data": {
    "category": [
      {
        "id": 7,
        "title_main": "شلوار",
        "list": [
          {
            "id": 19,
            "title_main": "جین",
            "list": [
              {
                "id": 28,
                "title_main": "جین"
              }
            ]
          }
        ]
      }
    ],
    "colors": [
      {
        "id": 89,
        "title_main": "آجری"
      },
      {
        "id": 72,
        "title_main": "ذغالی"
      }
    ],
    "sizes": [
      {
        "id": 87,
        "title_main": "فری سایز",
        "sub": [
          {
            "id": 101,
            "title_main": "فری سایز 3 (44 تا 46)"
          }
        ]
      }
    ]
  }
}
```

## نکات امنیتی

- تمام route ها دارای `jwt.auth` middleware هستند
- کلیدهای API به صورت رمزگذاری شده ذخیره می‌شوند  
- تمام درخواست‌ها لاگ می‌شوند

## مستندات API Tantooo

API Tantooo با ساختار زیر کار می‌کند:

**Endpoint:** `POST {api_url}`

**Headers:**
```
X-API-KEY: {api_key}
Authorization: Bearer {bearer_token} (اختیاری)
Content-Type: application/json
```

**Request Body:**
```json
{
  "fn": "update_product_info",
  "code": "PRODUCT_CODE",
  "title": "Product Title",
  "price": 100000,
  "discount": 10
}
```

**Response:**
```json
{
  "success": true,
  "message": "Product updated successfully",
  "data": {}
}
```
