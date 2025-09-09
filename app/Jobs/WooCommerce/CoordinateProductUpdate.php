<?php

namespace App\Jobs\WooCommerce;

use App\Models\License;
use App\Traits\WordPress\WordPressMasterTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CoordinateProductUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WordPressMasterTrait;

    protected $licenseId;
    protected $barcodes;
    protected $operation;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 1; // فقط یک بار چون فقط coordination است

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 30; // کوتاه چون فقط coordination

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId, $operation, $barcodes = [])
    {
        $this->licenseId = $licenseId;
        $this->operation = $operation;
        $this->barcodes = $barcodes;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('شروع coordination برای به‌روزرسانی محصولات', [
                'license_id' => $this->licenseId,
                'operation' => $this->operation,
                'barcodes_count' => count($this->barcodes)
            ]);

            // بررسی لایسنس و تنظیمات
            $license = License::with(['userSetting', 'woocommerceApiKey'])->find($this->licenseId);
            if (!$license || !$license->isActive()) {
                Log::error('لایسنس معتبر نیست', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                Log::error('کلید API ووکامرس یافت نشد', [
                    'license_id' => $license->id
                ]);
                return;
            }

            // تست اتصال WooCommerce قبل از شروع
            $connectionTest = $this->validateWooCommerceApiCredentials(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret
            );

            if (!$connectionTest['success']) {
                Log::error('اتصال به WooCommerce ناموفق', [
                    'license_id' => $license->id,
                    'error' => $connectionTest['message']
                ]);
                return;
            }

            // تقسیم به chunk های کوچک
            $chunkSize = 50; // افزایش سایز chunk برای سرعت بیشتر

            if (empty($this->barcodes)) {
                // اگر barcodes خالی باشد، یعنی همه محصولات
                // دریافت همه unique_ids از WooCommerce
                $allUniqueIds = $this->getAllWooCommerceProductUniqueIds($license, $wooApiKey);

                if (empty($allUniqueIds)) {
                    Log::warning('هیچ محصولی با unique_id یافت نشد', [
                        'license_id' => $license->id
                    ]);
                    return;
                }

                Log::info('دریافت کامل unique_ids از WooCommerce', [
                    'license_id' => $license->id,
                    'total_products' => count($allUniqueIds)
                ]);

                // تقسیم unique_ids به chunks
                $chunks = array_chunk($allUniqueIds, $chunkSize);

                foreach ($chunks as $index => $chunk) {
                    $delaySeconds = 2 + ($index * 3); // کاهش delay برای سرعت بیشتر

                    ProcessSingleProductBatch::dispatch($this->licenseId, $chunk)
                        ->onQueue('product-processing')
                        ->delay(now()->addSeconds($delaySeconds));
                }

            } else {
                // تقسیم barcodes موجود
                $chunks = array_chunk($this->barcodes, $chunkSize);

                foreach ($chunks as $index => $chunk) {
                    $delaySeconds = 3 + ($index * 5); // کاهش delay برای سرعت بیشتر

                    ProcessSingleProductBatch::dispatch($this->licenseId, $chunk)
                        ->onQueue('product-processing')
                        ->delay(now()->addSeconds($delaySeconds));
                }
            }

            Log::info('تکمیل coordination محصولات', [
                'license_id' => $this->licenseId,
                'total_chunks' => count($chunks ?? []),
                'operation' => $this->operation
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در coordination محصولات: ' . $e->getMessage(), [
                'license_id' => $this->licenseId,
                'error_details' => $e->getTraceAsString()
            ]);

            // در صورت خطا، job را fail نکن، فقط log کن
        }
    }

    /**
     * دریافت همه unique_id های محصولات از WooCommerce
     */
    private function getAllWooCommerceProductUniqueIds($license, $wooApiKey)
    {
        try {
            $result = $this->getWooCommerceProductsWithUniqueIds(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret
            );

            if (!$result['success'] || empty($result['data'])) {
                Log::error('عدم دریافت محصولات از WooCommerce', [
                    'license_id' => $license->id,
                    'error' => $result['message'] ?? 'پاسخ خالی'
                ]);
                return [];
            }

            // استخراج unique_id ها
            $uniqueIds = [];
            foreach ($result['data'] as $product) {
                if (!empty($product['unique_id'])) {
                    $uniqueIds[] = $product['unique_id'];
                }
            }

            Log::info('استخراج unique_ids از WooCommerce', [
                'license_id' => $license->id,
                'total_products' => count($result['data']),
                'valid_unique_ids' => count($uniqueIds)
            ]);

            return $uniqueIds;

        } catch (\Exception $e) {
            Log::error('خطا در دریافت محصولات از WooCommerce: ' . $e->getMessage(), [
                'license_id' => $license->id
            ]);
            return [];
        }
    }
}
