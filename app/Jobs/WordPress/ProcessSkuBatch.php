<?php

namespace App\Jobs\WordPress;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\License;
use App\Traits\WordPress\WordPressMasterTrait;
use Exception;

class ProcessSkuBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WordPressMasterTrait;

    protected $licenseId;
    protected $skus;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public $maxExceptions = 2;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 45; // 45 ثانیه

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public $backoff = [5, 15, 30];

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId, $skus)
    {
        $this->licenseId = $licenseId;
        $this->skus = $skus;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Starting SKU batch processing job', [
                'license_id' => $this->licenseId,
                'sku_count' => count($this->skus)
            ]);

            $license = License::find($this->licenseId);
            if (!$license || !$license->isActive()) {
                Log::error('Invalid or inactive license in SKU batch job', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            $user = $license->user;
            if (!$user) {
                Log::error('User not found for license in SKU batch job', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                Log::error('WooCommerce API key not found for license in SKU batch job', [
                    'license_id' => $license->id
                ]);
                return;
            }

            // بارگذاری تنظیمات کاربر برای دسترسی به default_warehouse_code
            $license->load('userSetting');
            $stockId = $license->userSetting ? $license->userSetting->default_warehouse_code : '';

            Log::info('استفاده از default_warehouse_code برای stockId', [
                'license_id' => $this->licenseId,
                'stock_id' => $stockId,
                'has_user_settings' => !is_null($license->userSetting)
            ]);

            // Get unique IDs from database - hybrid approach
            $result = $this->getUniqueIdsBySkusFromBaran($this->skus, $user, $stockId);
            $uniqueIdMapping = $result['found'] ?? [];
            $notFoundSkus = $result['not_found'] ?? [];

            // Log detailed info about found and not found SKUs
            Log::info('نتیجه جستجوی SKU', [
                'license_id' => $this->licenseId,
                'found_count' => count($uniqueIdMapping),
                'not_found_count' => count($notFoundSkus),
                'found_skus' => array_map(function($item) { return $item['sku']; }, $uniqueIdMapping),
                'not_found_skus' => $notFoundSkus
            ]);

            // اگر SKU‌های یافت شده داشته باشیم، آنها را به WooCommerce ارسال کنید
            if (!empty($uniqueIdMapping)) {
                // Update products with unique IDs using the new endpoint
                $this->batchUpdateUniqueIds($license->website_url, $wooApiKey, $uniqueIdMapping);
            }

            // اگر SKU‌های یافت نشده داشته باشیم، آنها را لاگ کنید
            if (!empty($notFoundSkus)) {
                Log::warning('SKU‌های یافت نشده در جدول محلی - نیازمند درج در انبار', [
                    'license_id' => $this->licenseId,
                    'not_found_count' => count($notFoundSkus),
                    'not_found_details' => $notFoundSkus,
                    'note' => 'خیر اگر نبود نیاز نیست از باران بگیرد'
                ]);
            }

            Log::info('SKU batch processing job completed successfully', [
                'license_id' => $this->licenseId,
                'sku_count' => count($this->skus)
            ]);

        } catch (\Exception $e) {
            Log::error('Error in SKU batch processing job', [
                'license_id' => $this->licenseId,
                'sku_count' => count($this->skus),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Get unique IDs by SKUs - check local database only
     */
    private function getUniqueIdsBySkusFromBaran($skus, $user, $stockId)
    {
        $found = [];
        $notFound = [];

        try {
            Log::info('جستجوی محصولات در جدول محلی', [
                'license_id' => $this->licenseId,
                'sku_count' => count($skus),
                'stock_id' => $stockId,
                'skus_detail' => $skus
            ]);

            // جستجو در جدول products برای تمام SKU‌ها
            foreach ($skus as $skuItem) {
                // Handle both array and string formats
                $barcode = is_array($skuItem) ? $skuItem['sku'] : $skuItem;
                $productId = is_array($skuItem) ? ($skuItem['product_id'] ?? null) : null;
                $variationId = is_array($skuItem) ? ($skuItem['variation_id'] ?? null) : null;
                $type = is_array($skuItem) ? ($skuItem['type'] ?? 'product') : 'product';

                $product = \App\Models\Product::where('license_id', $this->licenseId)
                    ->where('barcode', $barcode)
                    ->first();

                if ($product && $product->item_id) {
                    $mapping = [
                        'unique_id' => $product->item_id,
                        'sku' => $barcode
                    ];

                    // اگر واریانت است، product_id و variation_id را اضافه کنید
                    if ($variationId && $productId) {
                        $mapping['product_id'] = $productId;
                        $mapping['variation_id'] = $variationId;
                    }

                    $found[] = $mapping;

                    Log::info('محصول در جدول محلی یافت شد', [
                        'barcode' => $barcode,
                        'item_id' => $product->item_id,
                        'stock_id' => $product->stock_id,
                        'license_id' => $this->licenseId,
                        'product_id' => $productId,
                        'variation_id' => $variationId,
                        'type' => $type
                    ]);
                } else {
                    $notFoundItem = [
                        'sku' => $barcode,
                        'product_id' => $productId,
                        'variation_id' => $variationId,
                        'type' => $type
                    ];

                    $notFound[] = $notFoundItem;

                    Log::warning('محصول در جدول محلی یافت نشد', [
                        'barcode' => $barcode,
                        'license_id' => $this->licenseId,
                        'product_id' => $productId,
                        'variation_id' => $variationId,
                        'type' => $type
                    ]);
                }
            }

            Log::info('نتیجه جستجو در جدول محلی', [
                'license_id' => $this->licenseId,
                'total_skus' => count($skus),
                'found_count' => count($found),
                'not_found_count' => count($notFound)
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در دریافت کدهای یکتا', [
                'license_id' => $this->licenseId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return [
            'found' => $found,
            'not_found' => $notFound
        ];
    }

    /**
     * Batch update bim_unique_id in WooCommerce
     */
    private function batchUpdateUniqueIds($websiteUrl, $wooApiKey, $uniqueIdMapping)
    {
        try {
            if (empty($uniqueIdMapping)) {
                Log::info('No unique IDs to update', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            // Separate simple products and variations
            $simpleProducts = [];
            $variations = [];

            foreach ($uniqueIdMapping as $item) {
                if (isset($item['variation_id']) && isset($item['product_id'])) {
                    $variations[] = $item;
                } else {
                    $simpleProducts[] = $item;
                }
            }

            Log::info('آماده‌سازی برای batch update', [
                'license_id' => $this->licenseId,
                'total_items' => count($uniqueIdMapping),
                'simple_products' => count($simpleProducts),
                'variations' => count($variations)
            ]);

            // Update simple products using batch endpoint
            if (!empty($simpleProducts)) {
                $this->batchUpdateSimpleProducts($websiteUrl, $wooApiKey, $simpleProducts);
            }

            // Update variations using individual endpoints
            if (!empty($variations)) {
                $this->batchUpdateVariations($websiteUrl, $wooApiKey, $variations);
            }

        } catch (\Exception $e) {
            Log::error('Error in batch update bim_unique_id', [
                'error' => $e->getMessage(),
                'license_id' => $this->licenseId,
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Update simple products
     */
    private function batchUpdateSimpleProducts($websiteUrl, $wooApiKey, $simpleProducts)
    {
        try {
            Log::info('شروع آپدیت محصولات ساده', [
                'license_id' => $this->licenseId,
                'count' => count($simpleProducts)
            ]);

            $batchData = [
                'products' => $simpleProducts
            ];

            // استفاده از trait برای batch update unique IDs
            $result = $this->batchUpdateWooCommerceUniqueIdsBySku(
                $websiteUrl,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                $batchData
            );

            Log::info('نتیجه آپدیت محصولات ساده', [
                'license_id' => $this->licenseId,
                'success' => $result['success'],
                'message' => $result['message'] ?? 'بدون پیام'
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در آپدیت محصولات ساده', [
                'error' => $e->getMessage(),
                'license_id' => $this->licenseId
            ]);
        }
    }

    /**
     * Update variations individually
     */
    private function batchUpdateVariations($websiteUrl, $wooApiKey, $variations)
    {
        try {
            Log::info('شروع آپدیت واریانت‌ها', [
                'license_id' => $this->licenseId,
                'count' => count($variations)
            ]);

            foreach ($variations as $variation) {
                try {
                    $productId = $variation['product_id'];
                    $variationId = $variation['variation_id'];
                    $uniqueId = $variation['unique_id'];
                    $sku = $variation['sku'];

                    Log::info('آپدیت واریانت', [
                        'product_id' => $productId,
                        'variation_id' => $variationId,
                        'sku' => $sku,
                        'unique_id' => $uniqueId
                    ]);

                    // اگر endpoint batch-update-sku موجود است، از آن استفاده کنید
                    // در غیر این صورت، از endpoint جداگانه variation استفاده کنید
                    $batchData = [
                        'products' => [$variation]
                    ];

                    $result = $this->batchUpdateWooCommerceUniqueIdsBySku(
                        $websiteUrl,
                        $wooApiKey->api_key,
                        $wooApiKey->api_secret,
                        $batchData
                    );

                    if ($result['success']) {
                        Log::info('واریانت با موفقیت آپدیت شد', [
                            'product_id' => $productId,
                            'variation_id' => $variationId,
                            'sku' => $sku
                        ]);
                    } else {
                        Log::warning('خطا در آپدیت واریانت', [
                            'product_id' => $productId,
                            'variation_id' => $variationId,
                            'sku' => $sku,
                            'error' => $result['message']
                        ]);
                    }

                } catch (\Exception $e) {
                    Log::error('خطا در آپدیت واریانت منفرد', [
                        'error' => $e->getMessage(),
                        'variation' => $variation
                    ]);
                }
            }

            Log::info('تکمیل آپدیت تمام واریانت‌ها', [
                'license_id' => $this->licenseId,
                'count' => count($variations)
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در بخش آپدیت واریانت‌ها', [
                'error' => $e->getMessage(),
                'license_id' => $this->licenseId
            ]);
        }
    }
}
