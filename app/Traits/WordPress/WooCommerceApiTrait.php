<?php

namespace App\Traits\WordPress;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\WooCommerceApiKey;

trait WooCommerceApiTrait
{
    /**
     * به‌روزرسانی محصولات در WooCommerce
     *
     * @param object $license لایسنس
     * @param array $foundProducts محصولات یافت شده از انبار
     * @param object $userSettings تنظیمات کاربر
     * @return array نتیجه به‌روزرسانی
     */
    protected function updateWooCommerceProducts($license, $foundProducts, $userSettings)
    {
        try {
            // بررسی تنظیمات enable_stock_update
            if (!$userSettings->enable_stock_update) {
                return [
                    'success' => false,
                    'message' => 'به‌روزرسانی موجودی غیرفعال است',
                    'updated_count' => 0
                ];
            }

            // دریافت اطلاعات WooCommerce API
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                return [
                    'success' => false,
                    'message' => 'اطلاعات API وردپرس تنظیم نشده است',
                    'updated_count' => 0
                ];
            }

            // تبدیل محصولات به فرمت وردپرس
            $wordpressProducts = [];
            foreach ($foundProducts as $product) {
                $productInfo = $product['product_info'];
                $defaultWarehouseStock = $product['default_warehouse_stock'];

                // محاسبه موجودی و وضعیت موجودی
                $stockQuantity = 0;
                $stockStatus = 'outofstock';

                if ($defaultWarehouseStock && isset($defaultWarehouseStock['quantity'])) {
                    $stockQuantity = (int) $defaultWarehouseStock['quantity'];
                    $stockStatus = $stockQuantity > 0 ? 'instock' : 'outofstock';
                }

                // محاسبه قیمت با در نظر گیری واحد قیمت
                $regularPrice = '0';
                if (isset($productInfo['sellPrice'])) {
                    $price = (float) $productInfo['sellPrice'];

                    // تبدیل واحد قیمت از RainSale به WooCommerce
                    if ($userSettings->rain_sale_price_unit && $userSettings->woocommerce_price_unit) {
                        $rainSaleUnit = $userSettings->rain_sale_price_unit;
                        $wooCommerceUnit = $userSettings->woocommerce_price_unit;

                        // تبدیل قیمت بر اساس واحد
                        if ($rainSaleUnit === 'rial' && $wooCommerceUnit === 'toman') {
                            $price = $price / 10; // ریال به تومان
                        } elseif ($rainSaleUnit === 'toman' && $wooCommerceUnit === 'rial') {
                            $price = $price * 10; // تومان به ریال
                        }
                    }

                    $regularPrice = (string) $price;
                }

                $wordpressProduct = [
                    'unique_id' => $product['unique_id'],
                    'sku' => $productInfo['code'] ?? '',
                    'regular_price' => $regularPrice,
                    'stock_quantity' => $stockQuantity,
                    'manage_stock' => true,
                    'stock_status' => $stockStatus
                ];

                // اضافه کردن product_id اگر وجود دارد
                if (isset($productInfo['product_id'])) {
                    $wordpressProduct['product_id'] = $productInfo['product_id'];
                }

                $wordpressProducts[] = $wordpressProduct;
            }

            // ارسال درخواست به وردپرس
            $wordpressUrl = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products/unique/batch/update';

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 120,
                'connect_timeout' => 30
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($wooApiKey->api_key . ':' . $wooApiKey->api_secret)
            ])->put($wordpressUrl, [
                'products' => $wordpressProducts
            ]);

            if ($response->successful()) {
                $responseData = $response->json();

                Log::info('محصولات با موفقیت در وردپرس به‌روزرسانی شدند', [
                    'license_id' => $license->id,
                    'updated_count' => count($wordpressProducts),
                    'wordpress_response' => $responseData
                ]);

                return [
                    'success' => true,
                    'message' => 'محصولات با موفقیت به‌روزرسانی شدند',
                    'updated_count' => count($wordpressProducts),
                    'wordpress_response' => $responseData
                ];
            } else {
                Log::error('خطا در به‌روزرسانی محصولات وردپرس', [
                    'license_id' => $license->id,
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'url' => $wordpressUrl
                ]);

                return [
                    'success' => false,
                    'message' => 'خطا در به‌روزرسانی وردپرس: ' . $response->status(),
                    'updated_count' => 0,
                    'error_details' => $response->body()
                ];
            }

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی محصولات وردپرس', [
                'license_id' => $license->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطای سیستمی در به‌روزرسانی وردپرس',
                'updated_count' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * دریافت تمام محصولات از WooCommerce API
     *
     * @param object $woocommerce instance WooCommerce API
     * @param int $perPage تعداد محصولات در هر صفحه
     * @return array لیست تمام محصولات
     */
    protected function getAllWooCommerceProducts($woocommerce, $perPage = 100)
    {
        try {
            $allProducts = [];
            $page = 1;

            do {
                $products = $woocommerce->get('products', [
                    'per_page' => $perPage,
                    'page' => $page,
                    'status' => 'publish'
                ]);

                if (!empty($products)) {
                    $allProducts = array_merge($allProducts, $products);
                    $page++;
                } else {
                    break;
                }
            } while (count($products) === $perPage);

            return $allProducts;

        } catch (\Exception $e) {
            Log::error('خطا در دریافت محصولات از WooCommerce', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * دریافت دسته‌بندی‌های WooCommerce
     *
     * @param object $license لایسنس
     * @param WooCommerceApiKey $wooApiKey کلید API WooCommerce
     * @return array لیست دسته‌بندی‌ها
     */
    protected function fetchWooCommerceCategories($license, WooCommerceApiKey $wooApiKey)
    {

        try {
            $url = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products/categories';

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30
            ])->withHeaders([
                'Authorization' => 'Basic ' . base64_encode($wooApiKey->api_key . ':' . $wooApiKey->api_secret)
            ])->get($url, [
                'per_page' => 100,
                'hide_empty' => false
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('خطا در دریافت دسته‌بندی‌های WooCommerce', [
                'license_id' => $license->id,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return [];

        } catch (\Exception $e) {
            Log::error('خطا در دریافت دسته‌بندی‌های WooCommerce', [
                'license_id' => $license->id,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * بررسی وجود محصول در WooCommerce بر اساس unique_id
     *
     * @param object $license لایسنس
     * @param WooCommerceApiKey $wooApiKey کلید API
     * @param array $product اطلاعات محصول
     * @return bool|array false یا اطلاعات محصول
     */
    protected function checkProductExistsInWooCommerce($license, $wooApiKey, $product)
    {
        try {
            $websiteUrl = rtrim($license->website_url, '/');
            $uniqueId = $product['unique_id'] ?? null;

            if (!$uniqueId) {
                return false;
            }

            // جستجو در محصولات ساده
            $url = $websiteUrl . '/wp-json/wc/v3/products';
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30
            ])->withHeaders([
                'Authorization' => 'Basic ' . base64_encode($wooApiKey->api_key . ':' . $wooApiKey->api_secret)
            ])->get($url, [
                'meta_key' => 'unique_id',
                'meta_value' => $uniqueId,
                'per_page' => 1
            ]);

            if ($response->successful()) {
                $products = $response->json();
                if (!empty($products)) {
                    return $products[0];
                }
            }

            // جستجو در variations
            $variationUrl = $websiteUrl . '/wp-json/wc/v3/products/variations/search';
            $variationResponse = Http::withOptions([
                'verify' => false,
                'timeout' => 30
            ])->withHeaders([
                'Authorization' => 'Basic ' . base64_encode($wooApiKey->api_key . ':' . $wooApiKey->api_secret)
            ])->get($variationUrl, [
                'unique_id' => $uniqueId
            ]);

            if ($variationResponse->successful()) {
                $variations = $variationResponse->json();
                if (!empty($variations)) {
                    return $variations[0];
                }
            }

            return false;

        } catch (\Exception $e) {
            Log::error('خطا در بررسی وجود محصول در WooCommerce', [
                'unique_id' => $uniqueId ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * دریافت سفارش کامل از WooCommerce
     *
     * @param object $license لایسنس
     * @param WooCommerceApiKey $wooApiKey کلید API
     * @param int $orderId شناسه سفارش
     * @return array|null اطلاعات سفارش
     */
    protected function fetchCompleteOrderFromWooCommerce($license, $wooApiKey, $orderId)
    {
        try {
            $websiteUrl = rtrim($license->website_url, '/');
            $url = $websiteUrl . '/wp-json/wc/v3/orders/' . $orderId;

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30
            ])->withHeaders([
                'Authorization' => 'Basic ' . base64_encode($wooApiKey->api_key . ':' . $wooApiKey->api_secret)
            ])->get($url);

            if ($response->successful()) {
                return $response->json();
            }

            Log::error('خطا در دریافت سفارش از WooCommerce', [
                'license_id' => $license->id,
                'order_id' => $orderId,
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error('خطا در دریافت سفارش از WooCommerce', [
                'license_id' => $license->id,
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * پردازش داده‌های سفارش WooCommerce
     *
     * @param array $wooOrderData داده‌های سفارش از WooCommerce
     * @return array داده‌های پردازش شده
     */
    protected function processWooCommerceOrderData($wooOrderData)
    {
        try {
            $processedData = [
                'order_id' => $wooOrderData['id'] ?? null,
                'order_number' => $wooOrderData['number'] ?? null,
                'status' => $wooOrderData['status'] ?? null,
                'total' => $wooOrderData['total'] ?? 0,
                'currency' => $wooOrderData['currency'] ?? 'IRR',
                'date_created' => $wooOrderData['date_created'] ?? null,
                'customer' => [
                    'id' => $wooOrderData['customer_id'] ?? 0,
                    'email' => $wooOrderData['billing']['email'] ?? null,
                    'first_name' => $wooOrderData['billing']['first_name'] ?? null,
                    'last_name' => $wooOrderData['billing']['last_name'] ?? null,
                    'phone' => $wooOrderData['billing']['phone'] ?? null,
                ],
                'billing' => $wooOrderData['billing'] ?? [],
                'shipping' => $wooOrderData['shipping'] ?? [],
                'line_items' => [],
                'shipping_lines' => $wooOrderData['shipping_lines'] ?? [],
                'tax_lines' => $wooOrderData['tax_lines'] ?? [],
                'coupon_lines' => $wooOrderData['coupon_lines'] ?? [],
                'payment_method' => $wooOrderData['payment_method'] ?? null,
                'payment_method_title' => $wooOrderData['payment_method_title'] ?? null
            ];

            // پردازش آیتم‌های سفارش
            if (isset($wooOrderData['line_items']) && is_array($wooOrderData['line_items'])) {
                foreach ($wooOrderData['line_items'] as $item) {
                    $processedData['line_items'][] = [
                        'id' => $item['id'] ?? null,
                        'name' => $item['name'] ?? null,
                        'product_id' => $item['product_id'] ?? null,
                        'variation_id' => $item['variation_id'] ?? null,
                        'quantity' => $item['quantity'] ?? 0,
                        'price' => $item['price'] ?? 0,
                        'total' => $item['total'] ?? 0,
                        'subtotal' => $item['subtotal'] ?? 0,
                        'sku' => $item['sku'] ?? null,
                        'meta_data' => $item['meta_data'] ?? []
                    ];
                }
            }

            return $processedData;

        } catch (\Exception $e) {
            Log::error('خطا در پردازش داده‌های سفارش WooCommerce', [
                'error' => $e->getMessage(),
                'order_data' => $wooOrderData
            ]);
            return [];
        }
    }

    /**
     * دریافت تمام کدهای محصولات (barcodes) از WooCommerce
     *
     * @param object $license لایسنس
     * @param WooCommerceApiKey $wooApiKey کلید API
     * @param int $startTime زمان شروع
     * @param int $maxExecutionTime حداکثر زمان اجرا
     * @return array لیست کدهای محصولات
     */
    protected function getAllProductBarcodes($license, $wooApiKey, $startTime, $maxExecutionTime)
    {
        try {
            $barcodes = [];
            $page = 1;
            $perPage = 100;

            do {
                // بررسی زمان اجرا
                if (time() - $startTime > $maxExecutionTime) {
                    Log::warning('زمان اجرا به حداکثر رسید در دریافت کدهای محصولات', [
                        'collected_count' => count($barcodes)
                    ]);
                    break;
                }

                $websiteUrl = rtrim($license->website_url, '/');
                $url = $websiteUrl . '/wp-json/wc/v3/products';

                $response = Http::withOptions([
                    'verify' => false,
                    'timeout' => 30
                ])->withHeaders([
                    'Authorization' => 'Basic ' . base64_encode($wooApiKey->api_key . ':' . $wooApiKey->api_secret)
                ])->get($url, [
                    'page' => $page,
                    'per_page' => $perPage,
                    'status' => 'publish'
                ]);

                if (!$response->successful()) {
                    Log::error('خطا در دریافت محصولات از WooCommerce', [
                        'page' => $page,
                        'status' => $response->status()
                    ]);
                    break;
                }

                $products = $response->json();

                if (empty($products)) {
                    break;
                }

                foreach ($products as $product) {
                    if (!empty($product['sku'])) {
                        $barcodes[] = $product['sku'];
                    }
                }

                $page++;

            } while (count($products) === $perPage);

            return array_unique($barcodes);

        } catch (\Exception $e) {
            Log::error('خطا در دریافت کدهای محصولات از WooCommerce', [
                'license_id' => $license->id,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * به‌روزرسانی دسته‌ای محصولات در WooCommerce
     *
     * @param object $woocommerce instance WooCommerce API
     * @param array $products لیست محصولات برای به‌روزرسانی
     * @return array نتیجه به‌روزرسانی
     */
    protected function updateWooCommerceProductsBatch($woocommerce, $products)
    {
        try {
            $batchData = ['update' => $products];

            $result = $woocommerce->post('products/batch', $batchData);

            Log::info('به‌روزرسانی دسته‌ای محصولات WooCommerce انجام شد', [
                'products_count' => count($products),
                'result' => $result
            ]);

            return [
                'success' => true,
                'updated_count' => count($products),
                'result' => $result
            ];

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی دسته‌ای محصولات WooCommerce', [
                'products_count' => count($products),
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'updated_count' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * حذف unique_ids نامعتبر از WooCommerce
     *
     * @param object $license لایسنس
     * @param WooCommerceApiKey $wooApiKey کلید API
     * @param array $invalidUniqueIds لیست unique_ids نامعتبر
     * @return bool نتیجه حذف
     */
    protected function deleteInvalidUniqueIds($license, $wooApiKey, $invalidUniqueIds)
    {
        try {
            $websiteUrl = rtrim($license->website_url, '/');

            foreach ($invalidUniqueIds as $uniqueId) {
                // حذف meta_key از محصولات
                $deleteUrl = $websiteUrl . '/wp-json/wc/v3/products/meta/delete';

                Http::withOptions([
                    'verify' => false,
                    'timeout' => 30
                ])->withHeaders([
                    'Authorization' => 'Basic ' . base64_encode($wooApiKey->api_key . ':' . $wooApiKey->api_secret)
                ])->delete($deleteUrl, [
                    'meta_key' => 'unique_id',
                    'meta_value' => $uniqueId
                ]);
            }

            Log::info('unique_ids نامعتبر از WooCommerce حذف شدند', [
                'license_id' => $license->id,
                'deleted_count' => count($invalidUniqueIds)
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('خطا در حذف unique_ids نامعتبر از WooCommerce', [
                'license_id' => $license->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * تبدیل اطلاعات محصول برای WooCommerce
     *
     * @param array $productInfo اطلاعات محصول از انبار
     * @param object $userSettings تنظیمات کاربر
     * @return array اطلاعات محصول برای WooCommerce
     */
    protected function convertProductForWooCommerce($productInfo, $userSettings = null)
    {
        $code = $productInfo['code'] ?? $productInfo['uniqueId'] ?? '';
        $name = $productInfo['name'] ?? $productInfo['title'] ?? '';
        $price = $productInfo['sellPrice'] ?? $productInfo['price'] ?? 0;
        $stockQuantity = $productInfo['quantity'] ?? 0;

        // تبدیل واحد قیمت اگر نیاز باشد
        if ($userSettings && isset($userSettings->rain_sale_price_unit) && isset($userSettings->woocommerce_price_unit)) {
            $rainSaleUnit = $userSettings->rain_sale_price_unit;
            $wooCommerceUnit = $userSettings->woocommerce_price_unit;

            if ($rainSaleUnit === 'rial' && $wooCommerceUnit === 'toman') {
                $price = $price / 10; // ریال به تومان
            } elseif ($rainSaleUnit === 'toman' && $wooCommerceUnit === 'rial') {
                $price = $price * 10; // تومان به ریال
            }
        }

        return [
            'sku' => $code,
            'name' => $name,
            'regular_price' => (string) $price,
            'stock_quantity' => (int) $stockQuantity,
            'manage_stock' => true,
            'stock_status' => $stockQuantity > 0 ? 'instock' : 'outofstock',
            'meta_data' => [
                [
                    'key' => 'unique_id',
                    'value' => $productInfo['uniqueId'] ?? $code
                ]
            ]
        ];
    }

    /**
     * دریافت تنظیمات WooCommerce API از لایسنس
     *
     * @param object $license لایسنس
     * @return array|null تنظیمات API
     */
    protected function getWooCommerceApiSettings($license)
    {
        try {
            $wooApiKey = $license->woocommerceApiKey;

            if (!$wooApiKey) {
                return null;
            }

            return [
                'api_key' => $wooApiKey->api_key,
                'api_secret' => $wooApiKey->api_secret,
                'website_url' => $license->website_url
            ];

        } catch (\Exception $e) {
            Log::error('خطا در دریافت تنظیمات WooCommerce API', [
                'license_id' => $license->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * تست اتصال ووکامرس با کلید و رمز API مشخص
     *
     * @param string $siteUrl آدرس سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @return array نتیجه تست اتصال
     */
    protected function validateWooCommerceApiCredentials($siteUrl, $apiKey, $apiSecret)
    {
        try {
            $response = Http::withBasicAuth($apiKey, $apiSecret)
                ->timeout(30)
                ->get($siteUrl . '/wp-json/wc/v3/system_status');

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در اتصال به ووکامرس',
                    'error_code' => $response->status(),
                    'error_body' => $response->body()
                ];
            }

            $systemStatus = $response->json();

            return [
                'success' => true,
                'message' => 'اتصال به ووکامرس برقرار است',
                'system_status' => [
                    'woocommerce_version' => $systemStatus['woocommerce_version'] ?? null,
                    'wordpress_version' => $systemStatus['wordpress_version'] ?? null,
                    'php_version' => $systemStatus['php_version'] ?? null,
                    'database_version' => $systemStatus['database_version'] ?? null
                ]
            ];

        } catch (\Exception $e) {
            Log::error('خطا در تست اتصال ووکامرس', [
                'site_url' => $siteUrl,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در ارتباط با ووکامرس: ' . $e->getMessage()
            ];
        }
    }

    /**
     * دریافت تمام barcodes محصولات از WooCommerce
     *
     * @param object $license لایسنس
     * @return array آرایه barcodes
     */
    protected function getAllWooCommerceProductBarcodes($license)
    {
        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                Log::error('کلید API ووکامرس یافت نشد', [
                    'license_id' => $license->id
                ]);
                return [];
            }

            $response = Http::withBasicAuth($wooApiKey->api_key, $wooApiKey->api_secret)
                ->timeout(25)
                ->get($license->website_url . '/wp-json/wc/v3/products/unique');

            if (!$response->successful()) {
                Log::error('خطا در دریافت محصولات از ووکامرس', [
                    'license_id' => $license->id,
                    'status' => $response->status()
                ]);
                return [];
            }

            $responseData = $response->json();

            if (!isset($responseData['success']) || !$responseData['success'] || !isset($responseData['data'])) {
                Log::error('پاسخ نامعتبر از API ووکامرس', [
                    'license_id' => $license->id
                ]);
                return [];
            }

            // استخراج barcodes
            $barcodes = [];
            foreach ($responseData['data'] as $product) {
                if (!empty($product->barcode)) {
                    $barcodes[] = $product->barcode;
                }
            }

            Log::info('barcodes دریافت شد از WooCommerce', [
                'license_id' => $license->id,
                'count' => count($barcodes)
            ]);

            return $barcodes;

        } catch (\Exception $e) {
            Log::error('خطا در دریافت محصولات از WooCommerce: ' . $e->getMessage(), [
                'license_id' => $license->id
            ]);
            return [];
        }
    }

    /**
     * بررسی وجود محصولات در ووکامرس بر اساس unique_ids
     *
     * @param object $license لایسنس
     * @param array $uniqueIds آرایه شناسه‌های یکتا
     * @return array نتیجه بررسی
     */
    protected function checkWooCommerceProductsExistence($license, $uniqueIds)
    {
        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                throw new \Exception('کلیدهای API ووکامرس یافت نشد یا نامعتبر است');
            }

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth(
                $wooApiKey->api_key,
                $wooApiKey->api_secret
            )->get($license->website_url . '/wp-json/wc/v3/products/unique', [
                'unique_id' => implode(',', $uniqueIds),
                'per_page' => 100
            ]);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'data' => $response->successful() ? $response->json() : null,
                'body' => $response->body()
            ];

        } catch (\Exception $e) {
            Log::error('خطا در بررسی وجود محصولات در ووکامرس: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * درج دسته‌ای محصولات در ووکامرس
     *
     * @param object $license لایسنس
     * @param array $products آرایه محصولات
     * @return array نتیجه درج
     */
    protected function insertWooCommerceBatchProducts($license, $products)
    {
        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                throw new \Exception('کلیدهای API ووکامرس یافت نشد یا نامعتبر است');
            }

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth(
                $wooApiKey->api_key,
                $wooApiKey->api_secret
            )->post($license->website_url . '/wp-json/wc/v3/products/unique/batch', [
                'products' => $products
            ]);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'data' => $response->successful() ? $response->json() : null,
                'body' => $response->body(),
                'response' => $response
            ];

        } catch (\Exception $e) {
            Log::error('خطا در درج دسته‌ای محصولات در ووکامرس: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * درج واریانت‌های محصول در ووکامرس
     *
     * @param object $license لایسنس
     * @param int $parentWooId شناسه محصول مادر در ووکامرس
     * @param array $variations آرایه واریانت‌ها
     * @return array نتیجه درج
     */
    protected function insertWooCommerceProductVariations($license, $parentWooId, $variations)
    {
        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                throw new \Exception('کلیدهای API ووکامرس یافت نشد یا نامعتبر است');
            }

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth(
                $wooApiKey->api_key,
                $wooApiKey->api_secret
            )->post($license->website_url . "/wp-json/wc/v3/products/{$parentWooId}/variations/batch", [
                'create' => $variations
            ]);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'data' => $response->successful() ? $response->json() : null,
                'body' => $response->body(),
                'response' => $response
            ];

        } catch (\Exception $e) {
            Log::error('خطا در درج واریانت‌های محصول در ووکامرس: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * به‌روزرسانی دسته‌ای محصولات در ووکامرس
     *
     * @param object $license لایسنس
     * @param array $products آرایه محصولات
     * @return array نتیجه به‌روزرسانی
     */
    protected function updateWooCommerceBatchProducts($license, $products)
    {
        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                throw new \Exception('کلیدهای API ووکامرس یافت نشد یا نامعتبر است');
            }

            $httpClient = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->retry(3, 300, function ($exception, $request) {
                // لاگ کردن خطاهای شبکه برای کمک به تشخیص مشکلات
                if ($exception instanceof \Illuminate\Http\Client\ConnectionException) {
                    Log::error('خطای اتصال به وب‌سرویس ووکامرس (تلاش مجدد):', [
                        'error' => $exception->getMessage()
                    ]);
                } elseif (isset($exception->response)) {
                    Log::error('خطای سرور در وب‌سرویس ووکامرس (تلاش مجدد):', [
                        'status' => $exception->response->status(),
                        'body' => $exception->response->body()
                    ]);
                }

                return $exception instanceof \Illuminate\Http\Client\ConnectionException ||
                       (isset($exception->response) && $exception->response->status() >= 500);
            })->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth(
                $wooApiKey->api_key,
                $wooApiKey->api_secret
            );

            $response = $httpClient->put($license->website_url . '/wp-json/wc/v3/products/unique/batch/update', [
                'products' => $products
            ]);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'data' => $response->successful() ? $response->json() : null,
                'body' => $response->body(),
                'response' => $response
            ];

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی دسته‌ای محصولات در ووکامرس: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * دریافت اطلاعات سفارش از WooCommerce
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @param int $orderId شناسه سفارش
     * @return array نتیجه درخواست
     */
    protected function getWooCommerceOrder($websiteUrl, $apiKey, $apiSecret, $orderId)
    {
        try {
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->withBasicAuth(
                $apiKey,
                $apiSecret
            )->get($websiteUrl . '/wp-json/wc/v3/orders/' . $orderId);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت اطلاعات سفارش از WooCommerce - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $orderData = $response->json();

            return [
                'success' => true,
                'data' => $orderData,
                'message' => 'سفارش با موفقیت دریافت شد'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در دریافت سفارش: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * به‌روزرسانی سفارش در WooCommerce
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @param int $orderId شناسه سفارش
     * @param array $updateData داده‌های به‌روزرسانی
     * @return array نتیجه درخواست
     */
    protected function updateWooCommerceOrder($websiteUrl, $apiKey, $apiSecret, $orderId, $updateData)
    {
        try {
            $httpClient = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->retry(3, 300, function ($exception, $request) {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException ||
                       (isset($exception->response) && $exception->response->status() >= 500);
            })->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth($apiKey, $apiSecret);

            $response = $httpClient->put($websiteUrl . '/wp-json/wc/v3/orders/' . $orderId, $updateData);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در به‌روزرسانی سفارش - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            return [
                'success' => true,
                'data' => $response->json(),
                'message' => 'سفارش با موفقیت به‌روزرسانی شد'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در به‌روزرسانی سفارش: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * دریافت لیست محصولات از WooCommerce
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @param array $params پارامترهای درخواست
     * @return array نتیجه درخواست
     */
    protected function getWooCommerceProducts($websiteUrl, $apiKey, $apiSecret, $params = [])
    {
        try {
            $websiteUrl = rtrim($websiteUrl, '/');
            $url = $websiteUrl . '/wp-json/wc/v3/products';

            // اضافه کردن consumer_key و consumer_secret به پارامترها
            $params['consumer_key'] = $apiKey;
            $params['consumer_secret'] = $apiSecret;

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60,
            ])->get($url, $params);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت محصولات از WooCommerce - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $products = $response->json();

            return [
                'success' => true,
                'data' => $products,
                'message' => 'محصولات با موفقیت دریافت شدند'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در دریافت محصولات: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * دریافت واریانت‌های یک محصول از WooCommerce
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @param int $productId شناسه محصول
     * @param array $params پارامترهای درخواست
     * @return array نتیجه درخواست
     */
    protected function getWooCommerceProductVariations($websiteUrl, $apiKey, $apiSecret, $productId, $params = [])
    {
        try {
            $websiteUrl = rtrim($websiteUrl, '/');
            $url = $websiteUrl . "/wp-json/wc/v3/products/{$productId}/variations";

            // اضافه کردن consumer_key و consumer_secret به پارامترها
            $params['consumer_key'] = $apiKey;
            $params['consumer_secret'] = $apiSecret;

            Log::info('درخواست WooCommerce Variations API', [
                'url' => $url,
                'product_id' => $productId,
                'page' => $params['page'] ?? 1,
                'per_page' => $params['per_page'] ?? 100,
                'api_key_preview' => substr($apiKey, 0, 10) . '...'
            ]);

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 15, // کاهش timeout به 15 ثانیه
                'connect_timeout' => 5,
            ])->get($url, $params);

            Log::info('پاسخ WooCommerce Variations API دریافت شد', [
                'product_id' => $productId,
                'status_code' => $response->status(),
                'response_headers' => json_encode($response->headers()),
                'response_length' => strlen($response->body()),
                'response_type' => gettype($response->body()),
                'response_preview' => substr($response->body(), 0, 300)
            ]);

            if (!$response->successful()) {
                Log::error('خطا در WooCommerce Variations API', [
                    'product_id' => $productId,
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت واریانت‌ها از WooCommerce - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $variations = $response->json();

            Log::info('WooCommerce Variations پردازش شد', [
                'product_id' => $productId,
                'variations_type' => gettype($variations),
                'variations_count' => is_array($variations) ? count($variations) : 'NA',
                'variations_preview' => substr(json_encode($variations), 0, 200)
            ]);

            return [
                'success' => true,
                'data' => $variations,
                'message' => 'واریانت‌ها با موفقیت دریافت شدند'
            ];

        } catch (\Exception $e) {
            Log::error('Exception در getWooCommerceProductVariations', [
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500)
            ]);
            return [
                'success' => false,
                'message' => 'خطا در دریافت واریانت‌ها: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * به‌روزرسانی دسته‌ای محصولات در WooCommerce با استفاده از unique_id
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @param array $batchData داده‌های دسته‌ای برای به‌روزرسانی
     * @return array نتیجه درخواست
     */
    protected function updateWooCommerceBatchProductsByUniqueId($websiteUrl, $apiKey, $apiSecret, $batchData)
    {
        try {
            $websiteUrl = rtrim($websiteUrl, '/');
            $url = $websiteUrl . '/wp-json/wc/v3/products/unique/batch/update';

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30,
                'connect_timeout' => 10
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth(
                $apiKey,
                $apiSecret
            )->put($url, $batchData);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در batch update محصولات - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $responseData = $response->json();

            return [
                'success' => true,
                'data' => $responseData,
                'message' => 'Batch update محصولات با موفقیت انجام شد'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در batch update محصولات: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * به‌روزرسانی دسته‌ای unique_id محصولات در WooCommerce بر اساس SKU
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @param array $batchData داده‌های دسته‌ای برای به‌روزرسانی
     * @return array نتیجه درخواست
     */
    protected function batchUpdateWooCommerceUniqueIdsBySku($websiteUrl, $apiKey, $apiSecret, $batchData)
    {
        try {
            $websiteUrl = rtrim($websiteUrl, '/');
            $url = $websiteUrl . '/wp-json/wc/v3/products/unique/batch-update-sku';

            // تحضیر headers
            $headers = [
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($apiKey . ':' . $apiSecret)
            ];

            // لاگ تفصیلی درخواست (Header + Body)
            Log::info('تهیه درخواست batch update unique IDs برای WooCommerce', [
                'url' => $url,
                'method' => 'POST',
                'headers' => $headers,
                'products_count' => count($batchData['products'] ?? []),
                'request_body' => $batchData,
                'products_detail' => array_map(function($p) {
                    return [
                        'sku' => $p['sku'] ?? null,
                        'unique_id' => $p['unique_id'] ?? null,
                        'product_id' => $p['product_id'] ?? null,
                        'variation_id' => $p['variation_id'] ?? null,
                    ];
                }, $batchData['products'] ?? [])
            ]);

            $response = Http::withHeaders($headers)->withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60,
                'http_errors' => false
            ])->post($url, $batchData);

            // لاگ تفصیلی پاسخ (Status + Headers + Body)
            Log::info('دریافت پاسخ از WooCommerce برای batch update unique IDs', [
                'url' => $url,
                'status_code' => $response->status(),
                'status_text' => $response->status() >= 200 && $response->status() < 300 ? 'Success' : 'Error',
                'response_headers' => $response->headers(),
                'response_body' => $response->body(),
                'response_json' => $response->successful() ? $response->json() : null,
                'successful' => $response->successful(),
                'products_count' => count($batchData['products'] ?? [])
            ]);

            if (!$response->successful()) {
                // اگر endpoint پیدا نشد یا error داد، سعی کنید variations را به طور جداگانه آپدیت کنید
                if ($response->status() === 404 || $response->status() === 405) {
                    Log::warning('batch-update-sku endpoint not found, trying individual variation updates', [
                        'status_code' => $response->status(),
                        'url' => $url,
                        'response_body' => substr($response->body(), 0, 500)
                    ]);

                    // Check if we have variations to update
                    $hasVariations = false;
                    foreach ($batchData['products'] ?? [] as $product) {
                        if (!empty($product['variation_id']) && !empty($product['product_id'])) {
                            $hasVariations = true;
                            break;
                        }
                    }

                    if ($hasVariations) {
                        Log::info('درخواست دستی برای variations شروع شد');
                        // Will handle individual variation updates in PHP code
                        return [
                            'success' => false,
                            'message' => 'Batch endpoint not available, using individual updates',
                            'status_code' => $response->status(),
                            'needs_individual_update' => true,
                            'response_body' => substr($response->body(), 0, 500)
                        ];
                    }
                }

                Log::error('خطا در batch update unique IDs - پاسخ ناموفق', [
                    'status_code' => $response->status(),
                    'status_text' => 'Error',
                    'url' => $url,
                    'products_count' => count($batchData['products'] ?? []),
                    'response_headers' => $response->headers(),
                    'response_body' => substr($response->body(), 0, 1500),
                    'response_json' => $response->json(),
                    'request_body' => $batchData
                ]);

                return [
                    'success' => false,
                    'message' => 'خطا در batch update unique IDs - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $responseData = $response->json();

            Log::info('✅ batch update unique IDs با موفقیت انجام شد', [
                'url' => $url,
                'status_code' => $response->status(),
                'status_text' => 'Success',
                'products_count' => count($batchData['products'] ?? []),
                'response_headers' => $response->headers(),
                'response_body' => $response->body(),
                'response_data' => $responseData,
                'request_products' => array_map(function($p) {
                    return [
                        'sku' => $p['sku'] ?? null,
                        'unique_id' => $p['unique_id'] ?? null,
                        'product_id' => $p['product_id'] ?? null,
                        'variation_id' => $p['variation_id'] ?? null,
                    ];
                }, $batchData['products'] ?? [])
            ]);

            return [
                'success' => true,
                'data' => $responseData,
                'message' => 'Batch update unique IDs با موفقیت انجام شد'
            ];

        } catch (\Exception $e) {
            Log::error('استثنا در batch update unique IDs', [
                'url' => $url,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'trace' => $e->getTraceAsString(),
                'request_body' => $batchData,
                'products_count' => count($batchData['products'] ?? [])
            ]);

            return [
                'success' => false,
                'message' => 'خطا در batch update unique IDs: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * آپدیت direct variation unique_id
     */
    protected function updateWooCommerceVariationUniqueId($websiteUrl, $apiKey, $apiSecret, $productId, $variationId, $uniqueId)
    {
        try {
            $websiteUrl = rtrim($websiteUrl, '/');
            $url = $websiteUrl . "/wp-json/wc/v3/products/{$productId}/variations/{$variationId}";

            Log::info('درخواست آپدیت variation unique_id مستقیم', [
                'url' => $url,
                'product_id' => $productId,
                'variation_id' => $variationId,
                'unique_id' => $uniqueId
            ]);

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($apiKey . ':' . $apiSecret)
            ])->withOptions([
                'verify' => false,
                'timeout' => 30,
                'connect_timeout' => 10,
                'http_errors' => false
            ])->put($url, [
                'meta_data' => [
                    [
                        'key' => '_bim_unique_id',
                        'value' => $uniqueId
                    ]
                ]
            ]);

            if ($response->successful()) {
                Log::info('variation unique_id با موفقیت آپدیت شد', [
                    'product_id' => $productId,
                    'variation_id' => $variationId,
                    'unique_id' => $uniqueId
                ]);

                return [
                    'success' => true,
                    'data' => $response->json()
                ];
            } else {
                Log::error('خطا در آپدیت variation unique_id', [
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500),
                    'product_id' => $productId,
                    'variation_id' => $variationId
                ]);

                return [
                    'success' => false,
                    'status_code' => $response->status(),
                    'error' => $response->body()
                ];
            }

        } catch (\Exception $e) {
            Log::error('استثنا در آپدیت variation unique_id', [
                'error' => $e->getMessage(),
                'product_id' => $productId,
                'variation_id' => $variationId
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * دریافت محصولات WooCommerce بر اساس parent unique ID
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @param string $parentUniqueId شناسه یکتای والد
     * @return array نتیجه درخواست
     */
    protected function getWooCommerceProductsByParentUniqueId($websiteUrl, $apiKey, $apiSecret, $parentUniqueId)
    {
        try {
            $websiteUrl = rtrim($websiteUrl, '/');
            $url = $websiteUrl . '/wp-json/wc/v3/products/by-parent-unique-id/' . $parentUniqueId;

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 20,
                'connect_timeout' => 10
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth($apiKey, $apiSecret)->get($url);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت محصولات با parent unique ID - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $responseData = $response->json();

            // بررسی ساختار پاسخ
            if (isset($responseData['success']) && !$responseData['success']) {
                return [
                    'success' => false,
                    'message' => 'عدم موفقیت در دریافت محصولات',
                    'data' => []
                ];
            }

            return [
                'success' => true,
                'data' => $responseData['data'] ?? $responseData,
                'message' => 'محصولات با موفقیت دریافت شدند'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در دریافت محصولات با parent unique ID: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * دریافت محصول WooCommerce بر اساس unique ID
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @param string $uniqueId شناسه یکتا
     * @return array نتیجه درخواست
     */
    protected function getWooCommerceProductByUniqueId($websiteUrl, $apiKey, $apiSecret, $uniqueId)
    {
        try {
            $websiteUrl = rtrim($websiteUrl, '/');
            $url = $websiteUrl . '/wp-json/wc/v3/products/by-unique-id/' . $uniqueId;

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 20,
                'connect_timeout' => 10
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth($apiKey, $apiSecret)->get($url);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت محصول با unique ID - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $responseData = $response->json();

            // بررسی ساختار پاسخ
            if (isset($responseData['success']) && !$responseData['success']) {
                return [
                    'success' => false,
                    'message' => 'محصول یافت نشد',
                    'data' => null
                ];
            }

            return [
                'success' => true,
                'data' => $responseData['data'] ?? $responseData,
                'message' => 'محصول با موفقیت دریافت شد'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در دریافت محصول با unique ID: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * به‌روزرسانی محصول WooCommerce
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @param int $productId شناسه محصول
     * @param array $updateData داده‌های به‌روزرسانی
     * @return array نتیجه درخواست
     */
    protected function updateWooCommerceProduct($websiteUrl, $apiKey, $apiSecret, $productId, $updateData)
    {
        try {
            $websiteUrl = rtrim($websiteUrl, '/');
            $url = $websiteUrl . '/wp-json/wc/v3/products/' . $productId;

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30,
                'connect_timeout' => 10
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth($apiKey, $apiSecret)->put($url, $updateData);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در به‌روزرسانی محصول - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $responseData = $response->json();

            return [
                'success' => true,
                'data' => $responseData,
                'message' => 'محصول با موفقیت به‌روزرسانی شد'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در به‌روزرسانی محصول: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * دریافت دسته‌بندی‌های محصولات WooCommerce
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @param array $params پارامترهای درخواست
     * @return array نتیجه درخواست
     */
    protected function getWooCommerceProductCategories($websiteUrl, $apiKey, $apiSecret, $params = [])
    {
        try {
            $websiteUrl = rtrim($websiteUrl, '/');
            $url = $websiteUrl . '/wp-json/wc/v3/products/categories';

            // اضافه کردن consumer_key و consumer_secret به پارامترها
            $params['consumer_key'] = $apiKey;
            $params['consumer_secret'] = $apiSecret;

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60,
            ])->get($url, $params);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت دسته‌بندی‌ها از WooCommerce - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $categories = $response->json();

            return [
                'success' => true,
                'data' => $categories,
                'message' => 'دسته‌بندی‌ها با موفقیت دریافت شدند'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در دریافت دسته‌بندی‌ها: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * ایجاد دسته‌بندی محصول در WooCommerce
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @param array $categoryData داده‌های دسته‌بندی
     * @return array نتیجه درخواست
     */
    protected function createWooCommerceCategory($websiteUrl, $apiKey, $apiSecret, $categoryData)
    {
        try {
            $websiteUrl = rtrim($websiteUrl, '/');
            $url = $websiteUrl . '/wp-json/wc/v3/products/categories';

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30,
                'connect_timeout' => 10
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth($apiKey, $apiSecret)->post($url, $categoryData);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در ایجاد دسته‌بندی - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $responseData = $response->json();

            return [
                'success' => true,
                'data' => $responseData,
                'message' => 'دسته‌بندی با موفقیت ایجاد شد'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در ایجاد دسته‌بندی: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * دریافت محصولات WooCommerce با SKU
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @param string $sku کد SKU محصول
     * @return array نتیجه درخواست
     */
    protected function getWooCommerceProductsBySku($websiteUrl, $apiKey, $apiSecret, $sku)
    {
        try {
            $websiteUrl = rtrim($websiteUrl, '/');
            $url = $websiteUrl . '/wp-json/wc/v3/products';

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30,
                'connect_timeout' => 10
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth($apiKey, $apiSecret)->get($url, [
                'sku' => $sku
            ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در جستجوی محصول با SKU - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $products = $response->json();

            return [
                'success' => true,
                'data' => $products,
                'message' => 'محصولات با موفقیت دریافت شدند'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در جستجوی محصول با SKU: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * دریافت محصول WooCommerce
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @param int $productId شناسه محصول
     * @return array نتیجه درخواست
     */
    protected function getWooCommerceProduct($websiteUrl, $apiKey, $apiSecret, $productId)
    {
        try {
            $websiteUrl = rtrim($websiteUrl, '/');
            $url = $websiteUrl . '/wp-json/wc/v3/products/' . $productId;

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30,
                'connect_timeout' => 10
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth($apiKey, $apiSecret)->get($url);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت محصول - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $product = $response->json();

            return [
                'success' => true,
                'data' => $product,
                'message' => 'محصول با موفقیت دریافت شد'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در دریافت محصول: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * دریافت تنوع محصول WooCommerce
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @param int $productId شناسه محصول والد
     * @param int $variationId شناسه تنوع
     * @return array نتیجه درخواست
     */
    protected function getWooCommerceProductVariation($websiteUrl, $apiKey, $apiSecret, $productId, $variationId)
    {
        try {
            $websiteUrl = rtrim($websiteUrl, '/');
            $url = $websiteUrl . "/wp-json/wc/v3/products/{$productId}/variations/{$variationId}";

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30,
                'connect_timeout' => 10
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth($apiKey, $apiSecret)->get($url);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت تنوع محصول - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $variation = $response->json();

            return [
                'success' => true,
                'data' => $variation,
                'message' => 'تنوع محصول با موفقیت دریافت شد'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در دریافت تنوع محصول: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * به‌روزرسانی تنوع محصول WooCommerce
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @param int $productId شناسه محصول والد
     * @param int $variationId شناسه تنوع
     * @param array $updateData داده‌های به‌روزرسانی
     * @return array نتیجه درخواست
     */
    protected function updateWooCommerceProductVariation($websiteUrl, $apiKey, $apiSecret, $productId, $variationId, $updateData)
    {
        try {
            $websiteUrl = rtrim($websiteUrl, '/');
            $url = $websiteUrl . "/wp-json/wc/v3/products/{$productId}/variations/{$variationId}";

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30,
                'connect_timeout' => 10
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth($apiKey, $apiSecret)->put($url, $updateData);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در به‌روزرسانی تنوع محصول - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $responseData = $response->json();

            return [
                'success' => true,
                'data' => $responseData,
                'message' => 'تنوع محصول با موفقیت به‌روزرسانی شد'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در به‌روزرسانی تنوع محصول: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * حذف unique ID های نامعتبر از WooCommerce
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @param array $uniqueIds آرایه unique ID ها
     * @return array نتیجه درخواست
     */
    protected function deleteWooCommerceUniqueIds($websiteUrl, $apiKey, $apiSecret, $uniqueIds)
    {
        try {
            $websiteUrl = rtrim($websiteUrl, '/');
            $url = $websiteUrl . '/wp-json/wc/v3/products/unique/delete';

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60,
                'http_errors' => false
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth($apiKey, $apiSecret)->send('DELETE', $url, [
                'json' => [
                    'unique_ids' => $uniqueIds
                ]
            ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در حذف unique ID ها - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $result = $response->json();

            return [
                'success' => true,
                'data' => $result,
                'message' => 'unique ID ها با موفقیت حذف شدند'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در حذف unique ID ها: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * دریافت محصولات WooCommerce همراه با unique ID ها
     *
     * @param string $websiteUrl آدرس وب‌سایت
     * @param string $apiKey کلید API
     * @param string $apiSecret رمز API
     * @return array نتیجه درخواست
     */
    protected function getWooCommerceProductsWithUniqueIds($websiteUrl, $apiKey, $apiSecret)
    {
        try {
            $websiteUrl = rtrim($websiteUrl, '/');
            $url = $websiteUrl . '/wp-json/wc/v3/products/unique';

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30,
                'connect_timeout' => 10
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth($apiKey, $apiSecret)->get($url);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت محصولات با unique ID - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $responseData = $response->json();

            // بررسی ساختار پاسخ
            if (!isset($responseData['success']) || !$responseData['success'] || !isset($responseData['data'])) {
                return [
                    'success' => false,
                    'message' => 'ساختار پاسخ نامعتبر از سرور WooCommerce',
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            return [
                'success' => true,
                'data' => $responseData['data'],
                'message' => 'محصولات با unique ID با موفقیت دریافت شدند'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در دریافت محصولات با unique ID: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    // ========================================
    // توابع مدیریت محصولات متغیر (Variable Products)
    // ========================================

    /**
     * ایجاد محصول متغیر در WooCommerce
     *
     * @param object $license لایسنس
     * @param array $productData اطلاعات محصول
     * @return array نتیجه
     */
    protected function createWooCommerceVariableProduct($license, $productData)
    {
        $productData['type'] = 'variable';
        return $this->createWooCommerceSimpleProduct($license, $productData);
    }

    /**
     * ایجاد محصول ساده در WooCommerce
     *
     * @param object $license لایسنس
     * @param array $productData اطلاعات محصول
     * @return array نتیجه
     */
    protected function createWooCommerceSimpleProduct($license, $productData)
    {
        try {
            $apiKey = $license->woocommerceApiKey;
            if (!$apiKey || !$apiKey->api_key || !$apiKey->api_secret) {
                return [
                    'success' => false,
                    'message' => 'کلیدهای API WooCommerce تنظیم نشده است.'
                ];
            }

            $url = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products';

            Log::info('Creating WooCommerce product', [
                'url' => $url,
                'product_data' => $productData
            ]);

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30
            ])->withBasicAuth($apiKey->api_key, $apiKey->api_secret)
                ->post($url, $productData);

            Log::info('WooCommerce product creation response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => $response->body()
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'message' => 'محصول با موفقیت ایجاد شد'
                ];
            }

            $errorBody = $response->json();
            $errorMessage = 'خطا در ایجاد محصول - کد: ' . $response->status();

            if (isset($errorBody['message'])) {
                $errorMessage .= ' - ' . $errorBody['message'];
            }

            if (isset($errorBody['data']['params'])) {
                $errorMessage .= ' - فیلدهای مشکل‌دار: ' . implode(', ', array_keys($errorBody['data']['params']));
            }

            Log::error('WooCommerce Create Product Error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'error_decoded' => $errorBody
            ]);

            return [
                'success' => false,
                'message' => $errorMessage,
                'status_code' => $response->status(),
                'error_details' => $errorBody
            ];

        } catch (\Exception $e) {
            Log::error('WooCommerce Create Product Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در ایجاد محصول: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * ایجاد محصولات به صورت دسته‌ای با استفاده از endpoint batch
     *
     * @param object $license لایسنس
     * @param array $productsData آرایه محصولات برای ایجاد
     * @return array نتیجه
     */
    protected function createWooCommerceBatchProducts($license, $productsData)
    {
        try {
            $apiKey = $license->woocommerceApiKey;
            if (!$apiKey || !$apiKey->api_key || !$apiKey->api_secret) {
                return [
                    'success' => false,
                    'message' => 'کلیدهای API WooCommerce تنظیم نشده است.'
                ];
            }

            $url = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products/unique/batch';

            $requestPayload = ['products' => $productsData];

            Log::info('Sending batch request to WooCommerce', [
                'url' => $url,
                'products_count' => count($productsData),
                'request_payload' => $requestPayload,
                'auth_user' => $apiKey->api_key
            ]);

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 120
            ])->withHeaders([
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept' => 'application/json'
            ])->withBasicAuth($apiKey->api_key, $apiKey->api_secret)
                ->post($url, $requestPayload);

            Log::info('WooCommerce batch response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => $response->body()
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'message' => 'محصولات با موفقیت ایجاد شدند'
                ];
            }

            Log::error('WooCommerce Batch Products Error', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در ایجاد محصولات دسته‌ای - کد: ' . $response->status(),
                'status_code' => $response->status(),
                'error' => $response->body()
            ];

        } catch (\Exception $e) {
            Log::error('WooCommerce Batch Products Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در ایجاد محصولات دسته‌ای: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * ایجاد variation برای محصول متغیر
     *
     * @param object $license لایسنس
     * @param int $parentProductId شناسه محصول والد
     * @param array $variationData اطلاعات variation
     * @return array نتیجه
     */
    protected function createWooCommerceVariation($license, $parentProductId, $variationData)
    {
        try {
            $apiKey = $license->woocommerceApiKey;
            if (!$apiKey || !$apiKey->api_key || !$apiKey->api_secret) {
                return [
                    'success' => false,
                    'message' => 'کلیدهای API WooCommerce تنظیم نشده است.'
                ];
            }

            $url = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products/' . $parentProductId . '/variations';

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30
            ])->withBasicAuth($apiKey->api_key, $apiKey->api_secret)
                ->post($url, $variationData);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'message' => 'Variation با موفقیت ایجاد شد'
                ];
            }

            Log::error('WooCommerce Create Variation Error', [
                'parent_id' => $parentProductId,
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در ایجاد variation - کد: ' . $response->status(),
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در ایجاد variation: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    // ========================================
    // توابع مدیریت Attributes و Terms
    // ========================================

    /**
     * دریافت درخت کامل attributes و terms از WooCommerce
     *
     * @param object $license لایسنس
     * @return array نتیجه
     */
    protected function getWooCommerceAttributesTree($license)
    {
        try {
            $apiKey = $license->woocommerceApiKey;
            if (!$apiKey || !$apiKey->api_key || !$apiKey->api_secret) {
                return [
                    'success' => false,
                    'message' => 'کلیدهای API WooCommerce تنظیم نشده است.'
                ];
            }

            $url = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products/attributes/tree';

            Log::info('درخواست دریافت درخت attributes از WooCommerce', [
                'url' => $url,
                'license_id' => $license->id
            ]);

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 120,
                'connect_timeout' => 30
            ])->withBasicAuth($apiKey->api_key, $apiKey->api_secret)
                ->get($url);

            Log::info('پاسخ دریافت درخت attributes از WooCommerce', [
                'status' => $response->status(),
                'successful' => $response->successful()
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'message' => 'درخت Attributes با موفقیت دریافت شد'
                ];
            }

            return [
                'success' => false,
                'message' => 'خطا در دریافت درخت attributes - کد: ' . $response->status(),
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('خطا در دریافت درخت attributes از WooCommerce', [
                'error' => $e->getMessage(),
                'license_id' => $license->id
            ]);

            return [
                'success' => false,
                'message' => 'خطا در دریافت درخت attributes: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * دریافت لیست تمام attributes از WooCommerce
     *
     * @param object $license لایسنس
     * @return array نتیجه
     */
    protected function getWooCommerceAttributes($license)
    {
        try {
            $apiKey = $license->woocommerceApiKey;
            if (!$apiKey || !$apiKey->api_key || !$apiKey->api_secret) {
                return [
                    'success' => false,
                    'message' => 'کلیدهای API WooCommerce تنظیم نشده است.'
                ];
            }

            $url = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products/attributes';

            Log::info('درخواست دریافت attributes از WooCommerce', [
                'url' => $url,
                'license_id' => $license->id
            ]);

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 120, // افزایش timeout به 120 ثانیه
                'connect_timeout' => 30
            ])->withBasicAuth($apiKey->api_key, $apiKey->api_secret)
                ->get($url, ['per_page' => 100]);

            Log::info('پاسخ دریافت attributes از WooCommerce', [
                'status' => $response->status(),
                'successful' => $response->successful()
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'message' => 'Attributes با موفقیت دریافت شدند'
                ];
            }

            return [
                'success' => false,
                'message' => 'خطا در دریافت attributes - کد: ' . $response->status(),
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('خطا در دریافت attributes از WooCommerce', [
                'error' => $e->getMessage(),
                'license_id' => $license->id
            ]);

            return [
                'success' => false,
                'message' => 'خطا در دریافت attributes: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * ایجاد attribute جدید در WooCommerce
     *
     * @param object $license لایسنس
     * @param array $attributeData اطلاعات attribute
     * @return array نتیجه
     */
    protected function createWooCommerceAttribute($license, $attributeName, $attributeSlug = null)
    {
        try {
            $apiKey = $license->woocommerceApiKey;
            if (!$apiKey || !$apiKey->api_key || !$apiKey->api_secret) {
                return [
                    'success' => false,
                    'message' => 'کلیدهای API WooCommerce تنظیم نشده است.'
                ];
            }

            $url = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products/attributes';

            // اگر attributeName یک آرایه است (برای سازگاری با کد قدیمی)
            if (is_array($attributeName)) {
                $attributeData = $attributeName;
            } else {
                // ایجاد attributeData از پارامترها
                $attributeData = [
                    'name' => $attributeName
                ];

                if ($attributeSlug) {
                    $attributeData['slug'] = $attributeSlug;
                }
            }

            Log::info('درخواست ایجاد attribute در WooCommerce', [
                'url' => $url,
                'attribute_data' => $attributeData,
                'attribute_name' => $attributeData['name'] ?? 'unknown',
                'attribute_slug' => $attributeData['slug'] ?? 'none',
                'license_id' => $license->id
            ]);

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 120, // افزایش timeout به 120 ثانیه
                'connect_timeout' => 30
            ])->withHeaders([
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept' => 'application/json'
            ])->withBasicAuth($apiKey->api_key, $apiKey->api_secret)
                ->post($url, $attributeData);

            Log::info('پاسخ ایجاد attribute از WooCommerce', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'attribute_name' => $attributeData['name'] ?? 'unknown'
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'message' => 'Attribute با موفقیت ایجاد شد'
                ];
            }

            // بررسی اگر attribute قبلا وجود داشته باشد
            if ($response->status() === 400) {
                $errorBody = $response->json();
                $errorCode = $errorBody['code'] ?? '';
                $errorMessage = $errorBody['message'] ?? '';

                // اگر خطا به علت وجود قبلی attribute باشد، سعی کن آن را بازیابی کنی
                if (strpos($errorCode, 'cannot_create') !== false || strpos($errorMessage, 'پپیش') !== false) {
                    Log::info('Attribute already exists, attempting to retrieve it', [
                        'attribute_name' => $attributeData['name'] ?? 'unknown',
                        'attribute_slug' => $attributeData['slug'] ?? 'none',
                        'error_code' => $errorCode
                    ]);

                    $existingAttribute = null;

                    // 1. اول بر اساس slug جستجو کن (اولویت بالا)
                    if (!empty($attributeData['slug'])) {
                        $getUrl = $url . '?slug=' . urlencode($attributeData['slug']);
                        $getResponse = Http::withOptions([
                            'verify' => false,
                            'timeout' => 30,
                            'connect_timeout' => 30
                        ])->withBasicAuth($apiKey->api_key, $apiKey->api_secret)
                            ->get($getUrl);

                        if ($getResponse->successful()) {
                            $attributes = $getResponse->json();
                            if (!empty($attributes)) {
                                $existingAttribute = $attributes[0];
                            }
                        }
                    }

                    // 2. اگر slug موجود نباشد، بر اساس name جستجو کن
                    if (!$existingAttribute && !empty($attributeData['name'])) {
                        $getUrl = $url . '?search=' . urlencode($attributeData['name']);
                        $getResponse = Http::withOptions([
                            'verify' => false,
                            'timeout' => 30,
                            'connect_timeout' => 30
                        ])->withBasicAuth($apiKey->api_key, $apiKey->api_secret)
                            ->get($getUrl);

                        if ($getResponse->successful()) {
                            $attributes = $getResponse->json();
                            // جستجو برای تطابق دقیق نام
                            foreach ($attributes as $attr) {
                                if (strtolower($attr['name']) === strtolower($attributeData['name'])) {
                                    $existingAttribute = $attr;
                                    break;
                                }
                            }
                        }
                    }

                    if ($existingAttribute) {
                        Log::info('Retrieved existing attribute', [
                            'attribute_name' => $existingAttribute['name'] ?? 'unknown',
                            'attribute_id' => $existingAttribute['id'] ?? 'unknown',
                            'attribute_slug' => $existingAttribute['slug'] ?? 'unknown'
                        ]);
                        return [
                            'success' => true,
                            'data' => $existingAttribute,
                            'message' => 'Attribute قبلا وجود داشت و دریافت شد'
                        ];
                    }
                }
            }

            Log::error('WooCommerce Create Attribute Error', [
                'status' => $response->status(),
                'body' => $response->body(),
                'attribute_name' => $attributeData['name'] ?? 'unknown'
            ]);

            return [
                'success' => false,
                'message' => 'خطا در ایجاد attribute - کد: ' . $response->status(),
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('خطا در ایجاد attribute در WooCommerce', [
                'error' => $e->getMessage(),
                'attribute_name' => $attributeData['name'] ?? 'unknown',
                'license_id' => $license->id
            ]);

            return [
                'success' => false,
                'message' => 'خطا در ایجاد attribute: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * دریافت terms یک attribute
     *
     * @param object $license لایسنس
     * @param int $attributeId شناسه attribute
     * @return array نتیجه
     */
    protected function getWooCommerceAttributeTerms($license, $attributeId)
    {
        try {
            $apiKey = $license->woocommerceApiKey;
            if (!$apiKey || !$apiKey->api_key || !$apiKey->api_secret) {
                return [
                    'success' => false,
                    'message' => 'کلیدهای API WooCommerce تنظیم نشده است.'
                ];
            }

            $url = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products/attributes/' . $attributeId . '/terms';

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 120, // افزایش timeout به 120 ثانیه
                'connect_timeout' => 30
            ])->withBasicAuth($apiKey->api_key, $apiKey->api_secret)
                ->get($url, ['per_page' => 100]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'message' => 'Terms با موفقیت دریافت شدند'
                ];
            }

            return [
                'success' => false,
                'message' => 'خطا در دریافت terms - کد: ' . $response->status(),
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در دریافت terms: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * ایجاد term جدید برای attribute
     *
     * @param object $license لایسنس
     * @param int $attributeId شناسه attribute
     * @param array $termData اطلاعات term
     * @return array نتیجه
     */
    protected function createWooCommerceAttributeTerm($license, $attributeId, $termData)
    {
        try {
            $apiKey = $license->woocommerceApiKey;
            if (!$apiKey || !$apiKey->api_key || !$apiKey->api_secret) {
                return [
                    'success' => false,
                    'message' => 'کلیدهای API WooCommerce تنظیم نشده است.'
                ];
            }

            $url = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products/attributes/' . $attributeId . '/terms';

            Log::info('درخواست ایجاد term در WooCommerce', [
                'url' => $url,
                'attribute_id' => $attributeId,
                'term_name' => $termData['name'] ?? 'unknown',
                'license_id' => $license->id
            ]);

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 120, // افزایش timeout به 120 ثانیه
                'connect_timeout' => 30
            ])->withHeaders([
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept' => 'application/json'
            ])->withBasicAuth($apiKey->api_key, $apiKey->api_secret)
                ->post($url, $termData);

            Log::info('پاسخ ایجاد term از WooCommerce', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'term_name' => $termData['name'] ?? 'unknown'
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'message' => 'Term با موفقیت ایجاد شد'
                ];
            }

            Log::error('WooCommerce Create Term Error', [
                'attribute_id' => $attributeId,
                'status' => $response->status(),
                'body' => $response->body(),
                'term_name' => $termData['name'] ?? 'unknown'
            ]);

            return [
                'success' => false,
                'message' => 'خطا در ایجاد term - کد: ' . $response->status(),
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('خطا در ایجاد term در WooCommerce', [
                'error' => $e->getMessage(),
                'attribute_id' => $attributeId,
                'term_name' => $termData['name'] ?? 'unknown',
                'license_id' => $license->id
            ]);

            return [
                'success' => false,
                'message' => 'خطا در ایجاد term: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * به‌روزرسانی term موجود در WooCommerce
     *
     * @param object $license لایسنس
     * @param int $attributeId شناسه attribute
     * @param int $termId شناسه term
     * @param array $updateData داده‌های به‌روزرسانی
     * @return array نتیجه
     */
    protected function updateWooCommerceAttributeTerm($license, $attributeId, $termId, $updateData)
    {
        try {
            $apiKey = $license->woocommerceApiKey;
            if (!$apiKey || !$apiKey->api_key || !$apiKey->api_secret) {
                return [
                    'success' => false,
                    'message' => 'کلیدهای API WooCommerce تنظیم نشده است.'
                ];
            }

            $url = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products/attributes/' . $attributeId . '/terms/' . $termId;

            Log::info('درخواست به‌روزرسانی term در WooCommerce', [
                'url' => $url,
                'attribute_id' => $attributeId,
                'term_id' => $termId,
                'update_data' => $updateData,
                'license_id' => $license->id
            ]);

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 120,
                'connect_timeout' => 30
            ])->withHeaders([
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept' => 'application/json'
            ])->withBasicAuth($apiKey->api_key, $apiKey->api_secret)
                ->put($url, $updateData);

            Log::info('پاسخ به‌روزرسانی term از WooCommerce', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'term_id' => $termId
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                    'message' => 'Term با موفقیت به‌روزرسانی شد'
                ];
            }

            Log::error('WooCommerce Update Term Error', [
                'attribute_id' => $attributeId,
                'term_id' => $termId,
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در به‌روزرسانی term - کد: ' . $response->status(),
                'status_code' => $response->status()
            ];

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی term در WooCommerce', [
                'error' => $e->getMessage(),
                'attribute_id' => $attributeId,
                'term_id' => $termId,
                'license_id' => $license->id
            ]);

            return [
                'success' => false,
                'message' => 'خطا در به‌روزرسانی term: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * پیدا کردن یا ایجاد attribute در WooCommerce
     *
     * @param object $license لایسنس
     * @param string $attributeName نام attribute
     * @return array نتیجه
     */
    protected function findOrCreateWooCommerceAttribute($license, $attributeName)
    {
        // دریافت attributes موجود
        $result = $this->getWooCommerceAttributes($license);

        if (!$result['success']) {
            return $result;
        }

        $attributes = $result['data'];

        // جستجو در attributes موجود
        foreach ($attributes as $attr) {
            if (strtolower($attr['name']) === strtolower($attributeName)) {
                return [
                    'success' => true,
                    'data' => $attr,
                    'message' => 'Attribute موجود بود'
                ];
            }
        }

        // اگر پیدا نشد، ایجاد کن
        $slug = \Illuminate\Support\Str::slug($attributeName);

        // حذف pa_ از ابتدای slug اگر وجود دارد
        $slug = preg_replace('/^pa[_-]/', '', $slug);

        return $this->createWooCommerceAttribute($license, [
            'name' => $attributeName,
            'slug' => $slug,
            'type' => 'select',
            'order_by' => 'menu_order',
            'has_archives' => false
        ]);
    }

    /**
     * پیدا کردن یا ایجاد term برای attribute
     *
     * @param object $license لایسنس
     * @param int $attributeId شناسه attribute
     * @param string $termName نام term
     * @return array نتیجه
     */
    protected function findOrCreateWooCommerceAttributeTerm($license, $attributeId, $termName)
    {
        // بررسی اینکه termName خالی نباشد
        if (empty($termName)) {
            return [
                'success' => false,
                'message' => 'نام term نمی‌تواند خالی باشد',
                'error' => 'Empty term name'
            ];
        }

        // دریافت terms موجود
        $result = $this->getWooCommerceAttributeTerms($license, $attributeId);

        if (!$result['success']) {
            return $result;
        }

        $terms = $result['data'];

        // جستجو در terms موجود
        foreach ($terms as $term) {
            if (strtolower($term['name']) === strtolower($termName)) {
                return [
                    'success' => true,
                    'data' => $term,
                    'message' => 'Term موجود بود'
                ];
            }
        }

        // اگر پیدا نشد، ایجاد کن
        return $this->createWooCommerceAttributeTerm($license, $attributeId, [
            'name' => $termName,
            'slug' => \Illuminate\Support\Str::slug($termName)
        ]);
    }

    /**
     * بررسی وجود محصول با unique_id در WooCommerce
     *
     * @param object $license
     * @param string $uniqueId
     * @return bool
     */
    protected function checkProductExistsByUniqueId($license, $uniqueId)
    {
        try {
            $apiKey = $license->woocommerceApiKey;
            if (!$apiKey || !$apiKey->api_key || !$apiKey->api_secret) {
                Log::warning('WooCommerce API key not found for license', ['license_id' => $license->id]);
                return false;
            }

            $websiteUrl = rtrim($license->website_url, '/');
            $url = $websiteUrl . '/wp-json/wc/v3/products';

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 20,
                'connect_timeout' => 10
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->withBasicAuth($apiKey->api_key, $apiKey->api_secret)
                ->get($url, [
                    'meta_key' => '_unique_id',
                    'meta_value' => $uniqueId,
                    'per_page' => 1
                ]);

            if ($response->successful()) {
                $products = $response->json();
                $exists = !empty($products);

                if ($exists) {
                    Log::info('Product found in WooCommerce by unique_id', [
                        'unique_id' => $uniqueId,
                        'product_id' => $products[0]['id'] ?? null
                    ]);
                } else {
                    Log::info('Product not found in WooCommerce by unique_id', ['unique_id' => $uniqueId]);
                }

                return $exists;
            }

            Log::warning('Failed to check product existence', [
                'unique_id' => $uniqueId,
                'status' => $response->status()
            ]);
            return false;

        } catch (\Exception $e) {
            Log::error('Error checking product existence by unique_id', [
                'error' => $e->getMessage(),
                'unique_id' => $uniqueId
            ]);
            return false;
        }
    }

    /**
     * دریافت تمام محصولات موجود در WooCommerce با unique_id های آنها
     *
     * @param object $license لایسنس
     * @return array لیست محصولات با unique_id, product_id, variation_id
     */
    protected function getAllWooCommerceProductsByUniqueIds($license)
    {
        try {
            $apiKey = $license->woocommerceApiKey;
            if (!$apiKey || !$apiKey->api_key || !$apiKey->api_secret) {
                Log::error('WooCommerce API keys not configured');
                return [
                    'success' => false,
                    'message' => 'کلیدهای API WooCommerce تنظیم نشده است.',
                    'data' => []
                ];
            }

            $url = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products/unique';

            Log::info('Fetching all products from WooCommerce by unique_ids', [
                'url' => $url
            ]);

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 60
            ])->withBasicAuth($apiKey->api_key, $apiKey->api_secret)
                ->get($url);

            if ($response->successful()) {
                $data = $response->json();

                Log::info('WooCommerce products fetched successfully', [
                    'success' => $data['success'] ?? false,
                    'count' => count($data['data'] ?? [])
                ]);

                return [
                    'success' => true,
                    'data' => $data['data'] ?? []
                ];
            }

            Log::error('Failed to fetch WooCommerce products', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در دریافت محصولات - کد: ' . $response->status(),
                'data' => []
            ];

        } catch (\Exception $e) {
            Log::error('Error fetching WooCommerce products by unique_ids', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'message' => 'خطا در دریافت محصولات: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }
}
