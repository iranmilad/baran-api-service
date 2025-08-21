# Product Stock API Documentation

## Overview
این API برای بررسی موجودی محصولات بر اساس unique ID طراحی شده است. این API از **Warehouse API** جدید برای دریافت اطلاعات موجودی استفاده می‌کند و قابلیت دریافت اطلاعات موجودی یک یا چند محصول را در یک درخواست دارد و به صورت خودکار اطلاعات را در سایت وردپرس به‌روزرسانی می‌کند.

## Endpoint
```
POST /api/v1/stock
```

## Authentication
این API نیاز به JWT token دارد که از طریق `/api/v1/login` قابل دریافت است.

```
Authorization: Bearer {token}
```

## Request Parameters

API فقط از یک نوع درخواست پشتیبانی می‌کند:

### چند محصول (Multiple Products)
```json
{
    "unique_ids": [
        "80DEB248-1924-467C-8745-004BAF851746",
        "29FDC941-FD16-4AE5-AB94-013CDE27CDBC"
    ]
}
```

**نکته**: حتی برای یک محصول هم باید از آرایه استفاده کنید:
```json
{
    "unique_ids": ["80DEB248-1924-467C-8745-004BAF851746"]
}
```

## Response Format

### موفقیت‌آمیز (Success Response)
```json
{
    "success": true,
    "data": {
        "found_products": [
            {
                "unique_id": "80DEB248-1924-467C-8745-004BAF851746",
                "product_info": {
                    "uniqueId": "80DEB248-1924-467C-8745-004BAF851746",
                    "name": "نام محصول",
                    "code": "کد محصول",
                    "sellPrice": "6500000",
                    "inventories": [...]
                },
                "default_warehouse_stock": {
                    "quantity": 100,
                    "warehouse": {
                        "code": "انبار اصلی",
                        "name": "انبار مرکزی"
                    }
                }
            }
        ],
        "total_requested": 2,
        "total_found": 1,
        "total_not_found": 1,
        "wordpress_update": {
            "success": true,
            "message": "محصولات با موفقیت به‌روزرسانی شدند",
            "updated_count": 1,
            "wordpress_response": {
                "success": true,
                "updated": 1,
                "errors": []
            }
        },
        "not_found_products": [
            {
                "unique_id": "29FDC941-FD16-4AE5-AB94-013CDE27CDBC",
                "message": "محصول یافت نشد"
            }
        ],
        "default_warehouse_code": "W001"
    }
}
```

### خطا (Error Response)
```json
{
    "success": false,
    "message": "مقدار unique_id یا unique_ids الزامی است",
    "errors": {
        "unique_ids": ["The unique ids field is required."],
        "unique_id": ["The unique id field is required."]
    }
}
```

## Features

1. **پشتیبانی از تک و چند محصول**: می‌توانید در یک درخواست، موجودی یک یا چند محصول را بررسی کنید
2. **فیلتر بر اساس انبار پیش‌فرض**: موجودی محصولات بر اساس انبار پیش‌فرض کاربر فیلتر می‌شود
3. **به‌روزرسانی خودکار وردپرس**: اطلاعات موجودی و قیمت به صورت خودکار در سایت وردپرس به‌روزرسانی می‌شود
4. **گزارش کامل**: شامل محصولات پیدا شده، پیدا نشده و نتیجه به‌روزرسانی وردپرس
5. **یکپارچگی با Warehouse API**: داده‌ها از **Warehouse API جدید** دریافت می‌شود (به جای RainSale)
6. **تبدیل واحد قیمت**: قیمت‌ها بر اساس تنظیمات کاربر از ریال به تومان یا بالعکس تبدیل می‌شوند

## Warehouse API Integration

API با **Warehouse API** جدید ارتباط برقرار می‌کند:

```http
POST {warehouse_api_url}/api/itemlist/GetItemsByIds
Content-Type: application/json
Authorization: Basic {encoded_warehouse_credentials}

["80DEB248-1924-467C-8745-004BAF851746", "29FDC941-FD16-4AE5-AB94-013CDE27CDBC"]
```

**نکته مهم**: `default_warehouse_code` از تنظیمات کاربر گرفته می‌شود و برای فیلتر کردن موجودی در انبار مشخص استفاده می‌شود.

### ساختار پاسخ Warehouse API:

```json
{
    "data": {
        "items": [
            {
                "uniqueId": "80DEB248-1924-467C-8745-004BAF851746",
                "name": "نام محصول",
                "code": "104000102330050035",
                "sellPrice": "6500000",
                "inventories": [
                    {
                        "warehouse": {
                            "code": "W001",
                            "name": "انبار مرکزی"
                        },
                        "quantity": 100
                    },
                    {
                        "warehouse": {
                            "code": "W002", 
                            "name": "انبار فرعی"
                        },
                        "quantity": 50
                    }
                ]
            }
        ]
    }
}
```

