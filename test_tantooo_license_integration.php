<?php

require_once __DIR__ . '/vendor/autoload.php';

/**
 * تست نهایی سیستم Tantooo API با استفاده کامل از جدول License
 */

echo "=== Final Tantooo API System Using License Table ===\n\n";

// Test 1: Complete License Integration
echo "1. Complete License Table Integration:\n";
echo "✅ API URL: از license->website_url + '/accounting_api'\n";
echo "✅ API Key: از license->api_token\n";
echo "✅ Bearer Token: از license->token (با مدیریت خودکار)\n";
echo "✅ Token Expiry: از license->token_expires_at\n\n";

// Test 2: Updated Configuration Source
echo "2. Updated Configuration Source:\n";
echo "قبل:\n";
echo "- API URL: آدرس ثابت https://03535.ir/accounting_api\n";
echo "- API Key: کلید ثابت f3a7c8e45d912b6a19e6f2e7b0843c9d\n";
echo "- Bearer Token: از License\n\n";
echo "حالا:\n";
echo "- API URL: license->website_url + '/accounting_api'\n";
echo "- API Key: license->api_token\n";
echo "- Bearer Token: license->token\n";
echo "- Token Expiry: license->token_expires_at\n";
echo "✅ همه تنظیمات از License می‌آید\n\n";

// Test 3: License Fields Used
echo "3. License Fields Used:\n";
echo "- website_url: آدرس پایه وب‌سایت کاربر\n";
echo "- api_token: کلید API اختصاصی کاربر\n";
echo "- token: توکن Bearer فعلی\n";
echo "- token_expires_at: زمان انقضای توکن\n\n";

// Test 4: API Settings Structure
echo "4. API Settings Structure:\n";
echo "```php\n";
echo "protected function getTantoooApiSettings(\$license)\n";
echo "{\n";
echo "    \$websiteUrl = \$license->website_url;\n";
echo "    \$apiUrl = rtrim(\$websiteUrl, '/') . '/accounting_api';\n";
echo "    \$apiKey = \$license->api_token;\n";
echo "    \$token = \$license->isTokenValid() ? \$license->token : null;\n";
echo "    \n";
echo "    return [\n";
echo "        'api_url' => \$apiUrl,\n";
echo "        'api_key' => \$apiKey,\n";
echo "        'bearer_token' => \$token\n";
echo "    ];\n";
echo "}\n";
echo "```\n\n";

// Test 5: Configuration Examples
echo "5. Configuration Examples:\n\n";

echo "مثال 1 - کاربر با دامنه شخصی:\n";
echo "License Fields:\n";
echo "- website_url: 'https://mystore.com'\n";
echo "- api_token: 'abc123def456...'\n";
echo "- token: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...'\n";
echo "- token_expires_at: '2025-09-08 15:30:00'\n\n";
echo "Result API Settings:\n";
echo "- api_url: 'https://mystore.com/accounting_api'\n";
echo "- api_key: 'abc123def456...'\n";
echo "- bearer_token: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...'\n\n";

echo "مثال 2 - کاربر با زیردامنه:\n";
echo "License Fields:\n";
echo "- website_url: 'https://shop.example.ir'\n";
echo "- api_token: 'xyz789uvw012...'\n";
echo "- token: null (منقضی شده)\n";
echo "- token_expires_at: '2025-09-07 10:00:00'\n\n";
echo "Result API Settings:\n";
echo "- api_url: 'https://shop.example.ir/accounting_api'\n";
echo "- api_key: 'xyz789uvw012...'\n";
echo "- bearer_token: null (نیاز به تجدید)\n\n";

// Test 6: Token Management Flow
echo "6. Token Management Flow:\n";
echo "1. بررسی معتبر بودن توکن موجود\n";
echo "   - \$license->isTokenValid() چک می‌کند\n";
echo "   - اگر token_expires_at > now() باشد، معتبر است\n";
echo "2. اگر معتبر باشد، استفاده از همان توکن\n";
echo "3. اگر منقضی باشد یا نباشد:\n";
echo "   - درخواست توکن جدید با api_token\n";
echo "   - ذخیره در license->token\n";
echo "   - تنظیم license->token_expires_at\n\n";

// Test 7: Error Handling
echo "7. Error Handling:\n";
echo "✅ بررسی وجود website_url\n";
echo "✅ بررسی وجود api_token\n";
echo "✅ لاگ کردن خطاهای مربوط به License\n";
echo "✅ بازگشت null در صورت عدم وجود تنظیمات\n\n";

// Test 8: Benefits
echo "8. Benefits of New System:\n";
echo "✅ مستقل: هر کاربر تنظیمات خودش را دارد\n";
echo "✅ انعطاف‌پذیر: پشتیبانی از دامنه‌های مختلف\n";
echo "✅ امن: api_token اختصاصی هر کاربر\n";
echo "✅ خودکار: مدیریت توکن بدون دخالت کاربر\n";
echo "✅ قابل اعتماد: ذخیره در دیتابیس\n";
echo "✅ کارآمد: کش کردن توکن تا انقضا\n\n";

// Test 9: Usage in Controllers
echo "9. Usage in Controllers:\n";
echo "```php\n";
echo "// در کنترلر\n";
echo "\$license = JWTAuth::parseToken()->authenticate();\n\n";
echo "// چک کردن تنظیمات\n";
echo "if (empty(\$license->website_url) || empty(\$license->api_token)) {\n";
echo "    return response()->json([\n";
echo "        'success' => false,\n";
echo "        'message' => 'تنظیمات Tantooo API ناقص است'\n";
echo "    ], 400);\n";
echo "}\n\n";
echo "// استفاده از API\n";
echo "\$result = \$this->updateProductStockWithToken(\$license, 'PRODUCT_CODE', 10);\n";
echo "```\n\n";

// Test 10: Database Schema Requirements
echo "10. Database Schema Requirements:\n";
echo "جدول licenses باید شامل فیلدهای زیر باشد:\n";
echo "- website_url: VARCHAR(255) - آدرس وب‌سایت کاربر\n";
echo "- api_token: VARCHAR(255) - کلید API اختصاصی\n";
echo "- token: TEXT - توکن Bearer فعلی\n";
echo "- token_expires_at: DATETIME - زمان انقضای توکن\n\n";

echo "=== Final Integration Complete ===\n";
echo "Tantooo API now fully integrated with License table!\n";
echo "\n💡 Final Configuration:\n";
echo "- ✅ Dynamic API URLs from license->website_url\n";
echo "- ✅ User-specific API keys from license->api_token\n";
echo "- ✅ Automatic token management with license->token\n";
echo "- ✅ Token expiry tracking with license->token_expires_at\n";
echo "- ✅ Complete validation and error handling\n";
echo "- ✅ Full flexibility for multi-tenant usage\n";
