<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ErrorLogController;
use App\Http\Controllers\WooCommerce\InvoiceController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PriceUnitSettingController;
use App\Http\Controllers\WooCommerce\ProductController;
use App\Http\Controllers\WooCommerce\ProductStockController;
use App\Http\Controllers\WooCommerce\ProductSyncController;
use App\Http\Controllers\WooCommerce\SyncController;
use App\Http\Controllers\UserSettingController;
use App\Http\Controllers\VersionController;
use App\Http\Controllers\WooCommerce\WebhookController;
use App\Http\Controllers\WooCommerce\WooCommerceApiKeyController;
use App\Http\Controllers\LicenseWarehouseCategoryController;
use App\Http\Controllers\MongoDataController;
use App\Http\Controllers\Tantooo\TantoooApiKeyController;
use App\Http\Controllers\Tantooo\TantoooProductController;
use App\Http\Controllers\Tantooo\TantoooSyncController;
use App\Http\Controllers\Tantooo\TantoooDataController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/
Route::get('/',function(){
    return "Test api";
});

// Authentication routes
Route::prefix('v1')->group(function () {
    // Public routes
    Route::post('/login', [AuthController::class, 'login']);

    // User information
    Route::get('/me', [AuthController::class, 'me'])->middleware('jwt.auth');

    // Check plugin version
    Route::post('/check-version', [VersionController::class, 'check'])->middleware('jwt.auth');

    // WooCommerce related routes
    Route::prefix('woocommerce')->group(function () {
        // Register WooCommerce API key
        Route::post('/register-api-key', [WooCommerceApiKeyController::class, 'store']);

        // Validate WooCommerce API key
        Route::post('/validate-api-key', [WooCommerceApiKeyController::class, 'validate']);

        // Sync settings
        Route::post('/sync-settings', [SyncController::class, 'syncSettings']);
        Route::post('/trigger-sync', [SyncController::class, 'triggerSync']);

        // WooCommerce Webhook routes
        Route::post('/webhook/product-changes', [WebhookController::class, 'handleProductChanges']);

        // WooCommerce Invoice routes
        Route::post('/invoices/webhook', [InvoiceController::class, 'handleWebhook']);


        // WooCommerce Product routes
        Route::prefix('products')->group(function () {
            // بروزرسانی موجودی ووکامرس بر اساس همه دسته‌بندی‌ها (Job)
            Route::post('/update-stock-all-categories', [ProductStockController::class, 'updateWooCommerceStockAllCategories']);

            // Update products in WooCommerce
            Route::post('/sync', [ProductController::class, 'sync']);

            // Bulk update products in WooCommerce
            Route::post('/bulk-sync', [ProductController::class, 'bulkSync']);

            // Get update status
            Route::get('/sync-status/{syncId}', [ProductController::class, 'getSyncStatus']);

            // Product sync routes
            Route::post('/sync-on-cart', [ProductSyncController::class, 'syncOnCart']);

            // Get realtime stock from Baran API
            Route::post('/realtime-stock', [ProductStockController::class, 'getRealtimeStock']);

            // Get unique ID by barcode
            Route::get('/unique-by-sku/{sku}', [ProductController::class, 'getUniqueIdBySku']);

            // Get unique IDs by multiple SKUs/barcodes
            Route::post('/unique-by-skus', [ProductController::class, 'getUniqueIdsBySkus']);

            // Sync unique IDs for products
            Route::post('/sync-unique-ids', [ProductController::class, 'syncUniqueIds']);

            // Process products with empty unique IDs
            Route::post('/process-empty-unique-ids', [ProductController::class, 'processEmptyUniqueIds']);

            // Sync categories
            Route::post('/sync-categories', [ProductController::class, 'syncCategories']);

            // Categories and attributes routes
            Route::get('/categories-attributes', [ProductController::class, 'getCategoriesAndAttributes']);

            // Product Stock routes
            Route::post('/stock', [ProductStockController::class, 'getStockByUniqueId']);
        });

    })->middleware('jwt.auth');

    // Tantooo related routes
    Route::prefix('tantooo')->group(function () {
        // Register Tantooo API key
        Route::post('/register-api-key', [TantoooApiKeyController::class, 'store']);

        // Validate Tantooo API key
        Route::post('/validate-api-key', [TantoooApiKeyController::class, 'validate']);

        // Sync settings
        Route::post('/sync-settings', [TantoooSyncController::class, 'syncSettings']);
        Route::post('/trigger-sync', [TantoooSyncController::class, 'triggerSync']);

        // Test connection
        Route::post('/test-connection', [TantoooSyncController::class, 'testConnection']);

        // Get sync status
        Route::get('/sync-status/{syncId}', [TantoooSyncController::class, 'getSyncStatus']);

        // Tantooo Product routes
        Route::prefix('products')->group(function () {
            // Update single product
            Route::post('/update', [TantoooProductController::class, 'updateProduct']);

            // Update multiple products
            Route::post('/update-multiple', [TantoooProductController::class, 'updateMultipleProducts']);

            // Update product stock only
            Route::post('/update-stock', [TantoooProductController::class, 'updateProductStock']);

            // Update product info (title, price, discount)
            Route::post('/update-info', [TantoooProductController::class, 'updateProductInfo']);

            // Sync from Baran warehouse
            Route::post('/sync-from-baran', [TantoooProductController::class, 'syncFromBaran']);

            // Sync products with Tantooo
            Route::post('/sync', [TantoooProductController::class, 'sync']);

            // Check sync status
            Route::get('/sync-status/{syncId}', [TantoooProductController::class, 'getSyncStatus']);

            // Bulk sync products with Tantooo
            Route::post('/bulk-sync', [TantoooProductController::class, 'bulkSync']);

            // Update all products from database
            Route::post('/update-all', [TantoooProductController::class, 'updateAllProducts']);

            // Get status of update all
            Route::get('/update-all-status/{syncId}', [TantoooProductController::class, 'getUpdateAllStatus']);

            // Get products list from Tantooo
            Route::get('/list', [TantoooProductController::class, 'getProducts']);


            // Job-based bulk update routes
            Route::post('/update-all', [TantoooProductController::class, 'updateAllProducts']);
            Route::post('/update-specific', [TantoooProductController::class, 'updateSpecificProducts']);
            Route::post('/update-single', [TantoooProductController::class, 'updateSingleProduct']);
        });

        // Get Tantooo settings
        Route::get('/settings', [TantoooProductController::class, 'getSettings']);

        // Tantooo Data routes - دریافت اطلاعات اصلی
        Route::prefix('data')->group(function () {
            // Get all main data (categories, colors, sizes)
            Route::get('/main', [TantoooDataController::class, 'getMainData']);

            // Get categories only
            Route::get('/categories', [TantoooDataController::class, 'getCategories']);

            // Get colors only
            Route::get('/colors', [TantoooDataController::class, 'getColors']);

            // Get sizes only
            Route::get('/sizes', [TantoooDataController::class, 'getSizes']);

            // Refresh token
            Route::post('/refresh-token', [TantoooDataController::class, 'refreshToken']);
        });
    })->middleware('jwt.auth');

    // License routes
    Route::prefix('licenses')->group(function () {
        // Create new license (admin only)
        Route::post('/', [LicenseController::class, 'store']);

        // Update license (admin only)
        Route::put('/{license}', [LicenseController::class, 'update']);

        // Extend license expiry (admin only)
        Route::patch('/{license}/extend', [LicenseController::class, 'extend']);

        // Check license status
        Route::get('/status', [LicenseController::class, 'status']);
    })->middleware('jwt.auth');


    // User settings routes
    Route::prefix('settings')->group(function () {
        Route::get('/', [UserSettingController::class, 'get']);
        Route::post('/', [UserSettingController::class, 'update']);
        Route::get('/payment-gateways', [UserSettingController::class, 'getPaymentGateways']);
        Route::post('/payment-gateways', [UserSettingController::class, 'updatePaymentGateways']);
    })->middleware('jwt.auth');

    // Log routes
    Route::prefix('logs')->group(function () {
        // Get web service logs
        Route::get('/', [ErrorLogController::class, 'getLogs']);

        // Get plugin logs
        Route::get('/plugin', [ErrorLogController::class, 'getPluginLogs']);
    })->middleware('jwt.auth');

    // Mongo data routes
    Route::prefix('mongo')->group(function () {
        Route::post('/clear-data', [MongoDataController::class, 'clearData']);
    })->middleware('jwt.auth');

    // Notification routes
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::post('/dismiss', [NotificationController::class, 'dismiss']);

        // Admin routes (requires authentication)
        Route::middleware('jwt.auth')->group(function () {
            Route::post('/', [NotificationController::class, 'store']);
            Route::put('/{notification}', [NotificationController::class, 'update']);
            Route::delete('/{notification}', [NotificationController::class, 'destroy']);
        });
    });

    // Connection test
    Route::get('/test-connection', function () {
        return response()->json([
            'success' => true,
            'message' => 'Connection established',
            'timestamp' => now()
        ]);
    });

});


// Price unit settings routes
Route::prefix('price-unit-settings')->group(function () {
    Route::post('/update', [PriceUnitSettingController::class, 'update']);
    Route::get('/get', [PriceUnitSettingController::class, 'get']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
