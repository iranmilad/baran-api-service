<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErrorLog extends Model
{
    protected $fillable = [
        'license_id',
        'type',
        'message',
        'context'
    ];

    protected $casts = [
        'context' => 'array'
    ];

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public static function cleanup()
    {
        // پاکسازی لاگ‌های قدیمی‌تر از 7 روز
        self::where('created_at', '<', now()->subDays(7))->delete();
    }
}
