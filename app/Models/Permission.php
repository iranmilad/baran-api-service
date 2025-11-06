<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'group',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * ارتباط با جدول users از طریق جدول user_permissions
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_permissions');
    }

    /**
     * بررسی اینکه آیا کاربر این دسترسی را دارد یا نه
     */
    public static function userHasPermission($userId, $permissionSlug)
    {
        return self::whereHas('users', function($query) use ($userId) {
            $query->where('user_id', $userId);
        })
        ->where('slug', $permissionSlug)
        ->where('is_active', true)
        ->exists();
    }

    /**
     * دریافت دسترسی‌های کاربر
     */
    public static function getUserPermissions($userId)
    {
        return self::whereHas('users', function($query) use ($userId) {
            $query->where('user_id', $userId);
        })
        ->where('is_active', true)
        ->pluck('slug')
        ->toArray();
    }
}
