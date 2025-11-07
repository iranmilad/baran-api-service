<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // اضافه کردن permissions برای مدیریت چند انباره
        $permissions = [
            [
                'name' => 'مدیریت موجودی',
                'slug' => 'inventory-management',
                'description' => 'دسترسی به صفحه مدیریت موجودی انبارها',
                'group' => 'warehouse',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'مدیریت قیمت',
                'slug' => 'price-management',
                'description' => 'دسترسی به صفحه مدیریت قیمت محصولات',
                'group' => 'warehouse',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'تنظیم انبار',
                'slug' => 'warehouse-settings',
                'description' => 'دسترسی به صفحه تنظیمات انبارها',
                'group' => 'warehouse',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('permissions')->insert($permissions);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // حذف permissions مدیریت چند انباره
        DB::table('permissions')->whereIn('slug', [
            'inventory-management',
            'price-management',
            'warehouse-settings',
        ])->delete();
    }
};
