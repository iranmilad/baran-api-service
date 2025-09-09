# Tantooo Main Data API Implementation

## خلاصه تغییرات

### 1. سیستم مدیریت توکن عمومی

#### Migration جدید:
- فیلد `token` (TEXT, nullable) - برای ذخیره توکن
- فیلد `token_expires_at` (TIMESTAMP, nullable) - برای تاریخ انقضا

#### به‌روزرسانی مدل License:
```php
// متدهای جدید
public function isTokenValid(): bool
public function updateToken(string $token, $expiresAt = null): bool  
public function clearToken(): bool
```

### 2. متدهای جدید در TantoooApiTrait

#### دریافت توکن:
```php
protected function getTantoooToken($apiUrl, $apiKey)
```

#### دریافت اطلاعات اصلی:
```php
protected function getTantoooMainData($apiUrl, $apiKey, $bearerToken)
protected function getTantoooMainDataWithToken($license)
```

#### مدیریت خودکار توکن:
```php
protected function getOrRefreshTantoooToken($license)
```

### 3. کنترلر جدید TantoooDataController

#### Route های جدید:
- `GET /api/v1/tantooo/data/main` - همه اطلاعات اصلی
- `GET /api/v1/tantooo/data/categories` - دسته‌بندی‌ها
- `GET /api/v1/tantooo/data/colors` - رنگ‌ها
- `GET /api/v1/tantooo/data/sizes` - سایزها
- `POST /api/v1/tantooo/data/refresh-token` - تجدید توکن

#### متدهای کنترلر:
- `getMainData()` - دریافت همه اطلاعات
- `getCategories()` - دریافت دسته‌بندی‌ها
- `getColors()` - دریافت رنگ‌ها
- `getSizes()` - دریافت سایزها
- `refreshToken()` - تجدید توکن

## درخواست‌های API

### دریافت توکن:
```bash
curl --location 'https://03535.ir/accounting_api' \
--header 'X-API-KEY: f3a7c8e45d912b6a19e6f2e7b0843c9d' \
--header 'Content-Type: application/json' \
--data '{"fn": "get_token"}'
```

### دریافت اطلاعات اصلی:
```bash
curl --location 'https://03535.ir/accounting_api' \
--header 'X-API-KEY: f3a7c8e45d912b6a19e6f2e7b0843c9d' \
--header 'Content-Type: application/json' \
--header 'Authorization: Bearer TOKEN' \
--data '{"fn": "main"}'
```

## ساختار پاسخ

### اطلاعات اصلی:
```json
{
  "category": [
    {
      "id": 7,
      "title_main": "شلوار",
      "list": [
        {
          "id": 19,
          "title_main": "جین",
          "list": [{"id": 28, "title_main": "جین"}]
        }
      ]
    }
  ],
  "colors": [
    {"id": 89, "title_main": "آجری"},
    {"id": 72, "title_main": "ذغالی"}
  ],
  "sizes": [
    {
      "id": 87,
      "title_main": "فری سایز",
      "sub": [
        {"id": 101, "title_main": "فری سایز 3 (44 تا 46)"}
      ]
    }
  ],
  "msg": 0,
  "error": []
}
```

## نحوه استفاده

### از طریق API endpoint:
```bash
# دریافت همه اطلاعات
GET /api/v1/tantooo/data/main
Authorization: Bearer JWT_TOKEN

# دریافت فقط دسته‌بندی‌ها  
GET /api/v1/tantooo/data/categories
Authorization: Bearer JWT_TOKEN
```

### از طریق Trait در کنترلر:
```php
use App\Traits\Tantooo\TantoooApiTrait;

class SomeController extends Controller
{
    use TantoooApiTrait;
    
    public function getData()
    {
        $license = Auth::user()->license;
        $result = $this->getTantoooMainDataWithToken($license);
        
        if ($result['success']) {
            $categories = $result['data']['category'];
            $colors = $result['data']['colors'];
            $sizes = $result['data']['sizes'];
        }
    }
}
```

## ویژگی‌های سیستم

✅ **مدیریت خودکار توکن**: توکن به صورت خودکار دریافت و تجدید می‌شود
✅ **عمومی**: سیستم توکن برای هر وبسرویسی قابل استفاده است
✅ **امن**: توکن‌ها با تاریخ انقضا ذخیره می‌شوند
✅ **مقسم**: endpoint های جداگانه برای انواع مختلف داده
✅ **کامل**: شامل error handling و logging کامل
✅ **احراز هویت**: تمام endpoint ها با JWT محافظت شده‌اند

## فایل‌های تغییر یافته

1. `database/migrations/2025_09_08_150112_add_token_fields_to_licenses_table.php`
2. `app/Models/License.php`
3. `app/Traits/Tantooo/TantoooApiTrait.php`
4. `app/Http/Controllers/Tantooo/TantoooDataController.php` (جدید)
5. `routes/api.php`
6. `app/Http/Controllers/Tantooo/README.md`

## دستورات اجرا

```bash
# اجرای migration
php artisan migrate

# تست سیستم
php test_tantooo_main_data_system.php
```

## نتیجه

سیستم کاملی برای دریافت و مدیریت اطلاعات اصلی Tantooo پیاده‌سازی شد که شامل:
- مدیریت خودکار توکن
- دریافت دسته‌بندی‌ها، رنگ‌ها و سایزها
- API endpoint های کامل و مستند
- پشتیبانی از چندین نوع درخواست
- سیستم امن و قابل اعتماد
