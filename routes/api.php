<?php

use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\DeliveryProofController;
use App\Http\Controllers\MessageAttachmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BadgeController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\ColorController;
use App\Http\Controllers\Api\DriverDeliveryController;
use App\Http\Controllers\Api\MixController;
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

    // Forgotten password, customers only (see AuthController::resettableCustomer
    // — staff reset at the panel, through Laravel's own broker). Keyed by the
    // email throughout: the phone belongs to the account, not to whoever is
    // typing, and the app is only ever told a masked version of it.
    Route::post('/forgot-password',   [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
    Route::post('/verify-reset-code', [AuthController::class, 'verifyResetCode'])->middleware('throttle:10,1');
    Route::post('/reset-password',    [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');
});

// Public product routes
Route::get('/products',            [ProductController::class, 'index']);
Route::get('/products/{product}',  [ProductController::class, 'show']);
Route::get('/brands',              [ProductController::class, 'brands']);
Route::get('/brands/{brand}/categories', [ProductController::class, 'brandCategories']);
Route::get('/categories',          [ProductController::class, 'categories']);

// DEPRECATED — no screen in the mobile app calls this any more.
//
// resolve() answers against the latex TINTING gamut and returns a P/M/D base
// code: both model a dispenser NCM does not own. Anything asking "can we make
// this colour" belongs on /colors/reachable below.
//
// STILL ROUTED, deliberately, and not yet deleted because:
//   1. Removing a public endpoint breaks any older installed build that still
//      calls it, and the app ships separately from this API.
//   2. CartController still ACCEPTS custom_hex, so the server half of the
//      retired flow is alive regardless of whether this route exists —
//      deleting only this would leave the fiction half-retired.
//   3. MIXING.md defers retiring is_custom_color until no orders in the
//      retention window depend on it. This goes with that, not before it.
//
// See REACHABILITY.md Phase 4.
Route::get('/colors/resolve',      [ColorController::class, 'resolve']);

// Can the shop make it, and with what recipe — solved against live stock.
// Batched (the suggestions screen asks for four at once) and throttled,
// because each target is a search over the shelf rather than a lookup.
Route::get('/colors/reachable',    [ColorController::class, 'reachable'])
    ->middleware('throttle:30,1');

// Protected routes
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

    // Changing your own password, with the current one as proof. Throttled:
    // a stolen, unlocked phone should not be able to sit there guessing it.
    Route::post('/auth/change-password', [AuthController::class, 'changePassword'])
        ->middleware('throttle:6,1');

    // Counts for the app's tab badges — polled, so kept to two integers
    Route::get('/badges', [BadgeController::class, 'index']);

    // Cart — lines are keyed by cart_item_id so the same product can sit
    // in the cart in several sizes without colliding
    Route::prefix('cart')->group(function () {
        Route::get('/',                  [CartController::class, 'summary']);
        Route::post('/add',              [CartController::class, 'add']);

        // A customer-composed mix: a base can plus the pints poured into it,
        // posted as ONE recipe. Separate from /add because half a recipe in
        // the cart is not a partial order, it is a different colour.
        Route::post('/mix',              [MixController::class, 'store']);

        Route::delete('/',               [CartController::class, 'clear']);
        Route::put('/{cartItem}',        [CartController::class, 'update']);
        Route::delete('/{cartItem}',     [CartController::class, 'remove']);
    });

    // Orders
    Route::prefix('orders')->group(function () {
        Route::get('/',           [OrderController::class, 'index']);
        Route::post('/',          [OrderController::class, 'store']);
        Route::get('/{order}',    [OrderController::class, 'show']);

        // The delivery photo. Gated in the controller — the customer whose
        // order it is, any admin, or the driver who carried it; 404 otherwise.
        // Named because Order::$proof_url builds the link from it.
        Route::get('/{order}/proof', DeliveryProofController::class)
            ->name('api.orders.proof');
        Route::post('/{order}/cancel', [OrderController::class, 'cancel']);
    });

    // Messages
    /*
     * Delivery driver endpoints.
     *
     * Behind `driver` as well as `auth:sanctum`: a valid token is not enough,
     * the account has to be active delivery staff. Everything in here is a
     * thin wrapper over DeliveryService, the same service the /driver Filament
     * panel calls — so a driver app and the driver web panel can never drift
     * on who owns an order, whether COD cash was collected, or what happens at
     * the attempt cap. That is what Phase 1's extraction bought.
     */
    Route::middleware('driver')->prefix('driver')->group(function () {
        Route::get('/deliveries',          [DriverDeliveryController::class, 'index']);
        Route::get('/deliveries/summary',  [DriverDeliveryController::class, 'summaryCounts']);
        Route::get('/failure-reasons',     [DriverDeliveryController::class, 'failureReasons']);

        // AFTER /summary, or "summary" is swallowed as an order id.
        Route::get('/deliveries/{order}',  [DriverDeliveryController::class, 'show']);

        Route::post('/deliveries/{order}/pick-up', [DriverDeliveryController::class, 'pickUp']);
        Route::post('/deliveries/{order}/deliver', [DriverDeliveryController::class, 'deliver']);
        Route::post('/deliveries/{order}/fail',    [DriverDeliveryController::class, 'fail']);
    });

    Route::prefix('messages')->group(function () {
        Route::get('/admin',           [MessageController::class, 'getAdmin']);
        Route::get('/conversations',   [MessageController::class, 'conversations']);
        Route::get('/thread/{userId}', [MessageController::class, 'thread']);
        Route::post('/send',           [MessageController::class, 'send']);
        Route::patch('/{message}/read', [MessageController::class, 'markRead']);

        // Attachments are served BY THE APP, never from a storage path. The
        // controller is shared with the admin panel's own route so the two
        // entry points cannot drift into two different rules about who may
        // look at a customer's photo.
        Route::get('/attachments/{attachment}', MessageAttachmentController::class)
            ->name('api.messages.attachments.show');
    });
});