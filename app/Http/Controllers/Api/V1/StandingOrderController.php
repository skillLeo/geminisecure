<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Guard;
use App\Services\Gemini\StandingOrders;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GET /api/v1/standing-orders and POST /api/v1/standing-orders/{set}/acknowledge.
 *
 * The guard's side of the acknowledgement cycle (12 §2, item 28): the orders a
 * guard works to, and their acknowledgement of the version they read. The
 * token is a guard's handset token, so the guard is whoever it was issued to —
 * a handset cannot acknowledge on anybody else's behalf.
 *
 * INVARIANT 2. Orders are text and version numbers. Nothing here carries an
 * amount, a balance or a household.
 */
class StandingOrderController extends Controller
{
    public function index(StandingOrders $orders): JsonResponse
    {
        $guard = $this->guardFrom();

        return response()->json(['orders' => $orders->forGuard($guard)]);
    }

    public function acknowledge(Request $request, int $set, StandingOrders $orders): JsonResponse
    {
        $guard = $this->guardFrom();

        // The version the guard READ. Acknowledging "whatever is current" would
        // let a revision published while the screen was open be signed unseen.
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        try {
            $ack = $orders->acknowledge($guard, $set, (int) $data['version']);
        } catch (DomainException $refused) {
            // 409: the request is well-formed; the orders are not in the state
            // it assumed (revised since, or not this guard's post).
            return response()->json(['error' => $refused->getMessage()], 409);
        }

        return response()->json($ack);
    }

    /**
     * The guard the token was issued to. Read from the sanctum guard itself
     * rather than `$request->user()`, whose default provider is the console's
     * `users` — a handset token belongs to a `guards` row, and any other
     * tokenable (a resident, a console account) is refused here.
     */
    private function guardFrom(): Guard
    {
        $guard = Auth::guard('sanctum')->user();

        abort_unless($guard instanceof Guard, 403, 'Standing orders are acknowledged from a guard handset.');

        return $guard;
    }
}
