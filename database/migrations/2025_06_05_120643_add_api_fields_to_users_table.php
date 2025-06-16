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
            $table->string('api_webservice')->nullable()->after('mongo_password');
            $table->string('api_username')->nullable()->after('api_webservice');
            $table->string('api_password')->nullable()->after('api_username');
            $table->string('api_storeId')->nullable()->after('api_password');
            $table->string('api_userId')->nullable()->after('api_storeId');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'api_webservice',
                'api_username',
                'api_password',
                'api_storeId',
                'api_userId'
            ]);
        });
    }
};
