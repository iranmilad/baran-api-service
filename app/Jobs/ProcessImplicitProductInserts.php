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
 * این جاب مسئول درج محصولاتی است که در عملیات update درج شده‌اند
 * وقتی درخواست update برای محصولی است که وجود ندارد، بجای تغییر دادن logic، این محصول درج می‌شود
 * این جاب در انتهای تمام صف‌های درج اجرا می‌شود تا مغایرت‌های درج جلوگیری شود
 */
class ProcessImplicitProductInserts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;
    public $maxExceptions = 3;
    public $backoff = [30, 60, 120];

    protected $implicitProducts;
    protected $license_id;

    /**
     * ایجاد یک نمونه جدید از کار
     *
     * @param array $implicitProducts محصولاتی که در update درج شده‌اند
     * @param int $license_id شناسه لایسنس
     * @return void
     */
    public function __construct(array $implicitProducts, $license_id)
    {
        $this->implicitProducts = $implicitProducts;
        $this->license_id = $license_id;
        // این جاب در انتهای صف‌های درج قرار می‌گیرد
        $this->onQueue('product-inserts-implicit');
    }

    /**
     * اجرای کار
     *
     * @return void
     */
    public function handle()
    {
        Log::info('شروع پردازش درج‌های ضمنی محصولات', [
            'count' => count($this->implicitProducts),
            'license_id' => $this->license_id
        ]);

        DB::beginTransaction();

        try {
            $successCount = 0;
            $failureCount = 0;

            // مرتب‌سازی محصولات برای اطمینان از ایجاد محصولات مادر قبل از محصولات متغیر
            $sortedProducts = $this->sortProducts($this->implicitProducts);

            foreach ($sortedProducts as $product) {
                try {
                    // درج محصول
                    $createdProduct = Product::updateOrCreate(
                        [
                            'barcode' => $product['barcode'],
                            'license_id' => $this->license_id
                        ],
                        $product
                    );

                    $successCount++;

                    Log::info('محصول با موفقیت درج شد (درج ضمنی)', [
                        'barcode' => $product['barcode'],
                        'item_id' => $product['item_id'],
                        'is_variant' => $product['is_variant'],
                        'parent_id' => $product['parent_id'] ?? null
                    ]);
                } catch (\Exception $innerException) {
                    $failureCount++;

                    Log::error('خطا در درج محصول ضمنی', [
                        'barcode' => $product['barcode'],
                        'item_id' => $product['item_id'],
                        'error' => $innerException->getMessage()
                    ]);

                    // اگر محصول متغیر است و parent_id وجود ندارد، درج مجدد را تجویز کنید
                    if (!empty($product['parent_id'])) {
                        $parentExists = Product::where('item_id', $product['parent_id'])
                            ->where('license_id', $this->license_id)
                            ->exists();

                        if (!$parentExists) {
                            Log::warning('محصول مادر برای محصول فرزند وجود ندارد', [
                                'child_barcode' => $product['barcode'],
                                'parent_id' => $product['parent_id']
                            ]);
                        }
                    }
                }
            }

            DB::commit();

            Log::info('پایان پردازش درج‌های ضمنی محصولات', [
                'success' => $successCount,
                'failure' => $failureCount
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('خطا در پردازش درج‌های ضمنی: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * مرتب‌سازی محصولات برای اطمینان از ایجاد محصولات مادر قبل از محصولات متغیر
     *
     * @param array $products
     * @return array
     */
    protected function sortProducts($products)
    {
        usort($products, function($a, $b) {
            // محصولات بدون parent_id (مادر) اول می‌روند
            if (empty($a['parent_id']) && !empty($b['parent_id'])) return -1;
            if (!empty($a['parent_id']) && empty($b['parent_id'])) return 1;
            return 0;
        });

        return $products;
    }
}
