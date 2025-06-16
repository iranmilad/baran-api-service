<?php

namespace App\Console\Commands;

use App\Models\License;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncProducts extends Command
{
    protected $signature = 'products:sync';
    protected $description = 'همگام‌سازی محصولات از فایل JSON';

    public function handle()
    {
        $licenses = License::where('is_active', true)->get();

        foreach ($licenses as $license) {
            try {
                $response = Http::post(config('app.url') . '/api/v1/sync-products', [
                    'serial_key' => $license->serial_key,
                    'site_url' => $license->domain
                ]);

                if (!$response->successful()) {
                    Log::error('خطا در همگام‌سازی محصولات', [
                        'license_id' => $license->id,
                        'error' => $response->body()
                    ]);
                    continue;
                }

                $data = $response->json();
                if ($data['success']) {
                    $this->info("همگام‌سازی محصولات برای لایسنس {$license->serial_key} با موفقیت انجام شد");
                    $this->info("تعداد محصولات به‌روز شده: " . count($data['data']['updated_products']));
                    $this->info("تعداد محصولات جدید: " . count($data['data']['new_products']));
                }
            } catch (\Exception $e) {
                Log::error('خطا در همگام‌سازی محصولات: ' . $e->getMessage(), [
                    'license_id' => $license->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
