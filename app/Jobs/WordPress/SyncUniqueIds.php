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

class SyncUniqueIds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WordPressMasterTrait;

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
                'products_with_unique_id_count' => count($this->syncData['products_with_unique_id'])
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

        Log::info('Unique IDs sync job completed - only existing products with unique IDs processed', [
            'license_id' => $this->syncData['license_id']
        ]);

    }

    /**
     * Update WooCommerce product with data from external API
     */
    private function updateWooCommerceProduct($user, $productData, $apiResults)
    {
        try {
            // Find matching data from API results
            $matchingApiData = null;
            if (isset($apiResults['GetItemInfosByIdsResult']) && is_array($apiResults['GetItemInfosByIdsResult'])) {
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

            // Determine if it's a variation or simple product
            if ($productData['variation_id']) {
                $updateResult = $this->updateWooCommerceProductVariation(
                    $license->website_url,
                    $wooApiKey->api_key,
                    $wooApiKey->api_secret,
                    $productData['product_id'],
                    $productData['variation_id'],
                    $updateData
                );
            } else {
                $updateResult = $this->updateWooCommerceProduct(
                    $license->website_url,
                    $wooApiKey->api_key,
                    $wooApiKey->api_secret,
                    $productData['product_id'],
                    $updateData
                );
            }

            if ($updateResult['success']) {
                Log::info('WooCommerce product updated successfully', [
                    'product_id' => $productData['product_id'],
                    'variation_id' => $productData['variation_id'] ?? null,
                    'unique_id' => $productData['unique_id']
                ]);
            } else {
                Log::error('Failed to update WooCommerce product', [
                    'product_id' => $productData['product_id'],
                    'variation_id' => $productData['variation_id'] ?? null,
                    'message' => $updateResult['message']
                ]);
            }
        } catch (\Exception $e) {
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
            if ($product['variation_id']) {
                $result = $this->getWooCommerceProductVariation(
                    $license->website_url,
                    $wooApiKey->api_key,
                    $wooApiKey->api_secret,
                    $product['product_id'],
                    $product['variation_id']
                );
            } else {
                $result = $this->getWooCommerceProduct(
                    $license->website_url,
                    $wooApiKey->api_key,
                    $wooApiKey->api_secret,
                    $product['product_id']
                );
            }

            return $result['success'];

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
            $result = $this->getWooCommerceProducts(
                $websiteUrl,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                [
                    'include' => implode(',', $productIds),
                    'per_page' => 100
                ]
            );

            if ($result['success']) {
                $products = $result['data'];
                foreach ($products as $product) {
                    $validProducts[] = $product['id'];
                }
            } else {
                Log::warning('Failed to fetch simple products batch', [
                    'message' => $result['message'],
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
            $result = $this->getWooCommerceProductVariations(
                $websiteUrl,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                $parentId,
                [
                    'include' => implode(',', $variationIds),
                    'per_page' => 100
                ]
            );

            if ($result['success']) {
                $variations = $result['data'];
                foreach ($variations as $variation) {
                    $validVariations[] = $variation['id'];
                }
            } else {
                Log::warning('Failed to fetch variations batch', [
                    'message' => $result['message'],
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

            $result = $this->deleteWooCommerceUniqueIds(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                $invalidUniqueIds
            );

            if ($result['success']) {
                $resultData = $result['data'];

                Log::info('Invalid unique IDs deletion completed', [
                    'deleted_count' => $resultData['deleted_count'] ?? 0,
                    'failed_count' => $resultData['failed_count'] ?? 0,
                    'total_sent' => count($invalidUniqueIds)
                ]);

                // Log successful deletions
                if (isset($resultData['results']['success'])) {
                    foreach ($resultData['results']['success'] as $success) {
                        Log::info('Unique ID deleted successfully', [
                            'unique_id' => $success['unique_id'],
                            'message' => $success['message'] ?? 'با موفقیت حذف شد'
                        ]);
                    }
                }

                // Log failed deletions
                if (isset($resultData['results']['failed'])) {
                    foreach ($resultData['results']['failed'] as $failed) {
                        Log::warning('Unique ID deletion failed', [
                            'unique_id' => $failed['unique_id'] ?? null,
                            'error' => $failed['error'] ?? 'Unknown error'
                        ]);
                    }
                }

            } else {
                Log::error('Failed to delete invalid unique IDs', [
                    'unique_ids' => $invalidUniqueIds,
                    'message' => $result['message']
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
