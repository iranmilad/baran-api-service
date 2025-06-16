<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('license_key');
            $table->string('woocommerce_order_id');
            $table->string('customer_mobile')->nullable();
            $table->string('customer_id')->nullable();
            $table->json('order_data');
            $table->json('rain_sale_response')->nullable();
            $table->boolean('is_synced')->default(false);
            $table->string('sync_error')->nullable();
            $table->timestamps();

            $table->index('license_key');
            $table->index('woocommerce_order_id');
            $table->index('is_synced');
        });
    }

    public function down()
    {
        Schema::dropIfExists('invoices');
    }
};
