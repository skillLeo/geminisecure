<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Services\Gemini\StandingOrders;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    public function index(StandingOrders $orders, DeviceContext $context): JsonResponse
    {
        return response()->json(['orders' => $orders->forGuard($context->guardOrFail())]);
    }

    public function acknowledge(Request $request, int $set, StandingOrders $orders, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        // The version the guard READ. Acknowledging "whatever is current" would
        // let a revision published while the screen was open be signed unseen.
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        try {
            $ack = $orders->acknowledge($guard, $set, (int) $data['version']);
        } catch (DomainException $refused) {
            // 409: the request is well-formed; the orders are not in the state
            // it assumed (revised since, or not this guard's post).
            throw ApiError::conflict('orders_changed', $refused->getMessage());
        }

        return response()->json($ack);
    }
}