## WordPress Integration

API پس از دریافت اطلاعات از RainSale، به صورت خودکار درخواست زیر را به سایت وردپرس ارسال می‌کند:

```http
PUT /wp-json/wc/v3/products/unique/batch/update
Content-Type: application/json
Authorization: Basic {encoded_api_credentials}

{
    "products": [
        {
            "unique_id": "80DEB248-1924-467C-8745-004BAF851746",
            "sku": "104000102330050035",
            "regular_price": "6500000",
            "stock_quantity": 100,
            "manage_stock": true,
            "stock_status": "instock"
        }
    ]
}
```

### شرایط به‌روزرسانی وردپرس:

1. تنظیم `enable_stock_update` باید `true` باشد
2. اطلاعات WooCommerce API Key و Secret باید تنظیم شده باشد
3. حداقل یک محصول باید پیدا شده باشد

### تبدیل واحد قیمت:

- اگر `rain_sale_price_unit` = "rial" و `woocommerce_price_unit` = "toman" باشد، قیمت تقسیم بر 10 می‌شود
- اگر `rain_sale_price_unit` = "toman" و `woocommerce_price_unit` = "rial" باشد، قیمت در 10 ضرب می‌شود

## Error Codes

- `422` - Validation Error (پارامترهای نامعتبر)
- `400` - Bad Request (انبار پیش‌فرض تنظیم نشده)
- `500` - Internal Server Error (خطا در درخواست به RainSale)

## Testing

برای تست API می‌توانید از فایل‌های زیر استفاده کنید:

1. `test-warehouse-api.php` - تست کامل با Warehouse API جدید
2. `test-warehouse-api.cmd` - تست با cURL در ویندوز
3. `test-product-stock-with-wordpress.php` - تست قدیمی (RainSale API)
4. `test-product-stock.php` - تست بدون به‌روزرسانی وردپرس

### مراحل تست:

1. API credentials خود را در فایل تست وارد کنید
2. اطمینان حاصل کنید که تنظیمات Warehouse API صحیح است:
   - `warehouse_api_url` = آدرس API انبار
   - `warehouse_api_username` = نام کاربری API انبار
   - `warehouse_api_password` = رمز عبور API انبار
   - `enable_stock_update` = true
   - WooCommerce API Key و Secret تنظیم شده باشد
   - `default_warehouse_code` تنظیم شده باشد
3. فایل تست را اجرا کنید
4. نتایج به‌روزرسانی وردپرس را بررسی کنید

## Configuration

قبل از استفاده، مطمئن شوید که:

1. **Warehouse API**: 
   - `warehouse_api_url` در جدول users تنظیم شده باشد
   - `warehouse_api_username` در جدول users تنظیم شده باشد
   - `warehouse_api_password` در جدول users تنظیم شده باشد
   - `default_warehouse_code` در تنظیمات کاربر تنظیم شده باشد

2. **WordPress/WooCommerce**:
   - `enable_stock_update` در UserSetting فعال باشد
   - WooCommerce API Key و Secret در جدول `woocommerce_api_keys` تنظیم شده باشد
   - Plugin بران در سایت وردپرس نصب و فعال باشد
   - واحد قیمت‌ها (`rain_sale_price_unit` و `woocommerce_price_unit`) صحیح تنظیم شده باشد

3. **Security**:
   - JWT authentication فعال باشد
   - SSL certificate برای درخواست‌های وردپرس (اختیاری)

### Database Schema Updates:

جدول `users` باید شامل ستون‌های زیر باشد:
```sql
ALTER TABLE users ADD COLUMN warehouse_api_url VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN warehouse_api_username VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN warehouse_api_password VARCHAR(255) NULL;
```

## Usage Examples

### کد PHP
```php
$data = [
    'unique_ids' => [
        '80DEB248-1924-467C-8745-004BAF851746',
        '29FDC941-FD16-4AE5-AB94-013CDE27CDBC'
    ]
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/baran-api-service/public/api/v1/stock');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$result = json_decode($response, true);
```

### cURL Command
```bash
curl -X POST "http://localhost/baran-api-service/public/api/v1/stock" \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer YOUR_TOKEN" \
     -d '{"unique_ids":["80DEB248-1924-467C-8745-004BAF851746","29FDC941-FD16-4AE5-AB94-013CDE27CDBC"]}'
```
