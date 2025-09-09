<?php

namespace App\Jobs\WooCommerce;

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

            // Get unique IDs from Baran API
            $uniqueIdMapping = $this->getUniqueIdsBySkusFromBaran($this->skus, $user, $stockId);

            if (!empty($uniqueIdMapping)) {
                // Update products with unique IDs using the new endpoint
                $this->batchUpdateUniqueIds($license->website_url, $wooApiKey, $uniqueIdMapping);
            } else {
                Log::info('No unique ID mapping found for SKU batch', [
                    'license_id' => $this->licenseId,
                    'skus' => $this->skus
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
     * Get unique IDs by SKUs from Baran API
     */
    private function getUniqueIdsBySkusFromBaran($skus, $user, $stockId)
    {
        $uniqueIdMapping = [];

        try {
            Log::info('Fetching unique IDs from Baran API', [
                'sku_count' => count($skus),
                'stock_id' => $stockId
            ]);

            // آماده‌سازی body درخواست
            $requestBody = ['barcodes' => $skus];

            // اضافه کردن stockId فقط در صورت وجود مقدار
            if (!empty($stockId)) {
                $requestBody['stockId'] = $stockId;
            }

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user->api_username . ':' . $user->api_password)
            ])->post($user->api_webservice . '/RainSaleService.svc/GetItemInfos', $requestBody);

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

                Log::info('Successfully fetched unique IDs from Baran API', [
                    'mapping_count' => count($uniqueIdMapping)
                ]);
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

            Log::info('Starting batch update of bim_unique_id', [
                'mapping_count' => count($uniqueIdMapping)
            ]);

            // آماده‌سازی داده‌های batch update
            $batchData = [
                'products' => $uniqueIdMapping
            ];

            // استفاده از trait برای batch update unique IDs
            $result = $this->batchUpdateWooCommerceUniqueIdsBySku(
                $websiteUrl,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                $batchData
            );

            if ($result['success']) {
                $responseData = $result['data'];

                Log::info('Batch bim_unique_id update completed', [
                    'updated_count' => $responseData['updated_count'] ?? 0,
                    'failed_count' => $responseData['failed_count'] ?? 0,
                    'total_sent' => count($uniqueIdMapping)
                ]);

                // Log successful updates
                if (isset($responseData['results']['success'])) {
                    foreach ($responseData['results']['success'] as $success) {
                        Log::info('Product bim_unique_id updated successfully', [
                            'product_id' => $success['product_id'],
                            'variation_id' => $success['variation_id'] ?? null,
                            'sku' => $success['sku'],
                            'unique_id' => $success['unique_id'] ?? null
                        ]);
                    }
                }

                // Log failed updates
                if (isset($responseData['results']['failed'])) {
                    foreach ($responseData['results']['failed'] as $failed) {
                        Log::warning('Product bim_unique_id update failed', [
                            'sku' => $failed['sku'] ?? null,
                            'unique_id' => $failed['unique_id'] ?? null,
                            'error' => $failed['message'] ?? 'Unknown error'
                        ]);
                    }
                }
            } else {
                Log::error('Failed to batch update bim_unique_id', [
                    'error' => $result['message'],
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
