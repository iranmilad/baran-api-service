<?php

namespace App\Jobs\WordPress;

use App\Models\License;
use App\Models\ProductAttribute;
use App\Models\Notification;
use App\Traits\WordPress\WooCommerceApiTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncAttributesWithWooCommerceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use WooCommerceApiTrait;

    public $timeout = 600; // 10 دقیقه
    public $tries = 3;

    protected $licenseId;

    public function __construct($licenseId)
    {
        $this->licenseId = $licenseId;
        $this->onQueue('woocommerce-sync');
    }

    public function handle()
    {
        Log::info('=== شروع Job همگام‌سازی ویژگی‌ها ===', [
            'license_id' => $this->licenseId
        ]);

        try {
            $license = License::with('woocommerceApiKey')->findOrFail($this->licenseId);

            // بررسی نوع وب سرویس
            if ($license->web_service_type !== License::WEB_SERVICE_WORDPRESS) {
                throw new \Exception('این عملیات فقط برای لایسنس‌های وب سرویس قابل اجرا است');
            }

            if (!$license->woocommerceApiKey) {
                throw new \Exception('کلید API برای این لایسنس تنظیم نشده است');
            }

            $syncedAttributes = 0;
            $syncedTerms = 0;
            $errors = [];

            // دریافت درخت کامل attributes از WooCommerce (یک درخواست به جای چندین)
            $wcTreeResult = $this->getWooCommerceAttributesTree($license);

            if (!$wcTreeResult['success']) {
                throw new \Exception('خطا در دریافت درخت attributes از WooCommerce: ' . $wcTreeResult['message']);
            }

            $wcTree = $wcTreeResult['data'];
            $globalAttributes = $wcTree['data']['global_attributes'] ?? [];
            $customAttributes = $wcTree['data']['custom_attributes'] ?? [];

            Log::info('WooCommerce attributes tree fetched', [
                'global_count' => count($globalAttributes),
                'custom_count' => count($customAttributes)
            ]);

            // ایجاد map از attributes موجود برای جستجوی سریع
            $existingAttributesMap = [];
            foreach ($globalAttributes as $attr) {
                $key = strtolower($attr['slug']);
                $existingAttributesMap[$key] = [
                    'id' => $attr['id'],
                    'name' => $attr['name'],
                    'slug' => $attr['slug'],
                    'terms' => $attr['terms'] ?? []
                ];
            }

            // دریافت تمام ویژگی‌های فعال از دیتابیس
            $attributes = ProductAttribute::where('license_id', $this->licenseId)
                ->where('is_active', true)
                ->with(['properties' => function($query) {
                    $query->where('is_active', true);
                }])
                ->get();

            Log::info('Database attributes loaded', [
                'count' => $attributes->count()
            ]);

            foreach ($attributes as $attribute) {
                try {
                    $attributeKey = strtolower($attribute->slug);

                    // بررسی وجود در global attributes
                    if (isset($existingAttributesMap[$attributeKey])) {
                        $wcAttribute = $existingAttributesMap[$attributeKey];

                        Log::info('Attribute already exists in WooCommerce', [
                            'attribute_name' => $attribute->name,
                            'wc_id' => $wcAttribute['id']
                        ]);
                    } else {
                        // ایجاد attribute جدید
                        Log::info('Creating new attribute in WooCommerce', [
                            'attribute_name' => $attribute->name,
                            'slug' => $attribute->slug
                        ]);

                        $wcAttributeResult = $this->createWooCommerceAttribute($license, $attribute->name, $attribute->slug);

                        if (!$wcAttributeResult['success']) {
                            $errorMessage = $wcAttributeResult['message'] ?? 'خطای نامشخص';
                            $errors[] = "خطا در ایجاد ویژگی '{$attribute->name}': {$errorMessage}";

                            Log::warning('Failed to create attribute', [
                                'attribute_name' => $attribute->name,
                                'error' => $errorMessage
                            ]);
                            continue;
                        }

                        $wcAttribute = [
                            'id' => $wcAttributeResult['data']['id'],
                            'name' => $wcAttributeResult['data']['name'],
                            'slug' => $wcAttributeResult['data']['slug'],
                            'terms' => []
                        ];

                        // اضافه به map
                        $existingAttributesMap[$attributeKey] = $wcAttribute;

                        Log::info('Attribute created successfully', [
                            'attribute_name' => $attribute->name,
                            'wc_id' => $wcAttribute['id']
                        ]);
                    }

                    $syncedAttributes++;

                    // ایجاد map از terms موجود
                    $existingTermsMap = [];
                    foreach ($wcAttribute['terms'] as $term) {
                        // چک کردن با name و slug
                        $termNameKey = strtolower(trim($term['name']));
                        $termSlugKey = strtolower(trim($term['slug']));

                        $existingTermsMap[$termNameKey] = $term;
                        $existingTermsMap[$termSlugKey] = $term;
                    }

                    // همگام‌سازی پروپرتی‌ها (terms)
                    foreach ($attribute->properties as $property) {
                        try {
                            $termValue = !empty($property->value) ? $property->value : $property->name;

                            if (empty($termValue)) {
                                $errors[] = "پروپرتی فاقد نام و مقدار است";
                                continue;
                            }

                            $termNameKey = strtolower(trim($termValue));
                            $termSlugKey = strtolower(trim($property->slug));

                            // بررسی وجود term با name یا slug
                            if (isset($existingTermsMap[$termNameKey]) || isset($existingTermsMap[$termSlugKey])) {
                                $syncedTerms++;

                                Log::info('Term already exists, skipping', [
                                    'attribute_name' => $attribute->name,
                                    'term_name' => $termValue
                                ]);
                                continue;
                            }

                            // تولید slug: فقط فاصله‌ها را با - جایگزین کن
                            $termSlug = str_replace(' ', '-', trim($termValue));

                            // اگر slug در property موجود است، از آن استفاده کن
                            if (!empty($property->slug)) {
                                $termSlug = str_replace(' ', '-', trim($property->slug));
                            }

                            // بررسی تکراری بودن slug جدید
                            $counter = 1;
                            $originalSlug = $termSlug;
                            while (isset($existingTermsMap[strtolower($termSlug)])) {
                                $termSlug = $originalSlug . '-' . $counter;
                                $counter++;
                            }

                            Log::info('Preparing to create term', [
                                'attribute_name' => $attribute->name,
                                'term_name' => $termValue,
                                'term_slug' => $termSlug
                            ]);

                            // ایجاد term جدید
                            $termResult = $this->createWooCommerceAttributeTerm(
                                $license,
                                $wcAttribute['id'],
                                [
                                    'name' => $termValue,
                                    'slug' => $termSlug
                                ]
                            );

                            if ($termResult['success']) {
                                $syncedTerms++;

                                // اضافه کردن به map برای جلوگیری از تکرار
                                $existingTermsMap[strtolower($termValue)] = $termResult['data'];
                                $existingTermsMap[strtolower($termSlug)] = $termResult['data'];

                                Log::info('Term created successfully', [
                                    'attribute_name' => $attribute->name,
                                    'term_name' => $termValue
                                ]);
                            } else {
                                $errors[] = "خطا در ایجاد term '{$termValue}' برای '{$attribute->name}': " . $termResult['message'];
                            }
                        } catch (\Exception $e) {
                            $termValue = !empty($property->value) ? $property->value : $property->name;
                            $errors[] = "خطا در پردازش term '{$termValue}': " . $e->getMessage();
                        }
                    }
                } catch (\Exception $e) {
                    $errors[] = "خطا در پردازش ویژگی '{$attribute->name}': " . $e->getMessage();
                }
            }

            Log::info('=== Job همگام‌سازی ویژگی‌ها با موفقیت تکمیل شد ===', [
                'synced_attributes' => $syncedAttributes,
                'synced_terms' => $syncedTerms,
                'errors_count' => count($errors)
            ]);

            // ایجاد Notification برای کاربر
            if ($license->user_id) {
                $notificationType = count($errors) > 0 ? 'warning' : 'success';
                $notificationTitle = count($errors) > 0
                    ? 'همگام‌سازی با هشدار انجام شد'
                    : 'همگام‌سازی ویژگی‌ها با موفقیت انجام شد';

                $notificationMessage = "{$syncedAttributes} ویژگی و {$syncedTerms} term همگام‌سازی شدند.";

                if (count($errors) > 0) {
                    $notificationMessage .= "\n\nتعداد خطاها: " . count($errors);
                    $notificationMessage .= "\n" . implode("\n", array_slice($errors, 0, 3));
                    if (count($errors) > 3) {
                        $notificationMessage .= "\n... و " . (count($errors) - 3) . " خطای دیگر";
                    }
                }

                Notification::create([
                    'user_id' => $license->user_id,
                    'title' => $notificationTitle,
                    'message' => $notificationMessage,
                    'type' => $notificationType,
                    'is_read' => false,
                    'is_active' => true,
                    'data' => json_encode([
                        'license_id' => $license->id,
                        'synced_attributes' => $syncedAttributes,
                        'synced_terms' => $syncedTerms,
                        'errors_count' => count($errors),
                        'errors' => array_slice($errors, 0, 10)
                    ])
                ]);
            }

        } catch (\Exception $e) {
            Log::error('خطا در Job همگام‌سازی ویژگی‌ها', [
                'license_id' => $this->licenseId,
                'error' => $e->getMessage()
            ]);

            // ایجاد Notification خطا
            try {
                $license = License::find($this->licenseId);
                if ($license && $license->user_id) {
                    Notification::create([
                        'user_id' => $license->user_id,
                        'title' => 'خطا در همگام‌سازی ویژگی‌ها',
                        'message' => 'خطا در همگام‌سازی ویژگی‌ها با WooCommerce: ' . $e->getMessage(),
                        'type' => 'error',
                        'is_read' => false,
                        'is_active' => true,
                        'data' => json_encode([
                            'license_id' => $license->id,
                            'error' => $e->getMessage()
                        ])
                    ]);
                }
            } catch (\Exception $notifException) {
                Log::error('خطا در ایجاد notification', ['error' => $notifException->getMessage()]);
            }

            throw $e;
        }
    }
}
