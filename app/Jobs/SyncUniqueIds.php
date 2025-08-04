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

class SyncUniqueIds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $syncData;

    /**
     * Create a new job instance.
     */
    public function __construct($syncData)
    {
        $this->syncData = $syncData;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('Starting unique IDs sync job', [
                'license_id' => $this->syncData['license_id'],
                'products_with_unique_id_count' => count($this->syncData['products_with_unique_id']),
                'products_without_unique_id_count' => count($this->syncData['products_without_unique_id'])
            ]);

            $license = License::find($this->syncData['license_id']);
            if (!$license || !$license->isActive()) {
                Log::error('Invalid or inactive license in sync job', [
                    'license_id' => $this->syncData['license_id']
                ]);
                return;
            }

            $user = $license->user;
            if (!$user) {
                Log::error('User not found for license in sync job', [
                    'license_id' => $this->syncData['license_id']
                ]);
                return;
            }

            // Process products with unique_id
            $this->processProductsWithUniqueId($user);

            Log::info('Unique IDs sync job completed successfully', [
                'license_id' => $this->syncData['license_id']
            ]);

        } catch (\Exception $e) {
            Log::error('Error in unique IDs sync job', [
                'license_id' => $this->syncData['license_id'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Process products that have unique_id
     */
    private function processProductsWithUniqueId($user)
    {
        $productsWithUniqueId = $this->syncData['products_with_unique_id'];

        if (empty($productsWithUniqueId)) {
            return;
        }

        Log::info('Processing products with unique IDs', [
            'count' => count($productsWithUniqueId)
        ]);

        // First, verify products exist in WooCommerce
        $license = License::find($this->syncData['license_id']);
        $wooApiKey = $license->woocommerceApiKey;

        if (!$wooApiKey) {
            Log::error('WooCommerce API key not found for license', [
                'license_id' => $license->id
            ]);
            return;
        }

        $existingProducts = [];
        $invalidUniqueIds = [];

        // Check products in batches for better performance
        $validProducts = $this->checkProductsBatchInWooCommerce($license, $wooApiKey, $productsWithUniqueId);

        // Separate valid and invalid products
        foreach ($productsWithUniqueId as $product) {
            $productKey = $product['product_id'] . '_' . ($product['variation_id'] ?? '0');
            if (in_array($productKey, $validProducts)) {
                $existingProducts[] = $product;
            } else {
                $invalidUniqueIds[] = $product['unique_id'];
                Log::info('Product not found in WooCommerce', [
                    'product_id' => $product['product_id'],
                    'variation_id' => $product['variation_id'] ?? null,
                    'unique_id' => $product['unique_id']
                ]);
            }
        }

        // Send delete request for invalid unique IDs
        if (!empty($invalidUniqueIds)) {
            $this->deleteInvalidUniqueIds($license, $wooApiKey, $invalidUniqueIds);
        }


        // Process products that have empty unique_id but have SKU
        $this->processEmptyUniqueIdProducts($license, $wooApiKey);

    }

    /**
     * Process products that have empty unique_id but have SKU
     */
    private function processEmptyUniqueIdProducts($license, $wooApiKey)
    {
        try {
            Log::info('Starting to process products with empty unique IDs');

            $websiteUrl = rtrim($license->website_url, '/');
            $page = 1;
            $perPage = 100;
            $hasMore = true;

            while ($hasMore) {
                Log::info("Processing page {$page} of products with empty unique IDs");

                // Get products with empty bim_unique_id using the new endpoint
                $productsWithEmptyUniqueId = $this->getProductsWithEmptyUniqueId($websiteUrl, $wooApiKey, $page, $perPage);

                if (empty($productsWithEmptyUniqueId)) {
                    $hasMore = false;
                    continue;
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

                    // Get unique IDs from Baran API
                    $uniqueIdMapping = $this->getUniqueIdsBySkusFromBaran($skus);

                    if (!empty($uniqueIdMapping)) {
                        // Update products with unique IDs using the new endpoint
                        $this->batchUpdateUniqueIds($websiteUrl, $wooApiKey, $uniqueIdMapping);
                    }
                }

                $page++;

                // If we got less than perPage products, we're done
                if (count($productsWithEmptyUniqueId) < $perPage) {
                    $hasMore = false;
                }
            }

            Log::info('Completed processing products with empty unique IDs');

        } catch (\Exception $e) {
            Log::error('Error processing empty unique ID products', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Get products with empty unique_id using the new WooCommerce endpoint
     */
    private function getProductsWithEmptyUniqueId($websiteUrl, $wooApiKey, $page, $perPage)
    {
        $productsWithEmptyUniqueId = [];

        try {
            $url = $websiteUrl . '/wp-json/wc/v3/products';

            $response = Http::withBasicAuth($wooApiKey->api_key, $wooApiKey->api_secret)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withOptions([
                    'verify' => false,
                    'timeout' => 180,
                    'connect_timeout' => 60
                ])
                ->get($url, [
                    'page' => $page,
                    'per_page' => $perPage,
                    'bim_unique_id_empty' => 'true'
                ]);

            if ($response->successful()) {
                $products = $response->json();

                foreach ($products as $product) {
                    if (!empty($product['sku'])) {
                        $productsWithEmptyUniqueId[] = [
                            'product_id' => $product['id'],
                            'variation_id' => isset($product['parent_id']) && $product['parent_id'] > 0 ? $product['id'] : null,
                            'sku' => $product['sku'],
                            'type' => $product['type'] ?? 'simple'
                        ];
                    }
                }

                Log::info("Fetched {count} products with empty unique IDs from page {page}", [
                    'count' => count($productsWithEmptyUniqueId),
                    'page' => $page
                ]);

            } else {
                Log::warning('Failed to fetch products with empty unique IDs', [
                    'status' => $response->status(),
                    'page' => $page,
                    'response' => $response->body()
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error fetching products with empty unique ID', [
                'error' => $e->getMessage(),
                'page' => $page
            ]);
        }

        return $productsWithEmptyUniqueId;
    }

    /**
     * Get unique IDs by SKUs from Baran API
     */
    private function getUniqueIdsBySkusFromBaran($skus)
    {
        $uniqueIdMapping = [];

        try {
            $license = License::find($this->syncData['license_id']);
            $user = $license->user;

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
                            'unique_id' => $itemId,
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
     * Batch update unique IDs in WooCommerce
     */
    private function batchUpdateUniqueIds($websiteUrl, $wooApiKey, $uniqueIdMapping)
    {
        try {
            if (empty($uniqueIdMapping)) {
                return;
            }

            $url = $websiteUrl . '/wp-json/wc/v3/products/unique/batch-update-sku';

            $response = Http::withBasicAuth($wooApiKey->api_key, $wooApiKey->api_secret)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withOptions([
                    'verify' => false,
                    'timeout' => 180,
                    'connect_timeout' => 60
                ])
                ->post($url, [
                    'products' => $uniqueIdMapping
                ]);

            if ($response->successful()) {
                $result = $response->json();

                Log::info('Batch unique ID update completed', [
                    'updated_count' => $result['updated_count'] ?? 0,
                    'failed_count' => $result['failed_count'] ?? 0,
                    'total_sent' => count($uniqueIdMapping)
                ]);

                // Log successful updates
                if (isset($result['results']['success'])) {
                    foreach ($result['results']['success'] as $success) {
                        Log::info('Product unique ID updated successfully', [
                            'product_id' => $success['product_id'],
                            'variation_id' => $success['variation_id'] ?? null,
                            'sku' => $success['sku'],
                            'unique_id' => $success['unique_id']
                        ]);
                    }
                }

                // Log failed updates
                if (isset($result['results']['failed'])) {
                    foreach ($result['results']['failed'] as $failed) {
                        Log::warning('Product unique ID update failed', [
                            'sku' => $failed['sku'] ?? null,
                            'unique_id' => $failed['unique_id'] ?? null,
                            'error' => $failed['message'] ?? 'Unknown error'
                        ]);
                    }
                }

            } else {
                Log::error('Failed to batch update unique IDs', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'sent_data' => $uniqueIdMapping
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error in batch update unique IDs', [
                'error' => $e->getMessage(),
                'unique_id_mapping' => $uniqueIdMapping
            ]);
        }
    }

    /**
     * Get unique IDs by SKUs from Baran API
     */    /**
     * Update WooCommerce product with data from external API
     */
    private function updateWooCommerceProduct($user, $productData, $apiResults)
    {
        try {
            // Find matching data from API results
            $matchingApiData = null;
            if (isset($apiResults['GetItemInfosByIdsResult'])) {
                foreach ($apiResults['GetItemInfosByIdsResult'] as $apiProduct) {
                    if ($apiProduct['ItemID'] === $productData['unique_id']) {
                        $matchingApiData = $apiProduct;
                        break;
                    }
                }
            }

            if (!$matchingApiData) {
                Log::warning('No matching API data found for unique ID', [
                    'unique_id' => $productData['unique_id'],
                    'product_id' => $productData['product_id']
                ]);
                return;
            }

            // Prepare WooCommerce update data
            $updateData = [
                'name' => $matchingApiData['Name'] ?? '',
                'regular_price' => (string) ($matchingApiData['Price'] ?? 0),
                'stock_quantity' => (int) ($matchingApiData['CurrentUnitCount'] ?? 0),
                'manage_stock' => true
            ];

            // Add barcode/SKU if available
            if (!empty($matchingApiData['Barcode'])) {
                $updateData['sku'] = $matchingApiData['Barcode'];
            }

            // Call WooCommerce API to update product
            $this->callWooCommerceAPI($user, $productData, $updateData);

        } catch (\Exception $e) {
            Log::error('Error updating WooCommerce product', [
                'product_id' => $productData['product_id'],
                'variation_id' => $productData['variation_id'] ?? null,
                'unique_id' => $productData['unique_id'],
                'error' => $e->getMessage()
            ]);
        }

    }



    /**
     * Call WooCommerce REST API to update product
     */
    private function callWooCommerceAPI($user, $productData, $updateData)
    {
        try {
            $license = License::find($this->syncData['license_id']);
            $wooApiKey = $license->woocommerceApiKey;

            if (!$wooApiKey) {
                Log::error('WooCommerce API key not found for license', [
                    'license_id' => $license->id
                ]);
                return;
            }

            $websiteUrl = rtrim($license->website_url, '/');

            // Determine if it's a variation or simple product
            $endpoint = $productData['variation_id']
                ? "/wp-json/wc/v3/products/{$productData['product_id']}/variations/{$productData['variation_id']}"
                : "/wp-json/wc/v3/products/{$productData['product_id']}";

            $url = $websiteUrl . $endpoint;

            $response = Http::withBasicAuth($wooApiKey->api_key, $wooApiKey->api_secret)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withOptions([
                    'verify' => false,
                    'timeout' => 180,
                    'connect_timeout' => 60
                ])
                ->put($url, $updateData);

            if ($response->successful()) {
                Log::info('WooCommerce product updated successfully', [
                    'product_id' => $productData['product_id'],
                    'variation_id' => $productData['variation_id'] ?? null,
                    'unique_id' => $productData['unique_id']
                ]);
            } else {
                Log::error('Failed to update WooCommerce product', [
                    'product_id' => $productData['product_id'],
                    'variation_id' => $productData['variation_id'] ?? null,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
            }        } catch (\Exception $e) {
            Log::error('Error calling WooCommerce API', [
                'product_id' => $productData['product_id'],
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check if product exists in WooCommerce
     */
    private function checkProductExistsInWooCommerce($license, $wooApiKey, $product)
    {
        try {
            $websiteUrl = rtrim($license->website_url, '/');

            // Determine if it's a variation or simple product
            $endpoint = $product['variation_id']
                ? "/wp-json/wc/v3/products/{$product['product_id']}/variations/{$product['variation_id']}"
                : "/wp-json/wc/v3/products/{$product['product_id']}";

            $url = $websiteUrl . $endpoint;

            $response = Http::withBasicAuth($wooApiKey->api_key, $wooApiKey->api_secret)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withOptions([
                    'verify' => false,
                    'timeout' => 180,
                    'connect_timeout' => 60
                ])
                ->get($url);

            if ($response->successful()) {
                return true;
            } elseif ($response->status() === 404) {
                return false;
            } else {
                Log::warning('Unexpected response when checking product existence', [
                    'product_id' => $product['product_id'],
                    'variation_id' => $product['variation_id'] ?? null,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
                return false;
            }

        } catch (\Exception $e) {
            Log::error('Error checking product existence in WooCommerce', [
                'product_id' => $product['product_id'],
                'variation_id' => $product['variation_id'] ?? null,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check products existence in WooCommerce in batches for better performance
     */
    private function checkProductsBatchInWooCommerce($license, $wooApiKey, $products)
    {
        $validProducts = [];
        $websiteUrl = rtrim($license->website_url, '/');

        try {
            // Separate simple products and variations
            $simpleProducts = [];
            $variationsByParent = [];

            foreach ($products as $product) {
                if ($product['variation_id']) {
                    $variationsByParent[$product['product_id']][] = $product['variation_id'];
                } else {
                    $simpleProducts[] = $product['product_id'];
                }
            }

            // Check simple products in batch
            if (!empty($simpleProducts)) {
                $validSimpleProducts = $this->checkSimpleProductsBatch($websiteUrl, $wooApiKey, $simpleProducts);
                foreach ($validSimpleProducts as $productId) {
                    $validProducts[] = $productId . '_0';
                }
            }

            // Check variations in batch for each parent product
            foreach ($variationsByParent as $parentId => $variationIds) {
                $validVariations = $this->checkVariationsBatch($websiteUrl, $wooApiKey, $parentId, $variationIds);
                foreach ($validVariations as $variationId) {
                    $validProducts[] = $parentId . '_' . $variationId;
                }
            }

        } catch (\Exception $e) {
            Log::error('Error in batch product existence check', [
                'error' => $e->getMessage()
            ]);
        }

        return $validProducts;
    }

    /**
     * Check simple products in batch
     */
    private function checkSimpleProductsBatch($websiteUrl, $wooApiKey, $productIds)
    {
        $validProducts = [];

        try {
            // WooCommerce supports getting multiple products with include parameter
            $url = $websiteUrl . '/wp-json/wc/v3/products';

            $response = Http::withBasicAuth($wooApiKey->api_key, $wooApiKey->api_secret)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withOptions([
                    'verify' => false,
                    'timeout' => 180,
                    'connect_timeout' => 60
                ])
                ->get($url, [
                    'include' => implode(',', $productIds),
                    'per_page' => 100
                ]);

            if ($response->successful()) {
                $products = $response->json();
                foreach ($products as $product) {
                    $validProducts[] = $product['id'];
                }
            } else {
                Log::warning('Failed to fetch simple products batch', [
                    'status' => $response->status(),
                    'product_ids' => $productIds
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error checking simple products batch', [
                'error' => $e->getMessage(),
                'product_ids' => $productIds
            ]);
        }

        return $validProducts;
    }

    /**
     * Check variations in batch for a parent product
     */
    private function checkVariationsBatch($websiteUrl, $wooApiKey, $parentId, $variationIds)
    {
        $validVariations = [];

        try {
            $url = $websiteUrl . "/wp-json/wc/v3/products/{$parentId}/variations";

            $response = Http::withBasicAuth($wooApiKey->api_key, $wooApiKey->api_secret)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withOptions([
                    'verify' => false,
                    'timeout' => 180,
                    'connect_timeout' => 60
                ])
                ->get($url, [
                    'include' => implode(',', $variationIds),
                    'per_page' => 100
                ]);

            if ($response->successful()) {
                $variations = $response->json();
                foreach ($variations as $variation) {
                    $validVariations[] = $variation['id'];
                }
            } else {
                Log::warning('Failed to fetch variations batch', [
                    'status' => $response->status(),
                    'parent_id' => $parentId,
                    'variation_ids' => $variationIds
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error checking variations batch', [
                'error' => $e->getMessage(),
                'parent_id' => $parentId,
                'variation_ids' => $variationIds
            ]);
        }

        return $validVariations;
    }

    /**
     * Delete invalid unique IDs from plugin
     */
    private function deleteInvalidUniqueIds($license, $wooApiKey, $invalidUniqueIds)
    {
        try {
            if (empty($invalidUniqueIds)) {
                return;
            }

            $websiteUrl = rtrim($license->website_url, '/');
            $url = $websiteUrl . '/wp-json/wc/v3/products/unique/delete';

            $response = Http::withBasicAuth($wooApiKey->api_key, $wooApiKey->api_secret)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->withOptions([
                    'verify' => false,
                    'timeout' => 180,
                    'connect_timeout' => 60,
                    'http_errors' => false
                ])
                ->send('DELETE', $url, [
                    'json' => [
                        'unique_ids' => $invalidUniqueIds
                    ]
                ]);

            if ($response->successful()) {
                $result = $response->json();

                Log::info('Invalid unique IDs deletion completed', [
                    'deleted_count' => $result['deleted_count'] ?? 0,
                    'failed_count' => $result['failed_count'] ?? 0,
                    'total_sent' => count($invalidUniqueIds)
                ]);

                // Log successful deletions
                if (isset($result['results']['success'])) {
                    foreach ($result['results']['success'] as $success) {
                        Log::info('Unique ID deleted successfully', [
                            'unique_id' => $success['unique_id'],
                            'message' => $success['message'] ?? 'با موفقیت حذف شد'
                        ]);
                    }
                }

                // Log failed deletions
                if (isset($result['results']['failed'])) {
                    foreach ($result['results']['failed'] as $failed) {
                        Log::warning('Unique ID deletion failed', [
                            'unique_id' => $failed['unique_id'] ?? null,
                            'error' => $failed['error'] ?? 'Unknown error'
                        ]);
                    }
                }

            } else {
                Log::error('Failed to delete invalid unique IDs', [
                    'unique_ids' => $invalidUniqueIds,
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Error deleting invalid unique IDs', [
                'unique_ids' => $invalidUniqueIds,
                'error' => $e->getMessage()
            ]);
        }
    }
}
