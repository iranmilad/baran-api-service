<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('error_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained()->onDelete('cascade');
            $table->string('type')->comment('نوع خطا: sync, api, plugin');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();

            // ایجاد ایندکس برای پاکسازی خودکار لاگ‌های قدیمی
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('error_logs');
    }
};
