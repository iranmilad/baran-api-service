<?php

echo "=== سیستم Job-Based به‌روزرسانی محصولات Tantooo ===\n\n";

/**
 * سیستم کامل به‌روزرسانی محصولات Tantooo مشابه WooCommerce
 * 
 * گردش کار:
 * 1. دریافت همه محصولات از Tantooo API
 * 2. استخراج کدهای محصولات (Product Codes)
 * 3. استعلام موجودی و قیمت از RainSale API (باران)
 * 4. به‌روزرسانی در Tantooo از طریق API
 * 5. ذخیره در دیتابیس محلی
 */

echo "📋 ساختار Job ها:\n";
echo "1. CoordinateTantoooProductUpdate (تنسیق‌کننده اصلی)\n";
echo "2. FetchAndDivideTantoooProducts (دریافت و تقسیم محصولات)\n";
echo "3. UpdateTantoooProductsBatch (به‌روزرسانی دسته‌ای)\n";
echo "4. UpdateSingleTantoooProduct (به‌روزرسانی منفرد)\n\n";

echo "🔄 سناریوهای مختلف:\n\n";

// سناریو 1: به‌روزرسانی همه محصولات
echo "📦 سناریو 1: به‌روزرسانی همه محصولات\n";
echo "API Endpoint: POST /api/v1/tantooo/products/update-all\n";
echo "Headers: Authorization: Bearer [JWT_TOKEN]\n";
echo "Body: {} (خالی)\n\n";

echo "گردش کار:\n";
echo "1. CoordinateTantoooProductUpdate شروع می‌شود\n";
echo "2. تست اتصال Tantooo API\n";
echo "3. FetchAndDivideTantoooProducts dispatch می‌شود\n";
echo "4. دریافت همه محصولات از Tantooo (صفحه به صفحه)\n";
echo "5. استخراج کدهای محصولات\n";
echo "6. تقسیم به chunk های 50 تایی\n";
echo "7. UpdateTantoooProductsBatch برای هر chunk\n";
echo "8. استعلام از RainSale API برای هر محصول\n";
echo "9. به‌روزرسانی موجودی در Tantooo\n";
echo "10. ذخیره در دیتابیس محلی\n\n";

// سناریو 2: به‌روزرسانی محصولات خاص
echo "📦 سناریو 2: به‌روزرسانی محصولات خاص\n";
echo "API Endpoint: POST /api/v1/tantooo/products/update-specific\n";
echo "Headers: Authorization: Bearer [JWT_TOKEN]\n";
echo "Body:\n";
echo "{\n";
echo "  \"product_codes\": [\n";
echo "    \"PRODUCT_001\",\n";
echo "    \"PRODUCT_002\",\n";
echo "    \"PRODUCT_003\"\n";
echo "  ]\n";
echo "}\n\n";

echo "گردش کار:\n";
echo "1. CoordinateTantoooProductUpdate با کدهای مشخص\n";
echo "2. تقسیم کدها به chunk های 50 تایی\n";
echo "3. UpdateTantoooProductsBatch برای هر chunk\n";
echo "4. پردازش مشابه سناریو 1\n\n";

// سناریو 3: به‌روزرسانی محصول منفرد
echo "📦 سناریو 3: به‌روزرسانی محصول منفرد\n";
echo "API Endpoint: POST /api/v1/tantooo/products/update-single\n";
echo "Headers: Authorization: Bearer [JWT_TOKEN]\n";
echo "Body:\n";
echo "{\n";
echo "  \"product_code\": \"PRODUCT_001\",\n";
echo "  \"warehouse_code\": \"WAREHOUSE_ID\" // اختیاری\n";
echo "}\n\n";

echo "گردش کار:\n";
echo "1. UpdateSingleTantoooProduct مستقیماً\n";
echo "2. استعلام از RainSale API\n";
echo "3. به‌روزرسانی در Tantooo\n";
echo "4. ذخیره در دیتابیس\n\n";

echo "🏗️ ساختار Job ها:\n\n";

// Job 1
echo "📋 Job 1: CoordinateTantoooProductUpdate\n";
echo "Queue: tantooo-coordination\n";
echo "Timeout: 30 seconds\n";
echo "Tries: 1\n";
echo "وظایف:\n";
echo "- بررسی لایسنس و تنظیمات\n";
echo "- تست اتصال Tantooo API\n";
echo "- تعیین نوع عملیات (update_all, update_specific, fetch_and_update)\n";
echo "- dispatch کردن job های مربوطه\n\n";

