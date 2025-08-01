<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

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
    \Log::info('Cron schedule executed at: ' . now());
})->everyTenMinutes();

Schedule::command('queue:work --queue=category,woocommerce,woocommerce-update,invoices,default,products,bulk-update,woocommerce-insert --stop-when-empty --max-jobs=50')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/queue_work.log'));



//    /usr/local/lsws/lsphp82/bin/php  /home/samtatech.org/public_html/server/artisan schedule:run >> /home/samtatech.org/public_html/logs/artisan_schedule.log 2>&1
