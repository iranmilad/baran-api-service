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
        Schema::table('categories', function (Blueprint $table) {
            $table->string('remote_id')->nullable()->comment('شناسه دسته‌بندی در فروشگاه (WooCommerce/Tantooo)');
            $table->text('warehouse_parameter')->nullable()->comment('پارامترهای انبار برای دسته‌بندی');
            
            // ایندکس برای جستجوی سریع
            $table->index(['license_id', 'remote_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['license_id', 'remote_id']);
            $table->dropColumn(['remote_id', 'warehouse_parameter']);
        });
    }
};
