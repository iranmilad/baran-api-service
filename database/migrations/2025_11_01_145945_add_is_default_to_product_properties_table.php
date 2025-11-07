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
        Schema::table('product_properties', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_active')->comment('آیا این پروپرتی به محصول parent (مادر) منتسب شود؟');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_properties', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
