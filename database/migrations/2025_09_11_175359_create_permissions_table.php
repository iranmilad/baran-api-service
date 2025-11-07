<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique(); // نام دسترسی
            $table->string('slug')->unique(); // شناسه یکتا برای دسترسی
            $table->string('description')->nullable(); // توضیحات دسترسی
            $table->string('group')->nullable(); // گروه‌بندی دسترسی‌ها
            $table->boolean('is_active')->default(true); // وضعیت فعال/غیرفعال
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
