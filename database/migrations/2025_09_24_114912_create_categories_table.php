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
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('نام دسته‌بندی');
            $table->string('code')->nullable()->comment('کد انبار');
            $table->string('warehouse_reference')->nullable()->comment('مرجع فاکتور انبار');
            $table->text('description')->nullable()->comment('توضیحات');
            $table->unsignedBigInteger('parent_id')->nullable()->comment('دسته والد (برای زیردسته‌ها)');
            $table->unsignedBigInteger('license_id')->comment('شناسه لایسنس');
            $table->boolean('is_active')->default(true)->comment('وضعیت فعال/غیرفعال');
            $table->integer('sort_order')->default(0)->comment('ترتیب نمایش');
            $table->timestamps();

            // ایندکس‌ها
            $table->index(['license_id', 'is_active']);
            $table->index(['parent_id']);
            $table->index(['license_id', 'parent_id']);
            $table->unique(['license_id', 'code'])->comment('کد انبار باید در هر لایسنس یکتا باشد');

            // Foreign Key constraints
            $table->foreign('license_id')->references('id')->on('licenses')->onDelete('cascade');
            $table->foreign('parent_id')->references('id')->on('categories')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