// Job 2  
echo "📋 Job 2: FetchAndDivideTantoooProducts\n";
echo "Queue: tantooo-fetch\n";
echo "Timeout: 300 seconds (5 minutes)\n";
echo "Tries: 3\n";
echo "وظایف:\n";
echo "- دریافت محصولات از Tantooo API (صفحه به صفحه)\n";
echo "- استخراج کدهای محصولات\n";
echo "- تقسیم به chunk های 50 تایی\n";
echo "- dispatch کردن UpdateTantoooProductsBatch\n\n";

// Job 3
echo "📋 Job 3: UpdateTantoooProductsBatch\n";
echo "Queue: tantooo-update\n";
echo "Timeout: 600 seconds (10 minutes)\n";
echo "Tries: 3\n";
echo "وظایف:\n";
echo "- پردازش chunk محصولات (حداکثر 50 تا)\n";
echo "- استعلام از RainSale API برای هر محصول\n";
echo "- به‌روزرسانی موجودی در Tantooo\n";
echo "- ذخیره در دیتابیس محلی\n\n";

// Job 4
echo "📋 Job 4: UpdateSingleTantoooProduct\n";
echo "Queue: tantooo-single\n";
echo "Timeout: 120 seconds (2 minutes)\n";
echo "Tries: 3\n";
echo "وظایف:\n";
echo "- پردازش یک محصول منفرد\n";
echo "- استعلام از RainSale API\n";
echo "- به‌روزرسانی در Tantooo\n";
echo "- ذخیره در دیتابیس\n\n";

echo "🔌 API Integration:\n\n";

// Tantooo API
echo "📡 Tantooo API:\n";
echo "- get_sub_main: دریافت لیست محصولات (با pagination)\n";
echo "- update_product_stock: به‌روزرسانی موجودی\n";
echo "- update_product_price: به‌روزرسانی قیمت (در آینده)\n";
echo "- Authentication: Bearer Token (مدیریت خودکار)\n";
echo "- URL: license->website_url + '/accounting_api'\n";
echo "- API Key: license->api_token\n\n";

// RainSale API  
echo "📡 RainSale API (باران):\n";
echo "- GetItemInfos: دریافت اطلاعات محصولات\n";
echo "- Authentication: Basic Auth (username:password)\n";
echo "- URL: user->api_webservice + '/RainSaleService.svc/GetItemInfos'\n";
echo "- Parameters: barcodes[], stockId (optional)\n\n";

echo "💾 Database Integration:\n\n";
echo "جدول products:\n";
echo "- license_id: شناسه لایسنس\n";
echo "- item_id: کد محصول (Product Code)\n";
echo "- stock: موجودی فعلی\n";
echo "- price: قیمت فعلی\n";
echo "- warehouse_code: کد انبار\n";
echo "- warehouse_name: نام انبار\n";
echo "- item_name: نام محصول\n";
echo "- barcode: بارکد\n";
echo "- last_sync_at: آخرین زمان همگام‌سازی\n\n";

echo "⚙️ تنظیمات License:\n\n";
echo "فیلدهای ضروری:\n";
echo "- website_url: آدرس وب‌سایت Tantooo\n";
echo "- api_token: کلید API Tantooo\n";
echo "- token: Bearer Token فعلی\n";
echo "- token_expires_at: انقضای توکن\n\n";

echo "⚙️ تنظیمات User:\n\n";
echo "فیلدهای ضروری:\n";
echo "- api_webservice: آدرس RainSale API\n";
echo "- api_username: نام کاربری RainSale\n";
echo "- api_password: رمز عبور RainSale\n";
echo "- default_warehouse_code: کد انبار پیش‌فرض\n\n";

echo "🔧 Queue Configuration:\n\n";
echo "Queue ها:\n";
echo "- tantooo-coordination: Job های تنسیق‌کننده\n";
echo "- tantooo-fetch: Job های دریافت محصولات\n";
echo "- tantooo-update: Job های به‌روزرسانی دسته‌ای\n";
echo "- tantooo-single: Job های به‌روزرسانی منفرد\n\n";

