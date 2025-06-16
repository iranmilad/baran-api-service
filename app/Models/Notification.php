<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Notification extends Model
{
    protected $fillable = [
        'title',
        'message',
        'type',
        'expiry_date',
        'is_active'
    ];

    protected $casts = [
        'expiry_date' => 'datetime',
        'is_active' => 'boolean'
    ];

    public function dismissals(): HasMany
    {
        return $this->hasMany(NotificationDismissal::class);
    }

    public function isDismissed(string $licenseKey): bool
    {
        return $this->dismissals()->where('license_key', $licenseKey)->exists();
    }
}
