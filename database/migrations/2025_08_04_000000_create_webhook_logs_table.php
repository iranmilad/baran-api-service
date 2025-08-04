<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('license_id');
            $table->timestamp('logged_at');
            $table->json('payload');
            $table->timestamps();

            $table->foreign('license_id')->references('id')->on('licenses')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('webhook_logs');
    }
};
