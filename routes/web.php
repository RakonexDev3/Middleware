<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // Redirect to login if not authenticated, otherwise to dashboard
    return redirect()->route('login');
});

// Authentication Routes
Route::get('/login', [\App\Http\Controllers\AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [\App\Http\Controllers\AuthController::class, 'login']);
Route::post('/logout', [\App\Http\Controllers\AuthController::class, 'logout'])->name('logout');

// Protected Dashboard Routes
Route::middleware('auth')->group(function () {
    Route::get('/customers', [\App\Http\Controllers\CustomerController::class, 'index'])->name('customers.index');
    Route::get('/orders', [\App\Http\Controllers\OrderController::class, 'index'])->name('orders.index');
    Route::get('/products', [\App\Http\Controllers\WebProductController::class, 'index'])->name('products.index');
    Route::get('/logs', [\App\Http\Controllers\SyncLogController::class, 'index'])->name('logs.index');
});

Route::get('/shopify/install', [\App\Http\Controllers\ShopifyAuthController::class, 'install']);
Route::get('/shopify/auth/callback', [\App\Http\Controllers\ShopifyAuthController::class, 'callback']);

Route::get('/test-shopify', function (\Illuminate\Http\Request $request) {
    try {
        $shopDomain = $request->query('shop') ?? config('services.shopify.domain');
        // This will automatically fetch the token from your database 
        // using the domain provided or the one in your .env
        $shopify = new \App\Services\Shopify\ShopifyGraphQLService($shopDomain);
        
        $response = $shopify->query('
            query {
                shop {
                    name
                    email
                }
            }
        ');
        
        return response()->json($response);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()], 500);
    }
});

// Allow direct /method/ calls without /api prefix
Route::match(['get', 'post'], '/method/middleware.apis.sales_order.create_sales_order', [\App\Http\Controllers\SalesOrderController::class, 'createSalesOrder']);

