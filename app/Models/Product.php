<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_id');
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

}
