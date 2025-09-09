<?php

namespace App\Traits\WordPress;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

trait WordPressMasterTrait
{
    use WooCommerceApiTrait;
    use WooCommerceProductTrait;
    use WooCommerceSettingsTrait;

    /**
     * به‌روزرسانی کامل محصولات در WordPress/WooCommerce
     * این متد ترکیبی از تمام قابلیت‌های WooCommerce است
     *
     * @param object $license لایسنس
     * @param array $foundProducts محصولات یافت شده از انبار
     * @param object $userSettings تنظیمات کاربر
     * @return array نتیجه به‌روزرسانی کامل
     */
    protected function updateWordPressProducts($license, $foundProducts, $userSettings)
    {
        return $this->updateWooCommerceProducts($license, $foundProducts, $userSettings);
    }

    /**
     * دریافت وضعیت کامل WooCommerce برای یک لایسنس
     *
     * @param object $license لایسنس
     * @param object $userSettings تنظیمات کاربر
     * @return array وضعیت کامل
     */
    protected function getWooCommerceStatus($license, $userSettings)
    {
        try {
            $validation = $this->validateWooCommerceSettings($license, $userSettings);
            $apiSettings = $this->getWooCommerceApiSettings($license);
            $priceSettings = $this->getWooCommercePriceSettings($userSettings);

            return [
                'success' => true,
                'validation' => $validation,
                'api_configured' => !is_null($apiSettings),
                'api_settings' => $apiSettings,
                'price_settings' => $priceSettings,
                'stock_update_enabled' => $userSettings->enable_stock_update ?? false,
                'price_update_enabled' => $userSettings->enable_price_update ?? false,
                'website_url' => $license->website_url
            ];

        } catch (\Exception $e) {
            Log::error('خطا در دریافت وضعیت WooCommerce', [
                'license_id' => $license->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در دریافت وضعیت WooCommerce',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * تست اتصال WooCommerce API
     *
     * @param object $license لایسنس
     * @return array نتیجه تست
     */
    protected function testWooCommerceConnection($license)
    {
        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                return [
                    'success' => false,
                    'message' => 'کلید API WooCommerce تنظیم نشده است'
                ];
            }

            $url = $this->buildWooCommerceUrl($license->website_url, 'system_status');

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30
            ])->withHeaders(
                $this->prepareWooCommerceHeaders($wooApiKey)
            )->get($url);

            if ($response->successful()) {
                $this->logWooCommerceActivity('تست اتصال موفق', [
                    'license_id' => $license->id,
                    'response_code' => $response->status()
                ]);

                return [
                    'success' => true,
                    'message' => 'اتصال به WooCommerce API موفق است',
                    'status_code' => $response->status()
                ];
            } else {
                return $this->handleWooCommerceError($response, 'تست اتصال', [
                    'license_id' => $license->id
                ]);
            }

        } catch (\Exception $e) {
            Log::error('خطا در تست اتصال WooCommerce', [
                'license_id' => $license->id,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'خطای سیستمی در تست اتصال',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * همگام‌سازی کامل محصولات بین انبار و WooCommerce
     *
     * @param object $license لایسنس
     * @param array $warehouseProducts محصولات انبار
     * @param object $userSettings تنظیمات کاربر
     * @param array $options تنظیمات اضافی
     * @return array نتیجه همگام‌سازی
     */
    protected function syncWarehouseToWooCommerce($license, $warehouseProducts, $userSettings, $options = [])
    {
        try {
            $validation = $this->validateWooCommerceSettings($license, $userSettings);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => 'تنظیمات WooCommerce نامعتبر: ' . implode(', ', $validation['errors'])
                ];
            }

            $results = [
                'total_processed' => 0,
                'successful_updates' => 0,
                'failed_updates' => 0,
                'created_products' => 0,
                'updated_products' => 0,
                'errors' => []
            ];

            foreach ($warehouseProducts as $product) {
                $results['total_processed']++;

                try {
                    // بررسی وجود محصول در WooCommerce
                    $existingProduct = $this->checkProductExistsInWooCommerce(
                        $license,
                        $license->woocommerceApiKey,
                        $product
                    );

                    if ($existingProduct) {
                        // به‌روزرسانی محصول موجود
                        $updateResult = $this->updateWooCommerceProduct(
                            $license,
                            $existingProduct['id'],
                            $product,
                            $userSettings
                        );

                        if ($updateResult['success']) {
                            $results['successful_updates']++;
                            $results['updated_products']++;
                        } else {
                            $results['failed_updates']++;
                            $results['errors'][] = $updateResult['message'];
                        }
                    } else {
                        // ایجاد محصول جدید
                        if ($options['create_new_products'] ?? false) {
                            $createResult = $this->createWooCommerceProduct(
                                $license,
                                $product,
                                $userSettings
                            );

                            if ($createResult['success']) {
                                $results['successful_updates']++;
                                $results['created_products']++;
                            } else {
                                $results['failed_updates']++;
                                $results['errors'][] = $createResult['message'];
                            }
                        }
                    }

                } catch (\Exception $e) {
                    $results['failed_updates']++;
                    $results['errors'][] = 'خطا در پردازش محصول: ' . $e->getMessage();
                }
            }

            $this->logWooCommerceActivity('همگام‌سازی کامل', [
                'license_id' => $license->id,
                'results' => $results
            ]);

            return [
                'success' => $results['failed_updates'] === 0,
                'message' => "همگام‌سازی انجام شد. موفق: {$results['successful_updates']}, ناموفق: {$results['failed_updates']}",
                'results' => $results
            ];

        } catch (\Exception $e) {
            Log::error('خطا در همگام‌سازی کامل محصولات', [
                'license_id' => $license->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطای سیستمی در همگام‌سازی',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * بروزرسانی موجودی محصولات ووکامرس بر اساس همه دسته‌بندی‌ها (Job)
     *
     * @param object $license لایسنس
     * @return array نتیجه dispatch job
     */
    protected function dispatchWooCommerceStockUpdateJob($license)
    {
        try {
            // فقط dispatch یک Job کلی برای همه دسته‌ها
            dispatch(new \App\Jobs\UpdateWooCommerceStockByCategoryJob($license->id));

            $this->logWooCommerceActivity('dispatch job به‌روزرسانی موجودی همه دسته‌بندی‌ها', [
                'license_id' => $license->id
            ]);

            return [
                'success' => true,
                'message' => 'فرایند بروزرسانی موجودی برای همه دسته‌بندی‌های ووکامرس در صف قرار گرفت.'
            ];

        } catch (\Exception $e) {
            Log::error('خطا در بروزرسانی موجودی همه دسته‌بندی‌ها: ' . $e->getMessage(), [
                'license_id' => $license->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'خطای سیستمی در بروزرسانی موجودی'
            ];
        }
    }
}
