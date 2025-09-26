<?php

namespace App\Jobs;

use App\Models\License;
use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class SyncAllProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $licenseId;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 2;

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public $backoff = [30, 60];

    /**
     * The maximum number of seconds the job can run.
     */
    public $timeout = 900; // 15 minutes

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId)
    {
        $this->licenseId = $licenseId;
        $this->onQueue('product-sync-all');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('شروع همگام‌سازی تمام محصولات', [
            'license_id' => $this->licenseId
        ]);

        try {
            $license = License::with(['userSetting', 'woocommerceApiKey', 'user'])->find($this->licenseId);

            if (!$license || !$license->isActive()) {
                Log::warning('فرآیند متوقف شد - لایسنس نامعتبر یا غیرفعال', [
                    'license_id' => $this->licenseId,
                    'license_found' => !is_null($license),
                    'license_active' => $license ? $license->isActive() : false
                ]);
                return;
            }

            $userSettings = $license->userSetting;
            $wooApiKey = $license->woocommerceApiKey;
            $user = $license->user;

            if (!$userSettings || !$wooApiKey || !$user) {
                Log::warning('فرآیند متوقف شد - تنظیمات کاربر، کلید API یا کاربر یافت نشد', [
                    'license_id' => $this->licenseId,
                    'user_settings_found' => !is_null($userSettings),
                    'woo_api_key_found' => !is_null($wooApiKey),
                    'user_found' => !is_null($user)
                ]);
                return;
            }

            // گام 1: دریافت تمام کدهای یکتا از WooCommerce
            $wooCommerceUniqueIds = $this->getAllWooCommerceUniqueIds($license, $wooApiKey);

            Log::info('کدهای یکتا از ووکامرس دریافت شد', [
                'license_id' => $this->licenseId,
                'woo_unique_ids_count' => count($wooCommerceUniqueIds)
            ]);

            // گام 2: بررسی کدهای موجود در جدول محصولات
            $existingProducts = Product::where('license_id', $this->licenseId)
                ->whereNotNull('item_id')
                ->get(['item_id', 'barcode', 'name']);

            Log::info('کدهای یکتای موجود در جدول', [
                'license_id' => $this->licenseId,
                'existing_products_count' => $existingProducts->count()
            ]);

            if ($existingProducts->isEmpty()) {
                Log::info('هیچ محصولی در جدول وجود ندارد', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            $existingUniqueIds = $existingProducts->pluck('item_id')->toArray();

            // گام 3: پیدا کردن کدهایی که در جدول هستند ولی در ووکامرس نیستند
            $missingInWooCommerce = array_diff($existingUniqueIds, $wooCommerceUniqueIds);

            if (empty($missingInWooCommerce)) {
                Log::info('همه محصولات جدول در ووکامرس موجود هستند', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            Log::info('محصولاتی که در ووکامرس وجود ندارند شناسایی شد', [
                'license_id' => $this->licenseId,
                'missing_products_count' => count($missingInWooCommerce),
                'sample_missing_ids' => array_slice($missingInWooCommerce, 0, 10) // نمایش 10 اولی برای نمونه
            ]);

            // گام 4: درج محصولات جدید در ووکامرس
            $productsToInsert = $existingProducts->whereIn('item_id', $missingInWooCommerce);
            $this->insertProductsToWooCommerce($productsToInsert, $license, $wooApiKey);

            Log::info('همگام‌سازی تمام محصولات با موفقیت انجام شد', [
                'license_id' => $this->licenseId,
                'inserted_to_woocommerce_count' => count($missingInWooCommerce)
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در همگام‌سازی تمام محصولات: ' . $e->getMessage(), [
                'license_id' => $this->licenseId,
                'exception_line' => $e->getLine(),
                'exception_file' => $e->getFile()
            ]);
            throw $e;
        }
    }

    /**
     * دریافت تمام کدهای یکتا از WooCommerce
     */
    private function getAllWooCommerceUniqueIds($license, $wooApiKey)
    {
        try {
            $uniqueIds = [];
            $page = 1;
            $perPage = 100;

            do {
                Log::info('درخواست صفحه محصولات از ووکامرس', [
                    'license_id' => $this->licenseId,
                    'page' => $page,
                    'per_page' => $perPage
                ]);

                $response = Http::withOptions([
                    'verify' => false,
                    'timeout' => 60
                ])->withBasicAuth(
                    $wooApiKey->api_key,
                    $wooApiKey->api_secret
                )->get($license->website_url . '/wp-json/wc/v3/products/unique', [
                    'page' => $page,
                    'per_page' => $perPage
                ]);

                if (!$response->successful()) {
                    Log::warning('درخواست ووکامرس ناموفق', [
                        'license_id' => $this->licenseId,
                        'page' => $page,
                        'status_code' => $response->status(),
                        'response_body' => $response->body()
                    ]);
                    break;
                }

                $responseData = $response->json();

                // بررسی فرمت پاسخ
                if (isset($responseData['success']) && $responseData['success'] && isset($responseData['data'])) {
                    $products = $responseData['data'];
                } else {
                    Log::warning('فرمت پاسخ ووکامرس نامعتبر', [
                        'license_id' => $this->licenseId,
                        'page' => $page,
                        'response_keys' => array_keys($responseData ?? [])
                    ]);
                    break;
                }

                // استخراج کدهای یکتا
                foreach ($products as $product) {
                    if (isset($product['unique_id']) && !empty($product['unique_id'])) {
                        $uniqueIds[] = $product['unique_id'];
                    }
                }

                Log::info('صفحه محصولات پردازش شد', [
                    'license_id' => $this->licenseId,
                    'page' => $page,
                    'products_in_page' => count($products),
                    'total_unique_ids_so_far' => count($uniqueIds)
                ]);

                $page++;

                // اگر تعداد محصولات کمتر از per_page باشد، به معنای پایان صفحات است
                if (count($products) < $perPage) {
                    break;
                }

                // محدودیت تعداد صفحات برای جلوگیری از حلقه بی‌نهایت
                if ($page > 1000) {
                    Log::warning('حد اکثر صفحات رسیده شد', [
                        'license_id' => $this->licenseId,
                        'max_page' => $page
                    ]);
                    break;
                }

            } while (true);

            return array_unique($uniqueIds);

        } catch (\Exception $e) {
            Log::error('خطا در دریافت کدهای یکتا از ووکامرس: ' . $e->getMessage(), [
                'license_id' => $this->licenseId,
                'exception_line' => $e->getLine(),
                'exception_file' => $e->getFile()
            ]);
            return [];
        }
    }

    /**
     * درج محصولات جدید در WooCommerce
     */
    private function insertProductsToWooCommerce($products, $license, $wooApiKey)
    {
        try {
            $batchSize = 20; // کمتر از جدول database برای API
            $productsArray = $products->toArray();
            $batches = array_chunk($productsArray, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                $productsToCreate = [];

                foreach ($batch as $product) {
                    $productData = [
                        'name' => $product['name'] ?? 'محصول بدون نام',
                        'type' => 'simple',
                        'regular_price' => '0',
                        'sku' => $product['barcode'] ?? '',
                        'manage_stock' => true,
                        'stock_quantity' => 0,
                        'stock_status' => 'outofstock',
                        'status' => 'publish',
                        'meta_data' => [
                            [
                                'key' => '_bim_unique_id',
                                'value' => $product['item_id']
                            ]
                        ]
                    ];

                    $productsToCreate[] = $productData;
                }

                // ارسال batch به WooCommerce
                $response = Http::withOptions([
                    'verify' => false,
                    'timeout' => 120
                ])->withBasicAuth(
                    $wooApiKey->api_key,
                    $wooApiKey->api_secret
                )->post($license->website_url . '/wp-json/wc/v3/products/batch', [
                    'create' => $productsToCreate
                ]);

                if (!$response->successful()) {
                    Log::warning('درج batch محصولات در ووکامرس ناموفق', [
                        'license_id' => $this->licenseId,
                        'batch_index' => $batchIndex + 1,
                        'status_code' => $response->status(),
                        'response_body' => $response->body(),
                        'products_in_batch' => count($batch)
                    ]);
                    continue;
                }

                $responseData = $response->json();

                if (isset($responseData['create']) && is_array($responseData['create'])) {
                    $createdCount = count($responseData['create']);
                    Log::info('دسته از محصولات در ووکامرس درج شد', [
                        'license_id' => $this->licenseId,
                        'batch_index' => $batchIndex + 1,
                        'created_products_count' => $createdCount,
                        'total_batches' => count($batches)
                    ]);
                } else {
                    Log::warning('پاسخ نامعتبر از ووکامرس', [
                        'license_id' => $this->licenseId,
                        'batch_index' => $batchIndex + 1,
                        'response_keys' => array_keys($responseData ?? [])
                    ]);
                }

                // تأخیر بین batch ها برای جلوگیری از فشار به API
                if ($batchIndex < count($batches) - 1) {
                    sleep(2);
                }
            }

        } catch (\Exception $e) {
            Log::error('خطا در درج محصولات در ووکامرس: ' . $e->getMessage(), [
                'license_id' => $this->licenseId,
                'exception_line' => $e->getLine(),
                'exception_file' => $e->getFile()
            ]);
            throw $e;
        }
    }
}
