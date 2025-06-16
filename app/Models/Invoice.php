<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'license_key',
        'woocommerce_order_id',
        'customer_mobile',
        'status',
        'customer_id',
        'order_data',
        'rain_sale_response',
        'is_synced',
        'sync_error'
    ];

    protected $casts = [
        'order_data' => 'array',
        'rain_sale_response' => 'array',
        'is_synced' => 'boolean'
    ];
}
