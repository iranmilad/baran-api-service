<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->enum('shipping_cost_method', ['product', 'expense'])->default('expense')->after('woocommerce_price_unit');
            $table->string('shipping_product_unique_id', 100)->nullable()->after('shipping_cost_method');
        });
    }

    public function down()
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn(['shipping_cost_method', 'shipping_product_unique_id']);
        });
    }
};
