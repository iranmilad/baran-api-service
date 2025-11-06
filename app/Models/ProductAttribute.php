<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class ProductAttribute extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_required',
        'is_active',
        'is_visible',
        'is_variation',
        'sort_order',
        'license_id'
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'is_visible' => 'boolean',
        'is_variation' => 'boolean',
        'sort_order' => 'integer'
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(ProductProperty::class)->orderBy('sort_order');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_attribute_values')
            ->withPivot('product_property_id', 'value', 'display_value', 'sort_order')
            ->withTimestamps();
    }

    /**
     * فقط ویژگی‌های فعال
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * فقط ویژگی‌های قابل مشاهده
     */
    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    /**
     * فقط ویژگی‌های متغیر
     */
    public function scopeVariation($query)
    {
        return $query->where('is_variation', true);
    }

    /**
     * مرتب سازی بر اساس sort_order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * تولید slug خودکار
     */
    public function setNameAttribute($value)
    {
        $this->attributes['name'] = $value;
        if (empty($this->attributes['slug'])) {
            $this->attributes['slug'] = Str::slug($value);
        }
    }
}
