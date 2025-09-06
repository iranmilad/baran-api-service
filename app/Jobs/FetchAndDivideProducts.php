<?php

namespace App\Jobs;

use App\Models\License;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Automattic\WooCommerce\Client;

class FetchAndDivideProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $licenseId;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 2;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 60;

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
        $startTime = microtime(true);
        $maxExecutionTime = 60; // 60 ثانیه

        try {
            Log::info('شروع دریافت و تقسیم همه محصولات', [
                'license_id' => $this->licenseId
            ]);

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

            // دریافت unique_id محصولات از WooCommerce
            $allUniqueIds = $this->getAllProductUniqueIds($license, $wooApiKey, $startTime, $maxExecutionTime);

            if (empty($allUniqueIds)) {
                Log::error('هیچ کد یکتایی برای محصولات یافت نشد', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            // تقسیم به chunks و ارسال
            $chunkSize = 50; // افزایش chunk size برای سرعت بیشتر
            $chunks = array_chunk($allUniqueIds, $chunkSize);

            foreach ($chunks as $index => $chunk) {
                $delaySeconds = 3 + ($index * 5); // کاهش delay

                ProcessSingleProductBatch::dispatch($this->licenseId, $chunk);

                // هر 200 chunk یک log
                if (($index + 1) % 200 === 0) {
                    Log::info('پیشرفت ارسال chunks', [
                        'license_id' => $this->licenseId,
                        'processed_chunks' => $index + 1,
                        'total_chunks' => count($chunks)
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::error('خطا در دریافت و تقسیم محصولات: ' . $e->getMessage(), [
                'license_id' => $this->licenseId
            ]);
            throw $e;
        }
    }

    /**
     * دریافت همه unique_id های محصولات از WooCommerce
     */
    private function getAllProductUniqueIds($license, $wooApiKey, $startTime, $maxExecutionTime)
    {
        try {
            $woocommerce = new Client(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                [
                    'version' => 'wc/v3',
                    'verify_ssl' => false,
                    'timeout' => 25
                ]
            );

            $response = $woocommerce->get('products/unique');

            if (!isset($response->success) || !$response->success || !isset($response->data)) {
                Log::error('پاسخ نامعتبر از API ووکامرس', [
                    'license_id' => $this->licenseId
                ]);
                return [];
            }

            // استخراج unique_id
            $uniqueIds = [];
            foreach ($response->data as $product) {
                if (!empty($product->unique_id)) {
                    $uniqueIds[] = $product->unique_id;
                }

                // بررسی زمان
                $elapsedTime = microtime(true) - $startTime;
                if ($elapsedTime > $maxExecutionTime) {
                    Log::warning('زمان به پایان رسید در حین دریافت محصولات', [
                        'license_id' => $this->licenseId,
                        'unique_ids_collected' => count($uniqueIds)
                    ]);
                    break;
                }
            }

            Log::info('unique_id دریافت شد از WooCommerce', [
                'license_id' => $this->licenseId,
                'count' => count($uniqueIds)
            ]);

            return $uniqueIds;

        } catch (\Exception $e) {
            Log::error('خطا در دریافت محصولات از WooCommerce: ' . $e->getMessage(), [
                'license_id' => $this->licenseId
            ]);
            return [];
        }
    }
}
