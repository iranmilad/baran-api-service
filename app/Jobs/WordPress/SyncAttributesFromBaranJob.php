<?php

namespace App\Jobs\WordPress;

use App\Models\License;
use App\Models\ProductAttribute;
use App\Models\ProductProperty;
use App\Models\Notification;
use App\Traits\WordPress\WooCommerceApiTrait;
use App\Traits\Baran\BaranApiTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncAttributesFromBaranJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use WooCommerceApiTrait, BaranApiTrait;

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
            $license = License::with(['woocommerceApiKey', 'user'])->findOrFail($this->licenseId);

            // بررسی نوع وب سرویس
            if ($license->web_service_type !== License::WEB_SERVICE_WORDPRESS) {
                throw new \Exception('این عملیات فقط برای لایسنس‌های وب سرویس قابل اجرا است');
            }

            if (!$license->woocommerceApiKey) {
                throw new \Exception('کلید API برای این لایسنس تنظیم نشده است');
            }

            // دریافت ویژگی‌ها از Baran API
            $baranAttributes = $this->getBaranAttributes($license);

            if (empty($baranAttributes)) {
                throw new \Exception('هیچ ویژگی‌ای از Baran دریافت نشد');
            }

            Log::info('Baran attributes fetched', [
                'count' => count($baranAttributes)
            ]);

            // ذخیره در دیتابیس
            $this->saveBaranAttributesToDatabase($baranAttributes);

            // همگام‌سازی با WooCommerce
            $syncResult = $this->syncDatabaseWithWooCommerce($license);

            Log::info('=== Job همگام‌سازی با موفقیت تکمیل شد ===', $syncResult);

            // ایجاد Notification برای کاربر
            $this->createSuccessNotification($license, $syncResult);

        } catch (\Exception $e) {
            Log::error('خطا در Job همگام‌سازی ویژگی‌ها', [
                'license_id' => $this->licenseId,
                'error' => $e->getMessage()
            ]);

            $this->createErrorNotification($e);
            throw $e;
        }
    }

    /**
     * ذخیره attributes از Baran در دیتابیس
     */
    protected function saveBaranAttributesToDatabase($baranAttributes)
    {
        foreach ($baranAttributes as $baranAttribute) {
            $attributeName = $baranAttribute['AttributeName'] ?? null;
            $values = $baranAttribute['Values'] ?? [];

            if (empty($attributeName)) {
                continue;
            }

            // جستجو یا ایجاد attribute در دیتابیس
            $dbAttribute = ProductAttribute::firstOrCreate(
                [
                    'license_id' => $this->licenseId,
                    'name' => $attributeName
                ],
                [
                    'slug' => $attributeName,
                    'value' => $attributeName,
                    'is_active' => true,
                    'is_visible' => true,
                    'is_variation' => true,
                    'sort_order' => 0
                ]
            );

            // ذخیره properties
            foreach ($values as $value) {
                $displayText = $value['DisplayText'] ?? null;

                if (empty($displayText)) {
                    continue;
                }

                ProductProperty::firstOrCreate(
                    [
                        'product_attribute_id' => $dbAttribute->id,
                        'name' => $displayText
                    ],
                    [
                        'slug' => $displayText,
                        'value' => $displayText,
                        'is_active' => true,
                        'is_default' => false,
                        'sort_order' => 0
                    ]
                );
            }
        }

        Log::info('Baran attributes saved to database');
    }

    /**
     * همگام‌سازی دیتابیس با WooCommerce
     */
    protected function syncDatabaseWithWooCommerce($license)
    {
        $syncedAttributes = 0;
        $syncedProperties = 0;
        $errors = [];

        // دریافت درخت کامل از WooCommerce
        $wcTreeResult = $this->getWooCommerceAttributesTree($license);

        if (!$wcTreeResult['success']) {
            throw new \Exception('خطا در دریافت درخت attributes از WooCommerce: ' . $wcTreeResult['message']);
        }

        $globalAttributes = $wcTreeResult['data']['data']['global_attributes'] ?? [];

        Log::info('WooCommerce attributes tree fetched', [
            'global_count' => count($globalAttributes)
        ]);

        // ایجاد map از WooCommerce attributes بر اساس label (نه name)
        $wcAttributesMap = [];
        $wcAttributesBySlug = []; // نقشه جدید بر اساس slug

        foreach ($globalAttributes as $attr) {
            // استفاده از label برای مقایسه با database name
            $label = $attr['label'] ?? $attr['name'];
            $labelKey = strtolower(trim($label));
            $wcAttributesMap[$labelKey] = $attr;

            // همچنین با name نیز map کن برای سازگاری
            $nameKey = strtolower(trim($attr['name']));
            if (!isset($wcAttributesMap[$nameKey])) {
                $wcAttributesMap[$nameKey] = $attr;
            }

            // نقشه بر اساس slug
            $slugKey = strtolower(trim($attr['slug']));
            $wcAttributesBySlug[$slugKey] = $attr;
        }

        // دریافت تمام attributes از دیتابیس
        $dbAttributes = ProductAttribute::where('license_id', $this->licenseId)
            ->where('is_active', true)
            ->with('properties')
            ->get();

        Log::info('Database attributes loaded', [
            'count' => $dbAttributes->count()
        ]);

        // لاگ برای دیباگ
        Log::info('WooCommerce attributes map keys', [
            'keys' => array_keys($wcAttributesMap)
        ]);

        // پردازش هر attribute از دیتابیس
        foreach ($dbAttributes as $dbAttribute) {
            try {
                $attributeName = $dbAttribute->name;
                $attributeSlug = $dbAttribute->slug;
                $nameKey = strtolower(trim($attributeName));

                Log::info('Processing database attribute', [
                    'db_name' => $attributeName,
                    'db_slug' => $attributeSlug,
                    'search_key' => $nameKey
                ]);

                // بررسی وجود در WooCommerce: ابتدا بر اساس name، سپس بر اساس slug
                $wcAttribute = $wcAttributesMap[$nameKey] ?? null;
                $wcAttributeBySlug = null;

                // اگر با name پیدا نشد، بر اساس slug جستجو کن
                if (!$wcAttribute && !empty($attributeSlug)) {
                    $slugKey = strtolower(trim($attributeSlug));
                    $wcAttributeBySlug = $wcAttributesBySlug[$slugKey] ?? null;

                    if ($wcAttributeBySlug) {
                        Log::info('Attribute found by slug but not by name', [
                            'db_name' => $attributeName,
                            'db_slug' => $attributeSlug,
                            'wc_label' => $wcAttributeBySlug['label'] ?? $wcAttributeBySlug['name'],
                            'wc_slug' => $wcAttributeBySlug['slug']
                        ]);

                        // استفاده از attribute پیدا شده با slug
                        $wcAttribute = $wcAttributeBySlug;
                    }
                }

                if ($wcAttribute) {
                    // موجود است - بررسی slug
                    if ($wcAttribute['slug'] !== $attributeSlug) {
                        // به‌روزرسانی slug در WooCommerce
                        Log::info('Updating attribute slug in WooCommerce', [
                            'name' => $attributeName,
                            'old_slug' => $wcAttribute['slug'],
                            'new_slug' => $attributeSlug,
                            'wc_id' => $wcAttribute['id']
                        ]);

                        $updateResult = $this->updateWooCommerceAttribute($license, $wcAttribute['id'], [
                            'slug' => $attributeSlug
                        ]);

                        if (!$updateResult['success']) {
                            $errors[] = "خطا در به‌روزرسانی slug ویژگی '{$attributeName}': " . $updateResult['message'];
                            continue;
                        }

                        $wcAttribute['slug'] = $attributeSlug;
                    }

                    $syncedAttributes++;
                } else {
                    // موجود نیست - ایجاد جدید
                    Log::info('Creating attribute in WooCommerce', [
                        'name' => $attributeName,
                        'slug' => $attributeSlug
                    ]);

                    $createResult = $this->createWooCommerceAttribute($license, $attributeName, $attributeSlug);

                    if (!$createResult['success']) {
                        $errors[] = "خطا در ایجاد ویژگی '{$attributeName}' در WooCommerce: " . $createResult['message'];
                        continue;
                    }

                    $wcAttribute = $createResult['data'];
                    $syncedAttributes++;
                }

                // همگام‌سازی properties (terms)
                $propertiesResult = $this->syncAttributeProperties($license, $dbAttribute, $wcAttribute);
                $syncedProperties += $propertiesResult['synced'];
                $errors = array_merge($errors, $propertiesResult['errors']);

            } catch (\Exception $e) {
                $errors[] = "خطا در پردازش ویژگی '{$dbAttribute->name}': " . $e->getMessage();
            }
        }

        return [
            'synced_attributes' => $syncedAttributes,
            'synced_properties' => $syncedProperties,
            'errors' => $errors
        ];
    }

    /**
     * همگام‌سازی properties یک attribute
     */
    protected function syncAttributeProperties($license, $dbAttribute, $wcAttribute)
    {
        $syncedProperties = 0;
        $errors = [];

        // ایجاد map از terms موجود در WooCommerce بر اساس name
        $wcTermsMap = [];
        $wcTermsBySlug = []; // نقشه جدید بر اساس slug

        foreach ($wcAttribute['terms'] ?? [] as $term) {
            $termNameKey = strtolower(trim($term['name']));
            $wcTermsMap[$termNameKey] = $term;

            // نقشه بر اساس slug
            $termSlugKey = strtolower(trim($term['slug']));
            $wcTermsBySlug[$termSlugKey] = $term;
        }

        // پردازش هر property از دیتابیس
        foreach ($dbAttribute->properties as $dbProperty) {
            if (!$dbProperty->is_active) {
                continue;
            }

            $propertyName = $dbProperty->name;
            $propertySlug = $dbProperty->slug;
            $nameKey = strtolower(trim($propertyName));

            // بررسی وجود در WooCommerce: ابتدا بر اساس name، سپس بر اساس slug
            $wcTerm = $wcTermsMap[$nameKey] ?? null;
            $wcTermBySlug = null;

            // اگر با name پیدا نشد، بر اساس slug جستجو کن
            if (!$wcTerm && !empty($propertySlug)) {
                $slugKey = strtolower(trim($propertySlug));
                $wcTermBySlug = $wcTermsBySlug[$slugKey] ?? null;

                if ($wcTermBySlug) {
                    Log::info('Property found by slug but not by name', [
                        'db_name' => $propertyName,
                        'db_slug' => $propertySlug,
                        'wc_name' => $wcTermBySlug['name'],
                        'wc_slug' => $wcTermBySlug['slug']
                    ]);

                    // استفاده از term پیدا شده با slug
                    $wcTerm = $wcTermBySlug;
                }
            }

            if ($wcTerm) {
                // موجود است - بررسی slug
                if ($wcTerm['slug'] !== $propertySlug) {
                    // به‌روزرسانی slug در WooCommerce
                    Log::info('Updating term slug in WooCommerce', [
                        'attribute' => $dbAttribute->name,
                        'property' => $propertyName,
                        'old_slug' => $wcTerm['slug'],
                        'new_slug' => $propertySlug,
                        'wc_term_id' => $wcTerm['id']
                    ]);

                    $updateResult = $this->updateWooCommerceAttributeTerm(
                        $license,
                        $wcAttribute['id'],
                        $wcTerm['id'],
                        ['slug' => $propertySlug]
                    );

                    if (!$updateResult['success']) {
                        $errors[] = "خطا در به‌روزرسانی slug پروپرتی '{$propertyName}': " . $updateResult['message'];
                        continue;
                    }
                }

                $syncedProperties++;
            } else {
                // موجود نیست - ایجاد جدید
                Log::info('Creating term in WooCommerce', [
                    'attribute' => $dbAttribute->name,
                    'property' => $propertyName,
                    'slug' => $propertySlug
                ]);

                $createResult = $this->createWooCommerceAttributeTerm(
                    $license,
                    $wcAttribute['id'],
                    [
                        'name' => $propertyName,
                        'slug' => $propertySlug
                    ]
                );

                if (!$createResult['success']) {
                    $errors[] = "خطا در ایجاد پروپرتی '{$propertyName}': " . $createResult['message'];
                    continue;
                }

                $syncedProperties++;
            }
        }

        return [
            'synced' => $syncedProperties,
            'errors' => $errors
        ];
    }

    /**
     * ایجاد notification موفقیت
     */
    protected function createSuccessNotification($license, $syncResult)
    {
        if (!$license->user_id) {
            return;
        }

        $notificationType = count($syncResult['errors']) > 0 ? 'warning' : 'success';
        $notificationTitle = count($syncResult['errors']) > 0
            ? 'همگام‌سازی با هشدار انجام شد'
            : 'همگام‌سازی ویژگی‌ها با موفقیت انجام شد';

        $notificationMessage = "{$syncResult['synced_attributes']} ویژگی و {$syncResult['synced_properties']} پروپرتی همگام‌سازی شدند.";

        if (count($syncResult['errors']) > 0) {
            $notificationMessage .= "\n\nتعداد خطاها: " . count($syncResult['errors']);
            $notificationMessage .= "\n" . implode("\n", array_slice($syncResult['errors'], 0, 3));
            if (count($syncResult['errors']) > 3) {
                $notificationMessage .= "\n... و " . (count($syncResult['errors']) - 3) . " خطای دیگر";
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
                'synced_attributes' => $syncResult['synced_attributes'],
                'synced_properties' => $syncResult['synced_properties'],
                'errors_count' => count($syncResult['errors']),
                'errors' => array_slice($syncResult['errors'], 0, 10)
            ])
        ]);
    }

    /**
     * ایجاد notification خطا
     */
    protected function createErrorNotification($exception)
    {
        try {
            $license = License::find($this->licenseId);
            if ($license && $license->user_id) {
                Notification::create([
                    'user_id' => $license->user_id,
                    'title' => 'خطا در همگام‌سازی ویژگی‌ها',
                    'message' => 'خطا در همگام‌سازی: ' . $exception->getMessage(),
                    'type' => 'error',
                    'is_read' => false,
                    'is_active' => true,
                    'data' => json_encode([
                        'license_id' => $license->id,
                        'error' => $exception->getMessage()
                    ])
                ]);
            }
        } catch (\Exception $notifException) {
            Log::error('خطا در ایجاد notification', ['error' => $notifException->getMessage()]);
        }
    }
}
