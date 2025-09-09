<?php

namespace App\Jobs\Tantooo;

use App\Models\License;
use App\Traits\Tantooo\TantoooApiTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CoordinateTantoooProductUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TantoooApiTrait;

    protected $licenseId;
    protected $operation;
    protected $productCodes;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId, $operation = 'update_all', $productCodes = [])
    {
        $this->licenseId = $licenseId;
        $this->operation = $operation;
        $this->productCodes = $productCodes;
        $this->onQueue('tantooo-coordination');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('شروع coordination برای به‌روزرسانی محصولات Tantooo', [
                'license_id' => $this->licenseId,
                'operation' => $this->operation,
                'product_codes_count' => count($this->productCodes)
            ]);

            // بررسی لایسنس و تنظیمات
            $license = License::with(['userSetting'])->find($this->licenseId);
            if (!$license || !$license->isActive()) {
                Log::error('لایسنس معتبر نیست', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            // بررسی تنظیمات Tantooo API
            if (empty($license->website_url) || empty($license->api_token)) {
                Log::error('تنظیمات Tantooo API ناقص است', [
                    'license_id' => $license->id,
                    'website_url' => $license->website_url ? 'exists' : 'missing',
                    'api_token' => $license->api_token ? 'exists' : 'missing'
                ]);
                return;
            }

            // تست اتصال Tantooo API قبل از شروع
            $connectionTest = $this->testTantoooApiConnection($license);
            if (!$connectionTest['success']) {
                Log::error('اتصال به Tantooo API ناموفق', [
                    'license_id' => $license->id,
                    'error' => $connectionTest['message']
                ]);
                return;
            }

            // بر اساس نوع عملیات، شروع فرآیند
            switch ($this->operation) {
                case 'update_all':
                    $this->updateAllProducts($license);
                    break;
                
                case 'update_specific':
                    $this->updateSpecificProducts($license, $this->productCodes);
                    break;
                
                case 'fetch_and_update':
                    $this->fetchAndUpdateProducts($license);
                    break;
                
                default:
                    Log::warning('نوع عملیات نامشخص', [
                        'operation' => $this->operation,
                        'license_id' => $this->licenseId
                    ]);
                    break;
            }

            Log::info('تکمیل coordination برای محصولات Tantooo', [
                'license_id' => $this->licenseId,
                'operation' => $this->operation
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در coordination محصولات Tantooo', [
                'license_id' => $this->licenseId,
                'operation' => $this->operation,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * به‌روزرسانی همه محصولات
     */
    protected function updateAllProducts($license)
    {
        Log::info('شروع به‌روزرسانی همه محصولات Tantooo', [
            'license_id' => $license->id
        ]);

        // ابتدا همه محصولات را از Tantooo دریافت می‌کنیم
        FetchAndDivideTantoooProducts::dispatch($license->id)
            ->onQueue('tantooo-fetch');
    }

    /**
     * به‌روزرسانی محصولات خاص
     */
    protected function updateSpecificProducts($license, $productCodes)
    {
        if (empty($productCodes)) {
            Log::warning('کدهای محصول برای به‌روزرسانی خاص خالی است', [
                'license_id' => $license->id
            ]);
            return;
        }

        Log::info('شروع به‌روزرسانی محصولات خاص Tantooo', [
            'license_id' => $license->id,
            'product_codes' => $productCodes
        ]);

        // تقسیم کدهای محصول به چانک‌های کوچک
        $chunkSize = 50;
        $chunks = array_chunk($productCodes, $chunkSize);

        foreach ($chunks as $index => $chunk) {
            UpdateTantoooProductsBatch::dispatch($license->id, $chunk, $index + 1, count($chunks))
                ->onQueue('tantooo-update')
                ->delay(now()->addSeconds($index * 5)); // تاخیر 5 ثانیه‌ای بین هر chunk
        }
    }

    /**
     * دریافت و به‌روزرسانی محصولات
     */
    protected function fetchAndUpdateProducts($license)
    {
        Log::info('شروع دریافت و به‌روزرسانی محصولات Tantooo', [
            'license_id' => $license->id
        ]);

        // ابتدا محصولات جدید را دریافت کرده و سپس به‌روزرسانی می‌کنیم
        FetchAndDivideTantoooProducts::dispatch($license->id)
            ->onQueue('tantooo-fetch');
    }

    /**
     * تست اتصال به Tantooo API
     */
    protected function testTantoooApiConnection($license)
    {
        try {
            $settings = $this->getTantoooApiSettings($license);
            if (!$settings) {
                return [
                    'success' => false,
                    'message' => 'تنظیمات API یافت نشد'
                ];
            }

            // تست ساده با دریافت یک صفحه محصولات
            $result = $this->getProductsFromTantoooApiWithToken($license, 1, 1);
            
            if ($result['success']) {
                return [
                    'success' => true,
                    'message' => 'اتصال موفق'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? 'خطای نامشخص در اتصال'
                ];
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در تست اتصال: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('شکست در coordination محصولات Tantooo', [
            'license_id' => $this->licenseId,
            'operation' => $this->operation,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
