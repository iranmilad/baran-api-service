<?php

echo "=== تست سیستم همگام‌سازی پیشرفته Tantooo با باران ===\n\n";

/**
 * تست سیستم جدید همگام‌سازی که:
 * 1. کدهای محصولات را از درخواست استخراج می‌کند
 * 2. اطلاعات به‌روز را از باران دریافت می‌کند
 * 3. بر اساس تنظیمات کاربر محصولات را به‌روزرسانی می‌کند
 */

echo "🔄 گردش کار جدید همگام‌سازی:\n\n";

echo "1️⃣ دریافت درخواست از فروشگاه:\n";
echo "   - محصولات insert و update\n";
echo "   - هر محصول شامل Barcode، Title، Price، etc.\n\n";

echo "2️⃣ استخراج کدهای محصولات:\n";
echo "   - جستجو در فیلدهای: Barcode، Code، product_code، sku، ItemID\n";
echo "   - حذف موارد تکراری\n";
echo "   - تولید آرایه منحصر به فرد از کدها\n\n";

echo "3️⃣ درخواست به API باران (RainSale):\n";
echo "   - ارسال آرایه barcodes\n";
echo "   - دریافت اطلاعات کامل (موجودی، قیمت، نام)\n";
echo "   - اعمال فیلتر انبار (اگر تنظیم شده)\n\n";

echo "4️⃣ پردازش و تطبیق داده‌ها:\n";
echo "   - تطبیق محصولات اصلی با داده‌های باران\n";
echo "   - اعمال تنظیمات کاربر (enable_stock_update، enable_price_update)\n";
echo "   - تبدیل واحد قیمت (ریال/تومان)\n\n";

echo "5️⃣ به‌روزرسانی در Tantooo:\n";
echo "   - ارسال محصولات آماده شده به Tantooo API\n";
echo "   - مدیریت خودکار توکن\n";
echo "   - ثبت نتایج و خطاها\n\n";

echo "📋 مثال عملی درخواست:\n\n";

echo "📤 Input (از فروشگاه):\n";
echo "{\n";
echo "  \"insert\": [\n";
echo "    {\n";
echo "      \"Barcode\": \"123456789\",\n";
echo "      \"Title\": \"محصول تست 1\",\n";
echo "      \"Price\": 0,\n";
echo "      \"Stock\": 0\n";
echo "    }\n";
echo "  ],\n";
echo "  \"update\": [\n";
echo "    {\n";
echo "      \"Barcode\": \"987654321\",\n";
echo "      \"Title\": \"محصول تست 2\",\n";
echo "      \"Price\": 0,\n";
echo "      \"Stock\": 0\n";
echo "    }\n";
echo "  ]\n";
echo "}\n\n";

echo "🔍 استخراج کدها:\n";
echo "extracted_codes = [\"123456789\", \"987654321\"]\n\n";

echo "📡 درخواست به باران:\n";
echo "POST " . "/RainSaleService.svc/GetItemInfos\n";
echo "{\n";
echo "  \"barcodes\": [\"123456789\", \"987654321\"],\n";
echo "  \"stockId\": \"warehouse-code-if-set\"\n";
echo "}\n\n";

echo "📥 پاسخ از باران:\n";
echo "{\n";
echo "  \"GetItemInfosResult\": [\n";
echo "    {\n";
echo "      \"itemID\": \"uuid-1\",\n";
echo "      \"itemName\": \"نام اصلی محصول 1\",\n";
echo "      \"barcode\": \"123456789\",\n";
echo "      \"salePrice\": 50000,\n";
echo "      \"stockQuantity\": 25,\n";
echo "      \"stockID\": \"warehouse-id\",\n";
echo "      \"stockName\": \"انبار اصلی\"\n";
echo "    },\n";
echo "    {\n";
echo "      \"itemID\": \"uuid-2\",\n";
echo "      \"itemName\": \"نام اصلی محصول 2\",\n";
echo "      \"barcode\": \"987654321\",\n";
echo "      \"salePrice\": 75000,\n";
echo "      \"stockQuantity\": 10,\n";
echo "      \"stockID\": \"warehouse-id\",\n";
echo "      \"stockName\": \"انبار اصلی\"\n";
echo "    }\n";
echo "  ]\n";
echo "}\n\n";

echo "🔧 پردازش با تنظیمات کاربر:\n\n";

echo "تنظیمات نمونه:\n";
echo "- enable_stock_update: true\n";
echo "- enable_price_update: true\n";
echo "- enable_name_update: false\n";
echo "- rain_sale_price_unit: rial\n";
echo "- tantooo_price_unit: toman\n\n";

