<?php

namespace App\Jobs\WordPress;

use App\Models\License;
use App\Models\Category;
use App\Traits\WordPress\WooCommerceApiTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SyncWooCommerceCategoriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use WooCommerceApiTrait;

    public $timeout = 300;
    public $tries = 3;
    public $backoff = [60, 180, 300];

    protected $licenseId;

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId)
    {
        $this->licenseId = $licenseId;
        $this->onQueue('category-sync');
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        Log::info('=== شروع Job همگام‌سازی دسته‌بندی‌های WooCommerce ===', [
            'license_id' => $this->licenseId,
            'job_id' => $this->job->getJobId()
        ]);

        try {
            $license = License::with(['woocommerceApiKey', 'user'])->find($this->licenseId);
            if (!$license) {
                throw new \Exception('لایسنس یافت نشد');
            }

            // دریافت تمام دسته‌بندی‌های WooCommerce
            $categories = $this->getAllWooCommerceCategories($license);

            if (empty($categories)) {
                throw new \Exception('هیچ دسته‌بندی از WooCommerce دریافت نشد');
            }

            Log::info('دسته‌بندی‌های WooCommerce دریافت شد', [
                'categories_count' => count($categories)
            ]);

            DB::beginTransaction();

            // ابتدا تمام دسته‌بندی‌های موجود این لایسنس را غیرفعال کنیم
            Category::where('license_id', $license->id)->update(['is_active' => false]);

            $syncedCount = 0;
            $createdCount = 0;
            $updatedCount = 0;

            // ایجاد یک map از دسته‌بندی‌ها بر اساس remote_id برای پیدا کردن والدین
            $categoryMap = [];

            // مرحله اول: پردازش تمام دسته‌بندی‌ها
            foreach ($categories as $wcCategory) {
                $result = $this->processWooCommerceCategory($license, $wcCategory, $categoryMap);
                if ($result) {
                    $syncedCount++;
                    if ($result['created']) {
                        $createdCount++;
                    } else {
                        $updatedCount++;
                    }
                }
            }

            DB::commit();

            Log::info('=== همگام‌سازی دسته‌بندی‌های WooCommerce با موفقیت انجام شد ===', [
                'license_id' => $license->id,
                'total_synced' => $syncedCount,
                'created' => $createdCount,
                'updated' => $updatedCount
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('خطا در Job همگام‌سازی دسته‌بندی‌های WooCommerce', [
                'error' => $e->getMessage(),
                'license_id' => $this->licenseId,
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * دریافت تمام دسته‌بندی‌های WooCommerce
     */
    protected function getAllWooCommerceCategories($license)
    {
        $apiKey = $license->woocommerceApiKey;
        if (!$apiKey || !$apiKey->api_key || !$apiKey->api_secret) {
            throw new \Exception('کلیدهای API WooCommerce تنظیم نشده است');
        }

        $url = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products/categories';

        $allCategories = [];
        $page = 1;
        $perPage = 100;

        do {
            Log::info('درخواست دریافت دسته‌بندی‌های WooCommerce', [
                'page' => $page,
                'per_page' => $perPage
            ]);

            $response = \Illuminate\Support\Facades\Http::withOptions([
                'verify' => false,
                'timeout' => 120,
                'connect_timeout' => 30
            ])->withBasicAuth($apiKey->api_key, $apiKey->api_secret)
                ->get($url, [
                    'per_page' => $perPage,
                    'page' => $page
                ]);

            if (!$response->successful()) {
                throw new \Exception('خطا در دریافت دسته‌بندی‌ها از WooCommerce - کد: ' . $response->status());
            }

            $categories = $response->json();
            $allCategories = array_merge($allCategories, $categories);

            // بررسی اینکه آیا صفحه بعدی وجود دارد
            $totalPages = (int) $response->header('X-WP-TotalPages');

            Log::info('دسته‌بندی‌های صفحه دریافت شد', [
                'page' => $page,
                'count' => count($categories),
                'total_pages' => $totalPages
            ]);

            $page++;
        } while ($page <= $totalPages);

        return $allCategories;
    }

    /**
     * پردازش یک دسته‌بندی WooCommerce
     */
    protected function processWooCommerceCategory($license, $wcCategory, &$categoryMap)
    {
        $remoteId = $wcCategory['id'];
        $name = $wcCategory['name'];
        $parentRemoteId = $wcCategory['parent'] ?? 0;

        // جستجو یا ایجاد دسته‌بندی
        $category = Category::where('license_id', $license->id)
            ->where('remote_id', $remoteId)
            ->first();

        $isNew = false;
        if (!$category) {
            $category = new Category();
            $category->license_id = $license->id;
            $category->remote_id = $remoteId;
            $isNew = true;
        }

        // تعیین parent_id
        $parentId = null;
        if ($parentRemoteId > 0) {
            // جستجوی parent در دیتابیس
            $parent = Category::where('license_id', $license->id)
                ->where('remote_id', $parentRemoteId)
                ->first();

            if ($parent) {
                $parentId = $parent->id;
            }
        }

        // به‌روزرسانی اطلاعات
        $category->name = $name;

        $category->description = $wcCategory['description'] ?? null;
        $category->parent_id = $parentId;
        $category->is_active = true;
        $category->sort_order = $wcCategory['menu_order'] ?? 0;
        $category->save();

        // اضافه کردن به map
        $categoryMap[$remoteId] = $category->id;

        Log::info($isNew ? 'دسته‌بندی جدید ایجاد شد' : 'دسته‌بندی به‌روزرسانی شد', [
            'category_id' => $category->id,
            'remote_id' => $remoteId,
            'name' => $name,
            'parent_id' => $parentId,
            'parent_remote_id' => $parentRemoteId
        ]);

        return [
            'created' => $isNew,
            'category' => $category
        ];
    }
}
