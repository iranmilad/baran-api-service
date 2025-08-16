# راهنمای مدیریت Queue و جلوگیری از TimeoutExceededException

## مشکل
خطای `TimeoutExceededException` زمانی رخ می‌دهد که job ها بیش از حد مجاز اجرا می‌شوند.

## راه‌حل‌های پیاده‌سازی شده

### 1. کاهش Timeout ها
- `ProcessProductPage`: 50 ثانیه
- `ProcessSkuBatch`: 45 ثانیه  
- `ProcessProductChanges`: 50 ثانیه
- `UpdateWooCommerceProducts`: 55 ثانیه
- `ProcessInvoice`: 300 ثانیه (5 دقیقه)

### 2. مدیریت زمان اجرا در Job ها
- بررسی زمان اجرا در حین پردازش
- انتقال کار باقی‌مانده به job جدید اگر نزدیک timeout شد
- استفاده از `microtime(true)` برای اندازه‌گیری دقیق زمان

### 3. کاهش اندازه Batch ها
- **ProductController**: کاهش batch size از 10 به 5
- **Bulk Update**: کاهش از 100 به 50 محصول در هر batch
- **SKU Processing**: کاهش از 20 به 15 SKU در هر batch

### 4. افزایش Delay بین Job ها
- افزایش delay از 15 به 20 ثانیه برای product changes
- افزایش delay از 30 به 45 ثانیه برای bulk updates
- افزایش delay از 2 به 3 ثانیه برای SKU batches

### 5. Retry Logic بهبود یافته
- کاهش backoff times
- افزایش تعداد attempts در صورت نیاز
- مدیریت بهتر exceptions

## دستورات مفید

### راه‌اندازی Queue Workers
```bash
# راه‌اندازی با timeout پیش‌فرض (60 ثانیه)
php artisan queue:restart-workers

# راه‌اندازی با timeout سفارشی
php artisan queue:restart-workers --timeout=45
```

### مانیتورینگ Queue ها
```bash
# مشاهده job های فعال
php artisan queue:work --once

# مشاهده job های failed
php artisan queue:failed

# restart کردن همه workers
php artisan queue:restart

# پاک کردن همه failed jobs
php artisan queue:flush
```

### اجرای Worker برای Queue مشخص
```bash
# اجرای worker برای products queue
php artisan queue:work --queue=products --timeout=60 --tries=3

# اجرای worker برای invoices queue  
php artisan queue:work --queue=invoices --timeout=300 --tries=3

# اجرای worker برای empty-unique-ids queue
php artisan queue:work --queue=empty-unique-ids --timeout=60 --tries=3
```

## Schedule های فعال

### Worker اصلی (هر دقیقه)
```bash
queue:work --queue=invoices,products,bulk-update,empty-unique-ids,unique-ids-sync,category,woocommerce,woocommerce-update,woocommerce-insert,woocommerce-sync,default --timeout=60 --tries=3 --max-jobs=30 --stop-when-empty --memory=512
```

### Worker مخصوص Invoice ها (هر 2 دقیقه)
```bash
queue:work --queue=invoices --timeout=300 --tries=3 --max-jobs=10 --stop-when-empty --memory=256
```

### نگهداری سیستم
- **Restart workers**: هر 30 دقیقه
- **Flush failed jobs**: یکشنبه‌ها ساعت 2 صبح
- **Queue monitoring**: هر ساعت
- **Pending invoices check**: هر 15 دقیقه

## بهترین پروفایل‌ها

### برای سرور Production
```bash
# Worker اصلی برای همه queue ها
php artisan queue:work --queue=invoices,products,bulk-update,empty-unique-ids,unique-ids-sync,category,default --timeout=60 --tries=3 --delay=3 --memory=512

# Worker مخصوص invoice ها (اولویت بالا)
php artisan queue:work --queue=invoices --timeout=300 --tries=3 --delay=5 --memory=256

# Worker مخصوص bulk operations
php artisan queue:work --queue=bulk-update --timeout=120 --tries=2 --delay=10 --memory=512
```

### برای توسعه و تست
```bash
# Worker ساده برای تست
php artisan queue:work --once --timeout=60

# Worker با log بیشتر
php artisan queue:work --verbose --timeout=60 --tries=1
```

## نکات مهم

1. **همیشه timeout job را کمتر از timeout worker تنظیم کنید**
2. **از chunking استفاده کنید برای پردازش حجم بالا**
3. **delay مناسب بین job ها اعمال کنید**
4. **memory limit را مناسب تنظیم کنید**
5. **log ها را مانیتور کنید برای شناسایی bottleneck ها**

## صفوف (Queues) موجود

- `invoices`: پردازش فاکتورها (timeout: 300s)
- `products`: تغییرات محصولات و batch processing (timeout: 40-60s)  
- `bulk-update`: به‌روزرسانی انبوه (timeout: 55s)
- `empty-unique-ids`: پردازش کدهای یکتا خالی (timeout: 60s)
- `unique-ids-sync`: همگام‌سازی کدهای یکتا (timeout: 60s)
- `category`: دسته‌بندی‌ها (timeout: 60s)
- `default`: پیش‌فرض (timeout: 60s)

## Job های جدید

### ProcessProductBatch
- **هدف**: پردازش batch های کوچک محصولات (10-15 آیتم)
- **Timeout**: 40 ثانیه
- **مزایا**: جلوگیری از timeout در bulk operations
- **استفاده**: جایگزین UpdateWooCommerceProducts برای batch های کوچک
