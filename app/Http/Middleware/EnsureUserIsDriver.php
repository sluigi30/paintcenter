<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the /api/driver namespace.
 *
 * 403 here, not the 404 used elsewhere in this codebase. The two rules are not
 * in conflict: 404 is for a SPECIFIC record the caller may not see, where a 403
 * would confirm the row exists and order ids are a plain auto-increment anyone
 * can count through. This gate reveals nothing of the sort — it answers "are
 * you delivery staff", and a customer who asks already knows they are not.
 *
 * An individual delivery that belongs to another driver still 404s, because
 * that check is the scoped query in DriverDeliveryController, not this.
 */
class EnsureUserIsDriver
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isDriver() || $user->is_archived) {
            return response()->json([
                'message' => 'This account is not an active delivery driver.',
            ], 403);
        }

        return $next($request);
    }
}
