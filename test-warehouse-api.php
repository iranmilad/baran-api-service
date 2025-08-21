<?php

// تست API موجودی محصولات با Warehouse API جدید
$baseUrl = 'http://localhost/baran-api-service/public/api/v1';

// اطلاعات احراز هویت - باید جایگزین شود
$apiKey = 'YOUR_API_KEY';
$apiSecret = 'YOUR_API_SECRET';

echo "=== تست Product Stock API با Warehouse API ===\n\n";

// درخواست token
$loginData = [
    'api_key' => $apiKey,
    'api_secret' => $apiSecret
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $baseUrl . '/login');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$loginResponse = curl_exec($ch);
$loginData = json_decode($loginResponse, true);

if (!isset($loginData['access_token'])) {
    echo "❌ خطا در احراز هویت: " . $loginResponse . "\n";
    exit;
}

$token = $loginData['access_token'];
echo "✅ Token دریافت شد: " . substr($token, 0, 20) . "...\n\n";

// تست 1: درخواست با چند unique_id
echo "=== تست 1: درخواست چند محصول از Warehouse API ===\n";
$testData1 = [
    'unique_ids' => [
        '80DEB248-1924-467C-8745-004BAF851746',
        '29FDC941-FD16-4AE5-AB94-013CDE27CDBC',
        '283bff71-7a55-4610-acd5-c8852dd147f3',
        'fc06daa5-8d18-475b-b3ef-02ce0ee1179a'
    ]
];

curl_setopt($ch, CURLOPT_URL, $baseUrl . '/stock');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData1));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token
]);

$response1 = curl_exec($ch);
$data1 = json_decode($response1, true);

echo "پاسخ از API:\n";
echo json_encode($data1, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

// بررسی نتایج
if (isset($data1['success']) && $data1['success']) {
    echo "✅ درخواست موفق بود!\n";

    if (isset($data1['data'])) {
        $data = $data1['data'];
        echo "📊 آمار کلی:\n";
        echo "- تعداد درخواست شده: " . ($data['total_requested'] ?? 'نامشخص') . "\n";
        echo "- تعداد پیدا شده: " . ($data['total_found'] ?? 'نامشخص') . "\n";
        echo "- تعداد پیدا نشده: " . ($data['total_not_found'] ?? 'نامشخص') . "\n\n";

        // بررسی محصولات پیدا شده
        if (isset($data['found_products']) && !empty($data['found_products'])) {
            echo "📦 محصولات پیدا شده:\n";
            foreach ($data['found_products'] as $product) {
                echo "- کد یکتا: " . $product['unique_id'] . "\n";
                echo "  نام: " . ($product['product_info']['name'] ?? 'نامشخص') . "\n";
                echo "  SKU: " . ($product['product_info']['code'] ?? 'نامشخص') . "\n";
                echo "  قیمت: " . ($product['product_info']['sellPrice'] ?? '0') . "\n";
                echo "  موجودی: " . ($product['default_warehouse_stock']['quantity'] ?? '0') . "\n";
                echo "\n";
            }
        }

        // بررسی محصولات پیدا نشده
        if (isset($data['not_found_products']) && !empty($data['not_found_products'])) {
            echo "❌ محصولات پیدا نشده:\n";
            foreach ($data['not_found_products'] as $product) {
                echo "- کد یکتا: " . $product['unique_id'] . "\n";
                echo "  دلیل: " . $product['message'] . "\n";
            }
            echo "\n";
        }

        // بررسی نتیجه به‌روزرسانی وردپرس
        if (isset($data['wordpress_update'])) {
            $wpUpdate = $data['wordpress_update'];
            echo "🔄 نتیجه به‌روزرسانی وردپرس:\n";
            echo "- وضعیت: " . ($wpUpdate['success'] ? '✅ موفق' : '❌ ناموفق') . "\n";
            echo "- پیام: " . $wpUpdate['message'] . "\n";
            echo "- تعداد به‌روزرسانی شده: " . $wpUpdate['updated_count'] . "\n";

            if (isset($wpUpdate['error_details'])) {
                echo "- جزئیات خطا: " . $wpUpdate['error_details'] . "\n";
            }
            echo "\n";
        }
    }
} else {
    echo "❌ درخواست ناموفق!\n";
    echo "پیام خطا: " . ($data1['message'] ?? 'نامشخص') . "\n";

    if (isset($data1['errors'])) {
        echo "جزئیات خطا:\n";
        print_r($data1['errors']);
    }
}

// تست 2: درخواست با یک unique_id
echo "\n=== تست 2: درخواست تک محصول ===\n";
$testData2 = [
    'unique_id' => '80DEB248-1924-467C-8745-004BAF851746'
];

curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData2));
$response2 = curl_exec($ch);
$data2 = json_decode($response2, true);

if (isset($data2['success']) && $data2['success']) {
    echo "✅ تست تک محصول موفق بود!\n";
    echo "تعداد پیدا شده: " . ($data2['data']['total_found'] ?? 'نامشخص') . "\n";
} else {
    echo "❌ تست تک محصول ناموفق!\n";
    echo "خطا: " . ($data2['message'] ?? 'نامشخص') . "\n";
}

curl_close($ch);

echo "\n=== بررسی تنظیمات مورد نیاز ===\n";
echo "لطفاً مطمئن شوید که:\n";
echo "1. فیلدهای warehouse_api_url، warehouse_api_username، warehouse_api_password در User model تنظیم شده‌اند\n";
echo "2. enable_stock_update در UserSetting فعال است\n";
echo "3. default_warehouse_code در UserSetting تنظیم شده است (برای فیلتر کردن موجودی)\n";
echo "4. WooCommerce API credentials تنظیم شده‌اند\n";
echo "\nنکته: default_warehouse_code در درخواست ارسال نمی‌شود، فقط برای فیلتر کردن نتایج استفاده می‌شود.\n";

echo "\nتست تمام شد.\n";
