<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// Development utility routes (remove in production!)
use Illuminate\Support\Facades\Artisan;

Route::get('/dev/migrate', function () {
    Artisan::call('migrate');
    return 'Migrations refreshed!';
});

Route::get('/dev/cache-clear', function () {
    Artisan::call('cache:clear');
    Artisan::call('config:clear');
    Artisan::call('route:clear');
    return 'Cache, config, and route cleared!';
});

Route::get('/dev/seed', function () {
    Artisan::call('db:seed');
    return 'Database seeded!';
});
Route::get('/dev/migratefresh', function () {
    Artisan::call('migrate:fresh');
    return 'Migrations refreshed!';
});
