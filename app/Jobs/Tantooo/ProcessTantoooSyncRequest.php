<?php

namespace App\Jobs\Tantooo;

use App\Models\License;
use App\Models\UserSetting;
use App\Traits\Tantooo\TantoooApiTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTantoooSyncRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TantoooApiTrait;

    protected $licenseId;
    protected $syncId;
    protected $insertProducts;
    protected $updateProducts;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 600; // 10 دقیقه

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId, $syncId, $insertProducts = [], $updateProducts = [])
    {
        $this->licenseId = $licenseId;
        $this->syncId = $syncId;
        $this->insertProducts = $insertProducts ?? [];
        $this->updateProducts = $updateProducts ?? [];
        $this->onQueue('tantooo-sync');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);

        try {
            Log::info('شروع پردازش درخواست همگام‌سازی Tantooo', [
                'license_id' => $this->licenseId,
                'sync_id' => $this->syncId,
                'insert_count' => count($this->insertProducts),
                'update_count' => count($this->updateProducts)
            ]);

            // بررسی لایسنس
            $license = License::with(['user', 'userSetting'])->find($this->licenseId);
            if (!$license || !$license->isActive()) {
                $this->logError('لایسنس معتبر نیست یا منقضی شده است');
                return;
            }

            // بررسی تنظیمات کاربر
            $userSetting = UserSetting::where('license_id', $license->id)->first();
            if (!$userSetting) {
                $this->logError('تنظیمات کاربر یافت نشد');
                return;
            }

            // بررسی تنظیمات API Tantooo
            $tantoooSettings = $this->getTantoooApiSettings($license);
            if (!$tantoooSettings) {
                $this->logError('تنظیمات API Tantooo یافت نشد');
                return;
            }

            // ترکیب محصولات insert و update
            $allProducts = array_merge($this->insertProducts, $this->updateProducts);
            
            if (empty($allProducts)) {
                $this->logError('هیچ محصولی برای پردازش یافت نشد');
                return;
            }

            // استخراج کدهای محصولات
            $productCodes = $this->extractProductCodes($allProducts);
            
            if (empty($productCodes)) {
                $this->logError('کدهای محصولات قابل استخراج نیست');
                return;
            }

            Log::info('کدهای محصولات استخراج شد', [
                'license_id' => $this->licenseId,
                'sync_id' => $this->syncId,
                'total_products' => count($allProducts),
                'extracted_codes' => count($productCodes),
                'sample_codes' => array_slice($productCodes, 0, 5)
            ]);

            // دریافت اطلاعات به‌روز از باران
            $baranResult = $this->getUpdatedProductInfoFromBaran($license, $productCodes);
            
            if (!$baranResult['success']) {
                $this->logError('خطا در دریافت اطلاعات از باران: ' . $baranResult['message']);
                return;
            }

            Log::info('اطلاعات از باران دریافت شد', [
                'license_id' => $this->licenseId,
                'sync_id' => $this->syncId,
                'total_requested' => $baranResult['data']['total_requested'],
                'total_received' => $baranResult['data']['total_received']
            ]);

            // پردازش و به‌روزرسانی محصولات
            $updateResult = $this->processAndUpdateProductsFromBaran(
                $license, 
                $allProducts, 
                $baranResult['data']['products']
            );

            if (!$updateResult['success']) {
                $this->logError('خطا در پردازش محصولات: ' . $updateResult['message']);
                return;
            }

            $executionTime = microtime(true) - $startTime;

            // لاگ نتیجه نهایی
            Log::info('تکمیل پردازش درخواست همگام‌سازی Tantooo', [
                'license_id' => $this->licenseId,
                'sync_id' => $this->syncId,
                'total_processed' => $updateResult['data']['total_processed'],
                'success_count' => $updateResult['data']['success_count'],
                'error_count' => $updateResult['data']['error_count'],
                'execution_time' => $executionTime,
                'baran_data' => [
                    'total_requested' => $baranResult['data']['total_requested'],
                    'total_received' => $baranResult['data']['total_received']
                ]
            ]);

            // ذخیره نتیجه در Cache برای دریافت بعدی
            $this->saveSyncResult([
                'success' => true,
                'sync_id' => $this->syncId,
                'total_processed' => $updateResult['data']['total_processed'],
                'success_count' => $updateResult['data']['success_count'],
                'error_count' => $updateResult['data']['error_count'],
                'baran_data' => [
                    'total_requested' => $baranResult['data']['total_requested'],
                    'total_received' => $baranResult['data']['total_received']
                ],
                'tantooo_update_result' => $updateResult['data']['tantooo_update_result'],
                'errors' => $updateResult['data']['errors'],
                'execution_time' => $executionTime,
                'completed_at' => now()
            ]);

        } catch (\Exception $e) {
            $this->logError('خطا در پردازش همگام‌سازی: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            // ذخیره خطا در Cache
            $this->saveSyncResult([
                'success' => false,
                'sync_id' => $this->syncId,
                'error' => $e->getMessage(),
                'failed_at' => now()
            ]);

            throw $e;
        }
    }

    /**
     * ثبت خطا با جزئیات کامل
     */
    protected function logError($message, $extraData = [])
    {
        Log::error($message, array_merge([
            'license_id' => $this->licenseId,
            'sync_id' => $this->syncId,
            'job_class' => self::class
        ], $extraData));
    }

    /**
     * ذخیره نتیجه همگام‌سازی در Cache
     */
    protected function saveSyncResult($result)
    {
        $cacheKey = "tantooo_sync_result_{$this->syncId}";
        
        // ذخیره برای 24 ساعت
        \Illuminate\Support\Facades\Cache::put($cacheKey, $result, now()->addHours(24));
        
        Log::info('نتیجه همگام‌سازی در Cache ذخیره شد', [
            'sync_id' => $this->syncId,
            'cache_key' => $cacheKey
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('شکست در پردازش همگام‌سازی Tantooo', [
            'license_id' => $this->licenseId,
            'sync_id' => $this->syncId,
            'insert_count' => count($this->insertProducts),
            'update_count' => count($this->updateProducts),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // ذخیره نتیجه شکست در Cache
        $this->saveSyncResult([
            'success' => false,
            'sync_id' => $this->syncId,
            'error' => $exception->getMessage(),
            'failed_at' => now(),
            'attempts' => $this->attempts()
        ]);
    }

    /**
     * پردازش و به‌روزرسانی محصولات با داده‌های باران
     */
    protected function processAndUpdateProductsFromBaran($license, $allProducts, $baranProducts)
    {
        // فرض بر این است که $baranProducts آرایه‌ای از اطلاعات محصولات به‌روز شده است
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        $tantoooUpdateResult = [];

        foreach ($allProducts as $product) {
            $code = $product['code'] ?? null;
            if (!$code || !isset($baranProducts[$code])) {
                $errorCount++;
                $errors[] = [
                    'code' => $code,
                    'message' => 'اطلاعات محصول در داده‌های باران یافت نشد'
                ];
                continue;
            }

            // فرض بر این است که متدی برای به‌روزرسانی محصول در Tantooo وجود دارد
            try {
                $updateRes = $this->updateProductInTantooo($license, $product, $baranProducts[$code]);
                $tantoooUpdateResult[$code] = $updateRes;
                if ($updateRes['success']) {
                    $successCount++;
                } else {
                    $errorCount++;
                    $errors[] = [
                        'code' => $code,
                        'message' => $updateRes['message'] ?? 'خطا در به‌روزرسانی محصول'
                    ];
                }
            } catch (\Exception $ex) {
                $errorCount++;
                $errors[] = [
                    'code' => $code,
                    'message' => $ex->getMessage()
                ];
            }
        }

        return [
            'success' => $errorCount === 0,
            'data' => [
                'total_processed' => count($allProducts),
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'tantooo_update_result' => $tantoooUpdateResult,
                'errors' => $errors
            ],
            'message' => $errorCount === 0 ? 'همه محصولات با موفقیت به‌روزرسانی شدند' : 'برخی محصولات با خطا مواجه شدند'
        ];
    }
}
