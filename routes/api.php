<?php

use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);
});

// Public product routes
Route::get('/products',            [ProductController::class, 'index']);
Route::get('/products/{product}',  [ProductController::class, 'show']);
Route::get('/brands',              [ProductController::class, 'brands']);
Route::get('/categories',          [ProductController::class, 'categories']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

    // Cart — lines are keyed by cart_item_id so the same product can sit
    // in the cart in several sizes without colliding
    Route::prefix('cart')->group(function () {
        Route::get('/',                  [CartController::class, 'summary']);
        Route::post('/add',              [CartController::class, 'add']);
        Route::delete('/',               [CartController::class, 'clear']);
        Route::put('/{cartItem}',        [CartController::class, 'update']);
        Route::delete('/{cartItem}',     [CartController::class, 'remove']);
    });

    // Orders
    Route::prefix('orders')->group(function () {
        Route::get('/',           [OrderController::class, 'index']);
        Route::post('/',          [OrderController::class, 'store']);
        Route::get('/{order}',    [OrderController::class, 'show']);
        Route::post('/{order}/cancel', [OrderController::class, 'cancel']);
    });

    // Messages
    Route::prefix('messages')->group(function () {
        Route::get('/admin',           [MessageController::class, 'getAdmin']);
        Route::get('/conversations',   [MessageController::class, 'conversations']);
        Route::get('/thread/{userId}', [MessageController::class, 'thread']);
        Route::post('/send',           [MessageController::class, 'send']);
        Route::patch('/{message}/read', [MessageController::class, 'markRead']);
    });
});