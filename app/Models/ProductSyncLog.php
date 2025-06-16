<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSyncLog extends Model
{
    protected $fillable = [
        'isbn',
        'name',
        'price',
        'stock_quantity',
        'raw_response',
        'is_success',
        'error_message'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'raw_response' => 'array',
        'is_success' => 'boolean'
    ];
}
