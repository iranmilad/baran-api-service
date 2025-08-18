<?php

// تست منطق shipping cost در ProcessInvoice

// تست حالت expense
echo "=== تست حالت shipping_cost_method = 'expense' ===\n";
$shippingCostMethod = 'expense';
$shippingTotal = 50000; // 50 هزار تومان
$orderTotal = 500000; // 500 هزار تومان
$itemsTotal = 450000; // 450 هزار تومان

$deliveryCost = 0;
$totalAmount = $orderTotal;

if ($shippingCostMethod === 'expense') {
    $deliveryCost = $shippingTotal;

    if (abs($orderTotal - ($itemsTotal + $shippingTotal)) < 1) {
        // total قبلاً شامل shipping است
        $totalAmount = $orderTotal;
        echo "Total شامل shipping است\n";
    } else {
        // total شامل shipping نیست
        $totalAmount = $orderTotal + $deliveryCost;
        echo "Total شامل shipping نیست، اضافه می‌کنیم\n";
    }
} else {
    $deliveryCost = 0;
    $totalAmount = $orderTotal;
}

echo "DeliveryCost: $deliveryCost\n";
echo "TotalAmount: $totalAmount\n";
echo "آیتم shipping اضافه می‌شود: " . ($shippingCostMethod === 'product' ? 'بله' : 'خیر') . "\n\n";

// تست حالت product
echo "=== تست حالت shipping_cost_method = 'product' ===\n";
$shippingCostMethod = 'product';
$shippingProductUniqueId = 'SHIPPING-001';

$deliveryCost = 0;
$totalAmount = $orderTotal;

if ($shippingCostMethod === 'expense') {
    $deliveryCost = $shippingTotal;

    if (abs($orderTotal - ($itemsTotal + $shippingTotal)) < 1) {
        $totalAmount = $orderTotal;
    } else {
        $totalAmount = $orderTotal + $deliveryCost;
    }
} else {
    $deliveryCost = 0;
    $totalAmount = $orderTotal;
}

echo "DeliveryCost: $deliveryCost\n";
echo "TotalAmount: $totalAmount\n";
echo "آیتم shipping اضافه می‌شود: " . ($shippingCostMethod === 'product' ? 'بله' : 'خیر') . "\n";
if ($shippingCostMethod === 'product' && $shippingTotal > 0) {
    echo "آیتم shipping:\n";
    echo "  - ItemId: $shippingProductUniqueId\n";
    echo "  - Price: $shippingTotal\n";
    echo "  - Quantity: 1\n";
    echo "  - NetAmount: $shippingTotal\n";
}
