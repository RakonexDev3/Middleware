<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/webhooks/shopify', [\App\Http\Controllers\ShopifyWebhookController::class, 'handle'])
    ->middleware(\App\Http\Middleware\VerifyShopifyWebhook::class);

Route::post('/method/middleware.apis.sales_order.create_sales_order', [\App\Http\Controllers\SalesOrderController::class, 'createSalesOrder']);
Route::post('/method/middleware.apis.product.sync_product', [\App\Http\Controllers\ProductController::class, 'syncProduct']);

