<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\License;

class AddAccountTypeToLicenses extends Migration
{
    public function up()
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->string('account_type')->default(License::ACCOUNT_TYPE_BASIC)->after('status');
        });
    }

    public function down()
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropColumn('account_type');
        });
    }
}
