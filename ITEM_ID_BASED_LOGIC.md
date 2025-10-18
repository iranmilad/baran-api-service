# اصلاح منطق ذخیره‌سازی - بر اساس item_id

## 🔄 تغییر منطق

### قبل (منطق قدیم):
```php
// جستجو بر اساس license_id + item_id
$product = Product::where('license_id', $license->id)
    ->where('item_id', $itemId)
    ->first();
```

### بعد (منطق جدید):
```php
// جستجو فقط بر اساس item_id (مستقل)
$product = Product::where('item_id', $itemId)->first();
```

## ✨ تفاوت اصلی

| جنبه | قبل | بعد |
|------|------|------|
| **معیار جستجو** | license_id + item_id | فقط item_id |
| **محصول متفاوت license** | محصول جدید ایجاد | همان محصول به‌روزرسانی |
| **license_id** | تأثیر ندارد | برای رکورد موجود هم به‌روزرسانی |
| **نتیجه** | محصولات مستقل برای هر license | محصولات مشترک میان licenses |

## 📊 مثال عملی

### سناریو:
```
- License 1: از Baran دریافت ITEM-001 (قیمت 100000)
  → ایجاد Product(license_id=1, item_id=ITEM-001, price=100000)

- License 2: از Baran دریافت ITEM-001 (قیمت 150000)
  → منطق قدیم: محصول جدید ایجاد
  → منطق جدید: همان محصول به‌روزرسانی
     └─ license_id: 1 → 2
     └─ price: 100000 → 150000
```

## 🔧 کد اصلاح شده

### در `ProcessTantoooSyncRequest.php`:

```php
foreach ($baranProducts as $baranProduct) {
    $itemId = $baranProduct['itemID'] ?? $baranProduct['ItemID'] ?? null;
    $barcode = $baranProduct['barcode'] ?? $baranProduct['Barcode'] ?? null;
    
    // ✨ منطق جدید: جستجو فقط بر اساس item_id
    $product = Product::where('item_id', $itemId)->first();

    if ($product) {
        // به‌روزرسانی
        $product->update([
            'license_id' => $license->id,  // ✨ license_id هم به‌روزرسانی
            'item_name' => $itemName,
            'barcode' => $barcode,
            'price_amount' => (int)$priceAmount,
            'price_after_discount' => (int)$priceAfterDiscount,
            'total_count' => (int)$totalCount,
            'stock_id' => $stockId,
            'department_name' => $departmentName,
            'last_sync_at' => now()
        ]);
        $updatedCount++;
    } else {
        // ایجاد
        Product::create([
            'license_id' => $license->id,
            'item_id' => $itemId,
            'item_name' => $itemName,
            'barcode' => $barcode,
            'price_amount' => (int)$priceAmount,
            'price_after_discount' => (int)$priceAfterDiscount,
            'total_count' => (int)$totalCount,
            'stock_id' => $stockId,
            'department_name' => $departmentName,
            'is_variant' => false,
            'last_sync_at' => now()
        ]);
        $savedCount++;
    }
}
```

## 📝 لاگ‌های جدید

```
[DEBUG] محصول به‌روزرسانی شد
{
    license_id: 2,                    // ✨ license جدید
    item_id: 'ITEM-001',
    barcode: '123456',
    action: 'updated',
    old_license_id: 1                 // ✨ license قدیم
}

[DEBUG] محصول جدید ذخیره شد
{
    license_id: 1,
    item_id: 'ITEM-002',
    barcode: '789012',
    action: 'created'
}
```

## 🎯 مزایا

✅ **یکپارچگی**: یک محصول برای تمام licenses  
✅ **به‌روزرسانی مرکزی**: تغییرات قیمت برای همه منعکس می‌شود  
✅ **صرفه‌جویی**: کم‌تر رکورد تکراری  
✅ **مشترک**: محصولات مشترک میان licenses  

## ⚠️ نکات مهم

1. **license_id تغییر می‌کند**: آخرین license_id برنده است
2. **تاریخ**: last_sync_at برای هر به‌روزرسانی تنظیم می‌شود
3. **item_id منحصر**: هر item_id فقط یک بار در دیتابیس وجود دارد
4. **مشترک**: یک محصول برای چندین licenses قابل استفاده است

## 🧪 تست‌های SQL

### بررسی item_id های تکراری:
```sql
SELECT item_id, COUNT(*) as count, COUNT(DISTINCT license_id) as licenses
FROM products 
GROUP BY item_id 
HAVING COUNT(*) > 1;
```

### دیدن تغییرات license برای یک item:
```sql
SELECT license_id, item_id, item_name, price_amount, last_sync_at 
FROM products 
WHERE item_id = 'ITEM-001' 
ORDER BY last_sync_at DESC;
```

### محصولات یک license:
```sql
SELECT item_id, item_name, price_amount, last_sync_at 
FROM products 
WHERE license_id = 2 
ORDER BY last_sync_at DESC;
```

## 📊 تأثیر بر سیستم

| بخش | تأثیر |
|------|--------|
| **Product Model** | بدون تغییر |
| **Database** | بدون تغییر |
| **SaveBaranProducts** | ✨ منطق جستجو اصلاح شده |
| **Tantooo Updates** | بدون تغییر |
| **Logs** | ✨ old_license_id اضافه شد |

## 📋 چک‌لیست

- ✅ منطق جستجو از `license_id + item_id` به `item_id` تغییر شد
- ✅ license_id هم به‌روزرسانی می‌شود
- ✅ لاگ‌های جدید برای ردیابی license تغییری
- ✅ محصولات مشترک میان licenses
- ✅ بدون خطای syntax

## 🔍 مثال سناریوی بیشتر

### License متفاوت، item_id یکسان:

```
وضعیت اولیه:
├─ License 1: Product(item_id=ITEM-001, price=100, stock=10)
└─ License 2: هیچ چیز

Baran Sync License 2:
├─ دریافت: ITEM-001 (price=150, stock=20)
├─ جستجو: WHERE item_id='ITEM-001'
├─ نتیجه: رکورد موجود (از License 1) پیدا شد
├─ عملیات: به‌روزرسانی
└─ نتیجه نهایی:
   └─ License 2: Product(item_id=ITEM-001, price=150, stock=20, license_id=2)
```

---

**وضعیت:** ✅ تکمیل شده  
**تاریخ:** ۱۸ مهر ۱۴۰۴  
**خطاها:** ✅ بدون خطا
