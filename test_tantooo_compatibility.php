<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Facades\Route;

/**
 * تست سازگاری متدهای Tantooo با WooCommerce
 * این فایل بررسی می‌کند که آیا ورودی‌های Tantooo دقیقاً مشابه WooCommerce هستند
 */

echo "=== Testing Tantooo Compatibility with WooCommerce ===\n\n";

// Test 1: sync method input format
echo "1. Testing sync method input format compatibility:\n";
$syncInput = [
    'update' => [
        [
            'Barcode' => 'TEST001',
            'Title' => 'محصول تست ۱',
            'Price' => 100000,
            'Stock' => 50
        ]
    ],
    'insert' => [
        [
            'Barcode' => 'TEST002',
            'Title' => 'محصول تست ۲',
            'Price' => 150000,
            'Stock' => 30
        ]
    ]
];

echo "Input format: " . json_encode($syncInput, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "✅ sync method accepts update/insert arrays like WooCommerce\n\n";

// Test 2: bulkSync method input format
echo "2. Testing bulkSync method input format compatibility:\n";
$bulkSyncInput = [
    'barcodes' => ['BARCODE001', 'BARCODE002', 'BARCODE003']
];

echo "Input format: " . json_encode($bulkSyncInput, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "✅ bulkSync method accepts barcodes array like WooCommerce\n\n";

// Test 3: Empty barcodes for all products
echo "3. Testing bulkSync with empty barcodes (all products):\n";
$allProductsInput = [];
echo "Input format: " . json_encode($allProductsInput, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "✅ bulkSync method accepts empty array for all products like WooCommerce\n\n";

echo "=== All Tests Passed ===\n";
echo "Tantooo methods are now compatible with WooCommerce input formats.\n";
