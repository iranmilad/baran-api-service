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

class UpdateSingleTantoooProduct implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TantoooApiTrait;

    protected $licenseId;
    protected $productCode;
    protected $warehouseCode;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 120; // 2 دقیقه

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId, $productCode, $warehouseCode = null)
    {
        $this->licenseId = $licenseId;
        $this->productCode = $productCode;
        $this->warehouseCode = $warehouseCode;
        $this->onQueue('tantooo-single');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('شروع به‌روزرسانی محصول منفرد Tantooo', [
                'license_id' => $this->licenseId,
                'product_code' => $this->productCode,
                'warehouse_code' => $this->warehouseCode
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

            // تعیین انبار
            $targetWarehouse = $this->warehouseCode ?? $userSetting->default_warehouse_code;
            if (empty($targetWarehouse)) {
                Log::error('کد انبار تنظیم نشده است', [
                    'license_id' => $license->id,
                    'provided_warehouse' => $this->warehouseCode,
                    'default_warehouse' => $userSetting->default_warehouse_code
                ]);
                return;
            }

            // پردازش محصول
            $result = $this->processProductUpdate($license, $this->productCode, $targetWarehouse);

            if ($result['success']) {
                Log::info('محصول منفرد با موفقیت به‌روزرسانی شد', [
                    'license_id' => $license->id,
                    'product_code' => $this->productCode,
                    'result' => $result['data']
                ]);
            } else {
                Log::error('خطا در به‌روزرسانی محصول منفرد', [
                    'license_id' => $license->id,
                    'product_code' => $this->productCode,
                    'error' => $result['message']
                ]);
            }

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی محصول منفرد Tantooo', [
                'license_id' => $this->licenseId,
                'product_code' => $this->productCode,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * پردازش به‌روزرسانی محصول
     */
    protected function processProductUpdate($license, $productCode, $warehouseCode)
    {
        try {
            Log::info('شروع پردازش محصول منفرد', [
                'license_id' => $license->id,
                'product_code' => $productCode,
                'warehouse_code' => $warehouseCode
            ]);

            // 1. دریافت اطلاعات موجودی و قیمت از RainSale API
            $rainSaleResult = $this->getProductFromRainSale($license, $productCode, $warehouseCode);
            
            if (!$rainSaleResult['success']) {
                return [
                    'success' => false,
                    'message' => 'خطا در دریافت اطلاعات از RainSale: ' . $rainSaleResult['message']
                ];
            }

            $productData = $rainSaleResult['data'];
            $stock = $productData['stock'] ?? 0;
            $price = $productData['price'] ?? 0;

            Log::info('اطلاعات محصول از RainSale دریافت شد', [
                'product_code' => $productCode,
                'stock' => $stock,
                'price' => $price,
                'warehouse' => $productData['warehouse_name'] ?? 'نامشخص'
            ]);

            // 2. به‌روزرسانی موجودی در Tantooo
            $stockUpdateResult = $this->updateProductStockWithToken($license, $productCode, $stock);
            
            if (!$stockUpdateResult['success']) {
                Log::warning('خطا در به‌روزرسانی موجودی Tantooo', [
                    'product_code' => $productCode,
                    'error' => $stockUpdateResult['message']
                ]);
            } else {
                Log::info('موجودی محصول در Tantooo به‌روزرسانی شد', [
                    'product_code' => $productCode,
                    'new_stock' => $stock
                ]);
            }

            // 3. به‌روزرسانی قیمت در Tantooo (اگر پیاده‌سازی شود)
            if (isset($productData['price']) && $price > 0) {
                $priceUpdateResult = $this->updateProductPriceInTantooo($license, $productCode, $price);
                
                if ($priceUpdateResult['success']) {
                    Log::info('قیمت محصول در Tantooo به‌روزرسانی شد', [
                        'product_code' => $productCode,
                        'new_price' => $price
                    ]);
                }
            }

            // 4. ذخیره/به‌روزرسانی در جدول محصولات محلی
            $this->updateLocalProductRecord($license, $productCode, $productData);

            return [
                'success' => true,
                'data' => [
                    'product_code' => $productCode,
                    'stock' => $stock,
                    'price' => $price,
                    'warehouse_code' => $productData['warehouse_code'],
                    'warehouse_name' => $productData['warehouse_name'],
                    'updated_at' => now(),
                    'stock_update_success' => $stockUpdateResult['success'],
                    'price_update_available' => isset($productData['price'])
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
     * دریافت اطلاعات محصول از RainSale API
     */
    protected function getProductFromRainSale($license, $productCode, $warehouseCode)
    {
        try {
            // دریافت کاربر و تنظیمات API
            $user = User::find($license->user_id);
            if (!$user || !$user->api_webservice || !$user->api_username || !$user->api_password) {
                return [
                    'success' => false,
                    'message' => 'تنظیمات API RainSale ناقص است'
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

            Log::info('درخواست اطلاعات محصول منفرد از RainSale API', [
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
            Log::error('خطا در دریافت اطلاعات محصول منفرد از RainSale API', [
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
     * به‌روزرسانی قیمت محصول در Tantooo (موقتاً غیرفعال)
     */
    protected function updateProductPriceInTantooo($license, $productCode, $price)
    {
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
    protected function updateLocalProductRecord($license, $productCode, $productData)
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
            $product->stock = $productData['stock'] ?? 0;
            if (isset($productData['price'])) {
                $product->price = $productData['price'];
            }
            $product->warehouse_code = $productData['warehouse_code'] ?? null;
            $product->warehouse_name = $productData['warehouse_name'] ?? null;
            $product->item_name = $productData['item_name'] ?? null;
            $product->barcode = $productData['barcode'] ?? null;
            $product->last_sync_at = now();
            $product->save();

            Log::info('رکورد محصول محلی به‌روزرسانی شد', [
                'product_code' => $productCode,
                'product_id' => $product->id,
                'stock' => $product->stock,
                'price' => $product->price
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
        Log::error('شکست در به‌روزرسانی محصول منفرد Tantooo', [
            'license_id' => $this->licenseId,
            'product_code' => $this->productCode,
            'warehouse_code' => $this->warehouseCode,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
