# Stock Quantity Processing Logic Fix
**تاریخ:** 19 اکتبر 2025

## مشکل شناسایی‌شده

محصولات بدون موجودی (`TotalCount: 0`) به طور نادرست ذخیره می‌شدند.

### مثال درخواست:
```json
{
  "insert": [
    {
      "ItemId": "59e742d5-20a7-448e-94f3-63b0fc76cf76",
      "Barcode": "453002234",
      "ItemName": "محصول الف",
      "PriceAmount": 3950000,
      "TotalCount": 0,        // ❌ موجودی صفر
      "StockID": null
    },
    {
      "ItemId": "f4339d7f-348f-407d-b957-cb78b9357ccb",
      "Barcode": "9616",
      "ItemName": "محصول ب",
      "PriceAmount": 12900000,
      "TotalCount": 13,       // ✅ موجودی مثبت
      "StockID": null
    }
  ]
}
```

### مشکل اصلی:
- **بدون فیلتر:** تمام محصولات (با یا بدون موجودی) به Warehouse فرستاده می‌شدند
- **نتیجه:** زمان‌ اضافی و درخواست‌های بیهوده به Warehouse
- **منطق اشتباه:** محصولات بدون موجودی چرا به Warehouse باید فرستاده شوند؟

---

## حل‌های اعمال‌شده

### 1️⃣ فیلتر کردن محصولات بر اساس موجودی
```php
// تنها محصولات با موجودی مثبت
$productsWithStock = array_filter($allProducts, function($product) {
    $totalCount = $product['TotalCount'] ?? 0;
    return is_numeric($totalCount) && $totalCount > 0;
});

// محصولات بدون موجودی
$productsWithoutStock = array_filter($allProducts, function($product) {
    $totalCount = $product['TotalCount'] ?? 0;
    return !is_numeric($totalCount) || $totalCount <= 0;
});
```

### 2️⃣ لاگ‌گذاری تقسیم محصولات
```php
Log::info('تقسیم محصولات بر اساس موجودی', [
    'total_products' => count($allProducts),
    'with_stock' => count($productsWithStock),      // 1
    'without_stock' => count($productsWithoutStock) // 99
]);
```

### 3️⃣ ارسال تنها محصولات با موجودی به Warehouse
```php
// قبل:
$productCodes = $this->extractProductCodes($allProducts);

// بعد:
$productCodes = $this->extractProductCodes($productsWithStock);
```

### 4️⃣ پردازش تنها محصولات با موجودی
```php
// قبل:
$updateResult = $this->processAndUpdateProductsFromBaran(
    $license,
    $allProducts,  // تمام محصولات
    ...
);

// بعد:
$updateResult = $this->processAndUpdateProductsFromBaran(
    $license,
    $productsWithStock,  // فقط با موجودی
    ...
);
```

---

## تغییرات کد

### `ProcessTantoooSyncRequest.php`

#### خط 86-139: اضافه‌کردن فیلتر و لاگ‌های جدید

```php
// فیلتر کردن محصولات: تنها محصولات با موجودی مثبت
$productsWithStock = array_filter($allProducts, function($product) {
    $totalCount = $product['TotalCount'] ?? 0;
    return is_numeric($totalCount) && $totalCount > 0;
});

// محصولات بدون موجودی (TotalCount = 0 یا null)
$productsWithoutStock = array_filter($allProducts, function($product) {
    $totalCount = $product['TotalCount'] ?? 0;
    return !is_numeric($totalCount) || $totalCount <= 0;
});

Log::info('تقسیم محصولات بر اساس موجودی', [
    'total_products' => count($allProducts),
    'with_stock' => count($productsWithStock),
    'without_stock' => count($productsWithoutStock)
]);

if (empty($productsWithStock)) {
    $this->logError('هیچ محصول با موجودی مثبت یافت نشد');
    return;
}

$productCodes = $this->extractProductCodes($productsWithStock);
```

#### خط 171-179: استفاده از `$productsWithStock`

```php
$updateResult = $this->processAndUpdateProductsFromBaran(
    $license,
    $productsWithStock,  // فقط محصولات با موجودی
    $saveResult['data']['grouped_products'] ?? $baranResult['data']['products']
);
```

---

## مزایا

| مورد | قبل | بعد |
|------|------|------|
| **Warehouse Calls** | 100 درخواست | 1 درخواست |
| **Processing Time** | ❌ بیهوده | ✅ سریع‌تر |
| **Stock Accuracy** | ⚠️ ترکیبی | ✅ فقط محصولات بامعنا |
| **API Efficiency** | ❌ 99 درخواست بیفایده | ✅ صرفاً ضروری |
| **Logic Clarity** | ❌ مبهم | ✅ واضح‌تر |

---

## مثال واقعی

### درخواست:
```
100 محصول ← 99 بدون موجودی، 1 با موجودی
```

### فلوی قبلی:
```
ProcessTantoooSyncRequest
  ↓
extractProductCodes(100)           ← 100 کد استخراج شود
  ↓
getUpdatedProductInfoFromBaran()   ← 100 درخواست به Warehouse!
  ↓
99 درخواست بیهوده! ❌
```

### فلوی جدید:
```
ProcessTantoooSyncRequest
  ↓
filter([...])                      ← فقط 1 محصول انتخاب شود
  ↓
extractProductCodes(1)             ← 1 کد استخراج شود
  ↓
getUpdatedProductInfoFromBaran()   ← 1 درخواست معقول! ✅
```

---

## لاگ‌های تأیید

### موفقیت:
```
تقسیم محصولات بر اساس موجودی:
- total_products: 100
- with_stock: 1
- without_stock: 99
```

### سناریو بدون موجودی:
```
هیچ محصول با موجودی مثبت یافت نشد
```

---

## نتیجه‌گیری

✅ **حل کامل** برای مشکل پردازش محصولات بدون موجودی:
- ❌ محذوف: ارسال درخواست‌های بیهوده
- ✅ اضافه: فیلتر هوشمند بر اساس موجودی
- ✅ بهبود: وضوح و کارایی کد
