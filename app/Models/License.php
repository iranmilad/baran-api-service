<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class License extends Model
{
    const ACCOUNT_TYPE_BASIC = 'basic';
    const ACCOUNT_TYPE_STANDARD = 'standard';
    const ACCOUNT_TYPE_PREMIUM = 'premium';
    const ACCOUNT_TYPE_ENTERPRISE = 'enterprise';
    const ACCOUNT_TYPE_ULTIMATE = 'ultimate';

    // انواع سرویس‌های وب
    const WEB_SERVICE_WORDPRESS = 'WordPress';
    const WEB_SERVICE_TANTOOO = 'Tantooo';

    protected $fillable = [
        'key',
        'website_url',
        'status',
        'expires_at',
        'user_id',
        'account_type',
        'api_token',
        'token_expires_at',
        'web_service_type'
    ];

    protected $hidden = [
        'api_token'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'token_expires_at' => 'datetime',
        'status' => 'string',
        'account_type' => 'string',
        'web_service_type' => 'string'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function woocommerceApiKey(): HasOne
    {
        return $this->hasOne(WooCommerceApiKey::class);
    }

    public function userSetting(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    public function errorLogs(): HasMany
    {
        return $this->hasMany(ErrorLog::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function warehouseCategories(): HasMany
    {
        return $this->hasMany(LicenseWarehouseCategory::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at > now();
    }

    /**
     * لیست انواع سرویس‌های وب موجود
     */
    public static function getAvailableWebServices(): array
    {
        return [
            self::WEB_SERVICE_WORDPRESS => 'WordPress (شامل WooCommerce)',
            self::WEB_SERVICE_TANTOOO => 'Tantooo',
        ];
    }

    /**
     * دریافت نام trait مناسب برای سرویس وب
     */
    public function getWebServiceTraitName(): string
    {
        return $this->web_service_type ?: self::WEB_SERVICE_WORDPRESS;
    }

    /**
     * بررسی نوع سرویس وب
     */
    public function isWebServiceType(string $type): bool
    {
        return $this->web_service_type === $type;
    }

    /**
     * دسته‌بندی‌های مرتبط با این لایسنس
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class)->orderBy('sort_order');
    }

    /**
     * دسته‌بندی‌های اصلی (بدون والد)
     */
    public function mainCategories(): HasMany
    {
        return $this->hasMany(Category::class)->whereNull('parent_id')->orderBy('sort_order');
    }

    /**
     * دسته‌بندی‌های فعال
     */
    public function activeCategories(): HasMany
    {
        return $this->hasMany(Category::class)->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * ویژگی‌های محصولات
     */
    public function productAttributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class);
    }

    /**
     * ویژگی‌های فعال محصولات
     */
    public function activeProductAttributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class)->where('is_active', true)->orderBy('sort_order');
    }

    /**
     * ویژگی‌های قابل استفاده برای متغیر
     */
    public function variationAttributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class)
            ->where('is_active', true)
            ->where('is_variation', true)
            ->orderBy('sort_order');
    }

}
