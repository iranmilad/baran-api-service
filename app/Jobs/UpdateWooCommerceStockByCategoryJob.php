<?php

namespace App\Jobs;

use App\Models\License;
use App\Models\UserSetting;
use App\Models\LicenseWarehouseCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class UpdateWooCommerceStockByCategoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $licenseId;

    /**
     * Create a new job instance.
     */
    public function __construct($licenseId)
    {
        $this->licenseId = $licenseId;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $license = License::find($this->licenseId);
        if (!$license || !$license->isActive()) {
            Log::warning('License not found or inactive for stock update job.');
            return;
        }
        $user = $license->user;
        if (!$user) {
            Log::warning('User not found for license in stock update job.');
            return;
        }
        $userSettings = UserSetting::where('license_id', $license->id)->first();
        $defaultWarehouseCode = $userSettings->default_warehouse_code ?? null;

        $wooApiKey = $license->woocommerceApiKey;
        if (!$wooApiKey) {
            Log::warning('WooCommerce API key not set for license.');
            return;
        }

        // 1. دریافت دسته‌بندی‌های ووکامرس
        $categoriesUrl = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products/categories?per_page=100';
        $categoriesResponse = Http::withOptions([
            'verify' => false,
            'timeout' => 60,
            'connect_timeout' => 20
        ])->withHeaders([
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . base64_encode($wooApiKey->api_key . ':' . $wooApiKey->api_secret)
        ])->get($categoriesUrl);

        if (!$categoriesResponse->successful()) {
            Log::error('خطا در دریافت دسته‌بندی‌های ووکامرس: ' . $categoriesResponse->status());
            return;
        }
        $categories = $categoriesResponse->json();
        if (!is_array($categories)) {
            Log::error('ساختار نامعتبر دسته‌بندی‌های ووکامرس');
            return;
        }

        foreach ($categories as $category) {
            $categoryId = $category['id'] ?? null;
            $categoryName = $category['name'] ?? null;
            if (!$categoryId || !$categoryName) continue;

            // 2. بررسی انبار مرتبط با این دسته
            $warehouseIds = [];
            $warehouseCategory = LicenseWarehouseCategory::where('license_id', $license->id)
                ->where('category_name', $categoryName)
                ->first();
            if ($warehouseCategory && !empty($warehouseCategory->warehouse_codes)) {
                $warehouseIds = $warehouseCategory->warehouse_codes;
            } elseif ($defaultWarehouseCode) {
                $warehouseIds[] = $defaultWarehouseCode;
            }

            // 3. دریافت محصولات این دسته از ووکامرس (تا 1000 محصول)
            $productsUrl = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products?category=' . $categoryId . '&per_page=100';
            $page = 1;
            $allProducts = [];
            do {
                $pagedUrl = $productsUrl . '&page=' . $page;
                $productsResponse = Http::withOptions([
                    'verify' => false,
                    'timeout' => 60,
                    'connect_timeout' => 20
                ])->withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($wooApiKey->api_key . ':' . $wooApiKey->api_secret)
                ])->get($pagedUrl);
                if (!$productsResponse->successful()) break;
                $products = $productsResponse->json();
                if (!is_array($products) || empty($products)) break;
                $allProducts = array_merge($allProducts, $products);
                $page++;
            } while (count($products) === 100); // تا زمانی که صفحه پر است ادامه بده

            // 4. استخراج unique_id محصولات ساده و متغیر
            $uniqueIds = [];
            foreach ($allProducts as $product) {
                // اگر محصول ساده است
                if (($product['type'] ?? '') === 'simple') {
                    $uniqueId = $product['bim_unique_id'] ?? null;
                    if ($uniqueId) {
                        $uniqueIds[] = $uniqueId;
                    }
                }
                // اگر محصول متغیر است
                elseif (($product['type'] ?? '') === 'variable') {
                    $parentId = $product['id'] ?? null;
                    if ($parentId) {
                        // دریافت همه variations این محصول
                        $variationsUrl = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products/' . $parentId . '/variations?per_page=100';
                        $vPage = 1;
                        do {
                            $vPagedUrl = $variationsUrl . '&page=' . $vPage;
                            $variationsResponse = Http::withOptions([
                                'verify' => false,
                                'timeout' => 60,
                                'connect_timeout' => 20
                            ])->withHeaders([
                                'Content-Type' => 'application/json',
                                'Authorization' => 'Basic ' . base64_encode($wooApiKey->api_key . ':' . $wooApiKey->api_secret)
                            ])->get($vPagedUrl);
                            if (!$variationsResponse->successful()) break;
                            $variations = $variationsResponse->json();
                            if (!is_array($variations) || empty($variations)) break;
                            foreach ($variations as $variation) {
                                $vUniqueId = $variation['bim_unique_id'] ?? null;
                                if ($vUniqueId) {
                                    $uniqueIds[] = $vUniqueId;
                                }
                            }
                            $vPage++;
                        } while (count($variations) === 100);
                    }
                }
            }
            if (empty($uniqueIds)) continue;

            // 5. دریافت موجودی از باران با منطق getRealtimeStock (هر 100 تا)
            // ارسال همه unique_id ها در یک درخواست به باران
            $baranResponse = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user->warehouse_api_username . ':' . $user->warehouse_api_password)
            ])->timeout(60)->post($user->warehouse_api_url . '/api/itemlist/GetItemsByIds', $uniqueIds);
            if (!$baranResponse->successful()) {
                Log::error('خطا در دریافت موجودی از باران برای دسته ' . $categoryName . ': ' . $baranResponse->status());
                continue;
            }
            $baranItems = $baranResponse->json() ?? [];
            $groupedItems = collect($baranItems)->groupBy('itemID');

            // محصولات را 100تایی به ووکامرس ارسال کن
            $productsBatchList = array_chunk($uniqueIds, 100);
            foreach ($productsBatchList as $batchUniqueIds) {
                $productsBatch = [];
                foreach ($batchUniqueIds as $uniqueId) {
                    $items = $groupedItems->get($uniqueId, collect());
                    $totalStock = 0;
                    if ($items->isNotEmpty()) {
                        if (empty($warehouseIds)) {
                            foreach ($items as $item) {
                                $stockQuantity = (int)($item['stockQuantity'] ?? 0);
                                if ($stockQuantity > 0) {
                                    $totalStock += $stockQuantity;
                                }
                            }
                        } else {
                            foreach ($items as $item) {
                                if (isset($item['stockID']) && in_array($item['stockID'], $warehouseIds)) {
                                    $stockQuantity = (int)($item['stockQuantity'] ?? 0);
                                    if ($stockQuantity > 0) {
                                        $totalStock += $stockQuantity;
                                    }
                                }
                            }
                        }
                    }
                    $productsBatch[] = [
                        'unique_id' => $uniqueId,
                        'stock_quantity' => $totalStock,
                        'manage_stock' => true,
                        'stock_status' => $totalStock > 0 ? 'instock' : 'outofstock'
                    ];
                }

                // ارسال batch به ووکامرس
                $wordpressUrl = rtrim($license->website_url, '/') . '/wp-json/wc/v3/products/unique/batch/update';
                $wpResponse = Http::withOptions([
                    'verify' => false,
                    'timeout' => 120,
                    'connect_timeout' => 30
                ])->withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Basic ' . base64_encode($wooApiKey->api_key . ':' . $wooApiKey->api_secret)
                ])->put($wordpressUrl, [
                    'products' => $productsBatch
                ]);

                if ($wpResponse->successful()) {
                    Log::info('موجودی محصولات دسته ' . $categoryName . ' (batch) با موفقیت در ووکامرس به‌روزرسانی شد.');
                } else {
                    Log::error('خطا در به‌روزرسانی ووکامرس برای دسته ' . $categoryName . ' (batch): ' . $wpResponse->status());
                }
            }
        }
    }
}
