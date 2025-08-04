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
use Exception;

class ProcessEmptyUniqueIds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $licenseId;

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId)
    {
        $this->licenseId = $licenseId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Starting empty unique IDs processing job', [
                'license_id' => $this->licenseId
            ]);

            $license = License::find($this->licenseId);
            if (!$license || !$license->isActive()) {
                Log::error('Invalid or inactive license in empty unique IDs job', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            $user = $license->user;
            if (!$user) {
                Log::error('User not found for license in empty unique IDs job', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                Log::error('WooCommerce API key not found for license', [
                    'license_id' => $license->id
                ]);
                return;
            }

            // Process products that have empty unique_id but have SKU
            $this->processEmptyUniqueIdProducts($license, $wooApiKey, $user);

            Log::info('Empty unique IDs processing job completed successfully', [
                'license_id' => $this->licenseId
            ]);

        } catch (\Exception $e) {
            Log::error('Error in empty unique IDs processing job', [
                'license_id' => $this->licenseId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Process products that have empty bim_unique_id but have SKU
     */
    private function processEmptyUniqueIdProducts($license, $wooApiKey, $user)
    {
        try {
            Log::info('Starting to process products with empty bim_unique_id');

            // Get all products with empty bim_unique_id
            $productsWithEmptyUniqueId = $this->getProductsWithEmptyUniqueId($license);

            if (empty($productsWithEmptyUniqueId)) {
                Log::info('No products with empty bim_unique_id found');
                return;
            }

            // Extract SKUs from products that have them
            $skus = [];
            foreach ($productsWithEmptyUniqueId as $product) {
                if (!empty($product['sku'])) {
                    $skus[] = $product['sku'];
                }
            }

            if (!empty($skus)) {
                Log::info("Found {count} products with SKUs for unique ID lookup", ['count' => count($skus)]);

                // Process SKUs in batches of 100
                $skuBatches = array_chunk($skus, 100);

                foreach ($skuBatches as $batchIndex => $skuBatch) {
                    Log::info("Processing SKU batch {batch} with {count} items", [
                        'batch' => $batchIndex + 1,
                        'count' => count($skuBatch)
                    ]);

                    // Get unique IDs from Baran API
                    $uniqueIdMapping = $this->getUniqueIdsBySkusFromBaran($skuBatch, $user);

                    if (!empty($uniqueIdMapping)) {
                        // Update products with unique IDs using the new endpoint
                        $websiteUrl = rtrim($license->website_url, '/');
                        $this->batchUpdateUniqueIds($websiteUrl, $wooApiKey, $uniqueIdMapping);
                    }
                }
            }

            Log::info('Completed processing products with empty bim_unique_id');

        } catch (\Exception $e) {
            Log::error('Error processing empty bim_unique_id products', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Get products with empty bim_unique_id using WooCommerce filter
     */
    private function getProductsWithEmptyUniqueId($license)
    {
        $allProducts = [];
        $page = 1;
        $hasMore = true;

        try {
            while ($hasMore) {
                Log::info("Fetching products with empty bim_unique_id - page: {page}", ['page' => $page]);

                $websiteUrl = rtrim($license->website_url, '/');
                $url = $websiteUrl . '/wp-json/wc/v3/products';

                $wooApiKey = $license->woocommerceApiKey;
                if (!$wooApiKey) {
                    Log::error('WooCommerce API key not found for license', [
                        'license_id' => $license->id
                    ]);
                    break;
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
                        'response' => $response->body()
                    ]);
                    break;
                }

                $products = $response->json();
                $productsWithEmptyUniqueId = [];

                if (empty($products) || count($products) === 0) {
                    Log::info("No more products found on page {page}", ['page' => $page]);
                    $hasMore = false;
                    break;
                }

                foreach ($products as $product) {
                    // Server already filtered products with empty bim_unique_id
                    $productsWithEmptyUniqueId[] = $product;

                    // Handle variations for variable products
                    if ($product['type'] === 'variable') {
                        $variations = $this->getVariationsWithEmptyUniqueId($license, $product['id']);
                        $productsWithEmptyUniqueId = array_merge($productsWithEmptyUniqueId, $variations);
                    }
                }

                Log::info("Fetched {count} products with empty bim_unique_id from page {page}", [
                    'count' => count($products),
                    'page' => $page
                ]);

                Log::info("Total items (products + variations) with empty bim_unique_id from page {page}: {total}", [
                    'page' => $page,
                    'total' => count($productsWithEmptyUniqueId)
                ]);

                $allProducts = array_merge($allProducts, $productsWithEmptyUniqueId);

                // Check if we have more pages
                if (count($products) < 100) {
                    $hasMore = false;
                    Log::info("Reached last page of products at page {page}", ['page' => $page]);
                } else {
                    $page++;
                }
            }

        } catch (Exception $e) {
            Log::error("Error fetching products with empty bim_unique_id", [
                'error' => $e->getMessage(),
                'page' => $page
            ]);
        }

        Log::info("Total products with empty bim_unique_id: {total}", ['total' => count($allProducts)]);
        return $allProducts;
    }

    /**
     * Get variations with empty bim_unique_id for a variable product
     */
    private function getVariationsWithEmptyUniqueId($license, $productId)
    {
        $variationsWithEmptyUniqueId = [];
        $page = 1;
        $perPage = 100;
        $hasMore = true;

        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                Log::error('WooCommerce API key not found for license in variations fetch', [
                    'license_id' => $license->id,
                    'product_id' => $productId
                ]);
                return $variationsWithEmptyUniqueId;
            }

            while ($hasMore) {
                Log::info("Processing page {$page} of variations for product {$productId}");

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
                    'per_page' => $perPage,
                    'bim_unique_id_empty' => 'true'
                ]);

                if ($response->successful()) {
                    $variations = $response->json();

                    if (empty($variations)) {
                        $hasMore = false;
                        continue;
                    }

                    foreach ($variations as $variation) {
                        // Server already filtered variations with empty bim_unique_id
                        // Only add if it has a SKU
                        if (!empty($variation['sku'])) {
                            $variationsWithEmptyUniqueId[] = $variation;
                        }
                    }

                    Log::info("Fetched {count} variations with empty bim_unique_id from page {page} for product {product_id}", [
                        'count' => count($variations),
                        'page' => $page,
                        'product_id' => $productId
                    ]);

                    $page++;

                    // If we got less than perPage variations, we're done
                    if (count($variations) < $perPage) {
                        $hasMore = false;
                    }

                } else {
                    Log::warning('Failed to fetch variations with empty bim_unique_id', [
                        'status' => $response->status(),
                        'product_id' => $productId,
                        'page' => $page,
                        'response' => $response->body()
                    ]);
                    $hasMore = false;
                }
            }

            Log::info("Total variations with empty bim_unique_id found for product {product_id}: {total}", [
                'product_id' => $productId,
                'total' => count($variationsWithEmptyUniqueId)
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching variations with empty bim_unique_id', [
                'error' => $e->getMessage(),
                'product_id' => $productId,
                'page' => $page
            ]);
        }

        return $variationsWithEmptyUniqueId;
    }

    /**
     * Get unique IDs by SKUs from Baran API
     */
    private function getUniqueIdsBySkusFromBaran($skus, $user)
    {
        $uniqueIdMapping = [];

        try {
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user->api_username . ':' . $user->api_password)
            ])->post($user->api_webservice . '/RainSaleService.svc/GetItemInfos', [
                'barcodes' => $skus
            ]);

            if ($response->successful()) {
                $body = $response->json();
                $results = $body['GetItemInfosResult'] ?? [];

                foreach ($results as $item) {
                    $barcode = $item['Barcode'] ?? null;
                    $itemId = $item['ItemID'] ?? null;

                    if ($barcode && $itemId && $itemId !== '00000000-0000-0000-0000-000000000000') {
                        $uniqueIdMapping[] = [
                            'bim_unique_id' => $itemId,
                            'sku' => $barcode
                        ];
                    }
                }
            } else {
                Log::error('Failed to fetch unique IDs from Baran API', [
                    'status' => $response->status(),
                    'skus' => $skus
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error fetching unique IDs from Baran API', [
                'error' => $e->getMessage(),
                'skus' => $skus
            ]);
        }

        return $uniqueIdMapping;
    }

    /**
     * Batch update bim_unique_id in WooCommerce
     */
    private function batchUpdateUniqueIds($websiteUrl, $wooApiKey, $uniqueIdMapping)
    {
        try {
            if (empty($uniqueIdMapping)) {
                return;
            }

            $url = $websiteUrl . '/wp-json/wc/v3/products/unique/batch-update-sku';

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60,
                'http_errors' => false
            ])->send('POST', $url, [
                'json' => [
                    'products' => $uniqueIdMapping
                ],
                'auth' => [$wooApiKey->api_key, $wooApiKey->api_secret]
            ]);

            if ($response->successful()) {
                $result = $response->json();

                Log::info('Batch bim_unique_id update completed', [
                    'updated_count' => $result['updated_count'] ?? 0,
                    'failed_count' => $result['failed_count'] ?? 0,
                    'total_sent' => count($uniqueIdMapping)
                ]);

                // Log successful updates
                if (isset($result['results']['success'])) {
                    foreach ($result['results']['success'] as $success) {
                        Log::info('Product bim_unique_id updated successfully', [
                            'product_id' => $success['product_id'],
                            'variation_id' => $success['variation_id'] ?? null,
                            'sku' => $success['sku'],
                            'bim_unique_id' => $success['bim_unique_id'] ?? $success['unique_id'] ?? null
                        ]);
                    }
                }

                // Log failed updates
                if (isset($result['results']['failed'])) {
                    foreach ($result['results']['failed'] as $failed) {
                        Log::warning('Product bim_unique_id update failed', [
                            'sku' => $failed['sku'] ?? null,
                            'bim_unique_id' => $failed['bim_unique_id'] ?? $failed['unique_id'] ?? null,
                            'error' => $failed['message'] ?? 'Unknown error'
                        ]);
                    }
                }

            } else {
                Log::error('Failed to batch update bim_unique_id', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'sent_data' => $uniqueIdMapping
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error in batch update bim_unique_id', [
                'error' => $e->getMessage(),
                'unique_id_mapping' => $uniqueIdMapping
            ]);
        }
    }
}
