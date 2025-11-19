<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class RemoveParentIdForeignKeyFromProducts extends Migration
{
    /**
     * اجرای مهاجرت
     */
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            // حذف کلید خارجی اگر وجود داشته باشد
            try {
                $table->dropForeign(['parent_id']);
            } catch (\Exception $e) {
                // کلید خارجی وجود ندارد، اشکالی ندارد
                Log::info('کلید خارجی parent_id برای حذف پیدا نشد: ' . $e->getMessage());
            }
        });
    }

    /**
     * برگرداندن مهاجرت
     */
    public function down()
    {
        // این مهاجرت برگشت‌پذیر نیست
        // چون ممکن است داده‌های بدون parent_id وجود داشته باشد
    }
}
