<?php

namespace App\Jobs\Tantooo;

use App\Models\License;
use App\Models\Product;
use App\Models\User;
use App\Traits\Tantooo\TantoooApiTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class UpdateTantoooProductsBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TantoooApiTrait;

    protected $licenseId;
    protected $productCodes;
    protected $batchNumber;
    protected $totalBatches;

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
    public function __construct($licenseId, array $productCodes, $batchNumber = 1, $totalBatches = 1)
    {
        $this->licenseId = $licenseId;
        $this->productCodes = $productCodes;
        $this->batchNumber = $batchNumber;
        $this->totalBatches = $totalBatches;
        $this->onQueue('tantooo-update');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);

        try {
            Log::info('شروع به‌روزرسانی دسته محصولات Tantooo', [
                'license_id' => $this->licenseId,
                'batch_number' => $this->batchNumber,
                'total_batches' => $this->totalBatches,
                'product_codes_count' => count($this->productCodes)
            ]);

            $license = License::with(['userSetting'])->find($this->licenseId);
            if (!$license || !$license->isActive()) {
                Log::error('لایسنس معتبر نیست', [
                    'license_id' => $this->licenseId
                ]);
                return;
            }

            // بررسی تنظیمات API
            if (empty($license->website_url) || empty($license->api_token)) {
                Log::error('تنظیمات Tantooo API ناقص است', [
                    'license_id' => $license->id
                ]);
                return;
            }

            $userSetting = $license->userSetting;
            if (!$userSetting) {
                Log::error('تنظیمات کاربر یافت نشد', [
                    'license_id' => $license->id
                ]);
                return;
            }

            // بررسی انبار مقصد
            $warehouseCode = $userSetting->warehouse_code;
            if (empty($warehouseCode)) {
                Log::error('کد انبار تنظیم نشده است', [
                    'license_id' => $license->id
                ]);
                return;
            }

            $successCount = 0;
            $errorCount = 0;
            $processedProducts = [];

            // پردازش هر کد محصول
            foreach ($this->productCodes as $productCode) {
                try {
                    $result = $this->processProductUpdate($license, $productCode, $warehouseCode);
                    
                    if ($result['success']) {
                        $successCount++;
                        $processedProducts[] = [
                            'code' => $productCode,
                            'status' => 'success',
                            'data' => $result['data']
                        ];
                    } else {
                        $errorCount++;
                        $processedProducts[] = [
                            'code' => $productCode,
                            'status' => 'error',
                            'message' => $result['message']
                        ];
                    }

                    // تاخیر کوتاه بین هر محصول
                    usleep(500000); // 0.5 ثانیه

                } catch (\Exception $e) {
                    $errorCount++;
                    $processedProducts[] = [
                        'code' => $productCode,
                        'status' => 'exception',
                        'message' => $e->getMessage()
                    ];

                    Log::error('خطا در پردازش محصول', [
                        'license_id' => $this->licenseId,
                        'product_code' => $productCode,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $executionTime = microtime(true) - $startTime;

            Log::info('تکمیل به‌روزرسانی دسته محصولات Tantooo', [
                'license_id' => $this->licenseId,
                'batch_number' => $this->batchNumber,
                'total_batches' => $this->totalBatches,
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'execution_time' => $executionTime
            ]);

            // اگر این آخرین batch بود، لاگ نهایی بگیریم
            if ($this->batchNumber == $this->totalBatches) {
                Log::info('تکمیل همه batch های به‌روزرسانی محصولات Tantooo', [
                    'license_id' => $this->licenseId,
                    'total_batches' => $this->totalBatches
                ]);
            }

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی دسته محصولات Tantooo', [
                'license_id' => $this->licenseId,
                'batch_number' => $this->batchNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * پردازش به‌روزرسانی یک محصول
     */
    protected function processProductUpdate($license, $productCode, $warehouseCode)
    {
        try {
            Log::info('شروع پردازش محصول', [
                'license_id' => $license->id,
                'product_code' => $productCode,
                'warehouse_code' => $warehouseCode
            ]);

            // 1. دریافت اطلاعات موجودی و قیمت از API باران
            $baranResult = $this->getProductStockAndPriceFromBaran($license, $productCode, $warehouseCode);
            
            if (!$baranResult['success']) {
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت اطلاعات از باران: ' . $baranResult['message']
                ];
            }

            $stockData = $baranResult['data'];
            $stock = $stockData['stock'] ?? 0;
            $price = $stockData['price'] ?? 0;

            Log::info('اطلاعات محصول از باران دریافت شد', [
                'product_code' => $productCode,
                'stock' => $stock,
                'price' => $price
            ]);

            // 2. به‌روزرسانی موجودی در Tantooo
            if (isset($stockData['stock'])) {
                $stockUpdateResult = $this->updateProductStockWithToken($license, $productCode, $stock);
                
                if (!$stockUpdateResult['success']) {
                    Log::warning('خطا در به‌روزرسانی موجودی Tantooo', [
                        'product_code' => $productCode,
                        'error' => $stockUpdateResult['message']
                    ]);
                }
            }

            // 3. به‌روزرسانی قیمت در Tantooo (اگر API قیمت موجود باشد)
            if (isset($stockData['price']) && $price > 0) {
                $priceUpdateResult = $this->updateProductPriceWithToken($license, $productCode, $price);
                
                if (!$priceUpdateResult['success']) {
                    Log::warning('خطا در به‌روزرسانی قیمت Tantooo', [
                        'product_code' => $productCode,
                        'error' => $priceUpdateResult['message']
                    ]);
                }
            }

            // 4. ذخیره/به‌روزرسانی در جدول محصولات محلی
            $this->updateLocalProductRecord($license, $productCode, $stockData);

            return [
                'success' => true,
                'data' => [
                    'product_code' => $productCode,
                    'stock' => $stock,
                    'price' => $price,
                    'updated_at' => now()
                ]
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در پردازش: ' . $e->getMessage()
            ];
        }
    }

    /**
     * دریافت اطلاعات موجودی و قیمت از API باران (RainSale)
     */
    protected function getProductStockAndPriceFromBaran($license, $productCode, $warehouseCode)
    {
        try {
            // دریافت کاربر و تنظیمات API
            $user = User::find($license->user_id);
            if (!$user || !$user->api_webservice || !$user->api_username || !$user->api_password) {
                return [
                    'success' => false,
                    'message' => 'تنظیمات API باران ناقص است'
                ];
            }

            // آماده‌سازی درخواست
            $requestBody = [
                'barcodes' => [$productCode]
            ];

            // اضافه کردن stockId در صورت وجود
            if (!empty($warehouseCode)) {
                $requestBody['stockId'] = $warehouseCode;
            }

            Log::info('درخواست اطلاعات محصول از RainSale API', [
                'license_id' => $license->id,
                'product_code' => $productCode,
                'warehouse_code' => $warehouseCode,
                'api_url' => $user->api_webservice
            ]);

            // ارسال درخواست به RainSale API
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 30,
                'connect_timeout' => 10
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user->api_username . ':' . $user->api_password)
            ])->post($user->api_webservice . "/RainSaleService.svc/GetItemInfos", $requestBody);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت از RainSale API. کد وضعیت: ' . $response->status()
                ];
            }

            $data = $response->json();
            $products = $data['GetItemInfosResult'] ?? [];

            if (empty($products)) {
                return [
                    'success' => false,
                    'message' => 'محصول در RainSale یافت نشد'
                ];
            }

            // پیدا کردن محصول در انبار مورد نظر
            $targetProduct = $this->findProductInWarehouse($products, $productCode, $warehouseCode);

            if (!$targetProduct) {
                // اگر در انبار خاص پیدا نشد، اولین محصول را برگردان
                $targetProduct = $products[0];
                Log::warning('محصول در انبار مورد نظر یافت نشد، از اولین انبار استفاده می‌شود', [
                    'product_code' => $productCode,
                    'requested_warehouse' => $warehouseCode,
                    'found_warehouse' => $targetProduct['stockID'] ?? 'unknown'
                ]);
            }

            return [
                'success' => true,
                'data' => [
                    'stock' => $targetProduct['stockQuantity'] ?? 0,
                    'price' => $targetProduct['salePrice'] ?? 0,
                    'warehouse_code' => $targetProduct['stockID'] ?? $warehouseCode,
                    'warehouse_name' => $targetProduct['stockName'] ?? null,
                    'item_id' => $targetProduct['itemID'] ?? null,
                    'item_name' => $targetProduct['itemName'] ?? null,
                    'barcode' => $targetProduct['barcode'] ?? null
                ]
            ];

        } catch (\Exception $e) {
            Log::error('خطا در دریافت اطلاعات از RainSale API', [
                'product_code' => $productCode,
                'warehouse_code' => $warehouseCode,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'خطا در دریافت از RainSale: ' . $e->getMessage()
            ];
        }
    }

    /**
     * پیدا کردن محصول در انبار مشخص
     */
    protected function findProductInWarehouse($products, $productCode, $warehouseCode)
    {
        foreach ($products as $product) {
            // بررسی تطبیق کد محصول و انبار
            $matchesCode = (
                (isset($product['barcode']) && strtolower(trim($product['barcode'])) === strtolower(trim($productCode))) ||
                (isset($product['itemID']) && strtolower(trim($product['itemID'])) === strtolower(trim($productCode)))
            );

            $matchesWarehouse = (
                isset($product['stockID']) && 
                strtolower(trim($product['stockID'])) === strtolower(trim($warehouseCode))
            );

            if ($matchesCode && $matchesWarehouse) {
                return $product;
            }
        }

        return null;
    }

    /**
     * به‌روزرسانی قیمت محصول در Tantooo (اگر API مربوطه موجود باشد)
     */
    protected function updateProductPriceWithToken($license, $productCode, $price)
    {
        // فعلاً API مخصوص به‌روزرسانی قیمت در Tantooo موجود نیست
        // می‌توانیم این قسمت را در آینده پیاده‌سازی کنیم
        
        Log::info('به‌روزرسانی قیمت محصول در Tantooo (فعلاً غیرفعال)', [
            'product_code' => $productCode,
            'price' => $price,
            'license_id' => $license->id
        ]);

        return [
            'success' => true,
            'message' => 'API به‌روزرسانی قیمت در Tantooo هنوز پیاده‌سازی نشده است'
        ];
    }

    /**
     * به‌روزرسانی رکورد محصول در جدول محلی
     */
    protected function updateLocalProductRecord($license, $productCode, $stockData)
    {
        try {
            $product = Product::where('license_id', $license->id)
                             ->where('item_id', $productCode)
                             ->first();

            if (!$product) {
                // ایجاد محصول جدید
                $product = new Product();
                $product->license_id = $license->id;
                $product->item_id = $productCode;
            }

            // به‌روزرسانی اطلاعات
            $product->stock = $stockData['stock'] ?? 0;
            if (isset($stockData['price'])) {
                $product->price = $stockData['price'];
            }
            $product->warehouse_code = $stockData['warehouse_code'] ?? null;
            $product->last_sync_at = now();
            $product->save();

            Log::info('رکورد محصول محلی به‌روزرسانی شد', [
                'product_code' => $productCode,
                'product_id' => $product->id
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی رکورد محصول محلی', [
                'product_code' => $productCode,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('شکست در به‌روزرسانی دسته محصولات Tantooo', [
            'license_id' => $this->licenseId,
            'batch_number' => $this->batchNumber,
            'product_codes_count' => count($this->productCodes),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
