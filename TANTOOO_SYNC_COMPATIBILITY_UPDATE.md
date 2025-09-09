# Tantooo Sync Methods - WooCommerce Compatibility Update

## تغییرات اعمال شده

### 1. متد `sync()` در TantoooProductController

**قبل از تغییر:**
- ورودی: `{ "products": [...] }`
- ساختار محصولات: `{ "code", "title", "price", "discount" }`

**بعد از تغییر:**
- ورودی: `{ "update": [...], "insert": [...] }` - دقیقاً مشابه WooCommerce
- ساختار محصولات: `{ "Barcode", "Title", "Price", "Stock" }`

### 2. متد `bulkSync()` در TantoooProductController

**قبل از تغییر:**
- ورودی: `{ "unique_ids": [...], "batch_size": 50 }`

**بعد از تغییر:**
- ورودی: `{ "barcodes": [...] }` - دقیقاً مشابه WooCommerce
- برای همه محصولات: `{}` (آرایه خالی)

### 3. Validation Rules

**قبل از تغییر:**
```php
'products' => 'required|array',
'products.*.code' => 'required|string',
'products.*.title' => 'required|string'
```

**بعد از تغییر:**
```php
'update' => 'array',
'insert' => 'array', 
'update.*.Barcode' => 'required_with:update.*|string',
'insert.*.Barcode' => 'required_with:insert.*|string'
```

### 4. مستندات به‌روزرسانی شده

- فایل README.md در فولدر Tantooo به‌روزرسانی شد
- مثال‌های API با فرمت جدید اضافه شدند

## مثال‌های استفاده

### Sync Method
```bash
POST /api/v1/tantooo/products/sync
{
  "update": [
    {
      "Barcode": "BARCODE_1",
      "Title": "نام محصول",
      "Price": 100000,
      "Stock": 50
    }
  ],
  "insert": [
    {
      "Barcode": "BARCODE_2", 
      "Title": "محصول جدید",
      "Price": 150000,
      "Stock": 30
    }
  ]
}
```

### Bulk Sync Method
```bash
# همگام‌سازی محصولات انتخاب شده
POST /api/v1/tantooo/products/bulk-sync
{
  "barcodes": ["BARCODE1", "BARCODE2", "BARCODE3"]
}

# همگام‌سازی همه محصولات
POST /api/v1/tantooo/products/bulk-sync
{}
```

## سازگاری

✅ ورودی‌های Tantooo حالا دقیقاً مشابه WooCommerce هستند
✅ Validation rules به‌روزرسانی شدند
✅ مستندات کامل شدند
✅ تست سازگاری انجام شد

## نتیجه

متدهای `sync()` و `bulkSync()` در کنترلر Tantooo حالا دقیقاً همان ورودی‌هایی را که WooCommerce قبول می‌کند، می‌پذیرند. این تغییر باعث یکپارچگی و سادگی در استفاده از API می‌شود.
