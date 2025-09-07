<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    Log::info('Cron schedule executed at: ' . now());
})->everyTenMinutes();

Schedule::command('queue:work --queue=invoices,products,bulk-update,empty-unique-ids,unique-ids-sync,category,woocommerce,woocommerce-update,woocommerce-sync,woocommerce-insert,product-processing,product-coordination,default --tries=3 --max-jobs=100 --timeout=900 --stop-when-empty')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/queue_work.log'));



// Restart workers هر 30 دقیقه برای جلوگیری از memory leak
Schedule::command('queue:restart')
    ->everyThirtyMinutes()
    ->onOneServer();

// پاک کردن failed jobs قدیمی‌تر از 7 روز
Schedule::command('queue:flush')
    ->weekly()
    ->sundays()
    ->at('02:00')
    ->onOneServer();

// Log پاک کردن برای monitoring
Schedule::call(function () {
    $failedJobs = DB::table('failed_jobs')->count();
    $totalJobs = DB::table('jobs')->count();

    Log::info('Queue monitoring report', [
        'failed_jobs_count' => $failedJobs,
        'pending_jobs_count' => $totalJobs,
        'timestamp' => now()->toDateTimeString()
    ]);
})->hourly();

// بررسی و پردازش فاکتورهای pending هر 15 دقیقه
Schedule::call(function () {
    $pendingInvoices = DB::table('invoices')
        ->where('is_synced', false)
        ->whereNull('sync_error')
        ->count();

    if ($pendingInvoices > 0) {
        Log::info('Found pending invoices for processing', [
            'pending_count' => $pendingInvoices,
            'timestamp' => now()->toDateTimeString()
        ]);
    }
})->everyFifteenMinutes();



//    /usr/local/lsws/lsphp82/bin/php  /home/samtatech.org/public_html/server/artisan schedule:run >> /home/samtatech.org/public_html/server/logs/artisan_schedule.log 2>&1
