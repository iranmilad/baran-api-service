<?php

namespace App\Traits\WordPress;

use Illuminate\Support\Facades\Log;

trait WcTrait
{
    /**
     * @param User $user
     * @return array
     */
    public function getWcCategory($user): array
    {
        Log::info('WcTrait::getWcCategory called', [
            'user_id' => $user->id,
            'site_url' => $user->siteUrl,
            'consumer_key_set' => !empty($user->consumerKey),
            'consumer_secret_set' => !empty($user->consumerSecret)
        ]);

        // بررسی تنظیمات اساسی
        if (empty($user->siteUrl) || empty($user->consumerKey) || empty($user->consumerSecret)) {
            Log::error('WcTrait::getWcCategory - Missing required API settings', [
                'site_url' => $user->siteUrl ?? 'EMPTY',
                'consumer_key' => !empty($user->consumerKey) ? 'SET' : 'EMPTY',
                'consumer_secret' => !empty($user->consumerSecret) ? 'SET' : 'EMPTY'
            ]);
            return [];
        }

        $page = 1;
        $perPage = 100;
        $categorys = [];

        while (true) {
            $apiUrl = $user->siteUrl . '/wp-json/wc/v3/products/categories?page=' . $page . '&per_page=' . $perPage;

            Log::info('WcTrait::getWcCategory - Making API request', [
                'url' => $apiUrl,
                'page' => $page
            ]);

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_USERAGENT => 'Holoo',
                CURLOPT_USERPWD => $user->consumerKey . ":" . $user->consumerSecret,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ));

            $response = curl_exec($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if (curl_error($curl)) {
                Log::error('WcTrait::getWcCategory - CURL Error', [
                    'error' => curl_error($curl),
                    'url' => $apiUrl
                ]);
                curl_close($curl);
                break;
            }

            curl_close($curl);

            Log::info('WcTrait::getWcCategory - API Response', [
                'response_code' => $responseCode,
                'response_length' => strlen($response),
                'response_preview' => substr($response, 0, 200)
            ]);

            if ($responseCode == 200) {
                $responseData = json_decode($response, true);

                if ($responseData != null && is_array($responseData)) {
                    Log::info('WcTrait::getWcCategory - Processing categories', [
                        'count' => count($responseData)
                    ]);

                    foreach ($responseData as $value) {
                        if (isset($value["id"]) && isset($value["name"])) {
                            $category = (object) array("code" => $value["id"], "name" => $value["name"]);
                            $categorys[] = $category;
                        }
                    }

                    $page++;

                    // اگر تعداد آیتم‌ها کمتر از تعداد درخواست شده بود، به اتمام رسیده‌ایم
                    if (count($responseData) < $perPage) {
                        break;
                    }
                } else {
                    Log::warning('WcTrait::getWcCategory - Invalid JSON response', [
                        'response' => $response
                    ]);
                    break;
                }
            } else {
                Log::error('WcTrait::getWcCategory - HTTP Error', [
                    'response_code' => $responseCode,
                    'response' => $response
                ]);
                break;
            }
        }

        Log::info('WcTrait::getWcCategory - Final result', [
            'total_categories' => count($categorys),
            'sample_categories' => array_slice($categorys, 0, 3)
        ]);

