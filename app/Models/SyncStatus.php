<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncStatus extends Model
{
    protected $fillable = [
        'license_id',
        'file_name',
        'status',
        'total_records',
        'processed_records',
        'updated_records',
        'new_records',
        'error_message',
        'started_at',
        'completed_at'
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime'
    ];

    public function license()
    {
        return $this->belongsTo(License::class);
    }
}
