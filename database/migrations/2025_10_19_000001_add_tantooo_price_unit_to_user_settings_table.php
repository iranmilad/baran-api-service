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
        Schema::table('user_settings', function (Blueprint $table) {
            // اضافه کردن فیلد tantooo_price_unit برای تبدیل واحد قیمت
            // مقادیر: 'rial' یا 'toman' (پیش‌فرض: 'toman')
            $table->string('tantooo_price_unit')->default('toman')->after('woocommerce_price_unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn('tantooo_price_unit');
        });
    }
};
