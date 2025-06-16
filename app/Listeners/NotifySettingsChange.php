<?php

namespace App\Listeners;

use App\Events\SettingsUpdated;
use Illuminate\Support\Facades\Http;

class NotifySettingsChange
{
    public function handle(SettingsUpdated $event)
    {
        // ارسال درخواست همگام‌سازی به پلاگین
        $response = Http::withHeaders([
            'X-API-Key' => $event->license->api_key
        ])->post($event->license->domain . '/wp-json/baran-inventory-manager/v1/sync-settings');

        if (!$response->successful()) {
            \Log::error('خطا در ارسال درخواست همگام‌سازی به پلاگین', [
                'license_id' => $event->license->id,
                'domain' => $event->license->domain,
                'error' => $response->body()
            ]);
        }
    }
}
