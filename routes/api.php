<?php

use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BadgeController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\ColorController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

// Public routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);

    // Phone verification, ahead of registration. send-otp is throttled per
    // phone (the 'otp-send' limiter); verify-otp is capped to blunt guessing.
    Route::post('/send-otp',   [AuthController::class, 'sendOtp'])->middleware('throttle:otp-send');
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1');
});

// Public product routes
Route::get('/products',            [ProductController::class, 'index']);
Route::get('/products/{product}',  [ProductController::class, 'show']);
Route::get('/brands',              [ProductController::class, 'brands']);
Route::get('/brands/{brand}/categories', [ProductController::class, 'brandCategories']);
Route::get('/categories',          [ProductController::class, 'categories']);

// Custom colour: which base a picked colour needs, and whether paint can
// reach it at all. Public — choosing a colour precedes any intent to buy.
Route::get('/colors/resolve',      [ColorController::class, 'resolve']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

    // Counts for the app's tab badges — polled, so kept to two integers
    Route::get('/badges', [BadgeController::class, 'index']);

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