# Stock Quantity Aggregation Fix
**تاریخ:** 19 اکتبر 2025

## مشکل شناسایی‌شده

وقتی یک محصول (`itemID`) برای چندین انبار وجود دارد، موجودی‌ها صحیح نمی‌شدند:

```json
// مثال: محصول دارای 3 انبار
{
  "itemID": "a2ec4eee-e600-4c38-80b1-b3338280fffe",
  "barcode": "189948",
  "stockName": "دشتستان",
  "stockQuantity": 2.000    // انبار 1
},
{
  "itemID": "a2ec4eee-e600-4c38-80b1-b3338280fffe",
  "barcode": "189948",
  "stockName": "انبار اصلی",
  "stockQuantity": 0.000    // انبار 2
},
{
  "itemID": "a2ec4eee-e600-4c38-80b1-b3338280fffe",
  "barcode": "189948",
  "stockName": "شهدا",
  "stockQuantity": 1.000    // انبار 3
}
```

### مشکل قبلی:
- **بدون گروه‌بندی:** فقط آخرین موجودی (1) ثبت می‌شد
- **جستجوی ناقص:** در `processAndUpdateProductsFromBaran` داده‌های خام جستجو می‌شدند

### نتیجه بعد از اصلاح:
```
total_count = 2 + 0 + 1 = 3 ✅
```

---

## حل‌ها اعمال‌شده

### 1. گروه‌بندی محصولات در `saveBaranProductsToDatabase`
```php
// ایجاد نقشه بر اساس itemID
$groupedProducts[$itemId] = [
    'totalQuantity' => 0,  // موجودی جمع‌شده
    'stocks' => [...]      // تمام انبارهای محصول
];

// اضافه‌کردن موجودی هر انبار
$groupedProducts[$itemId]['totalQuantity'] += $stockQuantity;
```

### 2. برگرداندن داده‌های گروه‌بندی‌شده
```php
return [
    'data' => [
        'grouped_products' => $processedGroupedProducts,  // محصولات یکپارچه‌شده
        ...
    ]
];
```

### 3. استفاده از نقشه برای جستجوی سریع
```php
// در processAndUpdateProductsFromBaran
$baranProductMap = [];
foreach ($baranProducts as $baranItem) {
    $baranItemId = $baranItem['itemID'] ?? null;
    if ($baranItemId) {
        $baranProductMap[$baranItemId] = $baranItem;
    }
}

// جستجوی O(1) بدل O(n)
$baranProduct = $baranProductMap[$itemId] ?? null;
```

### 4. انتقال داده‌های یکپارچه‌شده
```php
$updateResult = $this->processAndUpdateProductsFromBaran(
    $license,
    $allProducts,
    $saveResult['data']['grouped_products'] ?? $baranResult['data']['products']
);
```

---

## فایل‌های تغییر‌یافته

### `ProcessTantoooSyncRequest.php`
- **خط 123-145:** اضافه‌کردن استفاده از `grouped_products`
- **خط 256-362:** بهینه‌سازی `processAndUpdateProductsFromBaran` با نقشه
- **خط 560-581:** ایجاد `grouped_products` و برگرداندن آن

---

## مثال عملی

### Input:
```
15 رکورد خام (5 محصول × 3 انبار)
```

### Process:
```
1. گروه‌بندی بر اساس itemID → 5 گروه یکتا
2. جمع موجودی برای هر گروه
3. ذخیره 5 محصول با موجودی‌های صحیح
4. جستجوی سریع برای updating
```

### Output:
```
- 5 محصول ذخیره شد
- موجودی‌ها صحیح (جمع‌شده)
- تمام انبارها ثبت شد
```

---

## مزایا

| مورد | قبل | بعد |
|------|------|------|
| Complexity | O(n×m) | O(n+m) |
| Stock Accuracy | ❌ نادرست | ✅ صحیح |
| Warehouse Info | ❌ گم شد | ✅ ثبت شد |
| Logging | ⚠️ ناقص | ✅ جامع |

---

## لاگ‌های مهم

### موفقیت:
```
تکمیل ذخیره‌سازی محصولات باران:
- total_raw_records: 15
- unique_items_count: 5
- saved_count: 5 (یا updated_count: 5)
- stocks_detail: [{"stockName": "دشتستان", "quantity": 2}, ...]
```

### جستجو:
```
شروع پردازش محصولات Tantooo:
- total_products: 5
- unique_baran_items: 5
- grouped_products_structure: {...}
```

---

## تست‌های توصیه‌شده

```php
// 1. بررسی گروه‌بندی
$product = Product::find($id);
assert($product->total_count === 3); // 2+0+1

// 2. بررسی لاگ‌ها
Log::info('...', ['total_raw_records' => 15, 'unique_items_count' => 5]);

// 3. بررسی جستجو
$baranProduct = $baranProductMap[$itemId];
assert($baranProduct !== null);
```

---

## نتیجه‌گیری

✅ **حل کامل** برای مسئله موجودی‌های چندگانه با:
- گروه‌بندی خودکار
- جمع‌آوری موجودی‌ها
- جستجوی بهینه
- لاگ‌گذاری جامع
