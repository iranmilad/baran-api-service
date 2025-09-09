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

class FetchAndDivideTantoooProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TantoooApiTrait;

    protected $licenseId;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 300; // 5 دقیقه

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId)
    {
        $this->licenseId = $licenseId;
        $this->onQueue('tantooo-fetch');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        $maxExecutionTime = 240; // 4 دقیقه

        try {
            Log::info('شروع دریافت و تقسیم همه محصولات Tantooo', [
                'license_id' => $this->licenseId
            ]);

            $license = License::find($this->licenseId);
            if (!$license || !$license->isActive()) {
                Log::error('لایسنس معتبر نیست', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            // بررسی تنظیمات Tantooo API
            if (empty($license->website_url) || empty($license->api_token)) {
                Log::error('تنظیمات Tantooo API ناقص است', [
                    'license_id' => $license->id
                ]);
                return;
            }

            $allProductCodes = [];
            $page = 1;
            $perPage = 100; // تعداد محصولات در هر صفحه
            $totalFetched = 0;
            $totalProducts = 0;

            // دریافت محصولات صفحه به صفحه
            do {
                // بررسی زمان اجرا
                if ((microtime(true) - $startTime) > $maxExecutionTime) {
                    Log::warning('زمان اجرا به حد مجاز رسید، متوقف شدن...', [
                        'license_id' => $this->licenseId,
                        'fetched_so_far' => $totalFetched,
                        'current_page' => $page
                    ]);
                    break;
                }

                Log::info('دریافت صفحه محصولات Tantooo', [
                    'license_id' => $this->licenseId,
                    'page' => $page,
                    'per_page' => $perPage
                ]);

                $result = $this->getProductsFromTantoooApiWithToken($license, $page, $perPage);

                if (!$result['success']) {
                    Log::error('خطا در دریافت محصولات Tantooo', [
                        'license_id' => $this->licenseId,
                        'page' => $page,
                        'error' => $result['message']
                    ]);
                    break;
                }

                $data = $result['data'];
                $products = $data['products'] ?? [];
                $totalProducts = $data['total'] ?? 0;

                if (empty($products)) {
                    Log::info('هیچ محصولی در صفحه یافت نشد', [
                        'license_id' => $this->licenseId,
                        'page' => $page
                    ]);
                    break;
                }

                // استخراج کدهای محصولات
                foreach ($products as $product) {
                    $productCode = $this->extractProductCode($product);
                    if ($productCode) {
                        $allProductCodes[] = $productCode;
                        $totalFetched++;
                    }
                }

                Log::info('محصولات صفحه پردازش شد', [
                    'license_id' => $this->licenseId,
                    'page' => $page,
                    'products_in_page' => count($products),
                    'total_fetched' => $totalFetched,
                    'total_products' => $totalProducts
                ]);

                $page++;

                // اگر همه محصولات دریافت شدند
                if ($totalFetched >= $totalProducts) {
                    break;
                }

                // تاخیر کوتاه بین درخواست‌ها
                sleep(1);

            } while (true);

            if (empty($allProductCodes)) {
                Log::warning('هیچ کد محصولی استخراج نشد', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            Log::info('تکمیل دریافت محصولات Tantooo', [
                'license_id' => $this->licenseId,
                'total_fetched' => count($allProductCodes),
                'execution_time' => microtime(true) - $startTime
            ]);

            // تقسیم کدهای محصولات به چانک‌ها و ارسال به job های به‌روزرسانی
            $this->divideAndDispatchUpdateJobs($license, $allProductCodes);

        } catch (\Exception $e) {
            Log::error('خطا در دریافت و تقسیم محصولات Tantooo', [
                'license_id' => $this->licenseId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * استخراج کد محصول از داده‌های محصول
     */
    protected function extractProductCode($product)
    {
        // جستجو برای فیلدهای مختلف که می‌تواند کد محصول باشد
        $possibleFields = ['code', 'product_code', 'Code', 'ProductCode', 'item_code', 'sku', 'SKU'];
        
        foreach ($possibleFields as $field) {
            if (isset($product[$field]) && !empty($product[$field])) {
                return trim($product[$field]);
            }
        }

        // اگر هیچ فیلد مناسبی یافت نشد، لاگ بگیریم
        Log::warning('کد محصول در داده‌های محصول یافت نشد', [
            'license_id' => $this->licenseId,
            'product_fields' => array_keys($product)
        ]);

        return null;
    }

    /**
     * تقسیم کدهای محصولات و ارسال job های به‌روزرسانی
     */
    protected function divideAndDispatchUpdateJobs($license, $allProductCodes)
    {
        $chunkSize = 50; // تعداد محصولات در هر chunk
        $chunks = array_chunk($allProductCodes, $chunkSize);
        $totalChunks = count($chunks);

        Log::info('تقسیم کدهای محصولات به چانک‌ها', [
            'license_id' => $license->id,
            'total_codes' => count($allProductCodes),
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks
        ]);

        foreach ($chunks as $index => $chunk) {
            $delay = $index * 10; // تاخیر 10 ثانیه‌ای بین هر chunk

            UpdateTantoooProductsBatch::dispatch(
                $license->id,
                $chunk,
                $index + 1,
                $totalChunks
            )
            ->onQueue('tantooo-update')
            ->delay(now()->addSeconds($delay));

            Log::info('ارسال job به‌روزرسانی chunk', [
                'license_id' => $license->id,
                'chunk_number' => $index + 1,
                'chunk_size' => count($chunk),
                'delay_seconds' => $delay
            ]);
        }

        Log::info('تکمیل ارسال همه job های به‌روزرسانی', [
            'license_id' => $license->id,
            'total_chunks_dispatched' => $totalChunks
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('شکست در دریافت و تقسیم محصولات Tantooo', [
            'license_id' => $this->licenseId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
