<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ColorService;
use Illuminate\Http\Request;

class ColorController extends Controller
{
    /**
     * Turn a colour the customer picked into the facts needed to sell it.
     *
     * The client picks a SIZE, but a variant is (size x base) — so something
     * has to name the base. Doing it here keeps paint chemistry out of the
     * app: the client filters the product's variants by the returned
     * base_code and shows sizes, never the base itself.
     *
     * Public on purpose. Choosing a colour and seeing whether it can be mixed
     * comes before any intent to buy, and gating it behind a login would put
     * a wall in front of the app's best feature.
     */
    public function resolve(Request $request)
    {
        $request->validate([
            'hex' => 'required|string|max:9',
        ]);

        $color = ColorService::describe($request->input('hex'));

        if ($color === null) {
            return response()->json([
                'message' => 'That is not a colour we can read.',
            ], 422);
        }

        return response()->json($color);
    }
}
