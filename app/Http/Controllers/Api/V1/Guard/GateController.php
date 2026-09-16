<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Estate\Household;
use App\Models\Estate\Unit;
use App\Models\Estate\VisitorPass;
use App\Models\GateEvent;
use App\Models\Guard;
use App\Models\Post;
use App\Services\Dispatch\GateLog;
use App\Services\Passes\VisitorPasses;
use App\Services\Restriction\RestrictionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The gate: verify, entry, exit, override, search, activity, walk-up approvals (13 D2).
 *
 * Boards guard-app-02 (waiting on resident), -07 (verify, override), -08 (walk-up).
 *
 * INVARIANT 2 IS THIS FILE'S WHOLE BOUNDARY. Everything a guard learns about a
 * household here is its name, its unit, and `access_restricted` — a boolean.
 * No response is assembled from a charge, an invoice or a payment, and the
 * guard contract test holds every response below to its catalogue allowlist.
 *
 * VERIFY DOES NOT ADMIT. A verdict is shown; the admission is a separate act
 * (`/gate/entry`), because a guard shown "valid" can still turn somebody away,
 * and a single-use pass is consumed only when the person actually comes in.
 *
 * OFFLINE, THE HANDSET DECIDES AND THE SERVER RECONCILES. A pass verified on the
 * handset with no signal is admitted there. When the entry syncs, the server
 * records it — the admission happened — and says in `reconciliation` whether the
 * pass had been cancelled or used meanwhile, so the app can tell the guard and
 * dispatch sees it. An ONLINE entry on a pass that is not admissible is refused:
 * the guard had the facts, and letting somebody in against them is an override.
 */
class GateController extends Controller
{
    /** How long a walk-up visitor waits for the household before the estate's policy applies. */
    public const APPROVAL_WAIT_SECONDS = 180;

    /** A pass's category, in the restriction policy's words. */
    private const RESTRICTION_CATEGORY = [
        'single' => 'guest',
        'recurring' => 'visitor',
        'contractor' => 'contractor',
        'delivery' => 'delivery',
        VisitorPasses::RESIDENT => 'resident',
    ];

    public function __construct(
        private readonly VisitorPasses $passes,
        private readonly RestrictionPolicy $policy,
        private readonly GateLog $log,
    ) {}

    public function verify(Request $request, DeviceContext $context): JsonResponse
    {
        $context->guardOrFail();

        $data = $request->validate(['pass' => ['required', 'string', 'max:2048']]);

        $result = $this->passes->verifyOnline($context->tenantId, $data['pass'], $context->serverTime);
        $pass = $result['pass'];
        $household = $pass === null ? null : $this->householdOf($pass);
        $restricted = null;
        $verdict = $result['verdict'];

        if ($verdict === 'valid' && $pass !== null) {
            $restricted = false;

            if ($household !== null) {
                $decision = $this->policy->decide($household, self::RESTRICTION_CATEGORY[$pass->category] ?? 'guest');
                $restricted = ! $decision['admitted'];
            }

            if ($restricted) {
                $verdict = 'restricted';
            }
        }

        [$tone, $headline, $detail] = match ($verdict) {
            'valid' => ['green', 'Admit', $pass?->single_use ? 'Single-visit pass. Recording the entry uses it.' : 'Recurring pass, valid until '.$pass?->valid_to->format('M j, g:i A').'.'],
            'restricted' => ['amber', 'Access restricted', 'Contact management.'],
            default => ['red', 'Do not admit on this pass', $result['reason']],
        };

        return response()->json([
            'verdict' => $verdict,
            'tone' => $tone,
            'headline' => $headline,
            'detail' => $detail,
            'admit_allowed' => $verdict === 'valid',
            'pass' => $pass === null ? null : $this->passShape($pass),
            'unit' => $pass?->unit?->reference,
            'household' => $household?->name,
            'access_restricted' => $restricted,
            'server_time' => $context->serverTime->toIso8601String(),
        ]);
    }

