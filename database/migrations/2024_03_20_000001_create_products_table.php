<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductsTable extends Migration
{
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('item_name');
            $table->string('barcode')->unique();
            $table->string('item_id', 100)->index();
            $table->bigInteger('price_amount')->default(0);
            $table->bigInteger('price_after_discount')->default(0);
            $table->integer('total_count')->default(0);
            $table->string('stock_id')->nullable();
            $table->string('parent_id', 100)->nullable()->index();
            $table->boolean('is_variant')->default(false);
            $table->foreignId('license_id')->constrained()->onDelete('cascade');
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();

            $table->index('barcode');
            $table->index('license_id');
            $table->index('stock_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('products');
    }
}
