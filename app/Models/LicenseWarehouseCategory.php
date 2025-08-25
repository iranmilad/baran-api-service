<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseWarehouseCategory extends Model
{
    protected $fillable = [
        'license_id',
        'category_name', 
        'warehouse_codes' // حالا شامل stockID ها می‌شود
    ];

    protected $casts = [
        'warehouse_codes' => 'array'
    ];

    /**
     * ارتباط با لایسنس
     */
    public function license()
    {
        return $this->belongsTo(License::class);
    }
}
