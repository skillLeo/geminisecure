<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Estate\VisitorPass;
use App\Models\Tenant;
use App\Services\Passes\VisitorPasses;
use App\Services\ResidentApp\ResidentAccounts;
use App\Services\Restriction\RestrictionPolicy;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Visitor passes, the resident's own e-pass, and answering a guard at the gate (13 D3).
 * Boards resident-app-05 (create), -06 (my passes, share), -07 (visitor approval), -08 (e-pass).
 *
 * THE PASS IS SIGNED WHEN IT IS ISSUED, and what is signed cannot be edited: the
 * window, the category and the single-use flag are in the payload a guard's
 * handset verifies offline. PATCH changes only what is not signed — who is coming,
 * their number, the purpose and the plate. A different window is a new pass.
 *
 * A RESTRICTED HOUSEHOLD IS TOLD, IN THE GATE'S WORDS. The resident issuing a
 * guest pass for a household in restriction is refused with the same sentence a
 * guard sees — "Access restricted — contact management" — and nothing about why.
 * The figure is on their statement, which is theirs to read; the pass screen is
 * not where a balance is argued.
 */
class PassesController extends Controller
{
    /** A pass's category, in the restriction policy's words. */
    private const RESTRICTION_CATEGORY = ['single' => 'guest', 'recurring' => 'visitor', 'contractor' => 'contractor', 'delivery' => 'delivery'];

    public function __construct(
        private readonly ResidentAccounts $accounts,
        private readonly VisitorPasses $passes,
        private readonly RestrictionPolicy $policy,
    ) {}

    public function index(Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $data = $request->validate(['status' => ['nullable', 'string', 'in:active,used,cancelled,expired']]);

        $items = VisitorPass::query()
            ->where('unit_id', $home['unit']->id)
            ->where('category', '!=', VisitorPasses::RESIDENT)
            ->where('valid_to', '>=', Carbon::now()->subDays(30))
            ->orderByDesc('valid_from')
            ->limit(100)
            ->get()
            ->map(fn (VisitorPass $p): array => $this->shape($p))
            ->when($data['status'] ?? null, static fn ($c, string $status) => $c->where('status', $status))
            ->values()
            ->all();

        return response()->json(['items' => $items]);
    }

    public function store(Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        $data = $request->validate([
            'category' => ['required', 'string', 'in:'.implode(',', VisitorPasses::CATEGORIES)],
            'visitor_name' => ['required', 'string', 'min:2', 'max:160'],
            'visitor_phone' => ['nullable', 'string', 'max:40'],
            'purpose' => ['nullable', 'string', 'max:160'],
            'vehicle_plate' => ['nullable', 'string', 'max:16'],
            'valid_from' => ['required', 'date'],
            'valid_to' => ['required', 'date', 'after:valid_from'],
        ]);

        if (! $this->policy->decide($home['household'], self::RESTRICTION_CATEGORY[$data['category']])['admitted']) {
            throw ApiError::conflict('access_restricted', 'Access restricted — contact management.');
        }

        try {
            $pass = $this->passes->issue(
                $context->tenantId,
                $home['unit'],
                $home['resident']?->id,
                (string) ($home['resident']->full_name ?? $home['account']->full_name ?? 'Resident'),
                [
                    ...$data,
                    'valid_from' => Carbon::parse($data['valid_from']),
                    'valid_to' => Carbon::parse($data['valid_to']),
                ],
                $request->header('Idempotency-Key'),
                $request->boolean('simulated'),
            );
        } catch (DomainException $refused) {
            throw ApiError::unprocessable('pass_refused', $refused->getMessage());
        }

        return response()->json($this->shape($pass), 201);
    }

    public function update(Request $request, int $pass, DeviceContext $context): JsonResponse
    {
        $record = $this->mine($pass, $context);

        $data = $request->validate([
            'visitor_name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'visitor_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:160'],
            'vehicle_plate' => ['sometimes', 'nullable', 'string', 'max:16'],
            'valid_from' => ['prohibited'],
            'valid_to' => ['prohibited'],
            'category' => ['prohibited'],
        ]);

        if ($record->status !== VisitorPass::ACTIVE) {
            throw ApiError::conflict('pass_not_active', 'Only an active pass can be changed. This one is '.$record->status.'.');
        }

        $record->forceFill($data)->save();

        return response()->json($this->shape($record));
    }

    public function cancel(int $pass, DeviceContext $context): JsonResponse
    {
        $record = $this->mine($pass, $context);

        try {
            $this->passes->cancel($record);
        } catch (DomainException $refused) {
            throw ApiError::conflict('pass_already_used', $refused->getMessage());
        }

        return response()->json($this->shape($record->refresh()));
    }

