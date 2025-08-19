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
            // به‌روزرسانی stock_id تمام محصولات این لایسنس
            $affectedRows = Product::where('license_id', $event->license->id)
                ->update(['stock_id' => $event->newWarehouseCode]);

            Log::info('به‌روزرسانی stock_id محصولات تکمیل شد', [
                'license_id' => $event->license->id,
                'affected_products' => $affectedRows,
                'new_stock_id' => $event->newWarehouseCode
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
