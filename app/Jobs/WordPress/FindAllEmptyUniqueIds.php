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

class FindAllEmptyUniqueIds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WordPressMasterTrait;

    protected $licenseId;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 2;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public $maxExceptions = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 300; // 5 دقیقه

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public $backoff = [30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId)
    {
        $this->licenseId = $licenseId;
    }

    /**
     * Execute the job.
     * جستجوی تمام محصولات و variations بدون unique_id
     */
    public function handle(): void
    {
        try {
            Log::info('شروع جستجوی تمام محصولات بدون unique_id', [
                'license_id' => $this->licenseId
            ]);

            $license = License::find($this->licenseId);
            if (!$license || !$license->isActive()) {
                Log::error('لایسنس نامعتبر یا غیرفعال', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                Log::error('کلید API WooCommerce یافت نشد', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            // دریافت تمام محصولات بدون pagination (تمام صفحات)
            $allProducts = $this->getAllWooCommerceProductsUnlimited($license, $wooApiKey);

            Log::info('تمام محصولات دریافت شد', [
                'license_id' => $this->licenseId,
                'total_products' => count($allProducts)
            ]);

            // پردازش تمام محصولات برای پیدا کردن SKU‌های بدون unique_id
            $allSkus = [];

            foreach ($allProducts as $product) {
                // محصولات ساده بدون unique_id
                if ($product['type'] !== 'variable' && empty($product['bim_unique_id']) && !empty($product['sku'])) {
                    $allSkus[] = [
                        'sku' => $product['sku'],
                        'product_id' => $product['id'],
                        'type' => 'product'
                    ];

                    Log::info('محصول ساده بدون unique_id', [
                        'product_id' => $product['id'],
                        'sku' => $product['sku']
                    ]);
                }

                // تمام محصولات variable (چه unique_id داشته باشند یا نه)
                // برای بررسی variations آنها
                if ($product['type'] === 'variable') {
                    Log::info('دریافت variations برای محصول variable', [
                        'product_id' => $product['id'],
                        'parent_unique_id' => $product['bim_unique_id'] ?? 'empty',
                        'note' => 'دریافت تمام variations بدون توجه به unique_id محصول مادر'
                    ]);

                    $variations = $this->getAllVariationsForProduct($license, $wooApiKey, $product['id']);

                    // تمام variations بدون unique_id (محصول مادر فارغ از وضعیت آن)
                    foreach ($variations as $variation) {
                        if (empty($variation['bim_unique_id']) && !empty($variation['sku'])) {
                            $allSkus[] = [
                                'sku' => $variation['sku'],
                                'product_id' => $product['id'],
                                'variation_id' => $variation['id'],
                                'type' => 'variation'
                            ];

                            Log::info('variation بدون unique_id (محصول مادر ممکن است یا نباشد unique_id)', [
                                'product_id' => $product['id'],
                                'variation_id' => $variation['id'],
                                'sku' => $variation['sku'],
                                'parent_has_unique_id' => !empty($product['bim_unique_id'])
                            ]);
                        }
                    }

                    Log::info('تکمیل دریافت variations برای محصول variable', [
                        'product_id' => $product['id'],
                        'total_variations' => count($variations),
                        'variations_without_unique_id' => count(array_filter($variations, function($v) {
                            return empty($v['bim_unique_id']) && !empty($v['sku']);
                        }))
                    ]);
                }
            }

            Log::info('تمام SKU‌های بدون unique_id شناسایی شد', [
                'license_id' => $this->licenseId,
                'total_skus' => count($allSkus),
                'skus' => $allSkus
            ]);

            // ارسال SKU‌ها برای batch processing
            if (!empty($allSkus)) {
                $skuBatches = array_chunk($allSkus, 50);

                foreach ($skuBatches as $batchIndex => $skuBatch) {
                    ProcessSkuBatch::dispatch($this->licenseId, $skuBatch)
                        ->onQueue('empty-unique-ids')
                        ->delay(now()->addSeconds($batchIndex * 2));

                    Log::info('Batch SKU ارسال شد', [
                        'license_id' => $this->licenseId,
                        'batch_index' => $batchIndex,
                        'batch_size' => count($skuBatch)
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error('خطا در جستجوی تمام محصولات', [
                'license_id' => $this->licenseId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * دریافت تمام محصولات بدون محدودیت صفحات
     */
    private function getAllWooCommerceProductsUnlimited($license, $wooApiKey)
    {
        $allProducts = [];
        $page = 1;
        $maxPages = 100; // حداکثر 100 صفحه (10,000 محصول)
        $hasMore = true;

        try {
            while ($hasMore && $page <= $maxPages) {
                Log::info('دریافت صفحه محصولات', [
                    'license_id' => $this->licenseId,
                    'page' => $page
                ]);

                $result = $this->getWooCommerceProducts(
                    $license->website_url,
                    $wooApiKey->api_key,
                    $wooApiKey->api_secret,
                    ['page' => $page, 'per_page' => 100]
                );

                if (!$result['success'] || empty($result['data'])) {
                    Log::info('پایان دریافت محصولات', [
                        'license_id' => $this->licenseId,
                        'last_page' => $page - 1,
                        'total_products' => count($allProducts)
                    ]);
                    $hasMore = false;
                    break;
                }

                $products = $result['data'];
                $allProducts = array_merge($allProducts, $products);

                Log::info('صفحه محصولات دریافت شد', [
                    'license_id' => $this->licenseId,
                    'page' => $page,
                    'page_products' => count($products),
                    'total_so_far' => count($allProducts)
                ]);

                if (count($products) < 100) {
                    $hasMore = false;
                }

                $page++;
            }

        } catch (\Exception $e) {
            Log::error('خطا در دریافت صفحات محصولات', [
                'license_id' => $this->licenseId,
                'error' => $e->getMessage()
            ]);
        }

        return $allProducts;
    }

    /**
     * دریافت تمام variations برای یک محصول
     */
    private function getAllVariationsForProduct($license, $wooApiKey, $productId)
    {
        $allVariations = [];
        $page = 1;
        $hasMore = true;

        try {
            while ($hasMore && $page <= 10) {
                $result = $this->getWooCommerceProductVariations(
                    $license->website_url,
                    $wooApiKey->api_key,
                    $wooApiKey->api_secret,
                    $productId,
                    ['page' => $page, 'per_page' => 100]
                );

                if (!$result['success'] || empty($result['data'])) {
                    break;
                }

                $variations = $result['data'];
                $allVariations = array_merge($allVariations, $variations);

                Log::info('صفحه variations دریافت شد', [
                    'product_id' => $productId,
                    'page' => $page,
                    'page_variations' => count($variations),
                    'total_so_far' => count($allVariations)
                ]);

                if (count($variations) < 100) {
                    $hasMore = false;
                }

                $page++;
            }

        } catch (\Exception $e) {
            Log::error('خطا در دریافت variations', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
        }

        return $allVariations;
    }
}
