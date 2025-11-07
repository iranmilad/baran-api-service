<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateProductAttributeValuesTable extends Migration
{
    public function up()
    {
        Schema::create('product_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_attribute_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_property_id')->constrained()->onDelete('cascade'); // کدام پروپرتی انتخاب شده
            $table->text('value')->nullable(); // مقدار اضافی در صورت نیاز
            $table->text('display_value')->nullable(); // نمایش سفارشی برای کاربر
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'product_attribute_id']);
            $table->unique(['product_id', 'product_attribute_id']); // هر محصول فقط یک بار با هر ویژگی
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_attribute_values');
    }
}
