<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * این migration فیلد barcode را nullable می‌کند
 * محصولاتی که barcode ندارند می‌توانند با barcode = null ذخیره شوند
 * چند محصول می‌توانند barcode = null داشته باشند
 */
class MakeBarcodeNullableInProductsTable extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            // حذف index قبلی barcode
            $table->dropIndex(['barcode']);
            
            // حذف unique constraint قبلی
            $table->dropUnique(['barcode']);
        });
        
        Schema::table('products', function (Blueprint $table) {
            // تغییر barcode به nullable
            $table->string('barcode')->nullable()->change();
            
            // اضافه کردن index ساده (بدون unique)
            $table->index('barcode');
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            // حذف index
            $table->dropIndex(['barcode']);
            
            // برگرداندن به حالت قبل (not nullable و unique)
            $table->string('barcode')->nullable(false)->unique()->change();
            
            // اضافه کردن index
            $table->index('barcode');
        });
    }
}
