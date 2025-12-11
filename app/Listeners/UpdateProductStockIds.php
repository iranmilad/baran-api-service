<?php

namespace App\Listeners;

use App\Events\WarehouseCodeChanged;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class UpdateProductStockIds implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(WarehouseCodeChanged $event): void
    {
        // بررسی اینکه آیا واقعاً تغییر کرده یا نه
        if ($event->oldWarehouseCode === $event->newWarehouseCode) {
            Log::info('کد انبار تغییر نکرده، عملیات متوقف شد', [
                'license_id' => $event->license->id,
                'warehouse_code' => $event->newWarehouseCode
            ]);
            return;
        }

        Log::info('شروع به‌روزرسانی stock_id محصولات', [
            'license_id' => $event->license->id,
            'old_warehouse_code' => $event->oldWarehouseCode,
            'new_warehouse_code' => $event->newWarehouseCode
        ]);

        try {
            // دریافت محصولات با warehouse code قدیم
            $productsWithOldWarehouse = Product::where('license_id', $event->license->id)
                ->where('stock_id', $event->oldWarehouseCode)
                ->get();

            Log::info('محصولات با کد انبار قدیم یافت شد', [
                'license_id' => $event->license->id,
                'count' => $productsWithOldWarehouse->count(),
                'old_warehouse_code' => $event->oldWarehouseCode
            ]);

            $updatedCount = 0;
            $deletedCount = 0;

            foreach ($productsWithOldWarehouse as $product) {
                // بررسی اینکه آیا رکورد جدید با ترکیب (item_id, stock_id, license_id) موجود است
                $existingProduct = Product::where('license_id', $event->license->id)
                    ->where('item_id', $product->item_id)
                    ->where('stock_id', $event->newWarehouseCode)
                    ->first();

                if ($existingProduct) {
                    // اگر رکورد جدید موجود است، رکورد قدیم را حذف کن
                    $product->delete();
                    $deletedCount++;

                    Log::info('محصول تکراری حذف شد', [
                        'product_id' => $product->id,
                        'item_id' => $product->item_id,
                        'license_id' => $event->license->id,
                        'old_stock_id' => $event->oldWarehouseCode,
                        'new_stock_id' => $event->newWarehouseCode
                    ]);
                } else {
                    // اگر رکورد جدید موجود نیست، stock_id را به‌روزرسانی کن
                    $product->update(['stock_id' => $event->newWarehouseCode]);
                    $updatedCount++;

                    Log::info('stock_id محصول به‌روزرسانی شد', [
                        'product_id' => $product->id,
                        'item_id' => $product->item_id,
                        'license_id' => $event->license->id,
                        'old_stock_id' => $event->oldWarehouseCode,
                        'new_stock_id' => $event->newWarehouseCode
                    ]);
                }
            }

            Log::info('به‌روزرسانی stock_id محصولات تکمیل شد', [
                'license_id' => $event->license->id,
                'updated_products' => $updatedCount,
                'deleted_duplicates' => $deletedCount,
                'total_processed' => $productsWithOldWarehouse->count(),
                'old_warehouse_code' => $event->oldWarehouseCode,
                'new_warehouse_code' => $event->newWarehouseCode
            ]);

        } catch (\Exception $e) {
            Log::error('خطا در به‌روزرسانی stock_id محصولات', [
                'license_id' => $event->license->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // در صورت خطا، job را مجدداً در صف قرار بده
            $this->fail($e);
        }
    }
}
