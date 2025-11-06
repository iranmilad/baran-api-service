<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttributeValue extends Model
{
    protected $fillable = [
        'product_id',
        'product_attribute_id',
        'product_property_id',
        'value',
        'display_value',
        'sort_order'
    ];

    protected $casts = [
        'sort_order' => 'integer'
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'product_attribute_id');
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(ProductProperty::class, 'product_property_id');
    }



    /**
     * دریافت مقدار نهایی برای نمایش
     */
    public function getFinalDisplayValue()
    {
        // اگر display_value تنظیم شده است، آن را برگردان
        if (!empty($this->attributes['display_value'])) {
            return $this->attributes['display_value'];
        }

        // اگر property وجود دارد، مقدار آن را برگردان
        if ($this->property && $this->property->value) {
            return $this->property->value;
        }

        // در غیر این صورت مقدار پیش‌فرض
        return $this->attributes['value'] ?? null;
    }

    /**
     * مرتب سازی بر اساس sort_order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
