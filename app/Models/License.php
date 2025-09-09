<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class License extends Authenticatable implements JWTSubject
{
    const ACCOUNT_TYPE_BASIC = 'basic';
    const ACCOUNT_TYPE_STANDARD = 'standard';
    const ACCOUNT_TYPE_PREMIUM = 'premium';
    const ACCOUNT_TYPE_ENTERPRISE = 'enterprise';
    const ACCOUNT_TYPE_ULTIMATE = 'ultimate';

    protected $fillable = [
        'key',
        'website_url',
        'status',
        'expires_at',
        'user_id',
        'account_type',
        'token',
        'token_expires_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'token_expires_at' => 'datetime',
        'status' => 'string',
        'account_type' => 'string'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function woocommerceApiKey(): HasOne
    {
        return $this->hasOne(WooCommerceApiKey::class);
    }

    public function userSetting(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    public function errorLogs(): HasMany
    {
        return $this->hasMany(ErrorLog::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function warehouseCategories(): HasMany
    {
        return $this->hasMany(LicenseWarehouseCategory::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expires_at > now();
    }

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [
            'website_url' => $this->website_url,
            'license_id' => $this->id,
            'user_id' => $this->user_id,
            'account_type' => $this->account_type
        ];
    }

    /**
     * بررسی اعتبار توکن
     *
     * @return bool
     */
    public function isTokenValid(): bool
    {
        if (empty($this->token)) {
            return false;
        }

        if ($this->token_expires_at && $this->token_expires_at <= now()) {
            return false;
        }

        return true;
    }

    /**
     * بروزرسانی توکن
     *
     * @param string $token
     * @param \DateTime|null $expiresAt
     * @return bool
     */
    public function updateToken(string $token, $expiresAt = null): bool
    {
        $this->token = $token;
        $this->token_expires_at = $expiresAt;
        
        return $this->save();
    }

    /**
     * حذف توکن
     *
     * @return bool
     */
    public function clearToken(): bool
    {
        $this->token = null;
        $this->token_expires_at = null;
        
        return $this->save();
    }
}
