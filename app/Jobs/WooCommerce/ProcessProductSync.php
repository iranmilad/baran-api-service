<?php

namespace App\Jobs\WooCommerce;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProcessProductSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $change;

    public function __construct(array $change)
    {
        $this->change = $change;
    }

    public function handle()
    {
        try {
            Log::info('شروع همگام‌سازی محصول', [
                'barcode' => $this->change['product']['barcode']
            ]);

            DB::beginTransaction();

            $product = Product::where('barcode', $this->change['product']['barcode'])->first();
            $isNew = $this->change['change_type'] === 'new';

            if ($isNew && $product) {
                DB::rollBack();
                return;
            }

            if (!$isNew && !$product) {
                DB::rollBack();
                return;
            }

            if (!$product) {
                $product = new Product();
                $product->barcode = $this->change['product']['Barcode'];
                $product->license_id = $this->change['license_id'];
            }

            // به‌روزرسانی اطلاعات اصلی محصول
            $product->item_name = $this->change['product']['ItemName'];
            $product->item_id = $this->change['product']['ItemId'] ?? null;
            $product->price_amount = $this->change['product']['PriceAmount'] ?? 0;
            $product->price_after_discount = $this->change['product']['PriceAfterDiscount'] ?? 0;
            $product->total_count = $this->change['product']['TotalCount'] ?? 0;
            $product->stock_id = $this->change['product']['StockID'] ?? null;
            $product->is_variant = !empty($this->change['product']['Attributes']);
            $product->last_sync_at = now();
            $product->save();

            // اگر محصول دارای ویژگی است
            if (!empty($this->change['product']['Attributes'])) {
                // حذف ویژگی‌های قدیمی اگر به‌روزرسانی است
                if (!$isNew) {
                    $product->variants()->delete();
                }

                foreach ($this->change['product']['Attributes'] as $variant) {
                    $variantProduct = new Product();
                    $variantProduct->barcode = $variant['Barcode'];
                    $variantProduct->item_name = $variant['ItemName'];
                    $variantProduct->item_id = $variant['ItemId'] ?? null;
                    $variantProduct->price_amount = $variant['PriceAmount'] ?? 0;
                    $variantProduct->price_after_discount = $variant['PriceAfterDiscount'] ?? 0;
                    $variantProduct->total_count = $variant['TotalCount'] ?? 0;
                    $variantProduct->stock_id = $variant['StockID'] ?? null;
                    $variantProduct->parent_id = $product->id;
                    $variantProduct->is_variant = true;
                    $variantProduct->last_sync_at = now();
                    $variantProduct->license_id = $this->change['license_id'];
                    $variantProduct->save();
                }
            }

            DB::commit();

            Log::info('پایان همگام‌سازی محصول', [
                'barcode' => $this->change['product']['barcode']
            ]);

            // ارسال به ووکامرس برای به‌روزرسانی
            // این بخش باید بر اساس تنظیمات کاربر پیاده‌سازی شود
            // dispatch(new UpdateWooCommerceProduct($product));

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('خطا در پردازش همگام‌سازی محصول: ' . $e->getMessage());
            throw $e;
        }
    }
}
