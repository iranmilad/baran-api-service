<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\License;
use App\Models\UserSetting;
use App\Models\WooCommerceApiKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Automattic\WooCommerce\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class UpdateWooCommerceProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 900; // افزایش تایم‌اوت به 15 دقیقه
    public $maxExceptions = 3;
    public $backoff = [180, 300, 600]; // افزایش به 3، 5 و 10 دقیقه

    protected $products;
    protected $license_id;
    protected $operation;
    protected $barcodes;
    protected $batchSize = 10; // تنظیم اندازه بسته به 10

    public function __construct($license_id, $operation, $barcodes = [])
    {
        $this->license_id = $license_id;
        $this->operation = $operation;
        $this->barcodes = $barcodes;
    }

    public function handle()
    {
        try {

            // لاگ شروع صف
            Log::info('شروع پردازش صف به‌روزرسانی محصولات ووکامرس', [
                'license_id' => $this->license_id,
                'operation' => $this->operation,
                'barcodes_count' => count($this->barcodes)
            ]);

            // لاگ اطلاع از ارسال barcode
            if (!empty($this->barcodes)) {
                Log::info('بارکدهای مشخص شده برای به‌روزرسانی', [
                    'license_id' => $this->license_id,
                    'barcodes' => $this->barcodes
                ]);
            }



            $license = License::with(['userSetting', 'woocommerceApiKey'])->find($this->license_id);
            if (!$license || !$license->isActive()) {
                Log::error('لایسنس معتبر نیست', [
                    'license_id' => $this->license_id
                ]);
                return;
            }

            $userSettings = $license->userSetting;
            $wooApiKey = $license->woocommerceApiKey;

            if (!$userSettings) {
                Log::error('تنظیمات کاربر ووکامرس یافت نشد', [
                    'license_id' => $license->id
                ]);
                return;
            }

            if (!$wooApiKey) {
                Log::error('کلید API ووکامرس یافت نشد', [
                    'license_id' => $license->id
                ]);
                return;
            }
            $woocommerce = new Client(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                [
                    'version' => 'wc/v3',
                    'verify_ssl' => false,
                    'timeout' => 300
                ]
            );

            // دریافت کدهای یکتا از ووکامرس
            $wooProducts = $this->getWooCommerceProducts($woocommerce);

            if (empty($wooProducts)) {
                Log::info('هیچ محصولی در ووکامرس یافت نشد', [
                    'license_id' => $this->license_id
                ]);
                return;
            }

            // اگر barcodes مشخص شده باشد، فقط آنها را فیلتر می‌کنیم
            if (!empty($this->barcodes)) {
                $wooProducts = array_filter($wooProducts, function($product) {
                    return in_array($product['barcode'], $this->barcodes);
                });
            }

            // استخراج بارکدها
            $barcodes = collect($wooProducts)->pluck('barcode')->filter(function($barcode) {
                return !is_null($barcode) && !empty($barcode);
            })->values()->toArray();

            // تقسیم بارکدها به دسته‌های 100 تایی
            $barcodeChunks = array_chunk($barcodes, 100);

            //log::info(json_encode($barcodeChunks));

            $allProducts = [];
            foreach ($barcodeChunks as $chunk) {
                $rainProducts = $this->getRainProducts($chunk);

                if (!empty($rainProducts)) {
                    $allProducts = array_merge($allProducts, $rainProducts);
                }
            }

            if (!empty($allProducts)) {
                $productsToUpdate = [];
                foreach ($allProducts as $rainProduct) {
                    // پیدا کردن محصول ووکامرس متناظر
                    $wooProduct = collect($wooProducts)->first(function ($product) use ($rainProduct) {
                        return $product['barcode'] === $rainProduct["Barcode"];
                    });

                    if ($wooProduct) {
                        $productsToUpdate[] = $this->prepareProductData([
                            'barcode' => $rainProduct["Barcode"],
                            'unique_id' => $rainProduct["ItemID"],
                            'name' => $rainProduct["Name"],
                            'regular_price' => $rainProduct["Price"],
                            'stock_quantity' => $rainProduct["CurrentUnitCount"],
                            'product_id' => $wooProduct['product_id'],
                            'variation_id' => $wooProduct['variation_id']
                        ], $userSettings);
                    }
                }

                if (!empty($productsToUpdate)) {
                    $this->updateWooCommerceProducts($woocommerce, $productsToUpdate);
                }
            }

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی محصولات در ووکامرس: ' . $e->getMessage(), [
                'license_id' => $this->license_id
            ]);
            throw $e;
        }
    }

    /**
     * دریافت محصولات از ووکامرس
     */
    protected function getWooCommerceProducts($woocommerce)
    {
        try {
            $response = $woocommerce->get('products/unique');

            if (!isset($response->success) || !$response->success || !isset($response->data)) {
                Log::error('پاسخ نامعتبر از API ووکامرس', [
                    'response' => $response
                ]);
                return [];
            }

            // تبدیل داده‌های stdClass به آرایه
            $products = [];
            foreach ($response->data as $product) {
                $products[] = [
                    'barcode' => $product->barcode ?? null,
                    'product_id' => $product->product_id ?? null,
                    'variation_id' => $product->variation_id ?? null
                ];
            }

            Log::info('تعداد محصولات دریافت شده از ووکامرس', [
                'count' => count($products),
                'license_id' => $this->license_id
            ]);

            return $products;
        } catch (\Exception $e) {
            Log::error('خطا در دریافت محصولات از ووکامرس: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * دریافت اطلاعات محصولات از API باران یا دیتابیس
     */
    protected function getRainProducts($barcodes)
    {
        try {
            $license = License::with('user')->find($this->license_id);

            if (!$license || !$license->user) {
                Log::error('لایسنس یا کاربر یافت نشد', [
                    'license_id' => $this->license_id
                ]);
                return $this->getProductsFromDatabase($barcodes);
            }

            $user = $license->user;
            if (!$user->api_webservice || !$user->api_username || !$user->api_password) {
                Log::warning('اطلاعات API باران کاربر یافت نشد، استفاده از داده‌های دیتابیس', [
                    'user_id' => $user->id,
                    'license_id' => $license->id
                ]);
                return $this->getProductsFromDatabase($barcodes);
            }

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user->api_username . ':' . $user->api_password)
            ])->post($user->api_webservice."/RainSaleService.svc/GetItemInfos", [
                'barcodes' => $barcodes
            ]);

            if (!$response->successful()) {
                Log::warning('خطا در دریافت اطلاعات از API باران، استفاده از داده‌های دیتابیس', [
                    'response' => $response->body(),
                    'user_id' => $user->id,
                    'license_id' => $license->id
                ]);
                return $this->getProductsFromDatabase($barcodes);
            }

            $data = $response->json();
            return $data['GetItemInfosResult'] ?? [];
        } catch (\Exception $e) {
            Log::warning('خطا در دریافت اطلاعات از API باران، استفاده از داده‌های دیتابیس: ' . $e->getMessage(), [
                'license_id' => $this->license_id
            ]);
            return $this->getProductsFromDatabase($barcodes);
        }
    }

    /**
     * دریافت اطلاعات محصولات از دیتابیس
     */
    protected function getProductsFromDatabase($barcodes)
    {
        try {
            $products = Product::where('license_id', $this->license_id)
                ->whereIn('barcode', $barcodes)
                ->get();

            $result = [];
            foreach ($products as $product) {
                $result[] = [
                    'Barcode' => $product->barcode,
                    'ItemID' => $product->item_id,
                    'Name' => $product->item_name,
                    'Price' => $product->price_amount,
                    'PriceAfterDiscount' => $product->price_after_discount,
                    'CurrentUnitCount' => $product->total_count,
                    'StockID' => $product->stock_id,
                    'DepartmentName' => $product->department_name
                ];
            }

            Log::info('اطلاعات محصولات از دیتابیس دریافت شد', [
                'license_id' => $this->license_id,
                'count' => count($result)
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('خطا در دریافت اطلاعات از دیتابیس: ' . $e->getMessage(), [
                'license_id' => $this->license_id
            ]);
            return [];
        }
    }

    /**
     * به‌روزرسانی محصولات در ووکامرس
     */
    protected function updateWooCommerceProducts($woocommerce, $products)
    {
        try {
            // تهیه خلاصه اطلاعات محصولات برای لاگ
            $productsInfo = array_map(function($product) {
                return [
                    'sku' => $product['sku'],
                    'unique_id' => $product['unique_id'],
                    'product_id' => $product['product_id'] ?? null,
                    'variation_id' => $product['variation_id'] ?? null,
                    'name' => $product['name'] ?? null,
                    'price' => $product['regular_price'] ?? null,
                    'stock' => $product['stock_quantity'] ?? null,
                ];
            }, $products);

            //log::info(json_encode($products));
            $response = $woocommerce->put('products/unique/batch/update', [
                'products' => $products
            ]);

            // تهیه خلاصه پاسخ دریافتی از API
            $responseData = [
                'status' => $response->status ?? null,
                'success' => $response->success ?? false,
                'message' => $response->message ?? null,
                'updated_count' => isset($response->data) ? count($response->data) : 0,
                'response_data' => $response->data ?? null
            ];

            Log::info('محصولات با موفقیت به‌روزرسانی شدند', [
                'count' => count($products),
                'updated_products' => $productsInfo,
                'api_response' => $responseData,
                'timestamp' => now()->toDateTimeString(),
                'license_id' => $this->license_id
            ]);

            return $response;
        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی محصولات در ووکامرس: ' . $e->getMessage(), [
                'products_count' => count($products),
                'products_skus' => array_column($products, 'sku'),
                'license_id' => $this->license_id,
                'error_code' => $e->getCode(),
                'error_trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    protected function prepareProductData($product, $userSettings): array
    {
        $data = [
            'unique_id' => $product['unique_id'],
            'sku' => $product['barcode']
        ];

        if ($userSettings->enable_name_update) {
            $data['name'] = $product['name'];
        }

        if ($userSettings->enable_price_update) {
            $data['regular_price'] = (string)$product['regular_price'];
            if (isset($product['CurrentDiscount']) && $product['CurrentDiscount'] > 0) {
                $data['sale_price'] = (string)($product['regular_price'] - $product['CurrentDiscount']);
            }
        }

        if ($userSettings->enable_stock_update) {
            $data['stock_quantity'] = $product['stock_quantity'];
            $data['manage_stock'] = true;
            $data['stock_status'] = $product['stock_quantity'] > 0 ? 'instock' : 'outofstock';
        }

        // اضافه کردن product_id و variation_id اگر وجود داشته باشند
        if (isset($product['product_id'])) {
            $data['product_id'] = $product['product_id'];
        }
        if (isset($product['variation_id'])) {
            $data['variation_id'] = $product['variation_id'];
        }


        return $data;
    }

    public function failed(\Throwable $exception)
    {
        Log::error('خطا در پردازش صف به‌روزرسانی محصولات ووکامرس: ' . $exception->getMessage(), [
            'license_id' => $this->license_id,
            'operation' => $this->operation
        ]);
    }
}
