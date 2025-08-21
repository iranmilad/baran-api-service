# Warehouse API Integration - تغییرات مهم

## خلاصه تغییرات

ProductStockController حالا از **Warehouse API** جدید به جای RainSale API استفاده می‌کند.

## تغییرات Database

### جدول Users
سه ستون جدید اضافه شده:

```sql
ALTER TABLE users ADD COLUMN warehouse_api_url VARCHAR(255) NULL AFTER api_userId;
ALTER TABLE users ADD COLUMN warehouse_api_username VARCHAR(255) NULL AFTER warehouse_api_url;
ALTER TABLE users ADD COLUMN warehouse_api_password VARCHAR(255) NULL AFTER warehouse_api_username;
```

### Migration اجرا شده:
```
2025_08_20_212440_add_warehouse_api_fields_to_users_table.php
```

## تغییرات User Model

### Fillable Fields:
```php
'warehouse_api_url',
'warehouse_api_username', 
'warehouse_api_password'
```

### Hidden Fields:
```php
'warehouse_api_password' // پسورد مخفی می‌شود
```

## تغییرات ProductStockController

### API Endpoint جدید:
- **قبل**: `{rainsale_api}/api/itemlist/GetItemsByIds`
- **حالا**: `{warehouse_api_url}/api/itemlist/GetItemsByIds`

### Request Format:
```json
["ID1", "ID2", "ID3"]
```

**نکته مهم**: درخواست به صورت آرایه مستقیم unique IDs ارسال می‌شود (مشابه RainSale API).

### Authentication:
- **قبل**: `user->api_username:api_password`
- **حالا**: `user->warehouse_api_username:warehouse_api_password`

### Response Processing:
ساختار پاسخ Warehouse API متفاوت از RainSale است و پردازش شده است.

## فایل‌های Test جدید

1. `test-warehouse-api.php` - تست کامل PHP
2. `test-warehouse-api.cmd` - تست CMD ویندوز
3. `test-user-warehouse-fields.php` - تست فیلدهای User model

## نحوه استفاده

### 1. Migration اجرا کنید:
```bash
php artisan migrate
```

### 2. تنظیمات کاربر:
```php
$user = User::find(1);
$user->warehouse_api_url = 'https://warehouse.example.com';
$user->warehouse_api_username = 'api_user';
$user->warehouse_api_password = 'api_pass';
$user->save();
```

### 3. تست API:
```bash
php test-warehouse-api.php
```

## نکات مهم

1. **سازگاری با وردپرس**: قابلیت به‌روزرسانی وردپرس همچنان کار می‌کند
2. **Backward Compatibility**: فیلدهای قدیمی RainSale همچنان موجود هستند
3. **Error Handling**: خطاهای Warehouse API مدیریت می‌شوند
4. **Security**: پسورد Warehouse API در response مخفی می‌شود

## اولویت‌ها

1. ✅ Database migration اجرا شده
2. ✅ User model به‌روزرسانی شده  
3. ✅ ProductStockController تغییر یافته
4. ✅ Test files ایجاد شده
5. ✅ Documentation به‌روزرسانی شده

## چک‌لیست راه‌اندازی

- [ ] Migration اجرا شده
- [ ] User model تست شده
- [ ] Warehouse API credentials تنظیم شده
- [ ] API تست شده با unique IDs
- [ ] به‌روزرسانی وردپرس تست شده
- [ ] Error handling بررسی شده

## مثال کامل

```php
// تنظیم کاربر
$user = User::find(1);
$user->warehouse_api_url = 'https://warehouse.example.com';
$user->warehouse_api_username = 'warehouse_user';
$user->warehouse_api_password = 'secure_password';
$user->save();

// درخواست API
POST /api/v1/stock
{
    "unique_ids": ["80DEB248-1924-467C-8745-004BAF851746"]
}
```

## پیش‌نیازها

1. Warehouse API باید endpoint `/api/products/stock/check` داشته باشد
2. Warehouse API باید Basic Authentication پشتیبانی کند
3. Response format باید استاندارد باشد (success, data array)

---

**نکته**: این تغییرات ProductStockController را از RainSale API به Warehouse API منتقل می‌کند. سایر بخش‌های سیستم همچنان می‌توانند از RainSale استفاده کنند.
