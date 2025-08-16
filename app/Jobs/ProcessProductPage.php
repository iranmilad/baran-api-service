<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\License;
use App\Jobs\ProcessSkuBatch;
use Exception;

class ProcessProductPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
    public $timeout = 50; // 50 ثانیه - کمی کمتر از 1 دقیقه

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
        $maxExecutionTime = 45; // 45 ثانیه - کمی کمتر از timeout

        try {
            Log::info('Starting product page processing job', [
                'license_id' => $this->licenseId,
                'page' => $this->page,
                'start_time' => date('Y-m-d H:i:s')
            ]);

            $license = License::find($this->licenseId);
            if (!$license || !$license->isActive()) {
                Log::error('Invalid or inactive license in product page job', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            // Get products for this specific page
            $products = $this->getProductsPage($license, $this->page);

            if (empty($products)) {
                Log::info('No products found on page', [
                    'page' => $this->page
                ]);
                return;
            }

            // Extract SKUs from products that have them
            $skus = [];
            $processedProducts = 0;

            foreach ($products as $product) {
                // بررسی زمان اجرا - اگر نزدیک timeout شدیم، باقی کار را به job جدید بسپاریم
                $currentTime = microtime(true);
                $elapsedTime = $currentTime - $startTime;

                if ($elapsedTime > $maxExecutionTime) {
                    Log::info('Job approaching timeout, dispatching continuation', [
                        'license_id' => $this->licenseId,
                        'current_page' => $this->page,
                        'processed_products' => $processedProducts,
                        'total_products' => count($products),
                        'elapsed_time' => round($elapsedTime, 2)
                    ]);

                    // ارسال job جدید برای ادامه کار از همان صفحه
                    ProcessProductPage::dispatch($this->licenseId, $this->page)
                        ->onQueue('empty-unique-ids')
                        ->delay(now()->addSeconds(5));

                    break;
                }

                if (!empty($product['sku'])) {
                    $skus[] = $product['sku'];
                }

                // Handle variations for variable products (فقط اگر زمان کافی داشتیم)
                if ($product['type'] === 'variable' && $elapsedTime < ($maxExecutionTime - 10)) {
                    $variationSkus = $this->getVariationSkus($license, $product['id']);
                    $skus = array_merge($skus, $variationSkus);
                }

                $processedProducts++;
            }

            if (!empty($skus)) {
                Log::info("Found {count} SKUs on page {page}, dispatching batch jobs", [
                    'count' => count($skus),
                    'page' => $this->page,
                    'processed_products' => $processedProducts,
                    'total_products' => count($products)
                ]);

                // Process SKUs in smaller batches
                $skuBatches = array_chunk($skus, 15); // کاهش اندازه batch

                foreach ($skuBatches as $batchIndex => $skuBatch) {
                    ProcessSkuBatch::dispatch($this->licenseId, $skuBatch)
                        ->onQueue('empty-unique-ids')
                        ->delay(now()->addSeconds($batchIndex * 3)); // افزایش delay
                }
            }

            // بررسی نهایی زمان - اگر همه محصولات پردازش شدند و زمان کافی داریم
            $finalElapsedTime = microtime(true) - $startTime;

            // فقط اگر همه محصولات پردازش شدند و تعداد برابر 100 بود، صفحه بعد را پردازش کن
            if ($processedProducts === count($products) && count($products) === 100 && $finalElapsedTime < $maxExecutionTime) {
                ProcessProductPage::dispatch($this->licenseId, $this->page + 1)
                    ->onQueue('empty-unique-ids')
                    ->delay(now()->addSeconds(15)); // افزایش delay
            }

            Log::info('Product page processing job completed', [
                'license_id' => $this->licenseId,
                'page' => $this->page,
                'processed_products' => $processedProducts,
                'total_products' => count($products),
                'execution_time' => round($finalElapsedTime, 2)
            ]);

        } catch (\Exception $e) {
            Log::error('Error in product page processing job', [
                'license_id' => $this->licenseId,
                'page' => $this->page,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Get products for a specific page
     */
    private function getProductsPage($license, $page)
    {
        try {
            Log::info("Fetching products with empty bim_unique_id - page: {page}", ['page' => $page]);

            $websiteUrl = rtrim($license->website_url, '/');
            $url = $websiteUrl . '/wp-json/wc/v3/products';

            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                Log::error('WooCommerce API key not found for license', [
                    'license_id' => $license->id
                ]);
                return [];
            }

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60,
            ])->get($url, [
                'consumer_key' => $wooApiKey->api_key,
                'consumer_secret' => $wooApiKey->api_secret,
                'page' => $page,
                'per_page' => 100,
                'bim_unique_id_empty' => 'true'
            ]);

            if ($response->failed()) {
                Log::error("WooCommerce API request failed", [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'page' => $page
                ]);
                return [];
            }

            $products = $response->json();

            Log::info("Fetched {count} products with empty bim_unique_id from page {page}", [
                'count' => count($products),
                'page' => $page
            ]);

            return $products;

        } catch (Exception $e) {
            Log::error("Error fetching products with empty bim_unique_id", [
                'error' => $e->getMessage(),
                'page' => $page
            ]);
            return [];
        }
    }

    /**
     * Get SKUs from variations of a variable product
     */
    private function getVariationSkus($license, $productId)
    {
        $skus = [];
        $page = 1;
        $hasMore = true;
        $maxPages = 3; // محدود کردن تعداد صفحات برای جلوگیری از timeout

        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                return $skus;
            }

            while ($hasMore && $page <= $maxPages) {
                $websiteUrl = rtrim($license->website_url, '/');
                $url = $websiteUrl . "/wp-json/wc/v3/products/{$productId}/variations";

                $response = Http::withOptions([
                    'verify' => false,
                    'timeout' => 30, // کاهش timeout
                    'connect_timeout' => 10,
                ])->get($url, [
                    'consumer_key' => $wooApiKey->api_key,
                    'consumer_secret' => $wooApiKey->api_secret,
                    'page' => $page,
                    'per_page' => 50, // کاهش per_page
                    'bim_unique_id_empty' => 'true'
                ]);

                if ($response->successful()) {
                    $variations = $response->json();

                    if (empty($variations)) {
                        $hasMore = false;
                        continue;
                    }

                    foreach ($variations as $variation) {
                        if (!empty($variation['sku'])) {
                            $skus[] = $variation['sku'];
                        }
                    }

                    $page++;

                    if (count($variations) < 50) {
                        $hasMore = false;
                    }
                } else {
                    Log::warning('Failed to fetch variations', [
                        'product_id' => $productId,
                        'page' => $page,
                        'status' => $response->status()
                    ]);
                    $hasMore = false;
                }
            }

            if ($page > $maxPages) {
                Log::info('Reached max pages limit for variations', [
                    'product_id' => $productId,
                    'max_pages' => $maxPages,
                    'skus_found' => count($skus)
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error fetching variation SKUs', [
                'error' => $e->getMessage(),
                'product_id' => $productId
            ]);
        }

        return $skus;
    }
}
