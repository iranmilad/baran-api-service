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
use App\Jobs\WordPress\ProcessProductPage;
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

            // Start processing from the first page by dispatching ProcessProductPage job
            ProcessProductPage::dispatch($this->licenseId, 1)
                ->onQueue('empty-unique-ids');

            Log::info('Dispatched first page processing job for products with empty bim_unique_id');

        } catch (\Exception $e) {
            Log::error('Error starting product page processing', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
