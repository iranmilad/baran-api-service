# تغییرات ProductStockController برای استفاده از WordPress Traits

## خلاصه تغییرات

کنترلر `ProductStockController` به‌روزرسانی شده تا از trait های WordPress به جای کد inline استفاده کند.

## تغییرات انجام شده

### 1. اضافه کردن WordPressMasterTrait

```php
use App\Traits\WordPress\WordPressMasterTrait;

class ProductStockController extends Controller
{
    use WordPressMasterTrait;
```

### 2. حذف متد updateWordPressProducts

متد `updateWordPressProducts` از کنترلر حذف شد چون حالا از trait استفاده می‌شود:

**قبل:**
```php
private function updateWordPressProducts($license, $foundProducts, $userSettings)
{
    // کد طولانی inline...
}
```

**بعد:**
```php
// استفاده از متد trait
$wordpressUpdateResult = $this->updateWordPressProducts($license, $foundProducts, $userSettings);
```

### 3. اضافه کردن متد جدید به WordPressMasterTrait

متد `dispatchWooCommerceStockUpdateJob` به trait اضافه شد:

```php
protected function dispatchWooCommerceStockUpdateJob($license)
{
    try {
        dispatch(new \App\Jobs\UpdateWooCommerceStockByCategoryJob($license->id));
        
        return [
            'success' => true,
            'message' => 'فرایند بروزرسانی موجودی برای همه دسته‌بندی‌های ووکامرس در صف قرار گرفت.'
        ];
    } catch (\Exception $e) {
        // مدیریت خطا...
    }
}
```

### 4. به‌روزرسانی updateWooCommerceStockAllCategories

```php
public function updateWooCommerceStockAllCategories(Request $request)
{
    // احراز هویت...
    
    // استفاده از متد trait
    $result = $this->dispatchWooCommerceStockUpdateJob($license);
    
    return response()->json($result);
}
```

### 5. تمیز کردن import ها

Import های غیرضروری حذف شدند:
- ❌ `App\Jobs\UpdateWooCommerceStockByCategoryJob`
- ❌ `Illuminate\Support\Facades\Bus`

## مزایای تغییرات

### 1. کاهش تکرار کد
- متد `updateWordPressProducts` حالا در trait قابل استفاده مجدد است
- منطق WooCommerce در یک مکان متمرکز شده

### 2. سازماندهی بهتر
- تمام عملیات WooCommerce در trait های مخصوص
- کنترلر فقط روی business logic تمرکز دارد

### 3. نگهداری آسان‌تر
- تغییرات API WooCommerce فقط در trait ها انجام می‌شود
- لاگ‌گیری و مدیریت خطا مرکزی

### 4. تست‌پذیری بهتر
- trait ها قابل تست مجزا هستند
- Mock کردن آسان‌تر برای تست‌ها

## فایل‌های تغییر یافته

1. **ProductStockController.php** - اضافه شدن trait و حذف متد inline
2. **WordPressMasterTrait.php** - اضافه شدن متد جدید job dispatch

## سازگاری

- ✅ API endpoints تغییری نکرده‌اند
- ✅ پاسخ‌های JSON همان format قبلی را دارند  
- ✅ منطق business تغییری نکرده
- ✅ تمام قابلیت‌های قبلی حفظ شده‌اند

## مثال استفاده

```php
// در کنترلر
$license = JWTAuth::parseToken()->authenticate();
$userSettings = UserSetting::where('license_id', $license->id)->first();

// به‌روزرسانی محصولات WordPress  
$result = $this->updateWordPressProducts($license, $foundProducts, $userSettings);

// dispatch job برای به‌روزرسانی دسته‌ای
$jobResult = $this->dispatchWooCommerceStockUpdateJob($license);
```
