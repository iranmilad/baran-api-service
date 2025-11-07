<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductAttributesTable extends Migration
{
    public function up()
    {
        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100)->index();
            $table->text('description')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_visible')->default(true);
            $table->boolean('is_variation')->default(false); // آیا برای ایجاد گونه استفاده می‌شود
            $table->integer('sort_order')->default(0);
            $table->foreignId('license_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->index(['license_id', 'is_active']);
            $table->index(['license_id', 'is_variation']);
            $table->unique(['license_id', 'slug']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_attributes');
    }
}
