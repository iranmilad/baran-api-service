<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * این جاب مسئول درج محصولات فرزندی است که محصول مادر آنها هنوز درج نشده است
 * این جاب با تأخیر زمانی (30 ثانیه) ارسال می‌شود تا اطمینان حاصل شود محصول مادر درج شده است
 */
class ProcessOrphanProductVariants implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $maxExceptions = 3;
    public $backoff = [30, 60, 120]; // تأخیر بیشتر برای اطمینان از درج محصول مادر

    protected $orphanVariants;
    protected $license_id;

    /**
     * ایجاد یک نمونه جدید از کار
     *
     * @param array $orphanVariants فرزندهایی که مادر ندارند
     * @param int $license_id شناسه لایسنس
     * @return void
     */
    public function __construct(array $orphanVariants, $license_id)
    {
        $this->orphanVariants = $orphanVariants;
        $this->license_id = $license_id;
        // این جاب در کیو مجزا قرار می‌گیرد
        $this->onQueue('product-orphan-variants');
    }

    /**
     * اجرای کار
     *
     * @return void
     */
    public function handle()
    {
        Log::info('شروع پردازش فرزندهای بی‌پدر', [
            'count' => count($this->orphanVariants),
            'license_id' => $this->license_id,
            'attempt' => $this->attempts()
        ]);

        DB::beginTransaction();

        try {
            $successCount = 0;
            $failureCount = 0;
            $orphanVariantsStillMissing = [];

            foreach ($this->orphanVariants as $variant) {
                try {
                    // بررسی اینکه آیا محصول مادر اکنون وجود دارد
                    $parentExists = Product::where('item_id', $variant['parent_id'])
                        ->where('license_id', $this->license_id)
                        ->exists();

                    if (!$parentExists) {
                        Log::warning('محصول مادر برای فرزند هنوز وجود ندارد', [
                            'child_barcode' => $variant['barcode'],
                            'parent_id' => $variant['parent_id'],
                            'attempt' => $this->attempts()
                        ]);

                        // اگر تلاش آخر نیست، فرزند را برای تلاش بعدی ذخیره کن
                        if ($this->attempts() < $this->tries) {
                            $orphanVariantsStillMissing[] = $variant;
                        }
                        continue;
                    }

                    // محصول مادر وجود دارد، فرزند را درج کن
                    $createdVariant = Product::updateOrCreate(
                        [
                            'barcode' => $variant['barcode'],
                            'license_id' => $this->license_id
                        ],
                        $variant
                    );

                    $successCount++;

                    Log::info('فرزند بی‌پدر با موفقیت درج شد', [
                        'barcode' => $variant['barcode'],
                        'item_id' => $variant['item_id'],
                        'parent_id' => $variant['parent_id']
                    ]);
                } catch (\Exception $innerException) {
                    $failureCount++;

                    Log::error('خطا در درج فرزند بی‌پدر', [
                        'barcode' => $variant['barcode'],
                        'parent_id' => $variant['parent_id'],
                        'error' => $innerException->getMessage()
                    ]);

                    // فرزند را برای تلاش مجدد ذخیره کن
                    if ($this->attempts() < $this->tries) {
                        $orphanVariantsStillMissing[] = $variant;
                    }
                }
            }

            DB::commit();

            Log::info('پایان پردازش فرزندهای بی‌پدر', [
                'success' => $successCount,
                'failure' => $failureCount,
                'still_orphan' => count($orphanVariantsStillMissing),
                'attempt' => $this->attempts()
            ]);

            // اگر هنوز فرزندهای بی‌پدر باقی‌مانده است، دوباره سعی کن
            if (!empty($orphanVariantsStillMissing) && $this->attempts() < $this->tries) {
                Log::info('ارسال مجدد فرزندهای بی‌پدر برای تلاش بعدی', [
                    'count' => count($orphanVariantsStillMissing),
                    'next_attempt' => $this->attempts() + 1
                ]);
                // تأخیر 30 ثانیه‌ای برای تلاش مجدد
                ProcessOrphanProductVariants::dispatch($orphanVariantsStillMissing, $this->license_id)
                    ->delay(now()->addSeconds(30));
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('خطا در پردازش فرزندهای بی‌پدر: ' . $e->getMessage());
            throw $e;
        }
    }
}
