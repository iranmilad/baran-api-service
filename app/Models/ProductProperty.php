<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ProductProperty extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'value',
        'description',
        'is_active',
        'is_default',
        'sort_order',
        'product_attribute_id'
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'is_default' => 'boolean'
    ];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'product_attribute_id');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
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
            // برای فارسی: فقط فاصه‌ها را با - جایگزین کن
            $this->attributes['slug'] = str_replace(' ', '-', trim($value));
        }
    }

    /**
     * فقط پروپرتی‌های فعال
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