        return $categorys;
    }

    /**
     * دریافت دسته‌بندی‌های WooCommerce با استفاده مستقیم از API credentials
     */
    private function getWcCategoryDirect($siteUrl, $apiKey, $apiSecret): array
    {
        Log::info('WcTrait::getWcCategoryDirect called', [
            'site_url' => $siteUrl,
            'api_key_set' => !empty($apiKey),
            'api_secret_set' => !empty($apiSecret)
        ]);

        // بررسی تنظیمات اساسی
        if (empty($siteUrl) || empty($apiKey) || empty($apiSecret)) {
            Log::error('WcTrait::getWcCategoryDirect - Missing required API settings', [
                'site_url' => $siteUrl ?: 'EMPTY',
                'api_key' => !empty($apiKey) ? 'SET' : 'EMPTY',
                'api_secret' => !empty($apiSecret) ? 'SET' : 'EMPTY'
            ]);
            return [];
        }

        // اطمینان از اینکه URL به درستی تشکیل شده
        $baseUrl = rtrim($siteUrl, '/');
        if (!str_starts_with($baseUrl, 'http://') && !str_starts_with($baseUrl, 'https://')) {
            $baseUrl = 'https://' . $baseUrl;
        }

        $page = 1;
        $perPage = 100;
        $categories = [];

        while (true) {
            $apiUrl = $baseUrl . '/wp-json/wc/v3/products/categories?page=' . $page . '&per_page=' . $perPage;

            Log::info('WcTrait::getWcCategoryDirect - Making API request', [
                'url' => $apiUrl,
                'page' => $page
            ]);

            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $apiUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_USERAGENT => 'Holoo-Account-Manager',
                CURLOPT_USERPWD => $apiKey . ":" . $apiSecret,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ));

            $response = curl_exec($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            if (curl_error($curl)) {
                Log::error('WcTrait::getWcCategoryDirect - CURL Error', [
                    'error' => curl_error($curl),
                    'url' => $apiUrl
                ]);
                curl_close($curl);
                break;
            }

            curl_close($curl);

            Log::info('WcTrait::getWcCategoryDirect - API Response', [
                'response_code' => $responseCode,
                'response_length' => strlen($response),
                'response_preview' => substr($response, 0, 200)
            ]);

            if ($responseCode == 200) {
                $responseData = json_decode($response, true);

                if ($responseData != null && is_array($responseData)) {
                    Log::info('WcTrait::getWcCategoryDirect - Processing categories', [
                        'count' => count($responseData)
                    ]);

                    foreach ($responseData as $value) {
                        if (isset($value["id"]) && isset($value["name"])) {
                            $category = (object) array("code" => $value["id"], "name" => $value["name"]);
                            $categories[] = $category;
                        }
                    }

                    $page++;

                    // اگر تعداد آیتم‌ها کمتر از تعداد درخواست شده بود، به اتمام رسیده‌ایم
                    if (count($responseData) < $perPage) {
                        break;
                    }
                } else {
                    Log::warning('WcTrait::getWcCategoryDirect - Invalid JSON response', [
                        'response' => substr($response, 0, 500)
                    ]);
                    break;
                }
            } else {
                Log::error('WcTrait::getWcCategoryDirect - HTTP Error', [
                    'response_code' => $responseCode,
                    'response' => substr($response, 0, 500)
                ]);
                break;
            }
        }

        Log::info('WcTrait::getWcCategoryDirect - Final result', [
            'total_categories' => count($categories),
            'sample_categories' => array_slice($categories, 0, 3)
        ]);

        return $categories;
    }

    /**
     * @param User $user
     * @param int $categoryId
     * @return array
     */
    public function getWcCategoryChildren($user, $categoryId): array
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $user->siteUrl . '/wp-json/wc/v3/products/categories/' . $categoryId . '/children',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_USERAGENT => 'Holoo',
            CURLOPT_USERPWD => $user->consumerKey . ":" . $user->consumerSecret,
        ));

        $response = curl_exec($curl);

        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get the response code
        $categorys = [];
        if ($responseCode == 200) {
            $responseData = json_decode($response, true); // Decode the JSON response
            if ($responseData != null) {
                foreach ($responseData as $value) {
                    $categorys[] = (object)array("code" => $value["id"], "name" => $value["name"]);
                }
            }
        }

        curl_close($curl);
        return $categorys;
    }

    public function paymentGateways($user): array
    {
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $user->siteUrl . '/wp-json/wc/v3/payment_gateways',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_USERAGENT => 'Holoo',
            CURLOPT_USERPWD => $user->consumerKey . ":" . $user->consumerSecret,
        ));

        $response = curl_exec($curl);

        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get the response code
        $gateways = [];
        if ($responseCode == 200) {
            $responseData = json_decode($response, true); // Decode the JSON response
            if ($responseData != null)
                foreach ($responseData as $value) {
                    $gateways[] = (object)array("id" => $value["id"], "title" => $value["title"]);
                }
        }

        curl_close($curl);
        return $gateways;
    }

    /**
     * @param User $user
     * @return $this|array
     */
    public function testWcProduct($user): array
    {
        $startTime = microtime(true); // Start time
        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => $user->siteUrl . '/wp-json/wc/v3/products',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_USERAGENT => 'Holoo',
            CURLOPT_USERPWD => $user->consumerKey . ":" . $user->consumerSecret,
        ));

        $response = curl_exec($curl);

        $endTime = microtime(true); // End time
        $executionTime = round($endTime - $startTime, 2);  // Calculate execution time in seconds

        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE); // Get the response code
        $products = [];
        if ($responseCode == 200) {
            $responseData = json_decode($response, true); // Decode the JSON response
            if ($responseData != null) {
                foreach ($responseData as $value) {
                    $products[] = (object)array("code" => $value["id"], "name" => $value["name"]);
                }
            }
        }

        curl_close($curl);
        return ["products" => $products, "response" => $responseCode, "executionTime" => $executionTime];
    }

    /**
     * دریافت دسته‌بندی‌های WooCommerce برای لایسنس
     */
    public function getWcCategories($license)
    {
        if (!$license->woocommerceApiKey) {
            return [];
        }

        $categories = [];
        $page = 1;
        $perPage = 100;

        try {
            while (true) {
                $curl = curl_init();

                curl_setopt_array($curl, array(
                    CURLOPT_URL => $license->website_url . '/wp-json/wc/v3/products/categories?page=' . $page . '&per_page=' . $perPage,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'GET',
                    CURLOPT_USERAGENT => 'SamTaTech',
                    CURLOPT_USERPWD => $license->woocommerceApiKey->api_key . ":" . $license->woocommerceApiKey->api_secret,
                ));

                $response = curl_exec($curl);
                $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

                if ($responseCode == 200) {
                    $responseData = json_decode($response, true);

                    if ($responseData && is_array($responseData)) {
                        foreach ($responseData as $category) {
                            $categories[] = [
                                'id' => $category['id'],
                                'name' => $category['name'],
                                'parent' => $category['parent'] ?? 0
                            ];
                        }

                        if (count($responseData) < $perPage) {
                            break;
                        }
                        $page++;
                    } else {
                        break;
                    }
                } else {
                    break;
                }

                curl_close($curl);
            }
        } catch (\Exception $e) {
            Log::error('Error fetching WC categories: ' . $e->getMessage());
        }

        return $categories;
    }

    /**
     * ایجاد محصول در WooCommerce
     */
    public function createWcProduct($license, $productData)
    {
        if (!$license->woocommerceApiKey) {
            return ['success' => false, 'message' => 'API Key تنظیم نشده'];
        }

        try {
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $license->website_url . '/wp-json/wc/v3/products',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($productData),
                CURLOPT_USERAGENT => 'SamTaTech',
                CURLOPT_USERPWD => $license->woocommerceApiKey->api_key . ":" . $license->woocommerceApiKey->api_secret,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                ),
            ));

            $response = curl_exec($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            curl_close($curl);

            if ($responseCode >= 200 && $responseCode < 300) {
                $responseData = json_decode($response, true);
                return [
                    'success' => true,
                    'data' => $responseData,
                    'message' => 'محصول با موفقیت ایجاد شد'
                ];
            } else {
                $errorData = json_decode($response, true);
                return [
                    'success' => false,
                    'message' => $errorData['message'] ?? 'خطا در ایجاد محصول',
                    'code' => $responseCode
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در ارتباط با WooCommerce: ' . $e->getMessage()
            ];
        }
    }

    /**
     * ایجاد گونه محصول در WooCommerce
     */
    public function createWcProductVariation($license, $parentProductId, $variationData)
    {
        if (!$license->woocommerceApiKey) {
            return ['success' => false, 'message' => 'API Key تنظیم نشده'];
        }

        try {
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $license->website_url . '/wp-json/wc/v3/products/' . $parentProductId . '/variations',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($variationData),
                CURLOPT_USERAGENT => 'SamTaTech',
                CURLOPT_USERPWD => $license->woocommerceApiKey->api_key . ":" . $license->woocommerceApiKey->api_secret,
                CURLOPT_HTTPHEADER => array(
                    'Content-Type: application/json',
                ),
            ));

            $response = curl_exec($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            curl_close($curl);

            if ($responseCode >= 200 && $responseCode < 300) {
                $responseData = json_decode($response, true);
                return [
                    'success' => true,
                    'data' => $responseData,
                    'message' => 'گونه محصول با موفقیت ایجاد شد'
                ];
            } else {
                $errorData = json_decode($response, true);
                return [
                    'success' => false,
                    'message' => $errorData['message'] ?? 'خطا در ایجاد گونه محصول',
                    'code' => $responseCode
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در ارتباط با WooCommerce: ' . $e->getMessage()
            ];
        }
    }

    /**
     * جستجوی محصول در WooCommerce بر اساس SKU
     */
    public function findWcProductBySku($license, $sku)
    {
        if (!$license->woocommerceApiKey) {
            return null;
        }

        try {
            $curl = curl_init();

            curl_setopt_array($curl, array(
                CURLOPT_URL => $license->website_url . '/wp-json/wc/v3/products?sku=' . urlencode($sku),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_USERAGENT => 'SamTaTech',
                CURLOPT_USERPWD => $license->woocommerceApiKey->api_key . ":" . $license->woocommerceApiKey->api_secret,
            ));

            $response = curl_exec($curl);
            $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

            curl_close($curl);

            if ($responseCode == 200) {
                $responseData = json_decode($response, true);
                return !empty($responseData) ? $responseData[0] : null;
            }
        } catch (\Exception $e) {
            Log::error('Error finding WC product by SKU: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * دریافت دسته‌بندی‌های WooCommerce برای یک لایسنس
     */
    public function getWooCommerceCategories($license)
    {
        try {
            Log::info('WcTrait::getWooCommerceCategories called', [
                'license_id' => $license->id,
                'web_service_type' => $license->web_service_type,
                'user_id' => $license->user_id ?? 'NULL'
            ]);

            // بررسی اینکه لایسنس WooCommerce API key دارد یا نه
            $wooCommerceApiKey = $license->woocommerceApiKey;

            if (!$wooCommerceApiKey) {
                Log::error('WcTrait::getWooCommerceCategories - No WooCommerce API key found for license', [
                    'license_id' => $license->id
                ]);
                return [];
            }

            Log::info('WcTrait::getWooCommerceCategories - WooCommerce API key found', [
                'license_id' => $license->id,
                'api_key_set' => !empty($wooCommerceApiKey->api_key),
                'api_secret_set' => !empty($wooCommerceApiKey->api_secret),
                'website_url' => $license->website_url
            ]);

            // استفاده از اطلاعات لایسنس برای WooCommerce API
            $categories = $this->getWcCategoryDirect(
                $license->website_url,
                $wooCommerceApiKey->api_key,
                $wooCommerceApiKey->api_secret
            );

            Log::info('WcTrait::getWooCommerceCategories - Result', [
                'categories_count' => count($categories)
            ]);

            return $categories;

        } catch (\Exception $e) {
            Log::error('WcTrait::getWooCommerceCategories - Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }
}
