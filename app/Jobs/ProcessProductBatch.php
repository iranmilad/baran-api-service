<?php

namespace App\Jobs;

use App\Models\License;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Automattic\WooCommerce\Client;

class ProcessProductBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $licenseId;
    protected $barcodes;
    protected $batchSize;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 2;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public $maxExceptions = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 40; // 40 ثانیه

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public $backoff = [10, 30];

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId, $barcodes, $batchSize = 10)
    {
        $this->licenseId = $licenseId;
        $this->barcodes = $barcodes;
        $this->batchSize = $batchSize;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        $maxExecutionTime = 35; // 35 ثانیه

        try {
            Log::info('شروع پردازش batch محصولات', [
                'license_id' => $this->licenseId,
                'barcodes_count' => count($this->barcodes),
                'batch_size' => $this->batchSize
            ]);

            $license = License::with(['userSetting', 'woocommerceApiKey'])->find($this->licenseId);
            if (!$license || !$license->isActive()) {
                Log::error('لایسنس معتبر نیست', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            $userSettings = $license->userSetting;
            $wooApiKey = $license->woocommerceApiKey;

            if (!$userSettings || !$wooApiKey) {
                Log::error('تنظیمات یا کلید API ووکامرس یافت نشد', [
                    'license_id' => $license->id
                ]);
                return;
            }

            // تقسیم barcodes به chunk های کوچکتر
            $barcodeChunks = array_chunk($this->barcodes, $this->batchSize);
            $processedChunks = 0;

            foreach ($barcodeChunks as $chunk) {
                // بررسی زمان
                $elapsedTime = microtime(true) - $startTime;
                if ($elapsedTime > $maxExecutionTime) {
                    Log::info('زمان به پایان رسید، ارسال chunks باقی‌مانده', [
                        'license_id' => $this->licenseId,
                        'processed_chunks' => $processedChunks,
                        'remaining_chunks' => count($barcodeChunks) - $processedChunks
                    ]);

                    // ارسال chunks باقی‌مانده به job جدید
                    $remainingBarcodes = array_merge(...array_slice($barcodeChunks, $processedChunks));

                    ProcessProductBatch::dispatch($this->licenseId, $remainingBarcodes, $this->batchSize)
                        ->onQueue('products')
                        ->delay(now()->addSeconds(5));

                    break;
                }

                $this->processChunk($chunk, $license, $userSettings, $wooApiKey);
                $processedChunks++;
            }

            Log::info('پردازش batch محصولات کامل شد', [
                'license_id' => $this->licenseId,
                'processed_chunks' => $processedChunks,
                'execution_time' => round(microtime(true) - $startTime, 2)
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در پردازش batch محصولات: ' . $e->getMessage(), [
                'license_id' => $this->licenseId,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * پردازش یک chunk از barcodes
     */
    private function processChunk($barcodes, $license, $userSettings, $wooApiKey)
    {
        try {
            // دریافت اطلاعات محصولات از RainSale API
            $rainProducts = $this->getRainProducts($barcodes, $license->user);

            if (empty($rainProducts)) {
                Log::info('هیچ محصولی از RainSale API دریافت نشد', [
                    'license_id' => $this->licenseId,
                    'barcodes' => $barcodes
                ]);
                return;
            }

            // به‌روزرسانی محصولات در WooCommerce
            $this->updateWooCommerceProducts($rainProducts, $license, $userSettings, $wooApiKey);

        } catch (\Exception $e) {
            Log::error('خطا در پردازش chunk: ' . $e->getMessage(), [
                'license_id' => $this->licenseId,
                'barcodes' => $barcodes
            ]);
        }
    }

    /**
     * دریافت اطلاعات محصولات از RainSale API
     */
    private function getRainProducts($barcodes, $user)
    {
        try {
            if (!$user->api_webservice || !$user->api_username || !$user->api_password) {
                Log::warning('اطلاعات API باران یافت نشد', [
                    'user_id' => $user->id
                ]);
                return [];
            }

            // دریافت لایسنس با تنظیمات برای دسترسی به default_warehouse_code
            $license = License::with('userSetting')->find($this->licenseId);
            $stockId = $license && $license->userSetting ? $license->userSetting->default_warehouse_code : '';

            Log::info('استفاده از default_warehouse_code برای stockId', [
                'license_id' => $this->licenseId,
                'stock_id' => $stockId,
                'has_user_settings' => !is_null($license ? $license->userSetting : null)
            ]);

            // آماده‌سازی body درخواست
            $requestBody = ['barcodes' => $barcodes];

            // اضافه کردن stockId فقط در صورت وجود مقدار
            if (!empty($stockId)) {
                $requestBody['stockId'] = $stockId;
            }

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 20,
                'connect_timeout' => 5
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user->api_username . ':' . $user->api_password)
            ])->post($user->api_webservice . "/RainSaleService.svc/GetItemInfos", $requestBody);

            if (!$response->successful()) {
                Log::warning('خطا در دریافت از RainSale API', [
                    'status' => $response->status(),
                    'barcodes' => $barcodes
                ]);
                return [];
            }

            $data = $response->json();
            return $data['GetItemInfosResult'] ?? [];

        } catch (\Exception $e) {
            Log::error('خطا در درخواست RainSale API: ' . $e->getMessage(), [
                'barcodes' => $barcodes
            ]);
            return [];
        }
    }

    /**
     * به‌روزرسانی محصولات در WooCommerce
     */
    private function updateWooCommerceProducts($rainProducts, $license, $userSettings, $wooApiKey)
    {
        // این قسمت می‌تواند پیاده‌سازی کوتاه‌تری داشته باشد
        // یا از طریق API مستقیم WooCommerce انجام شود
        Log::info('به‌روزرسانی محصولات در WooCommerce', [
            'license_id' => $this->licenseId,
            'products_count' => count($rainProducts)
        ]);
    }
}
