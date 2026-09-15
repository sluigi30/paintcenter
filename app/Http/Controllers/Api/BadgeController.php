<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The counts behind the app's tab badges.
 *
 * One endpoint rather than two, because it is polled: the tabs layout asks for
 * both numbers on a timer regardless of which tab is open, and two round trips
 * for two integers is a poor trade on a phone.
 *
 * It answers with counts only — never message or cart contents — so it stays
 * cheap enough to poll and leaks nothing if a badge is ever shown somewhere
 * less private than the app.
 */
class BadgeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return response()->json([
            // Deliberately the SAME predicate MessageController::thread() uses
            // when it marks a customer's incoming messages read. If the two
            // ever drift, the badge either never clears or clears early.
            'unread_messages' => Message::whereIn('sender_id', User::query()->admins()->select('id'))
                ->where('receiver_id', $user->id)
                ->where('is_read', false)
                ->count(),

            // Quantity, not lines — it has to agree with the `item_count` the
            // cart screen itself shows, or the badge and the page disagree.
            'cart_items' => (int) CartItem::where('user_id', $user->id)->sum('quantity'),
        ]);
    }
}
