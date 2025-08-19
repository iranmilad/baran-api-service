<?php

echo "=== بررسی کد ProcessInvoice برای محاسبه Payment ===\n";

// شبیه‌سازی داده‌های واقعی از خطا
$invoiceData = [
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

// متغیرهای تنظیمات
$shippingCostMethods = ['expense', 'product'];

foreach ($shippingCostMethods as $shippingCostMethod) {
    echo "\n🔧 روش حمل و نقل: $shippingCostMethod\n";

    $orderTotal = (float)$invoiceData['total'];
    $shippingTotal = (float)$invoiceData['shipping_total'];

    // محاسبه itemsTotal
    $itemsTotal = 0;
    foreach ($invoiceData['items'] as $item) {
        $itemsTotal += (float)$item['total'];
    }

    // محاسبه مبلغ کل و DeliveryCost بر اساس روش حمل و نقل
    $deliveryCost = 0;

    if ($shippingCostMethod === 'expense') {
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

    echo "   📊 نتایج محاسبه:\n";
    echo "   - Items Total: " . number_format($itemsTotal) . " تومان\n";
    echo "   - Shipping Total: " . number_format($shippingTotal) . " تومان\n";
    echo "   - Delivery Cost: " . number_format($deliveryCost) . " تومان\n";
    echo "   - Payment Amount: " . number_format($totalAmount) . " تومان\n";

    // بررسی با خطای RainSale
    if ($totalAmount == 28200000) {
        echo "   ✅ صحیح - مطابق با انتظار RainSale (28,200,000)\n";
    } elseif ($totalAmount == 53400000) {
        echo "   ❌ نادرست - همان خطای قبلی (53,400,000)\n";
    } else {
        echo "   ⚠️  مقدار غیرمنتظره: " . number_format($totalAmount) . "\n";
    }
}

echo "\n🔍 بررسی خطای ارسالی شما:\n";
echo "   - Items در request: 26,000,000 (صحیح)\n";
echo "   - DeliveryCost در request: 2,200,000 (صحیح)\n";
echo "   - Payment Amount در request: 53,400,000 (نادرست - باید 28,200,000 باشد)\n";

echo "\n❓ دلایل احتمالی:\n";
echo "   1. کش PHP/Laravel\n";
echo "   2. Job قدیمی در صف\n";
echo "   3. کد قدیمی هنوز در حال اجرا\n";
echo "   4. تنظیمات shipping_cost_method نادرست\n";

echo "\n🔧 راه‌حل‌های پیشنهادی:\n";
echo "   1. پاک کردن کش: php artisan cache:clear\n";
echo "   2. ری‌استارت صف: php artisan queue:restart\n";
echo "   3. بررسی تنظیمات کاربر\n";

?>
