<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'warehouse_reference',
        'warehouse_parameter',
        'description',
        'parent_id',
        'license_id',
        'remote_id',
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer'
    ];

    /**
     * دسته والد
     * استفاده از withDefault() برای جلوگیری از خطا در صورت نبودن دسته والد
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id')
            ->withDefault();
    }

    /**
     * زیردسته‌ها
     */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order');
    }

    /**
     * همه زیردسته‌ها (بازگشتی)
     */
    public function allChildren(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->with('allChildren');
    }

    /**
     * لایسنس مرتبط
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    /**
     * Scope: دسته‌های اصلی (بدون والد)
     */
    public function scopeMain($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope: دسته‌های فعال
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: برای لایسنس خاص
     */
    public function scopeForLicense($query, $licenseId)
    {
        return $query->where('license_id', $licenseId);
    }

    /**
     * آیا دسته والد است؟
     */
    public function isParent(): bool
    {
        return $this->children()->exists();
    }

    /**
     * آیا زیردسته است؟
     */
    public function isChild(): bool
    {
        return !is_null($this->parent_id);
    }

    /**
     * دریافت مسیر کامل دسته (والد > فرزند)
     */
    public function getFullPathAttribute(): string
    {
        $path = [];
        $category = $this;

        while ($category) {
            array_unshift($path, $category->name);
            $category = $category->parent;
        }

        return implode(' > ', $path);
    }

    /**
     * دریافت تعداد زیردسته‌ها
     */
    public function getChildrenCountAttribute(): int
    {
        return $this->children()->count();
    }

    /**
     * دریافت همه والدین (تا ریشه)
     */
    public function getAncestors(): array
    {
        $ancestors = [];
        $parent = $this->parent;

        while ($parent) {
            array_unshift($ancestors, $parent);
            $parent = $parent->parent;
        }

        return $ancestors;
    }
}
