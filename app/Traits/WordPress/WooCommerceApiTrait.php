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

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30, // کاهش timeout
                'connect_timeout' => 10,
            ])->get($url, $params);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت واریانت‌ها از WooCommerce - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $variations = $response->json();

            return [
                'success' => true,
                'data' => $variations,
                'message' => 'واریانت‌ها با موفقیت دریافت شدند'
            ];

        } catch (\Exception $e) {
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

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($apiKey . ':' . $apiSecret)
            ])->withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60,
                'http_errors' => false
            ])->post($url, $batchData);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در batch update unique IDs - کد: ' . $response->status(),
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500)
                ];
            }

            $responseData = $response->json();

            return [
                'success' => true,
                'data' => $responseData,
                'message' => 'Batch update unique IDs با موفقیت انجام شد'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در batch update unique IDs: ' . $e->getMessage(),
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
}
