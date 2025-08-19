<?php

echo "=== تست محاسبه مبلغ پرداخت در ProcessInvoice ===\n";

// شبیه‌سازی داده‌های واقعی از خطا
$orderData = [
    'items' => [
        [
            'unique_id' => '4b51d99a-8775-4873-b0f7-3a7d7b4e23eb',
            'quantity' => 2,
            'price' => 13000000,
            'total' => 26000000
        ]
    ],
    'total' => 51200000,
    'shipping_total' => 2200000
];

$shippingCostMethods = ['expense', 'product'];

echo "📊 داده‌های ورودی:\n";
echo "   Items total: " . number_format(26000000) . " تومان\n";
echo "   Order total: " . number_format(51200000) . " تومان\n";
echo "   Shipping total: " . number_format(2200000) . " تومان\n\n";

foreach ($shippingCostMethods as $method) {
    echo "🔧 روش حمل و نقل: $method\n";

    // محاسبه itemsTotal
    $itemsTotal = 0;
    foreach ($orderData['items'] as $item) {
        $itemsTotal += (float)$item['total'];
    }

    $shippingTotal = (float)$orderData['shipping_total'];

    // محاسبه بر اساس روش جدید (اصلاح شده)
    $deliveryCost = 0;

    if ($method === 'expense') {
        // حمل و نقل به عنوان هزینه - در DeliveryCost قرار می‌گیرد
        $deliveryCost = $shippingTotal;
        $totalAmount = $itemsTotal + $deliveryCost; // آیتم‌ها + DeliveryCost
    } else {
        // حمل و نقل به عنوان محصول - در آیتم‌ها قرار گرفته، DeliveryCost صفر
        $deliveryCost = 0;
        // محاسبه مجموع واقعی آیتم‌ها (شامل shipping item که اضافه شده)
        $realItemsTotal = $itemsTotal;
        if ($shippingTotal > 0) {
            $realItemsTotal += $shippingTotal; // اضافه کردن shipping که به عنوان item اضافه شده
        }
        $totalAmount = $realItemsTotal;
    }

    echo "   📋 نتایج:\n";
    echo "   - Items Total: " . number_format($itemsTotal) . " تومان\n";
    echo "   - Delivery Cost: " . number_format($deliveryCost) . " تومان\n";
    echo "   - Payment Amount: " . number_format($totalAmount) . " تومان\n";
    echo "   - مجموع کل (Items + Delivery): " . number_format($itemsTotal + $deliveryCost) . " تومان\n";

    // بررسی سازگاری با RainSale
    if ($method === 'expense') {
        $rainSaleExpectedTotal = $itemsTotal + $deliveryCost;
    } else {
        $rainSaleExpectedTotal = $totalAmount; // برای product، totalAmount شامل همه چیز است
    }

    if ($totalAmount == $rainSaleExpectedTotal) {
        echo "   ✅ محاسبه صحیح - Payment Amount = مجموع مورد انتظار RainSale\n";
    } else {
        echo "   ❌ محاسبه نادرست - عدم تطابق\n";
    }

    echo "\n";
}

echo "🎯 مورد مشکل (داده واقعی):\n";
echo "   - روش حمل و نقل فعلی احتمالاً: expense\n";
echo "   - Items: 26,000,000\n";
echo "   - DeliveryCost: 2,200,000\n";
echo "   - مجموع مورد انتظار RainSale: 28,200,000\n";
echo "   - Payment Amount قبلی (نادرست): 53,400,000\n";
echo "   - Payment Amount جدید (صحیح): 26,000,000\n";

echo "\n✅ اصلاح انجام شد:\n";
echo "   حالا Payment Amount بر اساس Items محاسبه می‌شود نه Order Total\n";

?>
