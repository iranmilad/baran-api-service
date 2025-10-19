<?php

namespace App\Jobs\Tantooo;

use App\Models\License;
use App\Models\Product;
use App\Models\UserSetting;
use App\Traits\Tantooo\TantoooApiTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class UpdateAllProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TantoooApiTrait;

    protected $licenseId;
    protected $syncId;
    public $timeout = 600; // 10 دقیقه
    public $tries = 3;

    public function __construct($licenseId, $syncId)
    {
        $this->licenseId = $licenseId;
        $this->syncId = $syncId;
    }

    public function handle()
    {
        try {
            $license = License::find($this->licenseId);
            if (!$license) {
                Log::error('لایسنس یافت نشد', [
                    'license_id' => $this->licenseId,
                    'sync_id' => $this->syncId
                ]);
                return;
            }

            // دریافت تنظیمات کاربر
            $userSetting = UserSetting::where('license_id', $license->id)->first();
            if (!$userSetting) {
                Log::error('تنظیمات کاربر یافت نشد', [
                    'license_id' => $license->id,
                    'sync_id' => $this->syncId
                ]);
                return;
            }

            // بررسی تنظیمات API Tantooo
            $tantoooSettings = $this->getTantoooApiSettings($license);
            if (!$tantoooSettings) {
                Log::error('تنظیمات API Tantooo یافت نشد', [
                    'license_id' => $license->id,
                    'sync_id' => $this->syncId
                ]);
                return;
            }

            // دریافت تمام محصولات از دیتابیس
            $products = Product::where('license_id', $license->id)->get();

            if ($products->isEmpty()) {
                Log::info('هیچ محصولی برای به‌روزرسانی یافت نشد', [
                    'license_id' => $license->id,
                    'sync_id' => $this->syncId
                ]);

                // ذخیره نتیجه در Cache
                Cache::put(
                    "tantooo_update_all_result_{$this->syncId}",
                    [
                        'success' => true,
                        'message' => 'هیچ محصولی برای به‌روزرسانی یافت نشد',
                        'data' => [
                            'total_products' => 0,
                            'success_count' => 0,
                            'error_count' => 0,
                            'skipped_count' => 0
                        ],
                        'completed_at' => now()
                    ],
                    3600 // 1 ساعت
                );
                return;
            }

            Log::info('شروع به‌روزرسانی تمام محصولات', [
                'license_id' => $license->id,
                'sync_id' => $this->syncId,
                'total_products' => $products->count()
            ]);

            $successCount = 0;
            $errorCount = 0;
            $skippedCount = 0;
            $errors = [];

            // پردازش هر محصول
            foreach ($products as $product) {
                try {
                    $itemId = $product->item_id;
                    $bareCode = $product->barcode;
                    if (!$itemId) {
                        $skippedCount++;
                        Log::warning('محصول بدون item_id رد شد', [
                            'product_id' => $product->id,
                            'license_id' => $license->id
                        ]);
                        continue;
                    }

                    // استخراج اطلاعات محصول
                    $stockQuantity = (int)($product->total_count ?? 0);
                    $price = (float)($product->price_amount ?? 0);
                    $discountPercent = 0;

                    // تبدیل واحد قیمت در صورت نیاز
                    if ($userSetting && isset($userSetting->rain_sale_price_unit) && isset($userSetting->tantooo_price_unit)) {
                        $rainSaleUnit = $userSetting->rain_sale_price_unit;
                        $tantoooUnit = $userSetting->tantooo_price_unit;

                        if ($rainSaleUnit === 'rial' && $tantoooUnit === 'toman') {
                            $price = $price / 10; // ریال به تومان
                        } elseif ($rainSaleUnit === 'toman' && $tantoooUnit === 'rial') {
                            $price = $price * 10; // تومان به ریال
                        }
                    }

                    // محاسبه تخفیف
                    if ($product->price_after_discount && $product->price_after_discount > 0 && $price > 0) {
                        $discountPercent = (($price - $product->price_after_discount) / $price) * 100;
                    }

                    // به‌روزرسانی مکمل در یک درخواست واحد
                    // (موجودی + قیمت + تخفیف)
                    // تنظیمات کاربر برای فیلتر کردن فیلدهای فعال را ارسال کنید
                    $updateResult = $this->updateProductCompleteWithToken(
                        $license,
                        $bareCode,
                        $stockQuantity,
                        $price,
                        (float)$discountPercent,
                        $userSetting
                    );

                    if ($updateResult['success']) {
                        $successCount++;

                        Log::info('محصول با موفقیت به‌روزرسانی شد (موجودی + قیمت + تخفیف)', [
                            'item_id' => $itemId,
                            'product_id' => $product->id,
                            'stock_quantity' => $stockQuantity,
                            'price_original' => (float)($product->price_amount ?? 0),
                            'price_after_conversion' => $price,
                            'price_unit_conversion' => $userSetting && isset($userSetting->rain_sale_price_unit) && isset($userSetting->tantooo_price_unit)
                                ? ($userSetting->rain_sale_price_unit . ' → ' . $userSetting->tantooo_price_unit)
                                : 'no conversion',
                            'discount' => $discountPercent
                        ]);
                    } else {
                        // بررسی اگر محصول وجود ندارد (msg: 4)
                        if (isset($updateResult['error_code']) && $updateResult['error_code'] === 4) {
                            Log::warning('محصول در سیستم Tantooo وجود ندارد', [
                                'item_id' => $itemId,
                                'product_id' => $product->id,
                                'message' => $updateResult['message'] ?? 'نامشخص'
                            ]);
                        } else {
                            Log::error('خطا در به‌روزرسانی محصول', [
                                'item_id' => $itemId,
                                'product_id' => $product->id,
                                'error' => $updateResult['message'] ?? 'نامشخص'
                            ]);
                        }

                        $errorCount++;
                        $errors[] = [
                            'product_id' => $product->id,
                            'item_id' => $itemId,
                            'error' => $updateResult['message'] ?? 'نامشخص'
                        ];
                    }

                } catch (\Exception $e) {
                    $errorCount++;
                    $errors[] = [
                        'product_id' => $product->id,
                        'item_id' => $product->item_id,
                        'error' => $e->getMessage()
                    ];

                    Log::error('خطا در به‌روزرسانی محصول', [
                        'product_id' => $product->id,
                        'item_id' => $product->item_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // ذخیره نتیجه در Cache
            $result = [
                'success' => $errorCount === 0,
                'message' => $errorCount === 0 ? 'تمام محصولات با موفقیت به‌روزرسانی شدند' : 'برخی محصولات با خطا مواجه شدند',
                'data' => [
                    'total_products' => $products->count(),
                    'success_count' => $successCount,
                    'error_count' => $errorCount,
                    'skipped_count' => $skippedCount,
                    'errors' => $errors
                ],
                'completed_at' => now()
            ];

            Cache::put(
                "tantooo_update_all_result_{$this->syncId}",
                $result,
                3600 // 1 ساعت
            );

            Log::info('تکمیل به‌روزرسانی تمام محصولات', [
                'license_id' => $license->id,
                'sync_id' => $this->syncId,
                'total_products' => $products->count(),
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'skipped_count' => $skippedCount
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در Job به‌روزرسانی تمام محصولات', [
                'license_id' => $this->licenseId,
                'sync_id' => $this->syncId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            Cache::put(
                "tantooo_update_all_result_{$this->syncId}",
                [
                    'success' => false,
                    'message' => 'خطا در به‌روزرسانی: ' . $e->getMessage(),
                    'failed_at' => now()
                ],
                3600
            );
        }
    }
}
