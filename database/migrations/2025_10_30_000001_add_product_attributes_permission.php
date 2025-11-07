<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // اضافه کردن permission جدید برای ویژگی محصولات
        DB::table('permissions')->insert([
            'name' => 'ویژگی محصولات',
            'slug' => 'product-attributes',
            'description' => 'دسترسی به مدیریت ویژگی‌ها و پروپرتی‌های محصولات',
            'group' => 'محصولات',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('permissions')->where('slug', 'product-attributes')->delete();
    }
};
