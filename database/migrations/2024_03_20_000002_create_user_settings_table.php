<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->unique()->constrained()->onDelete('cascade');
            $table->boolean('enable_price_update')->default(true);
            $table->boolean('enable_stock_update')->default(true);
            $table->boolean('enable_name_update')->default(true);
            $table->boolean('enable_new_product')->default(true);
            $table->boolean('enable_invoice')->default(false);
            $table->boolean('enable_cart_sync')->default(false);
            $table->json('payment_gateways')->nullable();
            $table->json('invoice_settings')->nullable();
            $table->enum('rain_sale_price_unit', ['rial', 'toman'])->default('toman');
            $table->enum('woocommerce_price_unit', ['rial', 'toman'])->default('toman');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_settings');
    }
};
