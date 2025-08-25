<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ErrorLogController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PriceUnitSettingController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductStockController;
use App\Http\Controllers\ProductSyncController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\UserSettingController;
use App\Http\Controllers\VersionController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WooCommerceApiKeyController;
use App\Http\Controllers\LicenseWarehouseCategoryController;
use App\Http\Controllers\MongoDataController;
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

    // Protected routes
    Route::middleware('jwt.auth')->group(function () {
        // User information
        Route::get('/me', [AuthController::class, 'me']);


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
        });

        // Register WooCommerce API key
        Route::post('/register-woocommerce-key', [WooCommerceApiKeyController::class, 'store']);

        // Validate WooCommerce API key
        Route::post('/validate-woocommerce-key', [WooCommerceApiKeyController::class, 'validate']);

        // Check plugin version
        Route::post('/check-version', [VersionController::class, 'check']);

        // User settings routes
        Route::prefix('settings')->group(function () {
            Route::get('/', [UserSettingController::class, 'get']);
            Route::post('/', [UserSettingController::class, 'update']);
            Route::get('/payment-gateways', [UserSettingController::class, 'getPaymentGateways']);
            Route::post('/payment-gateways', [UserSettingController::class, 'updatePaymentGateways']);
        });

        // Sync settings
        Route::post('/sync-settings', [SyncController::class, 'syncSettings']);
        Route::post('/trigger-sync', [SyncController::class, 'triggerSync']);

        // Log routes
        Route::prefix('logs')->group(function () {
            // Get web service logs
            Route::get('/', [ErrorLogController::class, 'getLogs']);

            // Get plugin logs
            Route::get('/plugin', [ErrorLogController::class, 'getPluginLogs']);
        });

        // Product routes
        Route::prefix('products')->group(function () {
            // Update products in WooCommerce
            Route::post('/sync', [ProductController::class, 'sync']);

            // Bulk update products in WooCommerce
            Route::post('/bulk-sync', [ProductController::class, 'bulkSync']);

            // Get update status
            Route::get('/sync-status/{syncId}', [ProductController::class, 'getSyncStatus']);

            // Product sync routes
            Route::post('/sync-on-cart', [ProductSyncController::class, 'syncOnCart']);

            // Get realtime stock from Baran API
            Route::post('/realtime/stock', [ProductStockController::class, 'getRealtimeStock']);

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
        });

        // Webhook routes
        Route::post('/webhook/product-changes', [WebhookController::class, 'handleProductChanges']);

        // Mongo data routes
        Route::prefix('mongo')->group(function () {
            Route::post('/clear-data', [MongoDataController::class, 'clearData']);
        });

        // Categories and attributes routes
        Route::get('/categories-attributes', [ProductController::class, 'getCategoriesAndAttributes']);

        // Warehouse categories routes
        Route::prefix('warehouse-categories')->group(function () {
            Route::get('/', [LicenseWarehouseCategoryController::class, 'index']);
            Route::post('/', [LicenseWarehouseCategoryController::class, 'store']);
            Route::put('/{id}', [LicenseWarehouseCategoryController::class, 'update']);
            Route::delete('/{id}', [LicenseWarehouseCategoryController::class, 'destroy']);
        });

    });

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

// Invoice routes
Route::prefix('invoices')->group(function () {
    Route::post('/webhook', [InvoiceController::class, 'handleWebhook'])->middleware("jwt.auth");
});

// Product Stock routes
Route::prefix('products')->middleware('jwt.auth')->group(function () {
    Route::post('/stock', [ProductStockController::class, 'getStockByUniqueId']);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
