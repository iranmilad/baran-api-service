<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'mobile',
        'password',
        'is_active',
        'mongo_connection_string',
        'mongo_username',
        'mongo_password',
        'api_webservice',
        'api_username',
        'api_password',
        'api_storeId',
        'api_userId',
        'warehouse_api_url',
        'warehouse_api_username',
        'warehouse_api_password'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'mongo_password',
        'api_password',
        'warehouse_api_password'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
    ];

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }


    /**
     * رابطه یک به چند با لایسنس‌ها
     */
    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    /**
     * ارتباط با جدول permissions از طریق جدول user_permissions
     */
    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'user_permissions');
    }

    /**
     * بررسی اینکه آیا کاربر دسترسی خاصی را دارد یا نه
     */
    public function hasPermission($permissionSlug)
    {
        return $this->permissions()
            ->where('slug', $permissionSlug)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * دریافت تمام دسترسی‌های کاربر
     */
    public function getPermissions()
    {
        return $this->permissions()
            ->where('is_active', true)
            ->pluck('slug')
            ->toArray();
    }

    public function getDaysSinceRegistrationAttribute()
    {
        $registrationDate = Carbon::parse($this->expireActiveLicense);
        $currentDate = Carbon::now();
        $diffInDays = $currentDate->diffInDays($registrationDate);

        return $diffInDays;
    }

    /**
     * فعال کردن کاربر
     */
    public function activate()
    {
        $this->update(['is_active' => true]);
        return $this;
    }

    /**
     * غیرفعال کردن کاربر
     */
    public function deactivate()
    {
        $this->update(['is_active' => false]);
        return $this;
    }

    /**
     * بررسی فعال بودن کاربر
     */
    public function isActive()
    {
        return $this->is_active;
    }

    /**
     * بررسی غیرفعال بودن کاربر
     */
    public function isInactive()
    {
        return !$this->is_active;
    }

    /**
     * Scope برای دریافت کاربران فعال
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope برای دریافت کاربران غیرفعال
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }



}
