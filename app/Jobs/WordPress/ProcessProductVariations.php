<?php

namespace App\Jobs\WordPress;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\License;
use App\Jobs\WordPress\ProcessSkuBatch;
use App\Traits\WordPress\WordPressMasterTrait;
use Exception;

class ProcessProductVariations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WordPressMasterTrait;

    protected $licenseId;
    protected $productId;

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
    public $timeout = 60; // 60 ثانیه

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public $backoff = [10, 30];

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId, $productId)
    {
        $this->licenseId = $licenseId;
        $this->productId = $productId;
    }

    /**
     * Execute the job.
     * پردازش محصول و دریافت variations آن
     */
    public function handle(): void
    {
        try {
            $productId = $this->productId;

            Log::info('شروع پردازش محصول', [
                'license_id' => $this->licenseId,
                'product_id' => $productId
            ]);

            $license = License::find($this->licenseId);
            if (!$license || !$license->isActive()) {
                Log::warning('لایسنس فعال نیست', ['license_id' => $this->licenseId]);
                return;
            }

            // دریافت اطلاعات محصول
            $productData = $this->getProductData($license, $productId);
            if (!$productData) {
                Log::warning('محصول یافت نشد', [
                    'license_id' => $this->licenseId,
                    'product_id' => $productId
                ]);
                return;
            }

            $productType = $productData['type'] ?? null;

            Log::info('اطلاعات محصول دریافت شد', [
                'product_id' => $productId,
                'product_type' => $productType
            ]);

            $skus = [];

            // محصول ساده بدون unique_id
            if ($productType !== 'variable' && empty($productData['bim_unique_id']) && !empty($productData['sku'])) {
                $skus[] = [
                    'sku' => $productData['sku'],
                    'product_id' => $productId,
                    'type' => 'product'
                ];

                Log::info('محصول ساده بدون unique_id', [
                    'product_id' => $productId,
                    'sku' => $productData['sku']
                ]);
            }

            // محصول متغیر - دریافت variations
            if ($productType === 'variable') {
                Log::info('محصول متغیر شناخته شد - دریافت variations', [
                    'product_id' => $productId
                ]);

                $variations = $this->getProductVariations($license, $productId);

                Log::info('variations دریافت شدند', [
                    'product_id' => $productId,
                    'variations_count' => count($variations)
                ]);

                foreach ($variations as $variation) {
                    if (empty($variation['bim_unique_id']) && !empty($variation['sku'])) {
                        $skus[] = [
                            'sku' => $variation['sku'],
                            'product_id' => $productId,
                            'variation_id' => $variation['id'],
                            'type' => 'variation'
                        ];

                        Log::info('variation بدون unique_id', [
                            'product_id' => $productId,
                            'variation_id' => $variation['id'],
                            'sku' => $variation['sku']
                        ]);
                    }
                }
            }

            // ارسال SKU‌ها برای پردازش batch
            if (!empty($skus)) {
                Log::info('SKU‌های پیدا شده - ارسال برای batch processing', [
                    'product_id' => $productId,
                    'skus_count' => count($skus)
                ]);

                $skuBatches = array_chunk($skus, 50);

                foreach ($skuBatches as $batchIndex => $skuBatch) {
                    ProcessSkuBatch::dispatch($this->licenseId, $skuBatch)
                        ->onQueue('empty-unique-ids')
                        ->delay(now()->addSeconds($batchIndex * 1));
                }

                Log::info('Batches ارسال شدند', [
                    'product_id' => $productId,
                    'batches_count' => count($skuBatches)
                ]);
            } else {
                Log::info('هیچ SKU بدون unique_id یافت نشد', [
                    'product_id' => $productId,
                    'product_type' => $productType
                ]);
            }

            Log::info('پایان پردازش محصول', [
                'license_id' => $this->licenseId,
                'product_id' => $productId
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در پردازش محصول', [
                'license_id' => $this->licenseId,
                'product_id' => $this->productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * دریافت اطلاعات محصول از WooCommerce
     */
    private function getProductData($license, $productId)
    {
        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                return null;
            }

            Log::info('درخواست اطلاعات محصول از WooCommerce', [
                'product_id' => $productId
            ]);

            $result = $this->getWooCommerceProduct(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                $productId
            );

            if (!$result['success']) {
                Log::warning('خطا در دریافت اطلاعات محصول', [
                    'product_id' => $productId,
                    'error' => $result['message']
                ]);
                return null;
            }

            return $result['data'] ?? null;

        } catch (Exception $e) {
            Log::error('خطا در دریافت اطلاعات محصول', [
                'license_id' => $this->licenseId,
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * دریافت تمام variations یک محصول متغیر
     */
    private function getProductVariations($license, $productId)
    {
        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                return [];
            }

            $allVariations = [];
            $page = 1;

            while ($page <= 5) { // حداکثر 5 صفحه = 500 variation
                $params = [
                    'page' => $page,
                    'per_page' => 100
                ];

                Log::info('درخواست variations', [
                    'product_id' => $productId,
                    'page' => $page
                ]);

                $result = $this->getWooCommerceProductVariations(
                    $license->website_url,
                    $wooApiKey->api_key,
                    $wooApiKey->api_secret,
                    $productId,
                    $params
                );

                if (!$result['success']) {
                    Log::warning('خطا در دریافت variations', [
                        'product_id' => $productId,
                        'page' => $page,
                        'error' => $result['message']
                    ]);
                    break;
                }

                $variations = $result['data'] ?? [];

                if (empty($variations)) {
                    Log::info('تمام variations دریافت شدند', [
                        'product_id' => $productId,
                        'total_pages' => $page - 1,
                        'total_variations' => count($allVariations)
                    ]);
                    break;
                }

                $allVariations = array_merge($allVariations, $variations);

                Log::info('صفحه variations دریافت شد', [
                    'product_id' => $productId,
                    'page' => $page,
                    'page_count' => count($variations),
                    'total_so_far' => count($allVariations)
                ]);

                if (count($variations) < 100) {
                    break; // آخرین صفحه
                }

                $page++;
            }

            return $allVariations;

        } catch (Exception $e) {
            Log::error('خطا در دریافت variations', [
                'license_id' => $this->licenseId,
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}
