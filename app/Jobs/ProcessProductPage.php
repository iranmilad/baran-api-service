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
        try {
            Log::info('Starting product page processing job', [
                'license_id' => $this->licenseId,
                'page' => $this->page
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
            foreach ($products as $product) {
                if (!empty($product['sku'])) {
                    $skus[] = $product['sku'];
                }

                // Handle variations for variable products
                if ($product['type'] === 'variable') {
                    $variationSkus = $this->getVariationSkus($license, $product['id']);
                    $skus = array_merge($skus, $variationSkus);
                }
            }

            if (!empty($skus)) {
                Log::info("Found {count} SKUs on page {page}, dispatching batch jobs", [
                    'count' => count($skus),
                    'page' => $this->page
                ]);

                // Process SKUs in smaller batches
                $skuBatches = array_chunk($skus, 20);

                foreach ($skuBatches as $batchIndex => $skuBatch) {
                    ProcessSkuBatch::dispatch($this->licenseId, $skuBatch)
                        ->onQueue('empty-unique-ids')
                        ->delay(now()->addSeconds($batchIndex * 2));
                }

                // Check if there are more pages to process
                if (count($products) === 100) {
                    ProcessProductPage::dispatch($this->licenseId, $this->page + 1)
                        ->onQueue('empty-unique-ids')
                        ->delay(now()->addSeconds(10));
                }
            }

            Log::info('Product page processing job completed', [
                'license_id' => $this->licenseId,
                'page' => $this->page
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

        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                return $skus;
            }

            while ($hasMore) {
                $websiteUrl = rtrim($license->website_url, '/');
                $url = $websiteUrl . "/wp-json/wc/v3/products/{$productId}/variations";

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

                    if (count($variations) < 100) {
                        $hasMore = false;
                    }
                } else {
                    $hasMore = false;
                }
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
