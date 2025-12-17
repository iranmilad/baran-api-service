<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    protected $fillable = [
        'barcode',
        'item_name',
        'item_id',
        'price_amount',
        'price_after_discount',
        'total_count',
        'stock_id',
        'department_name',
        'parent_id',
        'is_variant',
        'variant_data',
        'license_id',
        'last_sync_at'
    ];

    protected $casts = [
        'price_amount' => 'integer',
        'price_after_discount' => 'integer',
        'total_count' => 'integer',
        'is_variant' => 'boolean',
        'variant_data' => 'array',
        'last_sync_at' => 'datetime',
        'department_name' => 'string',

    ];

    /**
     * Mutator برای تبدیل stock_id به حروف کوچک قبل از ذخیره
     */
    public function setStockIdAttribute($value)
    {
        $this->attributes['stock_id'] = !empty($value) ? strtolower(trim((string)$value)) : null;
    }

    /**
     * Accessor برای تبدیل stock_id به حروف کوچک هنگام خواندن
     */
    public function getStockIdAttribute($value)
    {
        return !empty($value) ? strtolower(trim((string)$value)) : null;
    }

    public function parent(): BelongsTo
    {
        // رابطه بدون کلید خارجی (parent_id ممکن است null باشد)
        // اگر parent_id null بود، مقدار null برگردانده می‌شود
        return $this->belongsTo(Product::class, 'parent_id', 'item_id')
            ->withDefault();
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_id', 'item_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_id', 'item_id');
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function attributes(): BelongsToMany
    {
        return $this->belongsToMany(ProductAttribute::class, 'product_attribute_values')
            ->withPivot('product_property_id', 'value', 'display_value', 'sort_order')
            ->withTimestamps();
    }

    /**
     * دریافت تمام ویژگی‌های محصول با مقادیر آن‌ها
     */
    public function getAttributesWithValues()
    {
        return $this->attributeValues()
            ->with(['attribute', 'property'])
            ->whereHas('attribute', function($query) {
                $query->where('is_active', true);
            })
            ->join('product_attributes', 'product_attribute_values.product_attribute_id', '=', 'product_attributes.id')
            ->orderBy('product_attributes.sort_order')
            ->get();
    }

    /**
     * دریافت مقدار یک ویژگی خاص محصول
     */
    public function getProductAttributeValue($attributeSlug)
    {
        $attributeValue = $this->attributeValues()
            ->whereHas('attribute', function($query) use ($attributeSlug) {
                $query->where('slug', $attributeSlug)->where('is_active', true);
            })
            ->first();

        return $attributeValue ? $attributeValue->getFinalDisplayValue() : null;
    }

    /**
     * تنظیم مقدار برای یک ویژگی
     */
    public function setAttributeValue($attributeId, $propertyId, $displayValue = null)
    {
        // پیدا کردن یا ایجاد رکورد
        $attributeValue = $this->attributeValues()
            ->where('product_attribute_id', $attributeId)
            ->first();

        if (!$attributeValue) {
            $attributeValue = new ProductAttributeValue([
                'product_id' => $this->id,
                'product_attribute_id' => $attributeId,
                'product_property_id' => $propertyId,
                'display_value' => $displayValue
            ]);
        } else {
            $attributeValue->product_property_id = $propertyId;
            $attributeValue->display_value = $displayValue;
        }

        $attributeValue->save();

        return $attributeValue;
    }

    /**
     * حذف ویژگی از محصول
     */
    public function removeAttribute($attributeId)
    {
        return $this->attributeValues()
            ->where('product_attribute_id', $attributeId)
            ->delete();
    }

}
