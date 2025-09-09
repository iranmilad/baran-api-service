# Universal Token Management System

## خلاصه تغییرات

### 1. Migration جدید
فایل: `database/migrations/2025_09_08_150112_add_token_fields_to_licenses_table.php`

```php
// فیلدهای اضافه شده به جدول licenses:
$table->text('token')->nullable()->comment('API Token');
$table->timestamp('token_expires_at')->nullable()->comment('Token Expiration Date');
```

### 2. به‌روزرسانی مدل License
فایل: `app/Models/License.php`

**فیلدهای جدید در fillable:**
- `token`
- `token_expires_at`

**متدهای جدید:**
```php
// بررسی اعتبار توکن
public function isTokenValid(): bool

// به‌روزرسانی توکن
public function updateToken(string $token, $expiresAt = null): bool

// حذف توکن
public function clearToken(): bool
```

### 3. به‌روزرسانی TantoooApiTrait
فایل: `app/Traits/Tantooo/TantoooApiTrait.php`

**متدهای جدید:**
```php
// دریافت توکن از API Tantooo
protected function getTantoooToken($apiUrl, $apiKey)

// دریافت یا به‌روزرسانی توکن برای لایسنس
protected function getOrRefreshTantoooToken($license)
```

## مزایای طراحی عمومی

### 1. قابلیت استفاده مجدد
- فیلدهای `token` و `token_expires_at` برای هر وبسرویسی قابل استفاده هستند
- نیاز به ایجاد فیلدهای جداگانه برای هر API نیست

### 2. مدیریت خودکار انقضا
- سیستم خودکار اعتبار توکن را بررسی می‌کند
- در صورت انقضا، توکن جدید درخواست می‌شود

### 3. امنیت
- توکن‌ها به صورت رمزگذاری شده ذخیره می‌شوند
- تاریخ انقضا برای جلوگیری از سوءاستفاده

## نحوه استفاده

### برای Tantooo API:
```php
use App\Traits\Tantooo\TantoooApiTrait;

class SomeController extends Controller
{
    use TantoooApiTrait;
    
    public function someMethod()
    {
        $license = Auth::user()->license;
        
        // دریافت یا به‌روزرسانی توکن
        $tokenResult = $this->getOrRefreshTantoooToken($license);
        
        if ($tokenResult['success']) {
            $token = $tokenResult['token'];
            // استفاده از توکن برای درخواست‌های API
        }
    }
}
```

### برای APIهای دیگر:
```php
// بررسی اعتبار توکن
if ($license->isTokenValid()) {
    $token = $license->token;
    // استفاده از توکن
} else {
    // درخواست توکن جدید از API مربوطه
    $newToken = // دریافت از API
    $license->updateToken($newToken, $expiresAt);
}
```

## API Request Format برای Tantooo

```bash
curl --location 'https://03535.ir/accounting_api' \
--header 'X-API-KEY: f3a7c8e45d912b6a19e6f2e7b0843c9d' \
--header 'Content-Type: application/json' \
--data '{
    "fn": "get_token"
}'
```

## دستورات لازم

1. اجرای migration:
```bash
php artisan migrate
```

2. تست سیستم:
```bash
php test_universal_token_system.php
```

## نتیجه

سیستم مدیریت توکن عمومی طراحی شده که:
- ✅ برای هر وبسرویسی قابل استفاده است
- ✅ مدیریت خودکار انقضا دارد  
- ✅ امن و قابل اعتماد است
- ✅ قابلیت گسترش برای APIهای جدید را دارد
