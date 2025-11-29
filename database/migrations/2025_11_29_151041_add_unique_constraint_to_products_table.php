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
        Schema::table('products', function (Blueprint $table) {
            // اضافه کردن کلید یکتا برای ترکیب item_id, stock_id, license_id
            $table->unique(['item_id', 'stock_id', 'license_id'], 'products_item_stock_license_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // حذف کلید یکتا
            $table->dropUnique('products_item_stock_license_unique');
        });
    }
};
