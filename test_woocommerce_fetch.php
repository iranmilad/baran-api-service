<?php

echo "=== تست تابع دریافت سفارش کامل از WooCommerce ===\n";

// شبیه‌سازی پاسخ WooCommerce API
$sampleWooOrderData = [
    'id' => 12345,
    'order_key' => 'wc_order_abc123',
    'status' => 'processing',
    'currency' => 'IRR',
    'total' => '51200000',
    'shipping_total' => '2200000',
    'discount_total' => '0',
    'tax_total' => '0',
    'date_created' => '2025-08-19T14:20:18',
    'date_modified' => '2025-08-19T14:24:22',
    'payment_method' => 'WC_AsanPardakht',
    'payment_method_title' => 'آسان پرداخت',
    'customer_note' => 'لطفاً با دقت بسته‌بندی کنید',
    'billing' => [
        'first_name' => 'تبسم',
        'last_name' => 'ایلخان',
        'email' => 'test@example.com',
        'phone' => '09902847992',
        'address_1' => 'خیابان چهاباغ بالا، خ شهید امینی نژاد، بن بست کاج (۳)، انتهای بن بست، ساختما ۱۷، پلاک ۳۴',
        'address_2' => '',
        'city' => 'اصفهان',
        'state' => 'ESF',
        'postcode' => '۸۱۶۳۶۱۴۴۶۳',
        'country' => 'IR'
    ],
    'shipping' => [
        'first_name' => 'تبسم',
        'last_name' => 'ایلخان',
        'address_1' => 'خیابان چهاباغ بالا، خ شهید امینی نژاد، بن بست کاج (۳)، انتهای بن بست، ساختما ۱۷، پلاک ۳۴',
        'address_2' => '',
        'city' => 'اصفهان',
        'state' => 'ESF',
        'postcode' => '۸۱۶۳۶۱۴۴۶۳',
        'country' => 'IR'
    ],
    'line_items' => [
        [
            'id' => 1,
            'name' => 'شمعدان لاله بزرگ بی رنگ - بی رنگ',
            'product_id' => 789,
            'variation_id' => 0,
            'quantity' => 2,
            'sku' => '46861',
            'price' => 13000000,
            'total' => '26000000',
            'meta_data' => [
                [
                    'key' => '_bim_unique_id',
                    'value' => '4b51d99a-8775-4873-b0f7-3a7d7b4e23eb'
                ],
                [
                    'key' => 'some_other_meta',
                    'value' => 'test_value'
                ]
            ]
        ]
    ]
];

echo "📋 داده‌های نمونه WooCommerce:\n";
echo "   - Order ID: " . $sampleWooOrderData['id'] . "\n";
echo "   - Status: " . $sampleWooOrderData['status'] . "\n";
echo "   - Total: " . number_format($sampleWooOrderData['total']) . " تومان\n";
echo "   - Items Count: " . count($sampleWooOrderData['line_items']) . "\n\n";

