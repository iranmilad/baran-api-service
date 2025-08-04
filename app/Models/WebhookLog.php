<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    protected $fillable = [
        'license_id',
        'logged_at',
        'payload'
    ];

    protected $casts = [
        'logged_at' => 'datetime',
        'payload' => 'array'
    ];
}
