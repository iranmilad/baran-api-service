# 📡 API استعلام موجودی محصول

## 🎯 هدف:
دریافت موجودی محصول بر اساس کد یکتا و انبار پیش‌فرض

## 🔧 Route ایجاد شده:
```
POST /api/v1/products/stock
```

## 📋 نحوه استفاده:

### 1. درخواست موفق:
```bash
curl --location 'http://your-domain.com/api/v1/products/stock' \
--header 'Content-Type: application/json' \
--header 'Authorization: Bearer YOUR_JWT_TOKEN' \
--data '{
    "unique_id": "099a6b4b-958e-436d-ab05-004555284b3c"
}'
```

### 2. پاسخ موفق:
```json
{
    "success": true,
    "data": {
        "unique_id": "099a6b4b-958e-436d-ab05-004555284b3c",
        "item_id": "099a6b4b-958e-436d-ab05-004555284b3c",
        "item_name": "نمکدان سرامیکی شاه عباسی",
        "stock_id": "e9a28650-6b25-481a-967a-4a1ddaafaf90",
        "stock_name": "انبار محصول",
        "stock_quantity": 8.0,
        "sale_price": 2850000.0,
        "current_discount": 0.0,
        "barcode": "101102299940000000",
        "department_code": "1010111",
        "department_name": "نمکدان"
    }
}
```

### 3. پاسخ در صورت عدم وجود در انبار پیش‌فرض:
```json
{
    "success": false,
    "message": "Product not found in default warehouse",
    "unique_id": "099a6b4b-958e-436d-ab05-004555284b3c",
    "default_warehouse_code": "e9a28650-6b25-481a-967a-4a1ddaafaf90",
    "available_stocks": [
        {
            "stock_id": "32a81f6a-dc2f-4d4a-b84c-299a0c5cddd4",
            "stock_name": "انبار شعبه پالادیوم",
            "quantity": 2.0
        },
        {
            "stock_id": "75e80bf4-a627-42b8-9119-61dd7a2e0bdd",
            "stock_name": "انبار جدید شعبه روشا",
            "quantity": 4.0
        }
    ]
}
```

## 🔧 فرآیند کار:

### 1. احراز هویت:
- بررسی JWT token
- تأیید فعال بودن لایسنس
- بررسی وجود کاربر

### 2. اعتبارسنجی:
- بررسی وجود `unique_id` در درخواست
- بررسی تنظیم `default_warehouse_code`
- بررسی تنظیمات API کاربر

### 3. درخواست به RainSale:
```http
POST http://103.216.62.61:4645/api/itemlist/GetItemsByIds
Authorization: Basic {base64_encoded_credentials}
Content-Type: application/json

["099a6b4b-958e-436d-ab05-004555284b3c"]
```

### 4. پردازش پاسخ:
- جستجو برای `itemID` مطابق با `unique_id`
- یافتن رکورد با `stockID` مطابق با `default_warehouse_code`
- برگرداندن اطلاعات کامل محصول

## 🚨 خطاهای ممکن:

### 401 - Unauthorized:
```json
{
    "success": false,
    "message": "Invalid token - license not found"
}
```

### 403 - Forbidden:
```json
{
    "success": false,
    "message": "License is not active"
}
```

### 400 - Bad Request:
```json
{
    "success": false,
    "message": "Default warehouse code not configured"
}
```

### 404 - Not Found:
```json
{
    "success": false,
    "message": "No product data found for the given unique ID"
}
```

### 500 - Server Error:
```json
{
    "success": false,
    "message": "Failed to fetch product data from RainSale",
    "error_code": 500
}
```

## 📊 لاگ‌گذاری:

### درخواست موفق:
```
INFO: درخواست استعلام موجودی محصول
- license_id: 123
- unique_id: 099a6b4b-958e-436d-ab05-004555284b3c
- default_warehouse_code: e9a28650-6b25-481a-967a-4a1ddaafaf90

INFO: موجودی محصول با موفقیت دریافت شد
- stock_quantity: 8.0
- stock_name: انبار محصول
```

### خطا در API:
```
ERROR: خطا در درخواست GetItemsByIds
- status_code: 401
- response_body: Unauthorized access
```

## 🎯 کاربردهای عملی:

1. **بررسی موجودی در فروشگاه آنلاین**
2. **سیستم‌های POS**
3. **مدیریت انبار**
4. **سیستم‌های CRM**

## ✅ مزایا:

- ✅ دریافت موجودی از انبار مشخص
- ✅ اطلاعات کامل محصول (قیمت، بارکد، دپارتمان)
- ✅ مدیریت خطا و لاگ‌گذاری
- ✅ احراز هویت امن با JWT
- ✅ نمایش انبارهای جایگزین در صورت عدم وجود