// شبیه‌سازی تابع processWooCommerceOrderData
function processWooCommerceOrderData($wooOrderData) {
    // استخراج آیتم‌ها
    $items = [];
    foreach ($wooOrderData['line_items'] ?? [] as $lineItem) {
        $uniqueId = '';

        // جستجو برای unique_id در meta_data
        foreach ($lineItem['meta_data'] ?? [] as $meta) {
            if ($meta['key'] === '_bim_unique_id' || $meta['key'] === 'unique_id') {
                $uniqueId = $meta['value'];
                break;
            }
        }

        $items[] = [
            'unique_id' => $uniqueId,
            'sku' => $lineItem['sku'] ?? '',
            'quantity' => $lineItem['quantity'] ?? 1,
            'price' => (float)$lineItem['price'] ?? 0,
            'name' => $lineItem['name'] ?? '',
            'total' => (float)$lineItem['total'] ?? 0,
            'product_id' => $lineItem['product_id'] ?? 0,
            'variation_id' => $lineItem['variation_id'] ?? 0
        ];
    }

    // استخراج اطلاعات مشتری
    $customer = [
        'first_name' => $wooOrderData['billing']['first_name'] ?? '',
        'last_name' => $wooOrderData['billing']['last_name'] ?? '',
        'email' => $wooOrderData['billing']['email'] ?? null,
        'phone' => $wooOrderData['billing']['phone'] ?? '',
        'mobile' => $wooOrderData['billing']['phone'] ?? '',
        'address' => [
            'address_1' => $wooOrderData['billing']['address_1'] ?? '',
            'address_2' => $wooOrderData['billing']['address_2'] ?? '',
            'city' => $wooOrderData['billing']['city'] ?? '',
            'state' => $wooOrderData['billing']['state'] ?? '',
            'postcode' => $wooOrderData['billing']['postcode'] ?? '',
            'country' => $wooOrderData['billing']['country'] ?? ''
        ]
    ];

    return [
        'items' => $items,
        'customer' => $customer,
        'payment_method' => $wooOrderData['payment_method'] ?? '',
        'payment_method_title' => $wooOrderData['payment_method_title'] ?? '',
        'total' => $wooOrderData['total'] ?? '0',
        'shipping_total' => $wooOrderData['shipping_total'] ?? '0',
        'discount_total' => $wooOrderData['discount_total'] ?? '0',
        'tax_total' => $wooOrderData['tax_total'] ?? '0',
        'currency' => $wooOrderData['currency'] ?? 'IRR',
        'status' => $wooOrderData['status'] ?? '',
        'created_at' => $wooOrderData['date_created'] ?? '',
        'updated_at' => $wooOrderData['date_modified'] ?? '',
        'order_key' => $wooOrderData['order_key'] ?? '',
        'customer_note' => $wooOrderData['customer_note'] ?? ''
    ];
}

// تست تابع
$processedData = processWooCommerceOrderData($sampleWooOrderData);

echo "🔄 نتیجه پردازش:\n";
echo "   ✅ Items:\n";
foreach ($processedData['items'] as $index => $item) {
    echo "      " . ($index + 1) . ". " . $item['name'] . "\n";
    echo "         - Unique ID: " . $item['unique_id'] . "\n";
    echo "         - SKU: " . $item['sku'] . "\n";
    echo "         - Quantity: " . $item['quantity'] . "\n";
    echo "         - Price: " . number_format($item['price']) . " تومان\n";
    echo "         - Total: " . number_format($item['total']) . " تومان\n\n";
}

echo "   ✅ Customer:\n";
echo "      - Name: " . $processedData['customer']['first_name'] . " " . $processedData['customer']['last_name'] . "\n";
echo "      - Mobile: " . $processedData['customer']['mobile'] . "\n";
echo "      - Email: " . ($processedData['customer']['email'] ?? 'ندارد') . "\n";
echo "      - Address: " . $processedData['customer']['address']['address_1'] . "\n";
echo "      - City: " . $processedData['customer']['address']['city'] . "\n\n";

echo "   ✅ Order Details:\n";
echo "      - Payment Method: " . $processedData['payment_method'] . "\n";
echo "      - Total: " . number_format($processedData['total']) . " تومان\n";
echo "      - Shipping: " . number_format($processedData['shipping_total']) . " تومان\n";
echo "      - Status: " . $processedData['status'] . "\n";
echo "      - Currency: " . $processedData['currency'] . "\n\n";

echo "🎯 مزایای این روش:\n";
echo "   ✅ دریافت اطلاعات کامل و به‌روز از WooCommerce\n";
echo "   ✅ استخراج صحیح unique_id از meta_data\n";
echo "   ✅ اطلاعات کامل مشتری شامل آدرس‌های مختلف\n";
echo "   ✅ جزئیات پرداخت و وضعیت سفارش\n";
echo "   ✅ قابلیت دسترسی به تمام meta_data محصولات\n";

echo "\n📋 JSON نهایی:\n";
echo json_encode($processedData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

?>
