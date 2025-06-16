<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSetting extends Model
{
    protected $fillable = [
        'license_id',
        'enable_price_update',
        'enable_stock_update',
        'enable_name_update',
        'enable_new_product',
        'enable_invoice',
        'enable_cart_sync',
        'payment_gateways',
        'invoice_settings',
        'rain_sale_price_unit',
        'woocommerce_price_unit'
    ];

    protected $casts = [
        'enable_price_update' => 'boolean',
        'enable_stock_update' => 'boolean',
        'enable_name_update' => 'boolean',
        'enable_new_product' => 'boolean',
        'enable_invoice' => 'boolean',
        'enable_cart_sync' => 'boolean',
        'payment_gateways' => 'array',
        'invoice_settings' => 'array'
    ];

    /**
     * رابطه با لایسنس
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
