<?php

// تست API موجودی محصولات با به‌روزرسانی خودکار وردپرس
$baseUrl = 'http://localhost/baran-api-service/public/api/v1';

// اطلاعات احراز هویت - باید جایگزین شود
$apiKey = 'YOUR_API_KEY';
$apiSecret = 'YOUR_API_SECRET';

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
    echo "خطا در احراز هویت: " . $loginResponse . "\n";
    exit;
}

$token = $loginData['access_token'];
echo "Token دریافت شد: " . substr($token, 0, 20) . "...\n\n";

// تست 1: درخواست با چند unique_id - با به‌روزرسانی وردپرس
echo "=== تست 1: درخواست با چند unique_id + به‌روزرسانی وردپرس ===\n";
$testData1 = [
    'unique_ids' => [
        '80DEB248-1924-467C-8745-004BAF851746',
        '29FDC941-FD16-4AE5-AB94-013CDE27CDBC',
        '283bff71-7a55-4610-acd5-c8852dd147f3'
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

echo "پاسخ: " . $response1 . "\n\n";

// نمایش نتایج به‌روزرسانی وردپرس
if (isset($data1['data']['wordpress_update'])) {
    $wpUpdate = $data1['data']['wordpress_update'];
    echo "نتیجه به‌روزرسانی وردپرس:\n";
    echo "- موفقیت: " . ($wpUpdate['success'] ? 'بله' : 'خیر') . "\n";
    echo "- پیام: " . $wpUpdate['message'] . "\n";
    echo "- تعداد به‌روزرسانی شده: " . $wpUpdate['updated_count'] . "\n";

    if (isset($wpUpdate['wordpress_response'])) {
        echo "- پاسخ وردپرس: " . json_encode($wpUpdate['wordpress_response'], JSON_UNESCAPED_UNICODE) . "\n";
    }

    if (isset($wpUpdate['error_details'])) {
        echo "- جزئیات خطا: " . $wpUpdate['error_details'] . "\n";
    }
} else {
    echo "هیچ اطلاعاتی از به‌روزرسانی وردپرس دریافت نشد.\n";
}

curl_close($ch);

echo "\n=== خلاصه آمار ===\n";
if (isset($data1['data'])) {
    echo "تعداد درخواست شده: " . $data1['data']['total_requested'] . "\n";
    echo "تعداد پیدا شده: " . $data1['data']['total_found'] . "\n";
    echo "تعداد پیدا نشده: " . $data1['data']['total_not_found'] . "\n";
}

echo "\nتست تمام شد.\n";