    public function entry(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'pass_id' => ['nullable', 'string', 'max:64'],
            'approval_id' => ['nullable', 'integer'],
            'category' => ['required', 'string', 'max:40'],
            'subject' => ['nullable', 'string', 'max:120'],
            'verified_offline' => ['nullable', 'boolean'],
            'post_id' => ['nullable', 'integer'],
        ]);

        $offline = (bool) ($data['verified_offline'] ?? false);
        $postId = $this->postFor($guard, $data['post_id'] ?? null, $context);
        $subject = trim((string) ($data['subject'] ?? ''));
        $basis = GateLog::GUARD_DECISION;
        $reconciliation = null;
        $restricted = null;
        $pass = null;

        if (($data['pass_id'] ?? null) !== null) {
            $pass = VisitorPass::query()->with('unit')->where('pass_id', $data['pass_id'])->first();
            $basis = $offline ? 'QR pass · verified offline' : 'QR pass';

            if ($pass === null) {
                if (! $offline) {
                    throw ApiError::notFound('not_found', 'No such pass at this estate. Verify it first.');
                }

                $reconciliation = 'pass_unknown';
            } else {
                $household = $this->householdOf($pass);
                $restricted = $household === null ? false : ! $this->policy->decide($household, self::RESTRICTION_CATEGORY[$pass->category] ?? 'guest')['admitted'];

                $reconciliation = match (true) {
                    $pass->status === VisitorPass::CANCELLED => 'pass_cancelled',
                    $pass->status === VisitorPass::USED && $pass->single_use => 'pass_already_used',
                    $pass->valid_to->lessThan($context->deviceTime ?? $context->serverTime) => 'pass_expired',
                    $restricted => 'household_restricted',
                    default => $pass->single_use ? 'consumed' : 'valid',
                };

                if (! $offline && ! in_array($reconciliation, ['consumed', 'valid'], true)) {
                    throw ApiError::conflict('pass_not_admissible', 'This pass cannot admit anybody now ('.str_replace('_', ' ', $reconciliation).'). Admitting them is an override, with a reason.');
                }

                $this->passes->consume($pass, $context->serverTime);
                $subject = $subject !== '' ? $subject : $pass->visitor_name.' · '.$pass->unit->reference;
            }
        } elseif (($data['approval_id'] ?? null) !== null) {
            $approval = DB::connection('tenant')->table('gate_approval_requests')->where('id', $data['approval_id'])->first();

            if ($approval === null) {
                throw ApiError::notFound('not_found', 'No such walk-up request.');
            }

            if ($approval->status !== 'approved') {
                throw ApiError::conflict('approval_not_granted', 'The household has not approved this visitor. Apply the estate\'s policy, or override with a reason.');
            }

            $basis = 'Pre-approved';
            $unit = Unit::query()->find($approval->unit_id);
            $subject = $subject !== '' ? $subject : $approval->visitor_name.' · '.($unit->reference ?? '');
        }

        if ($subject === '') {
            throw ApiError::unprocessable('subject_required', 'Say who came in — a name, a plate, a company — when there is no pass or approval.');
        }

        $event = $this->log->record(
            tenantId: $context->tenantId,
            verdict: 'admit',
            category: $data['category'],
            subject: $subject,
            basis: $basis,
            guardId: $guard->id,
            postId: $postId,
            deviceTime: $context->deviceTime,
            idempotencyKey: $request->header('Idempotency-Key'),
            isSimulated: $request->boolean('simulated'),
        );

        return response()->json([
            ...$this->eventShape($event, $context),
            'reconciliation' => $reconciliation,
            'access_restricted' => $restricted,
        ], 201);
    }

    public function exit(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'category' => ['required', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:120'],
            'pass_id' => ['nullable', 'string', 'max:64'],
            'post_id' => ['nullable', 'integer'],
        ]);

        $event = $this->log->record(
            tenantId: $context->tenantId,
            verdict: 'exit',
            category: $data['category'],
            subject: $data['subject'],
            basis: ($data['pass_id'] ?? null) !== null ? 'QR pass' : GateLog::GUARD_DECISION,
            guardId: $guard->id,
            postId: $this->postFor($guard, $data['post_id'] ?? null, $context),
            deviceTime: $context->deviceTime,
            idempotencyKey: $request->header('Idempotency-Key'),
            isSimulated: $request->boolean('simulated'),
        );

        return response()->json($this->eventShape($event, $context), 201);
    }

    public function override(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'category' => ['required', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'min:5', 'max:160'],
            'pass_id' => ['nullable', 'string', 'max:64'],
            'post_id' => ['nullable', 'integer'],
        ]);

        $event = $this->log->record(
            tenantId: $context->tenantId,
            verdict: 'override',
            category: $data['category'],
            subject: $data['subject'],
            basis: 'Override — '.trim($data['reason']),
            guardId: $guard->id,
            postId: $this->postFor($guard, $data['post_id'] ?? null, $context),
            deviceTime: $context->deviceTime,
            idempotencyKey: $request->header('Idempotency-Key'),
            isSimulated: $request->boolean('simulated'),
        );

        return response()->json($this->eventShape($event, $context), 201);
    }

    /**
     * Find a unit by its reference or a household by name — board guard-app-07's search.
     *
     * `access_restricted` AS A BOOLEAN AND NOTHING ELSE ABOUT MONEY (13 D2). The
     * rows are built from the unit, the household's name, its primary resident's
     * name and today's expected visitors. None of those can carry a figure.
     */
    public function search(Request $request, DeviceContext $context): JsonResponse
    {
        $context->guardOrFail();

        $data = $request->validate([
            'unit' => ['nullable', 'string', 'min:1', 'max:32', 'required_without:name'],
            'name' => ['nullable', 'string', 'min:2', 'max:80', 'required_without:unit'],
        ]);

        $households = Household::query()->with(['unit', 'residents'])
            ->when($data['unit'] ?? null, fn ($q, string $unit) => $q->whereHas('unit', fn ($u) => $u->where('reference', 'like', '%'.$this->like($unit).'%')))
            ->when($data['name'] ?? null, fn ($q, string $name) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$this->like($name).'%')
                ->orWhereHas('residents', fn ($r) => $r->where('full_name', 'like', '%'.$this->like($name).'%'))))
            ->orderBy('name')
            ->limit(20)
            ->get();

        $now = $context->serverTime;
        $expected = VisitorPass::query()
            ->whereIn('unit_id', $households->pluck('unit_id'))
            ->where('status', VisitorPass::ACTIVE)
            ->where('valid_from', '<=', $now->copy()->endOfDay())
            ->where('valid_to', '>=', $now)
            ->orderBy('valid_from')
            ->get()
            ->groupBy('unit_id');

        $items = $households->map(fn (Household $h): array => [
            'unit' => (string) $h->unit?->reference,
            'household' => $h->name,
            'primary_resident' => $h->residents->firstWhere('is_primary', true)?->full_name,
            'access_restricted' => (bool) $h->access_restricted,
            'expected_visitors' => ($expected->get($h->unit_id) ?? collect())->map(static fn (VisitorPass $p): array => [
                'visitor_name' => $p->visitor_name,
                'category' => $p->category,
                'valid_to' => $p->valid_to->toIso8601String(),
            ])->values()->all(),
        ])->values()->all();

        return response()->json(['items' => $items]);
    }

    public function activity(Request $request, DeviceContext $context): JsonResponse
    {
        $context->guardOrFail();

        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $today = $context->serverTime->copy()->startOfDay();

        $events = GateEvent::query()->where('tenant_id', $context->tenantId)
            ->where('occurred_at', '>=', $today)
            ->orderByDesc('occurred_at')
            ->limit((int) ($data['limit'] ?? 50))
            ->get();

        $counts = GateEvent::query()->where('tenant_id', $context->tenantId)
            ->where('occurred_at', '>=', $today)
            ->selectRaw('verdict, COUNT(*) AS n')
            ->groupBy('verdict')
            ->pluck('n', 'verdict');

        return response()->json([
            'date' => $today->toDateString(),
            'counts' => [
                'admitted' => (int) ($counts['admit'] ?? 0),
                'exited' => (int) ($counts['exit'] ?? 0),
                'overridden' => (int) ($counts['override'] ?? 0),
                'denied' => (int) ($counts['deny'] ?? 0),
            ],
            'items' => $events->map(fn (GateEvent $e): array => [
                'id' => $e->id,
                'verdict' => $e->verdict,
                'category' => $e->category,
                'subject' => $e->subject,
                'basis' => $e->basis,
                'pass_based' => $this->log->isPassBased((string) $e->basis),
                'guard_name' => $e->guard_name,
                'post_name' => $e->post_name,
                'occurred_at' => $e->occurred_at->toIso8601String(),
                'device_time' => $e->device_time?->toIso8601String(),
            ])->all(),
        ]);
    }

    /** Board guard-app-08: record a walk-up visitor and ask the household. */
    public function requestApproval(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'unit' => ['required', 'string', 'max:32'],
            'visitor_name' => ['required', 'string', 'max:160'],
            'id_type' => ['nullable', 'string', 'max:40'],
            'id_number' => ['nullable', 'string', 'max:40'],
            'purpose' => ['nullable', 'string', 'max:160'],
            'vehicle_plate' => ['nullable', 'string', 'max:16'],
        ]);

        $unit = Unit::query()->with('household')->where('reference', $data['unit'])->first();

        if ($unit === null) {
            throw ApiError::notFound('not_found', 'No unit '.$data['unit'].' at this estate. Search by name.');
        }

        $post = $guard->post_id === null ? null : Post::query()->find($guard->post_id);
        $now = $context->serverTime;

        $id = DB::connection('tenant')->table('gate_approval_requests')->insertGetId([
            'unit_id' => $unit->id,
            'household_id' => $unit->household?->id,
            'guard_id' => $guard->id,
            'guard_name' => $guard->full_name,
            'post_id' => $post?->id,
            'post_name' => $post?->name,
            'visitor_name' => $data['visitor_name'],
            'id_type' => $data['id_type'] ?? null,
            'id_number' => $data['id_number'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'vehicle_plate' => $data['vehicle_plate'] ?? null,
            'status' => 'pending',
            'requested_at' => $now,
            'respond_by' => $now->copy()->addSeconds(self::APPROVAL_WAIT_SECONDS),
            'device_time' => $context->deviceTime,
            'is_simulated' => $request->boolean('simulated'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json($this->approvalShape((int) $id), 201);
    }

    public function approval(int $approval, DeviceContext $context): JsonResponse
    {
        $context->guardOrFail();

        if (! DB::connection('tenant')->table('gate_approval_requests')->where('id', $approval)->exists()) {
            throw ApiError::notFound('not_found', 'No such walk-up request.');
        }

        return response()->json($this->approvalShape($approval));
    }

    /** @return array<string, mixed> */
    private function approvalShape(int $id): array
    {
        DB::connection('tenant')->table('gate_approval_requests')
            ->where('id', $id)->where('status', 'pending')->where('respond_by', '<', Carbon::now())
            ->update(['status' => 'expired', 'updated_at' => Carbon::now()]);

        $row = DB::connection('tenant')->table('gate_approval_requests')->where('id', $id)->first();
        $unit = Unit::query()->with('household')->find($row->unit_id);

        return [
            'approval_id' => (int) $row->id,
            'status' => (string) $row->status,
            'unit' => (string) $unit?->reference,
            'household' => $unit?->household?->name,
            'visitor_name' => (string) $row->visitor_name,
            'requested_at' => Carbon::parse((string) $row->requested_at)->toIso8601String(),
            'respond_by' => Carbon::parse((string) $row->respond_by)->toIso8601String(),
            'responded_at' => $row->responded_at === null ? null : Carbon::parse((string) $row->responded_at)->toIso8601String(),
            'responded_by_name' => $row->responded_by_name,
            'guidance' => match ($row->status) {
                'approved' => 'Approved by the household. Record the entry with this approval.',
                'denied' => 'The household declined. Do not admit.',
                'expired' => 'No answer from the household. Apply the estate\'s policy for unannounced visitors.',
                default => 'Waiting on the household.',
            },
        ];
    }

    /** @return array<string, mixed> */
    private function eventShape(GateEvent $event, DeviceContext $context): array
    {
        return [
            'id' => $event->id,
            'verdict' => $event->verdict,
            'basis' => $event->basis,
            'pass_based' => $this->log->isPassBased((string) $event->basis),
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'server_time' => $context->serverTime->toIso8601String(),
            'device_time' => $event->device_time?->toIso8601String(),
            'clock_skewed' => $this->log->clockSkewed($event->device_time, $event->occurred_at),
        ];
    }

    /** @return array<string, mixed> */
    private function passShape(VisitorPass $pass): array
    {
        return [
            'pass_id' => $pass->pass_id,
            'category' => $pass->category,
            'visitor_name' => $pass->visitor_name,
            'vehicle_plate' => $pass->vehicle_plate,
            'purpose' => $pass->purpose,
            'valid_from' => $pass->valid_from->toIso8601String(),
            'valid_to' => $pass->valid_to->toIso8601String(),
            'single_use' => $pass->single_use,
            'status' => $pass->status,
        ];
    }

    private function householdOf(VisitorPass $pass): ?Household
    {
        return $pass->household_id === null
            ? $pass->unit->household
            : Household::query()->find($pass->household_id);
    }

    private function postFor(Guard $guard, ?int $postId, DeviceContext $context): ?int
    {
        $postId ??= $guard->post_id;

        if ($postId !== null && ! Post::query()->whereKey($postId)->where('tenant_id', $context->tenantId)->exists()) {
            throw ApiError::forbidden('wrong_site', 'That post is not at your estate.');
        }

        return $postId;
    }

    private function like(string $term): string
    {
        return addcslashes($term, '%_\\');
    }
}