    public function share(Request $request, int $pass, DeviceContext $context): JsonResponse
    {
        $record = $this->mine($pass, $context);
        $request->validate(['channel' => ['nullable', 'string', 'in:whatsapp,sms,email,copy']]);

        if ($record->status !== VisitorPass::ACTIVE) {
            throw ApiError::conflict('pass_not_active', 'A '.$record->status.' pass cannot be shared.');
        }

        $record->increment('share_count');
        $estate = (string) Tenant::query()->whereKey($context->tenantId)->value('name');

        return response()->json([
            'pass_id' => $record->pass_id,
            'share_count' => $record->share_count,
            'code' => $record->code,
            'token' => $record->token,
            'message' => sprintf(
                '%s, you are expected at %s (%s) from %s to %s. At the gate, show the pass or give the code %s.',
                $record->visitor_name,
                $estate,
                $record->unit->reference,
                $record->valid_from->setTimezone('America/Jamaica')->format('M j, g:i A'),
                $record->valid_to->setTimezone('America/Jamaica')->format('M j, g:i A'),
                $record->code,
            ),
        ]);
    }

    public function epass(Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        if ($home['resident'] === null) {
            throw ApiError::forbidden('account_pending', 'This account is not linked to a resident on the register yet.');
        }

        $pass = $this->passes->residentPass($context->tenantId, $home['unit'], $home['resident']->id, $home['resident']->full_name, $request->boolean('simulated'));

        return response()->json([
            ...$this->shape($pass),
            'refresh_after' => $pass->valid_to->copy()->subHour()->toIso8601String(),
        ]);
    }

    /** Board resident-app-07: the household answers a guard's walk-up request. */
    public function respond(Request $request, int $approval, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        $data = $request->validate(['decision' => ['required', 'string', 'in:approve,deny']]);

        $row = DB::connection('tenant')->table('gate_approval_requests')->where('id', $approval)->where('unit_id', $home['unit']->id)->first();

        if ($row === null) {
            throw ApiError::notFound('not_found', 'No request from the gate for your household with that id.');
        }

        if ($row->status === 'pending' && Carbon::parse((string) $row->respond_by)->isPast()) {
            DB::connection('tenant')->table('gate_approval_requests')->where('id', $approval)->update(['status' => 'expired', 'updated_at' => Carbon::now()]);

            throw ApiError::conflict('approval_expired', 'The guard waited and the time ran out, so the estate\'s policy for unannounced visitors applied. Call the gate if you are expecting them.');
        }

        if ($row->status !== 'pending') {
            throw ApiError::conflict('approval_decided', 'This request was already answered ('.$row->status.').');
        }

        DB::connection('tenant')->table('gate_approval_requests')->where('id', $approval)->where('status', 'pending')->update([
            'status' => $data['decision'] === 'approve' ? 'approved' : 'denied',
            'responded_at' => Carbon::now(),
            'responded_by_account_id' => $home['account']->id,
            'responded_by_name' => $home['resident']->full_name ?? $home['account']->full_name,
            'updated_at' => Carbon::now(),
        ]);

        $row = DB::connection('tenant')->table('gate_approval_requests')->where('id', $approval)->first();

        return response()->json([
            'approval_id' => (int) $row->id,
            'status' => (string) $row->status,
            'visitor_name' => (string) $row->visitor_name,
            'responded_at' => Carbon::parse((string) $row->responded_at)->toIso8601String(),
        ]);
    }

    private function mine(int $id, DeviceContext $context): VisitorPass
    {
        $home = $this->accounts->home($context);

        $pass = VisitorPass::query()->with('unit')->whereKey($id)
            ->where('unit_id', $home['unit']->id)
            ->where('category', '!=', VisitorPasses::RESIDENT)
            ->first();

        if ($pass === null) {
            throw ApiError::notFound('not_found', 'No pass of your household\'s with that id.');
        }

        return $pass;
    }

    /** @return array<string, mixed> */
    private function shape(VisitorPass $p): array
    {
        $status = $p->status === VisitorPass::ACTIVE && $p->valid_to->isPast() ? 'expired' : $p->status;

        return [
            'id' => $p->id,
            'pass_id' => $p->pass_id,
            'category' => $p->category,
            'visitor_name' => $p->visitor_name,
            'visitor_phone' => $p->visitor_phone,
            'purpose' => $p->purpose,
            'vehicle_plate' => $p->vehicle_plate,
            'valid_from' => $p->valid_from->toIso8601String(),
            'valid_to' => $p->valid_to->toIso8601String(),
            'single_use' => $p->single_use,
            'status' => $status,
            'code' => $p->code,
            'token' => $p->token,
            'share_count' => (int) $p->share_count,
            'used_at' => $p->used_at?->toIso8601String(),
            'cancelled_at' => $p->cancelled_at?->toIso8601String(),
        ];
    }
}
