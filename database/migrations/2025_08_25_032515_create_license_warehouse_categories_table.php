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
        Schema::create('license_warehouse_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('license_id');
            $table->string('category_name');
            $table->json('warehouse_codes'); // آرایه کدهای انبار
            $table->timestamps();

            $table->foreign('license_id')->references('id')->on('licenses')->onDelete('cascade');
            $table->unique(['license_id', 'category_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('license_warehouse_categories');
    }
};
