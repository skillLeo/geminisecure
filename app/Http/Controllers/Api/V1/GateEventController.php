<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Dispatch\GateLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/gate-events — what the guard decided at the gate.
 *
 * The event a scan produces, recorded after `passes/verify` has given the
 * verdict. The two are deliberately separate calls: a guard can be shown a
 * verdict and still not admit somebody, and a system that recorded the
 * admission at the moment it answered the scan would log arrivals that never
 * happened.
 *
 * INVARIANT 2. Nothing in the request and nothing in the response is a monetary
 * amount. A guard's handset records who came in and on what basis; what a
 * household owes is not their business, and `gate_events` has no column that
 * could carry a figure. The verdict vocabulary is the same three words
 * `passes/verify` answers with, plus the one act that is its own decision.
 *
 * AN OVERRIDE NEEDS A REASON AND THE VALIDATOR ENFORCES IT. Board guard-app-07
 * draws "Override admit" as its own control: somebody is being let in against
 * the system's advice, and the estate is entitled to know why. A required field
 * is the only place that can be guaranteed — a reason asked for by a screen is
 * a reason the next client's screen forgets to ask for.
 */
class GateEventController extends Controller
{
    /** What a guard can record having done. */
    private const VERDICTS = ['admit', 'deny', 'override', 'exit'];

    public function store(Request $request, GateLog $log): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'string', 'max:64'],
            'verdict' => ['required', 'string', 'in:'.implode(',', self::VERDICTS)],

            /*
             * "Visitor", "Contractor", "Resident vehicle", "Delivery" — what
             * arrived, in the estate's own words. Free text rather than an enum
             * because every community categorises its gate differently and a
             * fixed list would make the next client's log wrong.
             */
            'category' => ['required', 'string', 'max:40'],

            // Who or what. A name, a plate, a company.
            'subject' => ['required', 'string', 'max:120'],

            'basis' => ['required', 'string', 'max:40'],
            'guard_id' => ['nullable', 'integer', 'exists:guards,id'],
            'post_id' => ['nullable', 'integer', 'exists:posts,id'],
            'device_time' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:190'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        /*
         * Checked here rather than in the rules array, because it is a
         * relationship between two fields and a message that named only
         * `reason` would not say why it was suddenly required.
         */
        if ($data['verdict'] === 'override' && trim((string) ($data['reason'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'reason' => 'An override admits somebody the system advised against. The estate is '.
                    'entitled to know why, so a reason is required for this verdict and no other.',
            ]);
        }

        $event = $log->record(
            tenantId: $data['tenant_id'],
            verdict: $data['verdict'],
            category: $data['category'],
            subject: $data['subject'],

            /*
             * An override's basis is the reason it was overridden on. Board
             * guard-app-07 prints exactly that back on the gate log, and
             * storing "override" as the basis as well as the verdict would lose
             * the only sentence explaining the decision.
             */
            basis: $data['verdict'] === 'override'
                ? 'Override — '.trim((string) $data['reason'])
                : $data['basis'],
            guardId: $data['guard_id'] ?? null,
            postId: $data['post_id'] ?? null,
            deviceTime: isset($data['device_time']) ? now()->parse($data['device_time']) : null,
            idempotencyKey: $data['idempotency_key'],
            isSimulated: $request->boolean('simulated'),
        );

        return response()->json([
            'id' => $event->id,
            'verdict' => $event->verdict,
            'occurred_at' => $event->occurred_at->toIso8601String(),

            /*
             * Echoed so a handset can show the guard whether its own clock is
             * wrong, which is the only way they would ever find out. Computed
             * rather than stored: a gate event is not a life-safety record the
             * way an alert is, and one boolean does not earn a column.
             */
            'clock_skewed' => $log->clockSkewed($event->device_time, $event->occurred_at),
            'pass_based' => $log->isPassBased($event->basis),
        ], 201);
    }
}
