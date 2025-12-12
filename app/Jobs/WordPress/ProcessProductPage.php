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
use App\Jobs\WordPress\ProcessSkuBatch;
use App\Traits\WordPress\WordPressMasterTrait;
use Exception;

class ProcessProductPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WordPressMasterTrait;

    protected $licenseId;
    protected $page;

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
    public $timeout = 50; // 50 ثانیه

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public $backoff = [10, 30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId, $page)
    {
        $this->licenseId = $licenseId;
        $this->page = $page;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        $maxExecutionTime = 45; // 45 ثانیه

        try {
            Log::info('شروع پردازش صفحه محصولات', [
                'license_id' => $this->licenseId,
                'page' => $this->page
            ]);

            $license = License::find($this->licenseId);
            if (!$license || !$license->isActive()) {
                return;
            }

            // Get products for this specific page
            $products = $this->getProductsPage($license, $this->page);

            if (empty($products)) {
                Log::info('صفحه خالی است - پایان پردازش', [
                    'license_id' => $this->licenseId,
                    'page' => $this->page
                ]);
                return;
            }

            // Extract SKUs from products that need unique ID
            $skus = [];
            $productsProcessed = 0;

            foreach ($products as $product) {
                // بررسی زمان اجرا
                $currentTime = microtime(true);
                $elapsedTime = $currentTime - $startTime;

                if ($elapsedTime > $maxExecutionTime) {
                    // ارسال job جدید برای ادامه کار از همان صفحه
                    ProcessProductPage::dispatch($this->licenseId, $this->page)
                        ->onQueue('empty-unique-ids')
                        ->delay(now()->addSeconds(5));

                    Log::info('زمان اجرا تمام شد - صفحه جدید ارسال شد', [
                        'license_id' => $this->licenseId,
                        'page' => $this->page,
                        'elapsed_time' => $elapsedTime
                    ]);

                    break;
                }

                // بررسی اینکه آیا محصول نیاز به unique_id دارد
                $needsUniqueId = empty($product['bim_unique_id']);

                if ($needsUniqueId && !empty($product['sku'])) {
                    // تنها محصولات ساده را اضافه کنید
                    // محصولات متغیر از طریق واریانت‌های آنها پردازش می‌شوند
                    if ($product['type'] !== 'variable') {
                        $skus[] = [
                            'sku' => $product['sku'],
                            'product_id' => $product['id'],
                            'type' => 'product'
                        ];

                        Log::info('محصول بدون unique_id یافت شد', [
                            'product_id' => $product['id'],
                            'sku' => $product['sku'],
                            'type' => $product['type']
                        ]);
                    }
                }

                // Handle variations for variable products
                if ($product['type'] === 'variable' && $elapsedTime < ($maxExecutionTime - 10)) {
                    $variations = $this->getVariationSkus($license, $product['id']);

                    foreach ($variations as $variation) {
                        if (empty($variation['bim_unique_id']) && !empty($variation['sku'])) {
                            $skus[] = [
                                'sku' => $variation['sku'],
                                'product_id' => $product['id'],
                                'variation_id' => $variation['id'],
                                'type' => 'variation'
                            ];

                            Log::info('واریانت بدون unique_id یافت شد', [
                                'product_id' => $product['id'],
                                'variation_id' => $variation['id'],
                                'sku' => $variation['sku']
                            ]);
                        }
                    }
                }

                $productsProcessed++;
            }

            if (!empty($skus)) {
                // Process SKUs in batches of 50
                $skuBatches = array_chunk($skus, 50);

                foreach ($skuBatches as $batchIndex => $skuBatch) {
                    ProcessSkuBatch::dispatch($this->licenseId, $skuBatch)
                        ->onQueue('empty-unique-ids')
                        ->delay(now()->addSeconds($batchIndex * 2));

                    Log::info('Batch SKU برای پردازش ارسال شد', [
                        'license_id' => $this->licenseId,
                        'batch_index' => $batchIndex,
                        'batch_size' => count($skuBatch)
                    ]);
                }
            } else {
                Log::info('هیچ محصولی بدون unique_id در این صفحه نیافت شد', [
                    'license_id' => $this->licenseId,
                    'page' => $this->page,
                    'total_products' => count($products)
                ]);
            }

            // بررسی نهایی زمان
            $finalElapsedTime = microtime(true) - $startTime;

            // اگر تعداد محصولات کمتر از 100 است، صفحات دیگر وجود ندارد (این آخرین صفحه است)
            // اگر تعداد برابر 100 است و زمان اجرا کم است، صفحه بعد را پردازش کن
            if (count($products) === 100 && $finalElapsedTime < $maxExecutionTime) {
                ProcessProductPage::dispatch($this->licenseId, $this->page + 1)
                    ->onQueue('empty-unique-ids')
                    ->delay(now()->addSeconds(10));

                Log::info('ارسال صفحه بعد برای پردازش', [
                    'license_id' => $this->licenseId,
                    'current_page' => $this->page,
                    'next_page' => $this->page + 1,
                    'elapsed_time' => $finalElapsedTime
                ]);
            } elseif (count($products) < 100) {
                Log::info('پایان پردازش - تمام صفحات تکمیل شد', [
                    'license_id' => $this->licenseId,
                    'current_page' => $this->page,
                    'products_in_this_page' => count($products),
                    'total_skus_without_unique_id' => count($skus)
                ]);
            } else {
                Log::warning('صفحه بعد ارسال نشد - زمان اجرا بیش از حد مجاز است', [
                    'license_id' => $this->licenseId,
                    'current_page' => $this->page,
                    'elapsed_time' => $finalElapsedTime,
                    'max_time' => $maxExecutionTime
                ]);
            }

            Log::info('پایان پردازش صفحه محصولات', [
                'license_id' => $this->licenseId,
                'page' => $this->page,
                'products_processed' => $productsProcessed,
                'total_products_in_page' => count($products),
                'skus_needing_unique_id' => count($skus)
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در پردازش صفحه محصولات: ' . $e->getMessage(), [
                'license_id' => $this->licenseId,
                'page' => $this->page,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Get products for a specific page (all products, regardless of unique_id status)
     */
    private function getProductsPage($license, $page)
    {
        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                Log::warning('WooCommerce API key not found', [
                    'license_id' => $license->id,
                    'page' => $page
                ]);
                return [];
            }

            // پارامترهای درخواست - بدون bim_unique_id_empty
            $params = [
                'page' => $page,
                'per_page' => 100
            ];

            Log::info('درخواست صفحه محصولات از WooCommerce', [
                'license_id' => $license->id,
                'page' => $page,
                'per_page' => 100
            ]);

            // استفاده از trait برای دریافت محصولات
            $result = $this->getWooCommerceProducts(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                $params
            );

            if (!$result['success']) {
                Log::error("WooCommerce API request failed", [
                    'license_id' => $license->id,
                    'error' => $result['message'],
                    'page' => $page
                ]);
                return [];
            }

            Log::info('محصولات صفحه با موفقیت دریافت شد', [
                'license_id' => $license->id,
                'page' => $page,
                'products_count' => count($result['data'])
            ]);

            return $result['data'];

        } catch (Exception $e) {
            Log::error("Error fetching products page", [
                'license_id' => $license->id,
                'error' => $e->getMessage(),
                'page' => $page
            ]);
            return [];
        }
    }

    /**
     * Get variations that need unique ID from a variable product
     */
    private function getVariationSkus($license, $productId)
    {
        $variations = [];
        $page = 1;
        $hasMore = true;
        $maxPages = 10; // افزایش برای دریافت تمام واریانت‌ها

        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                return $variations;
            }

            while ($hasMore && $page <= $maxPages) {
                // پارامترهای درخواست - بدون bim_unique_id_empty
                $params = [
                    'page' => $page,
                    'per_page' => 100
                ];

                Log::info('درخواست واریانت‌های محصول', [
                    'product_id' => $productId,
                    'page' => $page,
                    'per_page' => 100
                ]);

                // استفاده از trait برای دریافت واریانت‌ها
                $result = $this->getWooCommerceProductVariations(
                    $license->website_url,
                    $wooApiKey->api_key,
                    $wooApiKey->api_secret,
                    $productId,
                    $params
                );

                if (!$result['success']) {
                    Log::error('Failed to fetch variations', [
                        'license_id' => $license->id,
                        'product_id' => $productId,
                        'page' => $page,
                        'error' => $result['message']
                    ]);
                    $hasMore = false;
                    continue;
                }

                $fetchedVariations = $result['data'];

                if (empty($fetchedVariations)) {
                    Log::info('واریانت‌های کافی دریافت شد', [
                        'product_id' => $productId,
                        'total_variations' => count($variations),
                        'current_page' => $page
                    ]);
                    $hasMore = false;
                    continue;
                }

                // اضافه کردن واریانت‌ها (بدون فیلتر، برای بررسی در handle)
                $variations = array_merge($variations, $fetchedVariations);

                Log::info('واریانت‌های صفحه دریافت شد', [
                    'product_id' => $productId,
                    'page' => $page,
                    'page_variations_count' => count($fetchedVariations),
                    'total_variations' => count($variations)
                ]);

                $page++;

                if (count($fetchedVariations) < 100) {
                    $hasMore = false;
                }
            }

            Log::info('پایان دریافت واریانت‌های محصول', [
                'product_id' => $productId,
                'total_variations' => count($variations)
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching variations', [
                'license_id' => $license->id,
                'error' => $e->getMessage(),
                'product_id' => $productId,
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $variations;
    }
}
