# تغییرات ProductStockController - خلاصه

## مسئله اصلی
کاربر endpoint صحیح Warehouse API را مشخص کرد: `/api/itemlist/GetItemsByIds` که مشابه RainSale API است.

## تغییرات انجام شده

### 1. تغییر endpoint
**قبل**:
```
POST {warehouse_api_url}/api/products/stock/check
{
    "unique_ids": ["ID1", "ID2"]
}
```

**حالا**:
```
POST {warehouse_api_url}/api/itemlist/GetItemsByIds
["ID1", "ID2", "ID3"]
```

### 2. ساختار درخواست
- درخواست به صورت آرایه مستقیم unique IDs ارسال می‌شود
- مشابه RainSale API است
- نیازی به object wrapper نیست

### 3. ساختار پاسخ
```json
{
    "data": {
        "items": [
            {
                "uniqueId": "...",
                "name": "...",
                "code": "...",
                "sellPrice": "...",
                "inventories": [
                    {
                        "warehouse": {
                            "code": "W001",
                            "name": "انبار مرکزی"
                        },
                        "quantity": 100
                    }
                ]
            }
        ]
    }
}
```

### 4. فیلتر کردن بر اساس default_warehouse_code
- `default_warehouse_code` از `UserSetting` خوانده می‌شود
- در لیست `inventories` انبار مطابق پیدا می‌شود
- موجودی آن انبار استفاده می‌شود

## مثال کاربردی
```
URL: http://103.216.62.61:4645/api/itemlist/GetItemsByIds
Method: POST
Body: ["80DEB248-1924-467C-8745-004BAF851746", "29FDC941-FD16-4AE5-AB94-013CDE27CDBC"]
```

## نتیجه
- API حالا مشابه RainSale عمل می‌کند
- فقط credentials و URL متفاوت است
- پردازش inventories برای فیلتر انبار حفظ شده
- سازگاری کامل با ساختار موجود
