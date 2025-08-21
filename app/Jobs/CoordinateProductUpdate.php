<?php

namespace App\Jobs;

use App\Models\License;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CoordinateProductUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

            // بررسی لایسنس
            $license = License::find($this->licenseId);
            if (!$license || !$license->isActive()) {
                Log::error('لایسنس معتبر نیست', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            // تقسیم به chunk های کوچک
            $chunkSize = 50; // افزایش سایز chunk برای سرعت بیشتر

            if (empty($this->barcodes)) {
                // اگر barcodes خالی باشد، یعنی همه محصولات
                // ارسال job برای دریافت همه محصولات و تقسیم آنها
                FetchAndDivideProducts::dispatch($this->licenseId)
                    ->onQueue('product-coordination')
                    ->delay(now()->addSeconds(2));

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

        } catch (\Exception $e) {
            Log::error('خطا در coordination محصولات: ' . $e->getMessage(), [
                'license_id' => $this->licenseId
            ]);

            // در صورت خطا، job را fail نکن، فقط log کن
        }
    }
}
