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
 * این جاب مسئول مجدد سعی برای درج محصولاتی است که به دلیل عدم وجود parent_id ناموفق شدند
 * این جاب در انتهای صف‌های درج اجرا می‌شود تا اطمینان حاصل کند که تمام محصولات مادر قبل از این درج شدند
 */
class RetryFailedProductInserts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $maxExceptions = 3;
    public $backoff = [30, 60, 120]; // مدت انتظار بیشتر برای اطمینان از درج محصولات مادر

    protected $failedProducts;
    protected $license_id;

    /**
     * ایجاد یک نمونه جدید از کار
     *
     * @param array $failedProducts محصولاتی که درج ناموفق شدند
     * @param int $license_id شناسه لایسنس
     * @return void
     */
    public function __construct(array $failedProducts, $license_id)
    {
        $this->failedProducts = $failedProducts;
        $this->license_id = $license_id;
        // این جاب در انتهای صف‌های درج قرار می‌گیرد
        $this->onQueue('product-retry');
    }

    /**
     * اجرای کار
     *
     * @return void
     */
    public function handle()
    {
        Log::info('شروع مجدد سعی برای درج محصولات ناموفق', [
            'count' => count($this->failedProducts),
            'license_id' => $this->license_id
        ]);

        DB::beginTransaction();

        try {
            $successCount = 0;
            $failureCount = 0;

            foreach ($this->failedProducts as $product) {
                try {
                    // مجدد سعی برای درج محصول
                    $createdProduct = Product::updateOrCreate(
                        [
                            'barcode' => $product['barcode'],
                            'license_id' => $this->license_id
                        ],
                        $product
                    );

                    $successCount++;

                    Log::info('محصول با موفقیت درج شد (مجدد سعی)', [
                        'barcode' => $product['barcode'],
                        'item_id' => $product['item_id'],
                        'parent_id' => $product['parent_id'] ?? null
                    ]);
                } catch (\Exception $innerException) {
                    $failureCount++;

                    Log::warning('محصول هنوز نمی‌تواند درج شود، parent_id شاید هنوز وجود ندارد', [
                        'barcode' => $product['barcode'],
                        'parent_id' => $product['parent_id'] ?? null,
                        'error' => $innerException->getMessage()
                    ]);

                    // اگر تلاش آخر است و parent_id وجود دارد، به درخواست مجدد دهید
                    if ($this->attempts() >= $this->tries && !empty($product['parent_id'])) {
                        // بررسی اینکه parent_id اکنون وجود دارد یا نه
                        $parentExists = Product::where('item_id', $product['parent_id'])
                            ->where('license_id', $this->license_id)
                            ->exists();

                        if (!$parentExists) {
                            Log::error('محصول مادر برای محصول فرزند وجود ندارد', [
                                'child_barcode' => $product['barcode'],
                                'parent_id' => $product['parent_id']
                            ]);
                        }
                    }
                }
            }

            DB::commit();

            Log::info('پایان مجدد سعی برای درج محصولات ناموفق', [
                'success' => $successCount,
                'failure' => $failureCount
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('خطا در مجدد سعی برای درج محصولات: ' . $e->getMessage());
            throw $e;
        }
    }
}
