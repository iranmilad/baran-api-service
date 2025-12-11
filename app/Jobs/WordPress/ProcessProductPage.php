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
use App\Traits\WordPress\WordPressMasterTrait;
use Exception;

class ProcessProductPage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WordPressMasterTrait;

    protected $licenseId;
    protected $page;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public $maxExceptions = 2;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 50; // 50 ثانیه

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public $backoff = [10, 30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId, $page)
    {
        $this->licenseId = $licenseId;
        $this->page = $page;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        $maxExecutionTime = 45; // 45 ثانیه

        try {
            Log::info('شروع پردازش صفحه محصولات', [
                'license_id' => $this->licenseId,
                'page' => $this->page
            ]);

            $license = License::find($this->licenseId);
            if (!$license || !$license->isActive()) {
                return;
            }

            // Get products for this specific page
            $products = $this->getProductsPage($license, $this->page);

            if (empty($products)) {
                return;
            }

            // Extract SKUs from products that have them
            $skus = [];
            $processedProducts = 0;

            foreach ($products as $product) {
                // بررسی زمان اجرا
                $currentTime = microtime(true);
                $elapsedTime = $currentTime - $startTime;

                if ($elapsedTime > $maxExecutionTime) {
                    // ارسال job جدید برای ادامه کار از همان صفحه
                    ProcessProductPage::dispatch($this->licenseId, $this->page)
                        ->onQueue('empty-unique-ids')
                        ->delay(now()->addSeconds(5));

                    break;
                }

                if (!empty($product['sku'])) {
                    $skus[] = $product['sku'];
                }

                // Handle variations for variable products
                if ($product['type'] === 'variable' && $elapsedTime < ($maxExecutionTime - 10)) {
                    $variationSkus = $this->getVariationSkus($license, $product['id']);
                    $skus = array_merge($skus, $variationSkus);
                }

                $processedProducts++;
            }

            if (!empty($skus)) {
                // Process SKUs in batches of 50
                $skuBatches = array_chunk($skus, 50);

                foreach ($skuBatches as $batchIndex => $skuBatch) {
                    ProcessSkuBatch::dispatch($this->licenseId, $skuBatch)
                        ->onQueue('empty-unique-ids')
                        ->delay(now()->addSeconds($batchIndex * 2));
                }
            }

            // بررسی نهایی زمان
            $finalElapsedTime = microtime(true) - $startTime;

            // اگر تعداد محصولات کمتر از 100 است، صفحات دیگر وجود ندارد (این آخرین صفحه است)
            // اگر تعداد برابر 100 است و زمان اجرا کم است، صفحه بعد را پردازش کن
            if (count($products) === 100 && $finalElapsedTime < $maxExecutionTime) {
                ProcessProductPage::dispatch($this->licenseId, $this->page + 1)
                    ->onQueue('empty-unique-ids')
                    ->delay(now()->addSeconds(10));

                Log::info('ارسال صفحه بعد برای پردازش', [
                    'license_id' => $this->licenseId,
                    'current_page' => $this->page,
                    'next_page' => $this->page + 1,
                    'elapsed_time' => $finalElapsedTime
                ]);
            } elseif (count($products) < 100) {
                Log::info('پایان پردازش - تمام صفحات تکمیل شد', [
                    'license_id' => $this->licenseId,
                    'current_page' => $this->page,
                    'products_in_this_page' => count($products)
                ]);
            } else {
                Log::warning('صفحه بعد ارسال نشد - زمان اجرا بیش از حد مجاز است', [
                    'license_id' => $this->licenseId,
                    'current_page' => $this->page,
                    'elapsed_time' => $finalElapsedTime,
                    'max_time' => $maxExecutionTime
                ]);
            }

            Log::info('پایان پردازش صفحه محصولات', [
                'license_id' => $this->licenseId,
                'page' => $this->page,
                'processed_products' => $processedProducts,
                'total_products_in_page' => count($products)
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در پردازش صفحه محصولات: ' . $e->getMessage(), [
                'license_id' => $this->licenseId,
                'page' => $this->page
            ]);
            throw $e;
        }
    }

    /**
     * Get products for a specific page
     */
    private function getProductsPage($license, $page)
    {
        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                return [];
            }

            // پارامترهای درخواست
            $params = [
                'page' => $page,
                'per_page' => 100,
                'bim_unique_id_empty' => 'true'
            ];

            // استفاده از trait برای دریافت محصولات
            $result = $this->getWooCommerceProducts(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                $params
            );

            if (!$result['success']) {
                Log::error("WooCommerce API request failed", [
                    'error' => $result['message'],
                    'page' => $page
                ]);
                return [];
            }

            return $result['data'];

        } catch (Exception $e) {
            Log::error("Error fetching products with empty bim_unique_id", [
                'error' => $e->getMessage(),
                'page' => $page
            ]);
            return [];
        }
    }

    /**
     * Get SKUs from variations of a variable product
     */
    private function getVariationSkus($license, $productId)
    {
        $skus = [];
        $page = 1;
        $hasMore = true;
        $maxPages = 3; // محدود کردن تعداد صفحات برای جلوگیری از timeout

        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                return $skus;
            }

            while ($hasMore && $page <= $maxPages) {
                // پارامترهای درخواست
                $params = [
                    'page' => $page,
                    'per_page' => 50,
                    'bim_unique_id_empty' => 'true'
                ];

                // استفاده از trait برای دریافت واریانت‌ها
                $result = $this->getWooCommerceProductVariations(
                    $license->website_url,
                    $wooApiKey->api_key,
                    $wooApiKey->api_secret,
                    $productId,
                    $params
                );

                if (!$result['success']) {
                    Log::error('Failed to fetch variations', [
                        'product_id' => $productId,
                        'page' => $page,
                        'error' => $result['message']
                    ]);
                    $hasMore = false;
                    continue;
                }

                $variations = $result['data'];

                if (empty($variations)) {
                    $hasMore = false;
                    continue;
                }

                foreach ($variations as $variation) {
                    if (!empty($variation['sku'])) {
                        $skus[] = $variation['sku'];
                    }
                }

                $page++;

                if (count($variations) < 50) {
                    $hasMore = false;
                }
            }

        } catch (\Exception $e) {
            Log::error('Error fetching variation SKUs', [
                'error' => $e->getMessage(),
                'product_id' => $productId
            ]);
        }

        return $skus;
    }
}
