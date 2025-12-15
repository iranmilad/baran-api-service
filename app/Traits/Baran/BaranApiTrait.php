<?php

namespace App\Traits\Baran;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait BaranApiTrait
{
    /**
     * دریافت اطلاعات آیتم‌ها بر اساس ID از Baran
     */
    protected function getBaranItemsByIds($license, array $itemIds)
    {
        // دریافت اطلاعات API از user مرتبط با لایسنس
        $user = $license->user;

        if (!$user) {
            throw new \Exception('کاربر مرتبط با این لایسنس یافت نشد.');
        }

        $warehouseApiUrl = $user->warehouse_api_url ?? null;
        $warehouseApiUsername = $user->warehouse_api_username ?? null;
        $warehouseApiPassword = $user->warehouse_api_password ?? null;

        if (!$warehouseApiUrl) {
            throw new \Exception('آدرس API انبار (warehouse_api_url) برای این کاربر تنظیم نشده است.');
        }

        if (!$warehouseApiUsername || !$warehouseApiPassword) {
            throw new \Exception('نام کاربری یا رمز عبور API انبار برای این کاربر تنظیم نشده است.');
        }

        // ساخت URL کامل
        $url = rtrim($warehouseApiUrl, '/') . '/api/itemlist/GetItemsByIds';

        // لاگ درخواست به باران
        Log::info('=== درخواست به Baran API ===');
        Log::info('URL: ' . $url);
        Log::info('Username: ' . $warehouseApiUsername);
        Log::info('Request Item IDs:', ['item_ids' => $itemIds, 'count' => count($itemIds)]);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json'
            ])->withBasicAuth($warehouseApiUsername, $warehouseApiPassword)
              ->post($url, $itemIds);

            if ($response->successful()) {
                $responseData = $response->json();

                // لاگ پاسخ موفق باران
                Log::info('=== پاسخ موفق از Baran API ===');
                Log::info('Response Status: ' . $response->status());
                Log::info('Items Count: ' . (is_array($responseData) ? count($responseData) : 0));

                // تبدیل پاسخ به فرمت استاندارد با Attributes
                // if (is_array($responseData)) {
                //     $responseData = $this->remapBaranResponse($responseData);
                // }

                // Log::info('Response Data (JSON) After Remap:', [
                //     'json' => json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                // ]);

                // نمایش اطلاعات اولین آیتم برای دیباگ
                if (is_array($responseData) && count($responseData) > 0) {
                    Log::info('First Item Sample:', [
                        'item' => $responseData[0]
                    ]);
                }

                return $responseData;
            }

            // تفسیر پیام خطا
            $errorBody = $response->body();
            $statusCode = $response->status();

            Log::error('Baran GetItemsByIds Error', [
                'status' => $statusCode,
                'body' => $errorBody,
                'item_ids' => $itemIds,
                'url' => $url,
                'username' => $warehouseApiUsername,
                'user_id' => $user->id
            ]);

            // پیام خطای واضح‌تر برای کاربر
            if ($statusCode === 401 || str_contains($errorBody, 'Invalid Authorization') || str_contains($errorBody, 'Unauthorized')) {
                throw new \Exception('خطا در احراز هویت API انبار - نام کاربری یا رمز عبور نامعتبر است');
            } elseif ($statusCode === 404) {
                throw new \Exception('آیتم‌های درخواستی در سرویس Baran یافت نشدند');
            } elseif ($statusCode >= 500) {
                throw new \Exception('خطای سرور Baran - لطفاً بعداً تلاش کنید');
            } else {
                throw new \Exception('خطا در دریافت اطلاعات از Baran: ' . $errorBody);
            }
        } catch (\Exception $e) {
            Log::error('Baran API Connection Error', [
                'message' => $e->getMessage(),
                'item_ids' => $itemIds
            ]);
            throw $e;
        }
    }

    /**
     * تبدیل پاسخ Baran به فرمت استاندارد با Attributes
     */
    protected function remapBaranResponse(array $items)
    {
        $remappedItems = [];

        foreach ($items as $item) {
            // ساخت آرایه Attributes از customReserve
            $attributes = [];

            // رنگ - از customReserve1
            if (!empty($item['customReserve1'])) {
                $attributes[] = [
                    'Attribute' => 'رنگ',
                    'Code' => '03',
                    'ItemID' => strtoupper($item['itemID'] ?? ''),
                    'Value' => $item['customReserve1']
                ];
            }

            // سایز - از customReserve2
            if (!empty($item['customReserve2'])) {
                $attributes[] = [
                    'Attribute' => 'سایز',
                    'Code' => '83',
                    'ItemID' => strtoupper($item['itemID'] ?? ''),
                    'Value' => $item['customReserve2']
                ];
            }

            // اضافه کردن Attributes به آیتم
            $item['attributes'] = $attributes;

            $remappedItems[] = $item;
        }

        return $remappedItems;
    }

    /**
     * تبدیل attributes دریافتی از Baran به فرمت قابل استفاده
     */
    protected function parseBaranAttributes(array $baranAttributes)
    {
        $attributes = [];

        foreach ($baranAttributes as $attr) {
            $attributes[] = [
                'name' => $attr['attribute'] ?? ($attr['Attribute'] ?? ''),
                'code' => $attr['code'] ?? ($attr['Code'] ?? ''),
                'value' => $attr['value'] ?? ($attr['Value'] ?? ''),
                'item_id' => $attr['itemID'] ?? ($attr['ItemID'] ?? '')
            ];
        }

        return $attributes;
    }

    /**
     * استخراج اطلاعات محصول از پاسخ Baran
     * این متد یک آیتم واحد را می‌پذیرد (معمولاً اولین مورد یا مورد فیلتر شده)
     */
    protected function extractBaranProductInfo($baranItem)
    {
        return [
            'item_id' => $baranItem['itemID'] ?? null,
            'parent_id' => $baranItem['parentID'] ?? null,
            'item_name' => $baranItem['itemName'] ?? '',
            'attributes' => $this->parseBaranAttributes($baranItem['attributes'] ?? []),
            'price' => $baranItem['salePrice'] ?? 0,
            'sale_price' => $baranItem['PriceAfterDiscount'] ?? 0,
            'current_discount' => $baranItem['currentDiscount'] ?? 0,
            'barcode' => $baranItem['barcode'] ?? '',
            'stock_name' => $baranItem['stockName'] ?? '',
            'stock_id' => $baranItem['stockID'] ?? '',
            'stock_quantity' => $baranItem['stockQuantity'] ?? 0,
            'department_code' => $baranItem['departmentCode'] ?? null,
            'department_name' => $baranItem['departmentName'] ?? null,
            'description' => $baranItem['description'] ?? '',
            'short_description' => $baranItem['shortDescription'] ?? ($baranItem['itemName'] ?? '')
        ];
    }

    /**
     * فیلتر و محاسبه موجودی بر اساس تنظیمات انبار
     *
     * @param array $itemStocks لیست موجودی‌های یک آیتم در انبارهای مختلف
     * @param string|array|null $defaultWarehouseCode کد انبار(های) پیش‌فرض
     * @return array اطلاعات آیتم با موجودی محاسبه شده
     */
    protected function filterAndCalculateStock($itemStocks, $defaultWarehouseCode = null)
    {
        if (empty($itemStocks)) {
            return null;
        }

        // اگر فقط یک مورد باشد و کد انبار خالی باشد، همان را برگردان
        if (count($itemStocks) === 1 && empty($defaultWarehouseCode)) {
            return $itemStocks[0];
        }

        // اگر کد انبار پیش‌فرض تنظیم شده باشد
        if (!empty($defaultWarehouseCode)) {
            $allowedWarehouses = is_array($defaultWarehouseCode) ? $defaultWarehouseCode : [$defaultWarehouseCode];

            // فیلتر کردن موجودی‌های انبارهای مجاز
            $filteredStocks = array_filter($itemStocks, function($stock) use ($allowedWarehouses) {
                return in_array($stock['stockID'] ?? '', $allowedWarehouses);
            });

            if (empty($filteredStocks)) {
                Log::warning('هیچ موجودی در انبار(های) مجاز یافت نشد', [
                    'item_id' => $itemStocks[0]['itemID'] ?? 'unknown',
                    'allowed_warehouses' => $allowedWarehouses,
                    'available_warehouses' => array_column($itemStocks, 'stockID')
                ]);

                // اگر هیچ انباری مطابقت نداشت، از اولین مورد استفاده کن با موجودی صفر
                $result = $itemStocks[0];
                $result['stockQuantity'] = 0;
                $result['stockName'] = 'انبار مجاز یافت نشد';
                return $result;
            }

            // محاسبه مجموع موجودی انبارهای فیلتر شده
            $totalQuantity = array_sum(array_column($filteredStocks, 'stockQuantity'));

            // استفاده از اطلاعات اولین انبار فیلتر شده به عنوان پایه
            $result = reset($filteredStocks);
            $result['stockQuantity'] = $totalQuantity;

            // اگر از چند انبار استفاده شد، نام را به‌روز کن
            if (count($filteredStocks) > 1) {
                $warehouseNames = array_column($filteredStocks, 'stockName');
                $result['stockName'] = implode(' + ', $warehouseNames);
            }

            Log::info('موجودی از انبار(های) مجاز محاسبه شد', [
                'item_id' => $result['itemID'] ?? 'unknown',
                'warehouses_count' => count($filteredStocks),
                'total_quantity' => $totalQuantity,
                'warehouse_names' => $result['stockName']
            ]);

            return $result;
        }

        // اگر کد انبار خالی باشد، مجموع تمام انبارها را محاسبه کن
        $totalQuantity = array_sum(array_column($itemStocks, 'stockQuantity'));

        // استفاده از اطلاعات اولین انبار به عنوان پایه
        $result = $itemStocks[0];
        $result['stockQuantity'] = $totalQuantity;

        // اگر از چند انبار استفاده شد، نام را به‌روز کن
        if (count($itemStocks) > 1) {
            $warehouseNames = array_column($itemStocks, 'stockName');
            $result['stockName'] = 'مجموع: ' . implode(', ', $warehouseNames);
        }

        Log::info('موجودی از مجموع تمام انبارها محاسبه شد', [
            'item_id' => $result['itemID'] ?? 'unknown',
            'warehouses_count' => count($itemStocks),
            'total_quantity' => $totalQuantity
        ]);

        return $result;
    }

    /**
     * گروه‌بندی پاسخ Baran بر اساس itemID
     * چون ممکن است یک آیتم در چند انبار باشد
     *
     * @param array $items لیست آیتم‌ها از Baran
     * @return array آرایه‌ای با کلید itemID و مقدار لیست موجودی‌های آن
     */
    protected function groupBaranItemsByItemId($items)
    {
        $grouped = [];

        foreach ($items as $item) {
            $itemId = $item['itemID'] ?? null;
            if ($itemId) {
                if (!isset($grouped[$itemId])) {
                    $grouped[$itemId] = [];
                }
                $grouped[$itemId][] = $item;
            }
        }

        return $grouped;
    }

    /**
     * دریافت تمام ویژگی‌ها (Attributes) از Baran API
     *
     * @param object $license لایسنس با user relation
     * @return array لیست ویژگی‌ها با پروپرتی‌هایشان
     * @throws \Exception
     */
    protected function getBaranAttributes($license)
    {
        // دریافت اطلاعات API از user مرتبط با لایسنس
        $user = $license->user;

        if (!$user) {
            throw new \Exception('کاربر مرتبط با این لایسنس یافت نشد.');
        }

        $warehouseApiUrl = $user->warehouse_api_url ?? null;
        $warehouseApiUsername = $user->warehouse_api_username ?? null;
        $warehouseApiPassword = $user->warehouse_api_password ?? null;

        if (!$warehouseApiUrl) {
            throw new \Exception('آدرس API انبار (warehouse_api_url) برای این کاربر تنظیم نشده است.');
        }

        if (!$warehouseApiUsername || !$warehouseApiPassword) {
            throw new \Exception('نام کاربری یا رمز عبور API انبار برای این کاربر تنظیم نشده است.');
        }

        // ساخت URL کامل
        $url = rtrim($warehouseApiUrl, '/') . '/api/attributes';

        Log::info('=== درخواست دریافت ویژگی‌ها از Baran API ===', [
            'url' => $url,
            'username' => $warehouseApiUsername
        ]);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json'
            ])->withBasicAuth($warehouseApiUsername, $warehouseApiPassword)
              ->get($url);

            if ($response->successful()) {
                $attributes = $response->json();

                Log::info('ویژگی‌ها با موفقیت از Baran دریافت شد', [
                    'attributes_count' => count($attributes),
                    'sample' => isset($attributes[0]) ? [
                        'name' => $attributes[0]['AttributeName'] ?? 'N/A',
                        'values_count' => count($attributes[0]['Values'] ?? [])
                    ] : null
                ]);

                return $attributes;
            }

            Log::error('خطا در دریافت ویژگی‌ها از Baran', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            throw new \Exception('خطا در دریافت ویژگی‌ها از Baran: ' . $response->body());

        } catch (\Exception $e) {
            Log::error('Exception در دریافت ویژگی‌ها از Baran', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }
}
