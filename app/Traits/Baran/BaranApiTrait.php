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
                if (is_array($responseData)) {
                    $responseData = $this->remapBaranResponse($responseData);
                }

                Log::info('Response Data (JSON) After Remap:', [
                    'json' => json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                ]);

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
            $item['Attributes'] = $attributes;

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
                'name' => $attr['Attribute'] ?? '',
                'code' => $attr['Code'] ?? '',
                'value' => $attr['Value'] ?? '',
                'item_id' => $attr['ItemID'] ?? ''
            ];
        }

        return $attributes;
    }

    /**
     * استخراج اطلاعات محصول از پاسخ Baran
     */
    protected function extractBaranProductInfo($baranItem)
    {
        return [
            'item_id' => $baranItem['itemID'] ?? null,
            'parent_id' => $baranItem['parentID'] ?? null,
            'item_name' => $baranItem['itemName'] ?? '',
            'attributes' => $this->parseBaranAttributes($baranItem['Attributes'] ?? []),
            'price' => $baranItem['price'] ?? ($baranItem['salePrice'] ?? 0),
            'sale_price' => $baranItem['salePrice'] ?? 0,
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
}
