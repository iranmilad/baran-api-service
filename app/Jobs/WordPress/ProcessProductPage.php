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
use App\Jobs\WordPress\ProcessProductVariations;
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
    public $timeout = 120; // 120 ثانیه

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
        try {
            Log::info('شروع پردازش صفحه محصولات', [
                'license_id' => $this->licenseId,
                'page' => $this->page
            ]);

            $license = License::find($this->licenseId);
            if (!$license || !$license->isActive()) {
                return;
            }

            // دریافت محصولات این صفحه (100 تا 100)
            $products = $this->getProductsPage($license, $this->page);

            if (empty($products)) {
                Log::info('صفحه خالی است - پایان پردازش', [
                    'license_id' => $this->licenseId,
                    'page' => $this->page
                ]);
                return;
            }

            // ارسال هر محصول برای پردازش مستقل
            // این کار اجازه می‌دهد محصولات به صورت parallel پردازش شوند
            foreach ($products as $index => $product) {
                ProcessProductVariations::dispatch($this->licenseId, $product)
                    ->onQueue('empty-unique-ids')
                    ->delay(now()->addSeconds($index * 0.5));

                Log::info('محصول برای پردازش مستقل ارسال شد', [
                    'license_id' => $this->licenseId,
                    'product_id' => $product['id'],
                    'product_type' => $product['type'],
                    'delay_seconds' => $index * 0.5
                ]);
            }

            Log::info('خلاصه صفحه پردازش شد - تمام محصولات برای processing ارسال شدند', [
                'license_id' => $this->licenseId,
                'page' => $this->page,
                'total_products' => count($products)
            ]);

            // اگر تعداد محصولات = 100 است، صفحه بعدی وجود دارد
            if (count($products) === 100) {
                ProcessProductPage::dispatch($this->licenseId, $this->page + 1)
                    ->onQueue('empty-unique-ids')
                    ->delay(now()->addSeconds(5));

                Log::info('ارسال صفحه بعد برای پردازش', [
                    'license_id' => $this->licenseId,
                    'current_page' => $this->page,
                    'next_page' => $this->page + 1,
                    'products_in_current_page' => count($products)
                ]);
            } else {
                Log::info('پایان پردازش - تمام صفحات تکمیل شد (آخرین صفحه)', [
                    'license_id' => $this->licenseId,
                    'last_page' => $this->page,
                    'products_in_this_page' => count($products)
                ]);
            }

            Log::info('پایان پردازش صفحه محصولات', [
                'license_id' => $this->licenseId,
                'page' => $this->page,
                'total_products_in_page' => count($products)
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در پردازش صفحه محصولات: ' . $e->getMessage(), [
                'license_id' => $this->licenseId,
                'page' => $this->page,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Get products for a specific page (all products, regardless of unique_id status)
     */
    private function getProductsPage($license, $page)
    {
        try {
            $wooApiKey = $license->woocommerceApiKey;
            if (!$wooApiKey) {
                Log::warning('WooCommerce API key not found', [
                    'license_id' => $license->id,
                    'page' => $page
                ]);
                return [];
            }

            // پارامترهای درخواست - بدون bim_unique_id_empty
            $params = [
                'page' => $page,
                'per_page' => 100
            ];

            Log::info('درخواست صفحه محصولات از WooCommerce', [
                'license_id' => $license->id,
                'page' => $page,
                'per_page' => 100
            ]);

            // استفاده از trait برای دریافت محصولات
            $result = $this->getWooCommerceProducts(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                $params
            );

            if (!$result['success']) {
                Log::error("WooCommerce API request failed", [
                    'license_id' => $license->id,
                    'error' => $result['message'],
                    'page' => $page
                ]);
                return [];
            }

            Log::info('محصولات صفحه با موفقیت دریافت شد', [
                'license_id' => $license->id,
                'page' => $page,
                'products_count' => count($result['data'])
            ]);

            return $result['data'];

        } catch (Exception $e) {
            Log::error("Error fetching products page", [
                'license_id' => $license->id,
                'error' => $e->getMessage(),
                'page' => $page
            ]);
            return [];
        }
    }


}
