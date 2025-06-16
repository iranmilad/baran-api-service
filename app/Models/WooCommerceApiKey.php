<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WooCommerceApiKey extends Model
{
    protected $table = 'woocommerce_api_keys';

    protected $fillable = [
        'license_id',
        'api_key',
        'api_secret'
    ];

    protected $hidden = [
        'api_secret'
    ];

    /**
     * رابطه با لایسنس
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
