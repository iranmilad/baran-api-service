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
     * Process products that have empty unique_id but have SKU
     */
    private function processEmptyUniqueIdProducts($license, $wooApiKey, $user)
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
                    $uniqueIdMapping = $this->getUniqueIdsBySkusFromBaran($skus, $user);

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
}