echo "📊 Performance & Scalability:\n\n";
echo "بهینه‌سازی‌ها:\n";
echo "- Chunk Size: 50 محصول در هر batch\n";
echo "- Delay: 10 ثانیه بین هر chunk\n";
echo "- Timeout: متغیر بر اساس نوع job\n";
echo "- Retry: 3 بار تلاش مجدد\n";
echo "- Rate Limiting: 0.5 ثانیه بین هر محصول\n\n";

echo "📈 Monitoring & Logging:\n\n";
echo "لاگ‌های مهم:\n";
echo "- شروع و پایان هر job\n";
echo "- تعداد محصولات پردازش شده\n";
echo "- خطاهای API\n";
echo "- نتایج به‌روزرسانی\n";
echo "- زمان اجرا\n\n";

echo "🔄 Error Handling:\n\n";
echo "مدیریت خطا:\n";
echo "- بررسی وضعیت لایسنس\n";
echo "- اعتبارسنجی تنظیمات API\n";
echo "- تست اتصال قبل از شروع\n";
echo "- Graceful degradation\n";
echo "- Retry mechanism\n";
echo "- Comprehensive logging\n\n";

echo "📋 Usage Examples:\n\n";

// مثال curl برای همه محصولات
echo "💻 Example 1: به‌روزرسانی همه محصولات\n";
echo "curl -X POST \"http://your-domain.com/api/v1/tantooo/products/update-all\" \\\n";
echo "  -H \"Authorization: Bearer YOUR_JWT_TOKEN\" \\\n";
echo "  -H \"Content-Type: application/json\" \\\n";
echo "  -d '{}'\n\n";

// مثال curl برای محصولات خاص
echo "💻 Example 2: به‌روزرسانی محصولات خاص\n";
echo "curl -X POST \"http://your-domain.com/api/v1/tantooo/products/update-specific\" \\\n";
echo "  -H \"Authorization: Bearer YOUR_JWT_TOKEN\" \\\n";
echo "  -H \"Content-Type: application/json\" \\\n";
echo "  -d '{\n";
echo "    \"product_codes\": [\"PROD001\", \"PROD002\", \"PROD003\"]\n";
echo "  }'\n\n";

// مثال curl برای محصول منفرد
echo "💻 Example 3: به‌روزرسانی محصول منفرد\n";
echo "curl -X POST \"http://your-domain.com/api/v1/tantooo/products/update-single\" \\\n";
echo "  -H \"Authorization: Bearer YOUR_JWT_TOKEN\" \\\n";
echo "  -H \"Content-Type: application/json\" \\\n";
echo "  -d '{\n";
echo "    \"product_code\": \"PRODUCT_CODE_123\",\n";
echo "    \"warehouse_code\": \"WAREHOUSE_ID_456\"\n";
echo "  }'\n\n";

echo "✅ Response Examples:\n\n";

// پاسخ موفق
echo "📤 Success Response:\n";
echo "{\n";
echo "  \"success\": true,\n";
echo "  \"message\": \"فرآیند به‌روزرسانی همه محصولات Tantooo آغاز شد\",\n";
echo "  \"data\": {\n";
echo "    \"operation\": \"update_all\",\n";
echo "    \"license_id\": 123,\n";
echo "    \"started_at\": \"2025-09-09T12:00:00Z\"\n";
echo "  }\n";
echo "}\n\n";

// پاسخ خطا
echo "📤 Error Response:\n";
echo "{\n";
echo "  \"success\": false,\n";
echo "  \"message\": \"تنظیمات Tantooo API ناقص است\"\n";
echo "}\n\n";

echo "🎯 مزایای سیستم جدید:\n\n";
echo "✅ مشابه سازی کامل با WooCommerce Jobs\n";
echo "✅ مقیاس‌پذیری بالا (Chunk-based processing)\n";
echo "✅ مدیریت خطای پیشرفته\n";
echo "✅ لاگینگ جامع\n";
echo "✅ Queue-based architecture\n";
echo "✅ Multi-tenant support\n";
echo "✅ Dynamic warehouse selection\n";
echo "✅ Retry mechanism\n";
echo "✅ Rate limiting\n";
echo "✅ Comprehensive validation\n\n";

echo "🚀 Ready for Production!\n";
echo "سیستم آماده استفاده در محیط تولید است.\n\n";

echo "📝 Next Steps:\n";
echo "1. تست در محیط development\n";
echo "2. پیکربندی Queue workers\n";
echo "3. تنظیم monitoring\n";
echo "4. Documentation کامل\n";
echo "5. Training تیم\n";
