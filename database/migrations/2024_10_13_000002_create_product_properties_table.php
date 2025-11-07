<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductPropertiesTable extends Migration
{
    public function up()
    {
        Schema::create('product_properties', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100); // نام پروپرتی (مثلاً "قرمز", "XL")
            $table->string('slug', 100)->index(); // slug پروپرتی
            $table->text('value')->nullable(); // مقدار پروپرتی (مثلاً "#FF0000" برای رنگ قرمز)
            $table->text('description')->nullable(); // توضیحات
            $table->boolean('is_active')->default(true); // فعال/غیرفعال
            $table->integer('sort_order')->default(0); // ترتیب نمایش
            $table->foreignId('product_attribute_id')->constrained()->onDelete('cascade'); // متعلق به کدام ویژگی
            $table->timestamps();

            $table->index(['product_attribute_id', 'sort_order']);
            $table->index(['product_attribute_id', 'is_active']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_properties');
    }
}
