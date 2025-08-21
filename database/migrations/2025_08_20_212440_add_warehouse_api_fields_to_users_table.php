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
        Schema::table('users', function (Blueprint $table) {
            $table->string('warehouse_api_url')->nullable()->after('api_userId');
            $table->string('warehouse_api_username')->nullable()->after('warehouse_api_url');
            $table->string('warehouse_api_password')->nullable()->after('warehouse_api_username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['warehouse_api_url', 'warehouse_api_username', 'warehouse_api_password']);
        });
    }
};
