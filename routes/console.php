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

// Command برای retry کردن جاب‌های ناموفق
Artisan::command('queue:retry-failed-now', function () {
    $limit = $this->ask('تعداد جاب‌هایی که می‌خواهید retry کنید؟', 50);

    $this->call('queue:retry-failed', [
        '--limit' => $limit
    ]);
})->purpose('مجدد اجرای جاب‌های ناموفق بصورت فوری');

// Command برای retry کردن جاب‌های ناموفق صف specific
Artisan::command('queue:retry-queue {queue : نام صف}', function () {
    $queue = $this->argument('queue');
    $limit = $this->ask('تعداد جاب‌هایی که می‌خواهید retry کنید؟', 50);

    $this->call('queue:retry-failed', [
        '--queue' => $queue,
        '--limit' => $limit
    ]);
})->purpose('مجدد اجرای جاب‌های ناموفق برای یک صف خاص');

Schedule::call(function () {
    Log::info('Cron schedule executed at: ' . now());
})->everyTenMinutes();

Schedule::command('queue:work --queue=products,product-changes,product-sync-all,product-retry,product-inserts-implicit,product-orphan-variants,empty-unique-ids,product-processing,invoices,bulk-update,unique-ids-sync,category,product-coordination,woocommerce,woocommerce-sync,woocommerce-insert,woocommerce-update,default --tries=3 --max-jobs=100 --timeout=900 --stop-when-empty')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/queue_work.log'));



// Restart workers هر 30 دقیقه برای جلوگیری از memory leak
Schedule::command('queue:restart')
    ->everyThirtyMinutes()
    ->onOneServer();

// مجدد اجرای جاب‌های ناموفق هر 10 دقیقه
Schedule::command('queue:retry-failed --limit=100')
    ->everyFiveMinutes()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/retry_failed_jobs.log'));

// مجدد اجرای جاب‌های ناموفق صف woocommerce-sync هر 5 دقیقه
Schedule::command('queue:retry-failed --queue=woocommerce-sync --limit=50')
    ->everyFiveMinutes()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/retry_woocommerce_sync.log'));

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



//    /usr/local/lsws/lsphp82/bin/php  /home/samtatech.org/public_html/server/artisan schedule:run >> /home/samtatech.org/public_html/logs/artisan_schedule.log 2>&1
