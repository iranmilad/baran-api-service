<?php

namespace App\Jobs;

use App\Models\License;
use App\Models\WooCommerceApiKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Automattic\WooCommerce\Client;

class SyncCategories implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 300;
    public $maxExceptions = 3;
    public $backoff = [60, 180, 300];

    protected $license_id;

    public function __construct($license_id)
    {
        $this->license_id = $license_id;
    }

    public function handle()
    {
        try {
            $license = License::with(['user', 'woocommerceApiKey'])->find($this->license_id);
            if (!$license || !$license->isActive()) {
                Log::error('لایسنس معتبر نیست', [
                    'license_id' => $this->license_id
                ]);
                return;
            }

            $user = $license->user;
            $wooApiKey = $license->woocommerceApiKey;

            if (!$user || !$wooApiKey) {
                Log::error('اطلاعات کاربر یا کلید API ووکامرس یافت نشد', [
                    'license_id' => $license->id
                ]);
                return;
            }

            // دریافت دسته‌بندی‌ها از API باران
            $rainCategories = $this->getRainCategories($user);
            if (empty($rainCategories)) {
                Log::error('هیچ دسته‌بندی از API باران دریافت نشد', [
                    'license_id' => $this->license_id
                ]);
                return;
            }

            // دریافت دسته‌بندی‌های موجود در ووکامرس
            $woocommerce = new Client(
                $license->website_url,
                $wooApiKey->api_key,
                $wooApiKey->api_secret,
                [
                    'version' => 'wc/v3',
                    'verify_ssl' => false
                ]
            );

            $existingCategories = $this->getWooCommerceCategories($woocommerce);

            // ایجاد دسته‌بندی‌های جدید
            foreach ($rainCategories as $rainCategory) {
                $categoryExists = collect($existingCategories)->contains('name', $rainCategory['DepartmentName']);

                if (!$categoryExists) {
                    try {
                        $response = $woocommerce->post('products/categories', [
                            'name' => $rainCategory['DepartmentName'],
                            'description' => 'دسته‌بندی ' . $rainCategory['DepartmentName']
                        ]);

                        Log::info('دسته‌بندی جدید ایجاد شد', [
                            'name' => $rainCategory['DepartmentName'],
                            'department_id' => $rainCategory['DepartmentID']
                        ]);
                    } catch (\Exception $e) {
                        Log::error('خطا در ایجاد دسته‌بندی: ' . $e->getMessage(), [
                            'name' => $rainCategory['DepartmentName']
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('خطا در همگام‌سازی دسته‌بندی‌ها: ' . $e->getMessage(), [
                'license_id' => $this->license_id
            ]);
            throw $e;
        }
    }

    protected function getRainCategories($user)
    {
        try {
            $response = Http::withOptions([
                'verify' => false,
                'timeout' => 180,
                'connect_timeout' => 60
            ])->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($user->api_username . ':' . $user->api_password)
            ])->post($user->api_webservice."/RainSaleService.svc/GetQuickItems", [
                "userId" => $user->api_userId
            ]);



            if (!$response->successful()) {
                Log::error('خطا در دریافت دسته‌بندی‌ها از API باران', [
                    'response' => $response->body()
                ]);
                return [];
            }

            $data = $response->json();
            return $data['GetQuickItemsResult']['Departments'] ?? [];
        } catch (\Exception $e) {
            Log::error('خطا در دریافت دسته‌بندی‌ها از API باران: ' . $e->getMessage());
            return [];
        }
    }

    protected function getWooCommerceCategories($woocommerce)
    {
        try {
            return $woocommerce->get('products/categories', [
                'per_page' => 100
            ]);
        } catch (\Exception $e) {
            Log::error('خطا در دریافت دسته‌بندی‌ها از ووکامرس: ' . $e->getMessage());
            return [];
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('خطا در پردازش صف همگام‌سازی دسته‌بندی‌ها: ' . $exception->getMessage(), [
            'license_id' => $this->license_id
        ]);
    }
}
