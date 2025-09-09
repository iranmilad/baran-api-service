<?php

namespace App\Jobs\WooCommerce;

use App\Models\License;
use App\Traits\WordPress\WordPressMasterTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PublishParentProduct implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WordPressMasterTrait;

    protected $licenseId;
    protected $parentUniqueId;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 30;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public $backoff = [10, 30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId, $parentUniqueId)
    {
        $this->licenseId = $licenseId;
        $this->parentUniqueId = $parentUniqueId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('شروع انتشار کالای مادر', [
                'license_id' => $this->licenseId,
                'parent_unique_id' => $this->parentUniqueId
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
                Log::error('API Key WooCommerce یافت نشد', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            // بررسی اینکه آیا کالای مادر دارای واریانت منتشر شده است یا نه
            $hasPublishedVariants = $this->checkForPublishedVariants($license, $wooApiKey, $this->parentUniqueId);

            if ($hasPublishedVariants) {
                // انتشار کالای مادر
                $this->publishParentProduct($license, $wooApiKey, $this->parentUniqueId);

                Log::info('کالای مادر با موفقیت منتشر شد', [
                    'license_id' => $this->licenseId,
                    'parent_unique_id' => $this->parentUniqueId
                ]);
            } else {
                Log::info('هنوز واریانت منتشر شده‌ای برای کالای مادر وجود ندارد', [
                    'license_id' => $this->licenseId,
                    'parent_unique_id' => $this->parentUniqueId
                ]);
            }

        } catch (\Exception $e) {
            Log::error('خطا در انتشار کالای مادر: ' . $e->getMessage(), [
                'license_id' => $this->licenseId,
                'parent_unique_id' => $this->parentUniqueId,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * بررسی وجود واریانت‌های منتشر شده
     */
    private function checkForPublishedVariants($license, $wooApiKey, $parentUniqueId)
    {
        try {
            // استفاده از trait برای دریافت محصولات بر اساس parent_unique_id
            $result = $this->getWooCommerceProductsByParentUniqueId(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                $parentUniqueId
            );

            if (!$result['success']) {
                Log::warning('عدم دریافت واریانت‌ها از WooCommerce', [
                    'parent_unique_id' => $parentUniqueId,
                    'error' => $result['message']
                ]);
                return false;
            }

            $variants = $result['data'] ?? [];
            $publishedCount = 0;

            foreach ($variants as $variant) {
                if (isset($variant['status']) && $variant['status'] === 'publish') {
                    $publishedCount++;
                }
            }

            Log::info('بررسی واریانت‌های منتشر شده', [
                'parent_unique_id' => $parentUniqueId,
                'total_variants' => count($variants),
                'published_variants' => $publishedCount
            ]);

            return $publishedCount > 0;

        } catch (\Exception $e) {
            Log::error('خطا در بررسی واریانت‌ها: ' . $e->getMessage(), [
                'parent_unique_id' => $parentUniqueId
            ]);
            return false;
        }
    }

    /**
     * انتشار کالای مادر
     */
    private function publishParentProduct($license, $wooApiKey, $parentUniqueId)
    {
        try {
            // پیدا کردن کالای مادر بر اساس unique_id
            $result = $this->getWooCommerceProductByUniqueId(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                $parentUniqueId
            );

            if (!$result['success'] || empty($result['data'])) {
                Log::warning('کالای مادر یافت نشد', [
                    'parent_unique_id' => $parentUniqueId,
                    'error' => $result['message'] ?? 'محصول یافت نشد'
                ]);
                return false;
            }

            $parentProduct = $result['data'];
            $productId = $parentProduct['product_id'] ?? $parentProduct['id'] ?? null;

            if (!$productId) {
                Log::error('شناسه محصول مادر یافت نشد', [
                    'parent_unique_id' => $parentUniqueId,
                    'response_data' => $parentProduct
                ]);
                return false;
            }

            // به‌روزرسانی وضعیت به منتشر شده
            $updateData = [
                'status' => 'publish'
            ];

            $updateResult = $this->updateWooCommerceProduct(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                $productId,
                $updateData
            );

            if ($updateResult['success']) {
                Log::info('کالای مادر به‌روزرسانی شد', [
                    'parent_unique_id' => $parentUniqueId,
                    'product_id' => $productId,
                    'new_status' => 'publish'
                ]);
                return true;
            } else {
                Log::error('خطا در به‌روزرسانی وضعیت کالای مادر', [
                    'parent_unique_id' => $parentUniqueId,
                    'product_id' => $productId,
                    'error' => $updateResult['message']
                ]);
                return false;
            }

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی وضعیت کالای مادر: ' . $e->getMessage(), [
                'parent_unique_id' => $parentUniqueId
            ]);
            return false;
        }
    }
}
