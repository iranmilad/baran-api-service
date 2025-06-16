<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('product_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('isbn');
            $table->string('name')->nullable();
            $table->decimal('price', 20, 2)->nullable();
            $table->integer('stock_quantity')->nullable();
            $table->json('raw_response')->nullable();
            $table->boolean('is_success')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('isbn');
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_sync_logs');
    }
};
