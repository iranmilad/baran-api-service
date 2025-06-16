<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Version extends Model
{
    protected $fillable = [
        'version',
        'download_url',
        'changelog'
    ];

    protected $casts = [
        'changelog' => 'array'
    ];
}
