-- حذف رکوردهای تکراری از جدول products
-- فقط رکورد با کمترین id از هر ترکیب item_id, stock_id, license_id باقی می‌ماند

-- مرحله 1: مشاهده رکوردهای تکراری (برای بررسی قبل از حذف)
SELECT
    item_id,
    stock_id,
    license_id,
    COUNT(*) as duplicate_count,
    GROUP_CONCAT(id ORDER BY id) as all_ids
FROM products
GROUP BY item_id, stock_id, license_id
HAVING COUNT(*) > 1;

-- مرحله 2: حذف رکوردهای تکراری (فقط اولین رکورد حفظ می‌شود)
DELETE p1 FROM products p1
INNER JOIN products p2
WHERE
    p1.item_id = p2.item_id
    AND p1.stock_id = p2.stock_id
    AND p1.license_id = p2.license_id
    AND p1.id > p2.id;

-- مرحله 3: بررسی که دیگر تکراری وجود ندارد
SELECT
    item_id,
    stock_id,
    license_id,
    COUNT(*) as count
FROM products
GROUP BY item_id, stock_id, license_id
HAVING COUNT(*) > 1;

-- اگر نتیجه مرحله 3 خالی بود، می‌توانید مایگریشن را اجرا کنید:
-- php artisan migrate
