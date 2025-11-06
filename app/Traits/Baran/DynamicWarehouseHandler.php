<?php

namespace App\Traits;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait DynamicWarehouseHandler
{
    /**
     * تعیین StockID برای آیتم بر اساس تنظیمات انبار دینامیک
     *
     * @param string $itemId شناسه یکتای محصول
     * @param int $requiredQuantity تعداد مورد نیاز
     * @param object $user اطلاعات کاربر (شامل API credentials)
     * @param bool $enableDynamicWarehouse وضعیت فعال بودن انبار دینامیک
     * @param array|string|null $defaultWarehouseCode کد انبار پیش‌فرض
     * @param string $context زمینه استفاده (برای لاگ)
     * @return string|null StockID انتخاب شده یا null
     */
    protected function determineStockIdForItem($itemId, $requiredQuantity, $user, $enableDynamicWarehouse, $defaultWarehouseCode, $context = 'invoice')
    {
        try {
            Log::info('شروع تعیین StockID برای آیتم', [
                'item_id' => $itemId,
                'required_quantity' => $requiredQuantity,
                'enable_dynamic_warehouse' => $enableDynamicWarehouse,
                'default_warehouse_code' => $defaultWarehouseCode,
                'context' => $context
            ]);

            // اگر انبار دینامیک فعال نیست
            if (!$enableDynamicWarehouse) {
                return $this->handleStaticWarehouse($defaultWarehouseCode, $context);
            }

            // اگر انبار دینامیک فعال است، دریافت اطلاعات از API
            $itemStockData = $this->fetchItemStockFromApi($itemId, $user, $context);

            if (empty($itemStockData)) {
                Log::warning('اطلاعات موجودی آیتم از API دریافت نشد', [
                    'item_id' => $itemId,
                    'context' => $context
                ]);
                return null;
            }

            // انتخاب بهترین انبار
            return $this->selectBestWarehouse($itemStockData, $requiredQuantity, $defaultWarehouseCode, $context);

        } catch (\Exception $e) {
            Log::error('خطا در تعیین StockID', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
                'context' => $context
            ]);
            return null;
        }
    }

    /**
     * مدیریت انبار استاتیک (غیر دینامیک)
     */
    private function handleStaticWarehouse($defaultWarehouseCode, $context)
    {
        if (empty($defaultWarehouseCode)) {
            Log::info('انبار دینامیک غیرفعال و کد انبار پیش‌فرض خالی - StockID تنظیم نمی‌شود', [
                'context' => $context
            ]);
            return null;
        }

        // اگر آرایه است، اولین عنصر را برمی‌گردانیم
        if (is_array($defaultWarehouseCode)) {
            $selectedStock = $defaultWarehouseCode[0];
            Log::info('انتخاب اولین انبار از آرایه انبارهای پیش‌فرض', [
                'selected_stock' => $selectedStock,
                'all_warehouses' => $defaultWarehouseCode,
                'context' => $context
            ]);
            return $selectedStock;
        }

        Log::info('استفاده از انبار پیش‌فرض تکی', [
            'stock_id' => $defaultWarehouseCode,
            'context' => $context
        ]);
        return $defaultWarehouseCode;
    }

    /**
     * دریافت اطلاعات موجودی آیتم از API
     */
    private function fetchItemStockFromApi($itemId, $user, $context)
    {
        try {
            Log::info('درخواست اطلاعات موجودی از API باران', [
                'item_id' => $itemId,
                'api_url' => $user->warehouse_api_url ?? $user->api_webservice,
                'context' => $context
            ]);

            // تعیین URL مناسب برای API
            $apiUrl = $user->warehouse_api_url ?? $user->api_webservice;
            $username = $user->warehouse_api_username ?? $user->api_username;
            $password = $user->warehouse_api_password ?? $user->api_password;

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 120,
                'connect_timeout' => 30
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
            ])->post($apiUrl . '/api/itemlist/GetItemsByIds', [$itemId]);

            if (!$response->successful()) {
                Log::error('درخواست API باران ناموفق', [
                    'item_id' => $itemId,
                    'status_code' => $response->status(),
                    'response_body' => substr($response->body(), 0, 500),
                    'context' => $context
                ]);
                return [];
            }

            $stockData = $response->json();

            Log::info('اطلاعات موجودی از API دریافت شد', [
                'item_id' => $itemId,
                'stocks_count' => count($stockData),
                'context' => $context
            ]);

            return $stockData;

        } catch (\Exception $e) {
            Log::error('خطا در درخواست API موجودی', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
                'context' => $context
            ]);
            return [];
        }
    }

    /**
     * انتخاب بهترین انبار بر اساس موجودی و تنظیمات
     */
    private function selectBestWarehouse($stockData, $requiredQuantity, $defaultWarehouseCode, $context)
    {
        // اگر کد انبار پیش‌فرض تنظیم نشده، انبار با بیشترین موجودی را انتخاب کن
        if (empty($defaultWarehouseCode)) {
            return $this->selectWarehouseByAvailability($stockData, $requiredQuantity, $context);
        }

        // اگر کد انبار پیش‌فرض تنظیم شده، از لیست انبارهای مجاز انتخاب کن
        $allowedWarehouses = is_array($defaultWarehouseCode) ? $defaultWarehouseCode : [$defaultWarehouseCode];

        return $this->selectFromAllowedWarehouses($stockData, $requiredQuantity, $allowedWarehouses, $context);
    }

    /**
     * انتخاب انبار بر اساس موجودی (بدون محدودیت انبار)
     */
    private function selectWarehouseByAvailability($stockData, $requiredQuantity, $context)
    {
        $bestWarehouse = null;
        $bestStock = 0;

        foreach ($stockData as $item) {
            $stockQuantity = (float)($item['stockQuantity'] ?? 0);

            // اولویت با انباری که موجودی کافی دارد
            if ($stockQuantity >= $requiredQuantity && $stockQuantity > $bestStock) {
                $bestWarehouse = $item['stockID'];
                $bestStock = $stockQuantity;
            }
        }

        // اگر هیچ انباری موجودی کافی نداشت، انبار با بیشترین موجودی را انتخاب کن
        if (!$bestWarehouse) {
            foreach ($stockData as $item) {
                $stockQuantity = (float)($item['stockQuantity'] ?? 0);
                if ($stockQuantity > $bestStock) {
                    $bestWarehouse = $item['stockID'];
                    $bestStock = $stockQuantity;
                }
            }
        }

        Log::info('انتخاب انبار بر اساس بیشترین موجودی', [
            'selected_warehouse' => $bestWarehouse,
            'available_stock' => $bestStock,
            'required_quantity' => $requiredQuantity,
            'context' => $context
        ]);

        return $bestWarehouse;
    }

    /**
     * انتخاب از انبارهای مجاز
     */
    private function selectFromAllowedWarehouses($stockData, $requiredQuantity, $allowedWarehouses, $context)
    {
        $bestWarehouse = null;
        $bestStock = 0;
        $availableWarehouses = [];

        // جمع‌آوری انبارهای مجاز که موجودی دارند
        foreach ($stockData as $item) {
            $stockId = $item['stockID'];
            $stockQuantity = (float)($item['stockQuantity'] ?? 0);

            if (in_array($stockId, $allowedWarehouses) && $stockQuantity > 0) {
                $availableWarehouses[] = [
                    'stock_id' => $stockId,
                    'quantity' => $stockQuantity,
                    'stock_name' => $item['stockName'] ?? 'نامشخص'
                ];
            }
        }

        if (empty($availableWarehouses)) {
            Log::warning('هیچ انبار مجازی با موجودی یافت نشد', [
                'allowed_warehouses' => $allowedWarehouses,
                'context' => $context
            ]);
            return null;
        }

        // انتخاب بهترین انبار از میان انبارهای مجاز
        foreach ($availableWarehouses as $warehouse) {
            // اولویت با انباری که موجودی کافی دارد
            if ($warehouse['quantity'] >= $requiredQuantity && $warehouse['quantity'] > $bestStock) {
                $bestWarehouse = $warehouse['stock_id'];
                $bestStock = $warehouse['quantity'];
            }
        }

        // اگر هیچ انباری موجودی کافی نداشت، انبار با بیشترین موجودی را انتخاب کن
        if (!$bestWarehouse) {
            foreach ($availableWarehouses as $warehouse) {
                if ($warehouse['quantity'] > $bestStock) {
                    $bestWarehouse = $warehouse['stock_id'];
                    $bestStock = $warehouse['quantity'];
                }
            }
        }

        Log::info('انتخاب انبار از لیست مجاز', [
            'selected_warehouse' => $bestWarehouse,
            'available_stock' => $bestStock,
            'required_quantity' => $requiredQuantity,
            'allowed_warehouses' => $allowedWarehouses,
            'available_warehouses' => $availableWarehouses,
            'context' => $context
        ]);

        return $bestWarehouse;
    }

    /**
     * دریافت اطلاعات کامل موجودی برای چندین آیتم (برای استفاده در جاهای دیگر)
     */
    protected function fetchMultipleItemsStock($itemIds, $user, $context = 'bulk_check')
    {
        try {
            Log::info('درخواست موجودی چندین آیتم', [
                'items_count' => count($itemIds),
                'context' => $context
            ]);

            $apiUrl = $user->warehouse_api_url ?? $user->api_webservice;
            $username = $user->warehouse_api_username ?? $user->api_username;
            $password = $user->warehouse_api_password ?? $user->api_password;

            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($username . ':' . $password)
            ])->post($apiUrl . '/api/itemlist/GetItemsByIds', $itemIds);

            if (!$response->successful()) {
                Log::error('درخواست موجودی چندین آیتم ناموفق', [
                    'status_code' => $response->status(),
                    'context' => $context
                ]);
                return [];
            }

            $stockData = $response->json();

            Log::info('موجودی چندین آیتم دریافت شد', [
                'items_count' => count($itemIds),
                'response_count' => count($stockData),
                'context' => $context
            ]);

            return $stockData;

        } catch (\Exception $e) {
            Log::error('خطا در درخواست موجودی چندین آیتم', [
                'error' => $e->getMessage(),
                'context' => $context
            ]);
            return [];
        }
    }
}
