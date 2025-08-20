<?php

echo "=== تست API استعلام موجودی محصول ===\n";

// شبیه‌سازی پاسخ RainSale API
$sampleRainSaleResponse = [
    [
        "itemID" => "099a6b4b-958e-436d-ab05-004555284b3c",
        "parentID" => "985f7284-802f-443b-bf39-8e50c36db7ca",
        "itemName" => "نمکدان سرامیکی شاه عباسی",
        "salePrice" => 2850000.000,
        "currentDiscount" => 0.000,
        "barcode" => "101102299940000000",
        "stockName" => "انبار شعبه پالادیوم",
        "stockID" => "32a81f6a-dc2f-4d4a-b84c-299a0c5cddd4",
        "stockQuantity" => 2.000,
        "departmentCode" => "1010111",
        "departmentName" => "نمکدان"
    ],
    [
        "itemID" => "099a6b4b-958e-436d-ab05-004555284b3c",
        "parentID" => "985f7284-802f-443b-bf39-8e50c36db7ca",
        "itemName" => "نمکدان سرامیکی شاه عباسی",
        "salePrice" => 2850000.000,
        "currentDiscount" => 0.000,
        "barcode" => "101102299940000000",
        "stockName" => "انبار محصول",
        "stockID" => "e9a28650-6b25-481a-967a-4a1ddaafaf90", // این انبار پیش‌فرض است
        "stockQuantity" => 8.000,
        "departmentCode" => "1010111",
        "departmentName" => "نمکدان"
    ],
    [
        "itemID" => "099a6b4b-958e-436d-ab05-004555284b3c",
        "parentID" => "985f7284-802f-443b-bf39-8e50c36db7ca",
        "itemName" => "نمکدان سرامیکی شاه عباسی",
        "salePrice" => 2850000.000,
        "currentDiscount" => 0.000,
        "barcode" => "101102299940000000",
        "stockName" => "انبار جدید شعبه روشا",
        "stockID" => "75e80bf4-a627-42b8-9119-61dd7a2e0bdd",
        "stockQuantity" => 4.000,
        "departmentCode" => "1010111",
        "departmentName" => "نمکدان"
    ]
];

// شبیه‌سازی تابع findProductInDefaultWarehouse
function findProductInDefaultWarehouse($productsData, $uniqueId, $defaultWarehouseCode) {
    foreach ($productsData as $product) {
        if (isset($product['itemID']) &&
            strtolower($product['itemID']) === strtolower($uniqueId) &&
            isset($product['stockID']) &&
            $product['stockID'] === $defaultWarehouseCode) {

            return $product;
        }
    }
    return null;
}

// متغیرهای تست
$uniqueId = "099a6b4b-958e-436d-ab05-004555284b3c";
$defaultWarehouseCode = "e9a28650-6b25-481a-967a-4a1ddaafaf90"; // انبار محصول

echo "📋 اطلاعات تست:\n";
echo "   - Unique ID: " . $uniqueId . "\n";
echo "   - Default Warehouse Code: " . $defaultWarehouseCode . "\n";
echo "   - تعداد محصولات در پاسخ: " . count($sampleRainSaleResponse) . "\n\n";

// تست 1: محصول در انبار پیش‌فرض موجود است
echo "🧪 تست 1: محصول در انبار پیش‌فرض موجود\n";
$targetProduct = findProductInDefaultWarehouse($sampleRainSaleResponse, $uniqueId, $defaultWarehouseCode);

if ($targetProduct) {
    echo "   ✅ محصول پیدا شد!\n";
    echo "   📦 نام محصول: " . $targetProduct['itemName'] . "\n";
    echo "   🏪 نام انبار: " . $targetProduct['stockName'] . "\n";
    echo "   📊 موجودی: " . $targetProduct['stockQuantity'] . "\n";
    echo "   💰 قیمت فروش: " . number_format($targetProduct['salePrice']) . " تومان\n";
    echo "   🏷️ بارکد: " . $targetProduct['barcode'] . "\n\n";
} else {
    echo "   ❌ محصول در انبار پیش‌فرض پیدا نشد\n\n";
}

// تست 2: محصول در انبار پیش‌فرض موجود نیست
echo "🧪 تست 2: محصول در انبار پیش‌فرض موجود نیست\n";
$wrongWarehouseCode = "wrong-warehouse-id";
$targetProduct2 = findProductInDefaultWarehouse($sampleRainSaleResponse, $uniqueId, $wrongWarehouseCode);

if (!$targetProduct2) {
    echo "   ✅ محصول در انبار پیش‌فرض پیدا نشد (انتظار می‌رفت)\n";
    echo "   📋 انبارهای موجود:\n";

    foreach ($sampleRainSaleResponse as $product) {
        if (strtolower($product['itemID']) === strtolower($uniqueId)) {
            echo "      - " . $product['stockName'] . " (ID: " . $product['stockID'] . ") - موجودی: " . $product['stockQuantity'] . "\n";
        }
    }
    echo "\n";
}

// تست 3: نمونه JSON Response
echo "🔧 نمونه JSON Response:\n";
if ($targetProduct) {
    $response = [
        'success' => true,
        'data' => [
            'unique_id' => $uniqueId,
            'item_id' => $targetProduct['itemID'],
            'item_name' => $targetProduct['itemName'],
            'stock_id' => $targetProduct['stockID'],
            'stock_name' => $targetProduct['stockName'],
            'stock_quantity' => $targetProduct['stockQuantity'],
            'sale_price' => $targetProduct['salePrice'],
            'current_discount' => $targetProduct['currentDiscount'],
            'barcode' => $targetProduct['barcode'],
            'department_code' => $targetProduct['departmentCode'],
            'department_name' => $targetProduct['departmentName']
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

echo "\n\n📡 نحوه استفاده از API:\n";
echo "POST /api/v1/products/stock\n";
echo "Authorization: Bearer {JWT_TOKEN}\n";
echo "Content-Type: application/json\n\n";
echo "Body:\n";
echo "{\n";
echo '    "unique_id": "099a6b4b-958e-436d-ab05-004555284b3c"' . "\n";
echo "}\n\n";

echo "🎯 مزایای این API:\n";
echo "   ✅ دریافت موجودی از انبار پیش‌فرض\n";
echo "   ✅ در صورت عدم وجود، نمایش انبارهای موجود\n";
echo "   ✅ اطلاعات کامل محصول (قیمت، بارکد، دپارتمان)\n";
echo "   ✅ احراز هویت با JWT\n";
echo "   ✅ مدیریت خطا و لاگ‌گذاری\n";

?>
