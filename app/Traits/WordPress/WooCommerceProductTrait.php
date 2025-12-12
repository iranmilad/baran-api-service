<?php

namespace App\Traits\WordPress;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\WooCommerceApiKey;

trait WooCommerceProductTrait
{
    use WooCommerceSettingsTrait;

    /**
     * ایجاد محصول جدید در WooCommerce
     *
     * @param object $license لایسنس
     * @param array $productData اطلاعات محصول
     * @param object $userSettings تنظیمات کاربر
     * @return array نتیجه ایجاد محصول
     */
    protected function createWooCommerceProduct($license, $productData, $userSettings)
    {
        try {
            $validation = $this->validateWooCommerceSettings($license, $userSettings);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => 'تنظیمات WooCommerce نامعتبر: ' . implode(', ', $validation['errors'])
                ];
            }

            $wooApiKey = $license->woocommerceApiKey;
            $url = $this->buildWooCommerceUrl($license->website_url, 'products');

            // تبدیل داده‌های محصول
            $convertedProduct = $this->convertProductForWooCommerce($productData, $userSettings);

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 60
            ])->withHeaders(
                $this->prepareWooCommerceHeaders($wooApiKey)
            )->post($url, $convertedProduct);

            if ($response->successful()) {
                $responseData = $response->json();

                $this->logWooCommerceActivity('ایجاد محصول', [
                    'license_id' => $license->id,
                    'product_sku' => $convertedProduct['sku'],
                    'woo_product_id' => $responseData['id'] ?? null
                ]);

                return [
                    'success' => true,
                    'message' => 'محصول با موفقیت ایجاد شد',
                    'data' => $responseData
                ];
            } else {
                return $this->handleWooCommerceError($response, 'ایجاد محصول', [
                    'license_id' => $license->id,
                    'product_data' => $convertedProduct
                ]);
            }

        } catch (\Exception $e) {
            Log::error('خطا در ایجاد محصول WooCommerce', [
                'license_id' => $license->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطای سیستمی در ایجاد محصول',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * به‌روزرسانی محصول موجود در WooCommerce
     *
     * @param object $license لایسنس
     * @param int $productId شناسه محصول WooCommerce
     * @param array $updateData داده‌های به‌روزرسانی
     * @param object $userSettings تنظیمات کاربر
     * @return array نتیجه به‌روزرسانی
     */
    protected function updateWooCommerceProduct($license, $productId, $updateData, $userSettings)
    {
        try {
            $validation = $this->validateWooCommerceSettings($license, $userSettings);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => 'تنظیمات WooCommerce نامعتبر: ' . implode(', ', $validation['errors'])
                ];
            }

            $wooApiKey = $license->woocommerceApiKey;
            $url = $this->buildWooCommerceUrl($license->website_url, "products/{$productId}");

            // تبدیل داده‌های محصول
            $convertedProduct = $this->convertProductForWooCommerce($updateData, $userSettings);

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 60
            ])->withHeaders(
                $this->prepareWooCommerceHeaders($wooApiKey)
            )->put($url, $convertedProduct);

            if ($response->successful()) {
                $responseData = $response->json();

                $this->logWooCommerceActivity('به‌روزرسانی محصول', [
                    'license_id' => $license->id,
                    'product_id' => $productId,
                    'product_sku' => $convertedProduct['sku']
                ]);

                return [
                    'success' => true,
                    'message' => 'محصول با موفقیت به‌روزرسانی شد',
                    'data' => $responseData
                ];
            } else {
                return $this->handleWooCommerceError($response, 'به‌روزرسانی محصول', [
                    'license_id' => $license->id,
                    'product_id' => $productId,
                    'update_data' => $convertedProduct
                ]);
            }

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی محصول WooCommerce', [
                'license_id' => $license->id,
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطای سیستمی در به‌روزرسانی محصول',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * حذف محصول از WooCommerce
     *
     * @param object $license لایسنس
     * @param int $productId شناسه محصول
     * @param bool $force حذف کامل (پیش‌فرض: false)
     * @return array نتیجه حذف
     */
    protected function deleteWooCommerceProduct($license, $productId, $force = false)
    {
        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                return [
                    'success' => false,
                    'message' => 'کلید API WooCommerce تنظیم نشده است'
                ];
            }

            $url = $this->buildWooCommerceUrl($license->website_url, "products/{$productId}");
            $params = $force ? ['force' => true] : [];

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30
            ])->withHeaders(
                $this->prepareWooCommerceHeaders($wooApiKey)
            )->delete($url, $params);

            if ($response->successful()) {
                $this->logWooCommerceActivity('حذف محصول', [
                    'license_id' => $license->id,
                    'product_id' => $productId,
                    'force_delete' => $force
                ]);

                return [
                    'success' => true,
                    'message' => 'محصول با موفقیت حذف شد'
                ];
            } else {
                return $this->handleWooCommerceError($response, 'حذف محصول', [
                    'license_id' => $license->id,
                    'product_id' => $productId
                ]);
            }

        } catch (\Exception $e) {
            Log::error('خطا در حذف محصول WooCommerce', [
                'license_id' => $license->id,
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'خطای سیستمی در حذف محصول',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * جستجوی محصولات در WooCommerce
     *
     * @param object $license لایسنس
     * @param array $searchParams پارامترهای جستجو
     * @return array نتایج جستجو
     */
    protected function searchWooCommerceProducts($license, $searchParams = [])
    {
        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                return [
                    'success' => false,
                    'message' => 'کلید API WooCommerce تنظیم نشده است'
                ];
            }

            $url = $this->buildWooCommerceUrl($license->website_url, 'products');

            $defaultParams = [
                'per_page' => 20,
                'page' => 1,
                'status' => 'publish'
            ];

            $params = array_merge($defaultParams, $searchParams);

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30
            ])->withHeaders(
                $this->prepareWooCommerceHeaders($wooApiKey)
            )->get($url, $params);

            if ($response->successful()) {
                $products = $response->json();

                $this->logWooCommerceActivity('جستجوی محصولات', [
                    'license_id' => $license->id,
                    'search_params' => $params,
                    'results_count' => count($products)
                ]);

                return [
                    'success' => true,
                    'data' => $products,
                    'count' => count($products)
                ];
            } else {
                return $this->handleWooCommerceError($response, 'جستجوی محصولات', [
                    'license_id' => $license->id,
                    'search_params' => $params
                ]);
            }

        } catch (\Exception $e) {
            Log::error('خطا در جستجوی محصولات WooCommerce', [
                'license_id' => $license->id,
                'search_params' => $searchParams,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'خطای سیستمی در جستجوی محصولات',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * دریافت اطلاعات یک محصول از WooCommerce
     *
     * @param object $license لایسنس
     * @param int $productId شناسه محصول
     * @return array اطلاعات محصول
     */
    protected function getWooCommerceProduct($license, $productId)
    {
        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                return [
                    'success' => false,
                    'message' => 'کلید API WooCommerce تنظیم نشده است'
                ];
            }

            $url = $this->buildWooCommerceUrl($license->website_url, "products/{$productId}");

            Log::info('درخواست WooCommerce Product API', [
                'url' => $url,
                'product_id' => $productId,
                'license_id' => $license->id,
                'api_key_preview' => substr($wooApiKey->api_key, 0, 10) . '...'
            ]);

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30
            ])->withHeaders(
                $this->prepareWooCommerceHeaders($wooApiKey)
            )->get($url);

            Log::info('پاسخ WooCommerce Product API دریافت شد', [
                'product_id' => $productId,
                'status_code' => $response->status(),
                'response_headers' => json_encode($response->headers()),
                'response_length' => strlen($response->body()),
                'response_type' => gettype($response->body()),
                'response_preview' => substr($response->body(), 0, 300)
            ]);

            if ($response->successful()) {
                $product = $response->json();

                Log::info('WooCommerce Product پردازش شد', [
                    'product_id' => $productId,
                    'product_type' => gettype($product),
                    'product_sku' => is_array($product) ? ($product['sku'] ?? 'NA') : 'NA',
                    'product_preview' => substr(json_encode($product), 0, 200)
                ]);

                return [
                    'success' => true,
                    'data' => $product
                ];
            } else {
                Log::error('خطا در WooCommerce Product API', [
                    'product_id' => $productId,
                    'status_code' => $response->status(),
                    'response_body' => $response->body()
                ]);
                return $this->handleWooCommerceError($response, 'دریافت محصول', [
                    'license_id' => $license->id,
                    'product_id' => $productId
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Exception در getWooCommerceProduct', [
                'license_id' => $license->id,
                'product_id' => $productId,
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500)
            ]);

            return [
                'success' => false,
                'message' => 'خطای سیستمی در دریافت محصول',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * به‌روزرسانی موجودی محصول در WooCommerce
     *
     * @param object $license لایسنس
     * @param int $productId شناسه محصول
     * @param int $stockQuantity مقدار موجودی
     * @return array نتیجه به‌روزرسانی
     */
    protected function updateWooCommerceProductStock($license, $productId, $stockQuantity)
    {
        try {
            $updateData = [
                'stock_quantity' => (int) $stockQuantity,
                'manage_stock' => true,
                'stock_status' => $stockQuantity > 0 ? 'instock' : 'outofstock'
            ];

            return $this->updateWooCommerceProduct($license, $productId, $updateData, null);

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی موجودی محصول WooCommerce', [
                'license_id' => $license->id,
                'product_id' => $productId,
                'stock_quantity' => $stockQuantity,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'خطای سیستمی در به‌روزرسانی موجودی',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * به‌روزرسانی قیمت محصول در WooCommerce
     *
     * @param object $license لایسنس
     * @param int $productId شناسه محصول
     * @param float $price قیمت جدید
     * @param object $userSettings تنظیمات کاربر
     * @return array نتیجه به‌روزرسانی
     */
    protected function updateWooCommerceProductPrice($license, $productId, $price, $userSettings)
    {
        try {
            // تبدیل واحد قیمت
            $priceSettings = $this->getWooCommercePriceSettings($userSettings);
            $convertedPrice = $this->convertPriceUnit(
                $price,
                $priceSettings['rain_sale_unit'],
                $priceSettings['woocommerce_unit']
            );

            $updateData = [
                'regular_price' => (string) $convertedPrice
            ];

            return $this->updateWooCommerceProduct($license, $productId, $updateData, $userSettings);

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی قیمت محصول WooCommerce', [
                'license_id' => $license->id,
                'product_id' => $productId,
                'price' => $price,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'خطای سیستمی در به‌روزرسانی قیمت',
                'error' => $e->getMessage()
            ];
        }
    }
}
