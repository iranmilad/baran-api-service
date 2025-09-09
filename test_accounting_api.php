<?php

/**
 * تست API حسابداری
 * این فایل نمونه‌ای از چگونگی استفاده از AccountingApiTrait است
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Traits\AccountingApiTrait;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// کلاس تست برای نمایش استفاده از trait
class AccountingApiTest
{
    use AccountingApiTrait;

    public function testSingleProduct()
    {
        echo "تست به‌روزرسانی یک محصول در API حسابداری:\n";
        echo "===============================================\n";

        // تنظیمات API
        $apiUrl = 'https://03535.ir/accounting_api';
        $apiKey = 'f3a7c8e45d912b6a19e6f2e7b0843c9d';
        $bearerToken = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpZF9zaCI6LTEsInVzZXJfdHlwZSI6LTIsImlhdCI6MTc1NzA4MjU3MiwiZXhwIjoxNzU3MTY4OTcyfQ.9JHeCb8QS37EFMC2jJVq0Y-XYLwA-9xZFvOeB7PH2UI';

        // اطلاعات محصول
        $code = 'کد یکتا';
        $title = 'دامن طرح دار نخی 188600';
        $price = 1791000;
        $discount = 2;

        // فراخوانی تابع به‌روزرسانی
        $result = $this->updateProductInAccountingApi(
            $code,
            $title,
            $price,
            $discount,
            $apiUrl,
            $apiKey,
            $bearerToken
        );

        // نمایش نتیجه
        if ($result['success']) {
            echo "✅ محصول با موفقیت به‌روزرسانی شد\n";
            echo "پیام: " . $result['message'] . "\n";
            if (isset($result['data'])) {
                echo "پاسخ API: " . json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            }
        } else {
            echo "❌ خطا در به‌روزرسانی محصول\n";
            echo "پیام خطا: " . $result['message'] . "\n";
            if (isset($result['error_details'])) {
                echo "جزئیات خطا: " . $result['error_details'] . "\n";
            }
        }

        echo "\n";
    }

    public function testMultipleProducts()
    {
        echo "تست به‌روزرسانی چندین محصول در API حسابداری:\n";
        echo "==============================================\n";

        // تنظیمات API
        $apiUrl = 'https://03535.ir/accounting_api';
        $apiKey = 'f3a7c8e45d912b6a19e6f2e7b0843c9d';
        $bearerToken = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpZF9zaCI6LTEsInVzZXJfdHlwZSI6LTIsImlhdCI6MTc1NzA4MjU3MiwiZXhwIjoxNzU3MTY4OTcyfQ.9JHeCb8QS37EFMC2jJVq0Y-XYLwA-9xZFvOeB7PH2UI';

        // آرایه محصولات
        $products = [
            [
                'code' => 'PROD001',
                'title' => 'محصول شماره یک',
                'price' => 150000,
                'discount' => 5
            ],
            [
                'code' => 'PROD002',
                'title' => 'محصول شماره دو',
                'price' => 250000,
                'discount' => 10
            ],
            [
                'code' => 'PROD003',
                'title' => 'محصول شماره سه',
                'price' => 350000,
                'discount' => 0
            ]
        ];

        // فراخوانی تابع به‌روزرسانی چندین محصول
        $result = $this->updateMultipleProductsInAccountingApi(
            $products,
            $apiUrl,
            $apiKey,
            $bearerToken
        );

        // نمایش نتیجه
        echo "نتیجه کلی: " . ($result['success'] ? '✅ موفق' : '❌ ناموفق') . "\n";
        echo "تعداد کل محصولات: " . $result['total_products'] . "\n";
        echo "تعداد موفق: " . $result['success_count'] . "\n";
        echo "تعداد ناموفق: " . $result['failure_count'] . "\n\n";

        // نمایش جزئیات هر محصول
        echo "جزئیات محصولات:\n";
        foreach ($result['results'] as $code => $productResult) {
            echo "محصول {$code}: " . ($productResult['success'] ? '✅' : '❌') . " " . $productResult['message'] . "\n";
        }

        echo "\n";
    }

    public function testPriceConversion()
    {
        echo "تست تبدیل واحد قیمت:\n";
        echo "====================\n";

        // شبیه‌سازی اطلاعات محصول از انبار
        $productInfo = [
            'code' => 'TEST001',
            'name' => 'محصول تست',
            'sellPrice' => 1000000 // قیمت به ریال
        ];

        // شبیه‌سازی تنظیمات کاربر
        $userSettings = (object) [
            'rain_sale_price_unit' => 'rial',
            'accounting_price_unit' => 'toman'
        ];

        // تبدیل محصول
        $convertedProduct = $this->convertProductForAccountingApi($productInfo, $userSettings);

        echo "قیمت اصلی (ریال): " . number_format($productInfo['sellPrice']) . "\n";
        echo "قیمت تبدیل شده (تومان): " . number_format($convertedProduct['price']) . "\n";
        echo "کد محصول: " . $convertedProduct['code'] . "\n";
        echo "نام محصول: " . $convertedProduct['title'] . "\n";

        echo "\n";
    }

    public function testDiscountCalculation()
    {
        echo "تست محاسبه تخفیف:\n";
        echo "==================\n";

        $originalPrice = 1000000;
        $discountPercent = 15;

        $discountedPrice = $this->calculateDiscountedPrice($originalPrice, $discountPercent);

        echo "قیمت اصلی: " . number_format($originalPrice) . " تومان\n";
        echo "درصد تخفیف: {$discountPercent}%\n";
        echo "قیمت با تخفیف: " . number_format($discountedPrice) . " تومان\n";
        echo "مبلغ تخفیف: " . number_format($originalPrice - $discountedPrice) . " تومان\n";

        echo "\n";
    }
}

// اجرای تست‌ها
echo "=== تست AccountingApiTrait ===\n\n";

$test = new AccountingApiTest();

// تست محصول تکی
$test->testSingleProduct();

// تست چندین محصول
$test->testMultipleProducts();

// تست تبدیل قیمت
$test->testPriceConversion();

// تست محاسبه تخفیف
$test->testDiscountCalculation();

echo "=== پایان تست‌ها ===\n";