echo "محصول پردازش شده:\n";
echo "{\n";
echo "  \"Barcode\": \"123456789\",\n";
echo "  \"Title\": \"محصول تست 1\",  // نام تغییر نکرد\n";
echo "  \"Price\": 5000,             // 50000 ریال ÷ 10 = 5000 تومان\n";
echo "  \"Stock\": 25,              // از باران\n";
echo "  \"warehouse_id\": \"warehouse-id\",\n";
echo "  \"warehouse_name\": \"انبار اصلی\"\n";
echo "}\n\n";

echo "📤 ارسال به Tantooo:\n";
echo "POST https://website.com/accounting_api\n";
echo "Headers:\n";
echo "- X-API-KEY: user-api-token\n";
echo "- Authorization: Bearer auto-managed-token\n";
echo "- Content-Type: text/plain\n\n";
echo "Body:\n";
echo "{\n";
echo "  \"fn\": \"update_multiple_products\",\n";
echo "  \"products\": [processed_products_array]\n";
echo "}\n\n";

echo "📊 پاسخ نهایی:\n";
echo "{\n";
echo "  \"success\": true,\n";
echo "  \"message\": \"درخواست همگام‌سازی با موفقیت انجام شد\",\n";
echo "  \"data\": {\n";
echo "    \"sync_id\": \"tantooo_sync_abc123\",\n";
echo "    \"total_processed\": 2,\n";
echo "    \"success_count\": 2,\n";
echo "    \"error_count\": 0,\n";
echo "    \"baran_data\": {\n";
echo "      \"total_requested\": 2,\n";
echo "      \"total_received\": 2\n";
echo "    },\n";
echo "    \"tantooo_update_result\": {\n";
echo "      \"success\": true,\n";
echo "      \"updated_count\": 2\n";
echo "    },\n";
echo "    \"errors\": []\n";
echo "  }\n";
echo "}\n\n";

echo "⚙️ تنظیمات مورد نیاز:\n\n";

echo "جدول UserSetting:\n";
echo "- enable_stock_update: آیا موجودی به‌روزرسانی شود؟\n";
echo "- enable_price_update: آیا قیمت به‌روزرسانی شود؟\n";
echo "- enable_name_update: آیا نام به‌روزرسانی شود؟\n";
echo "- rain_sale_price_unit: واحد قیمت در باران (rial/toman)\n";
echo "- tantooo_price_unit: واحد قیمت در تنتو (rial/toman)\n";
echo "- default_warehouse_code: کد انبار پیش‌فرض\n\n";

echo "جدول User:\n";
echo "- api_webservice: آدرس RainSale API\n";
echo "- api_username: نام کاربری RainSale\n";
echo "- api_password: رمز عبور RainSale\n\n";

echo "جدول License:\n";
echo "- website_url: آدرس Tantooo\n";
echo "- api_token: کلید API Tantooo\n";
echo "- token: Bearer Token فعلی\n";
echo "- token_expires_at: انقضای توکن\n\n";

echo "🔍 سناریوهای مختلف:\n\n";

echo "سناریو 1: همه تنظیمات فعال\n";
echo "✅ موجودی از باران\n";
echo "✅ قیمت از باران (با تبدیل واحد)\n";
echo "✅ نام از باران\n\n";

echo "سناریو 2: فقط موجودی\n";
echo "✅ موجودی از باران\n";
echo "❌ قیمت: بدون تغییر\n";
echo "❌ نام: بدون تغییر\n\n";

echo "سناریو 3: محصول در باران یافت نشد\n";
echo "❌ محصول رد می‌شود\n";
echo "📝 خطا در لیست errors ثبت می‌شود\n\n";

echo "سناریو 4: انبار مشخص\n";
echo "🏢 محصول از انبار مشخص دریافت می‌شود\n";
echo "🔄 اگر در انبار مشخص نباشد، از اولین انبار موجود\n\n";

echo "🚀 مزایای سیستم جدید:\n\n";
echo "✅ اطلاعات Real-time از انبار\n";
echo "✅ تنظیمات انعطاف‌پذیر کاربر\n";
echo "✅ تبدیل خودکار واحد قیمت\n";
echo "✅ مدیریت خطای جامع\n";
echo "✅ پشتیبانی از انبارهای متعدد\n";
echo "✅ لاگینگ کامل فرآیند\n";
echo "✅ کدهای قابل نگهداری\n\n";

echo "📝 نکات مهم:\n\n";
echo "• کدهای محصول از فیلدهای مختلف استخراج می‌شوند\n";
echo "• اولویت با Barcode است\n";
echo "• تنظیمات کاربر اعمال می‌شوند\n";
echo "• خطاها ثبت و گزارش می‌شوند\n";
echo "• توکن Tantooo خودکار مدیریت می‌شود\n";
echo "• پشتیبانی از batch processing\n\n";

echo "🎯 API Endpoint:\n";
echo "POST /api/v1/tantooo/products/sync\n";
echo "Authorization: Bearer [JWT_TOKEN]\n\n";

echo "✅ سیستم آماده استفاده است!\n";
