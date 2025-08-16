# معماری جدید پردازش محصولات - Queue Coordination

## نگاه کلی

این معماری جدید برای حل مشکل `TimeoutExceededException` طراحی شده است. بجای اینکه همه محصولات را در یک job پردازش کنیم، آنها را به chunks کوچک تقسیم می‌کنیم و با استفاده از دو queue مجزا، کارایی و قابلیت اطمینان را افزایش می‌دهیم.

## ساختار معماری

### Queue های استفاده شده:

1. **`product-coordination`** - Queue هماهنگی و تقسیم کار
2. **`product-processing`** - Queue پردازش محصولات

### Job های اصلی:

#### 1. `CoordinateProductUpdate`
- **هدف**: هماهنگی و شروع فرآیند
- **Timeout**: 30 ثانیه
- **Queue**: `product-coordination`
- **وظایف**:
  - دریافت درخواست کلی
  - تصمیم‌گیری برای تقسیم کار
  - ارسال به `FetchAndDivideProducts`

#### 2. `FetchAndDivideProducts`
- **هدف**: دریافت و تقسیم محصولات
- **Timeout**: 45 ثانیه
- **Queue**: `product-coordination`
- **وظایف**:
  - دریافت لیست کامل محصولات از WooCommerce
  - تقسیم به chunks کوچک (8 آیتم)
  - ارسال هر chunk به `ProcessSingleProductBatch`

#### 3. `ProcessSingleProductBatch`
- **هدف**: پردازش یک batch کوچک از محصولات
- **Timeout**: 35 ثانیه
- **Queue**: `product-processing`
- **وظایف**:
  - دریافت اطلاعات از RainSale API
  - به‌روزرسانی محصولات در WooCommerce
  - مدیریت خطاها

## مزایای معماری جدید

### 1. جلوگیری از Timeout
- هر job حداکثر 35 ثانیه زمان دارد
- chunks کوچک (8 محصول) سریع پردازش می‌شوند
- نظارت دقیق بر زمان اجرا

### 2. مقاوم در برابر خطا
- اگر یک batch خراب شود، بقیه ادامه می‌یابند
- خطاها لاگ می‌شوند و job های دیگر متوقف نمی‌شوند
- retry strategy برای هر job

### 3. کارایی بهتر
- queue های مجزا برای coordination و processing
- پردازش موازی batches
- مدیریت بهتر منابع

### 4. قابلیت نظارت
- لاگ‌های دقیق برای هر مرحله
- ردیابی پیشرفت هر batch
- گزارش خطاها

## تنظیمات Queue

در `routes/console.php`:

```php
Schedule::command('queue:work --queue=product-coordination,product-processing,invoices,products,bulk-update,empty-unique-ids,unique-ids-sync,category,woocommerce,woocommerce-update,woocommerce-insert,woocommerce-sync,default --tries=3 --max-jobs=50 --stop-when-empty')
```

اولویت queues:
1. `product-coordination` - اولویت بالا
2. `product-processing` - پردازش asynchronous

## نحوه استفاده

### در ProductController:

```php
// برای همه محصولات
CoordinateProductUpdate::dispatch($license->id, [])
    ->onQueue('product-coordination');

// برای محصولات خاص
CoordinateProductUpdate::dispatch($license->id, $barcodes)
    ->onQueue('product-coordination');
```

## فلوی کاری

```
درخواست کاربر
     ↓
CoordinateProductUpdate (product-coordination)
     ↓
FetchAndDivideProducts (product-coordination)
     ↓
چندین ProcessSingleProductBatch (product-processing)
     ↓
نتیجه نهایی
```

## مانیتورینگ و Debug

### لاگ‌های مهم:

1. **شروع coordination**:
```
[INFO] شروع coordination برای همه محصولات
```

2. **تقسیم محصولات**:
```
[INFO] محصولات تقسیم شدند: chunks=X, total_products=Y
```

3. **پردازش batch**:
```
[INFO] شروع پردازش batch محصولات: barcodes_count=8
```

4. **تکمیل موفق**:
```
[INFO] پردازش batch محصولات تکمیل شد: products_processed=8
```

### خطاهای ممکن:

1. **Timeout در coordination**:
   - بررسی اتصال به WooCommerce
   - کاهش تعداد محصولات در chunk

2. **خطای API RainSale**:
   - بررسی اطلاعات API
   - بررسی اتصال شبکه

3. **خطای WooCommerce**:
   - بررسی API credentials
   - بررسی plugin WooCommerce

## تنظیمات بهینه‌سازی

### اندازه Chunks:
- **CoordinateProductUpdate**: 10 آیتم در هر dispatch
- **FetchAndDivideProducts**: 8 آیتم در هر batch
- **ProcessSingleProductBatch**: 1 batch = 8 محصول

### Timeouts:
- **CoordinateProductUpdate**: 30s
- **FetchAndDivideProducts**: 45s  
- **ProcessSingleProductBatch**: 35s

### Delays:
- بین chunks: 5-10 ثانیه
- برای جلوگیری از overload API

## عیب‌یابی سریع

### اگر محصولات به‌روزرسانی نمی‌شوند:

1. بررسی لاگ‌ها در `storage/logs/laravel.log`
2. بررسی failed jobs: `php artisan queue:failed`
3. بررسی وضعیت queue workers: `php artisan queue:monitor`
4. تست manual: `php artisan queue:work --queue=product-coordination`

### اگر timeout هنوز داریم:

1. کاهش اندازه chunks در `FetchAndDivideProducts`
2. افزایش delay بین batches
3. بررسی سرعت API های external

این معماری باید مشکل timeout را کاملاً حل کند و سیستم پایدار و قابل اعتمادی ارائه دهد.
