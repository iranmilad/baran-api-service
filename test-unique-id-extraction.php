<?php

// تست استخراج unique_id از structures مختلف

// حالت 1: unique_id به صورت object
$item1 = [
    'unique_id' => [
        'unique_id' => 'd357cb62-acee-439a-8221-ae787f2f770e',
        'barcode' => 'FA0182'
    ],
    'sku' => 'PFA0182',
    'quantity' => 1,
    'price' => 40000000,
    'name' => 'تست محصول',
    'total' => '40000000'
];

// حالت 2: unique_id به صورت string
$item2 = [
    'unique_id' => 'd357cb62-acee-439a-8221-ae787f2f770e',
    'sku' => 'PFA0182',
    'quantity' => 1,
    'price' => 40000000,
    'name' => 'تست محصول',
    'total' => '40000000'
];

function extractUniqueId($item, $index = 0) {
    if (is_array($item['unique_id']) && isset($item['unique_id']['unique_id'])) {
        // اگر unique_id یک object است که خود unique_id و barcode دارد
        $itemId = $item['unique_id']['unique_id'];
        echo "آیتم {$index}: استخراج unique_id از object: {$itemId}\n";
        echo "ساختار اصلی: " . json_encode($item['unique_id']) . "\n";
    } else {
        // اگر unique_id یک string است
        $itemId = $item['unique_id'];
        echo "آیتم {$index}: استفاده از unique_id به صورت string: {$itemId}\n";
    }

    return $itemId;
}

echo "=== تست استخراج unique_id ===\n\n";

echo "تست 1 - Object Structure:\n";
$result1 = extractUniqueId($item1, 1);

echo "\nتست 2 - String Structure:\n";
$result2 = extractUniqueId($item2, 2);

echo "\nنتایج:\n";
echo "آیتم 1 (object): {$result1}\n";
echo "آیتم 2 (string): {$result2}\n";
echo "هر دو برابرند: " . ($result1 === $result2 ? 'بله' : 'خیر') . "\n";
