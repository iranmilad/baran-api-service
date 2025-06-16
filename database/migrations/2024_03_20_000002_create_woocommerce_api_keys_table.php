<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('woocommerce_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->unique()->constrained()->onDelete('cascade');
            $table->string('api_key');
            $table->string('api_secret');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woocommerce_api_keys');
    }
};
