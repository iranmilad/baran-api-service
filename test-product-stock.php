<?php

// تست API موجودی محصولات
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

// تست 1: درخواست با یک unique_id
echo "=== تست 1: درخواست با یک unique_id ===\n";
$testData1 = [
    'unique_id' => '80DEB248-1924-467C-8745-004BAF851746'
];

curl_setopt($ch, CURLOPT_URL, $baseUrl . '/stock');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData1));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $token
]);

$response1 = curl_exec($ch);
echo "پاسخ: " . $response1 . "\n\n";

// تست 2: درخواست با آرایه unique_ids
echo "=== تست 2: درخواست با آرایه unique_ids ===\n";
$testData2 = [
    'unique_ids' => [
        '80DEB248-1924-467C-8745-004BAF851746',
        '29FDC941-FD16-4AE5-AB94-013CDE27CDBC'
    ]
];

curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData2));
$response2 = curl_exec($ch);
echo "پاسخ: " . $response2 . "\n\n";

// تست 3: درخواست نامعتبر (بدون هیچ پارامتر)
echo "=== تست 3: درخواست نامعتبر ===\n";
$testData3 = [];

curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testData3));
$response3 = curl_exec($ch);
echo "پاسخ: " . $response3 . "\n\n";

curl_close($ch);

echo "تست‌ها تمام شد.\n";
