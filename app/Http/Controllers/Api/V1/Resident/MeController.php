<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\Tenant;
use App\Services\ResidentApp\ResidentAccounts;
use App\Services\ResidentApp\ResidentSignIn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in resident, their household, and the people and vehicles in it (13 D3).
 * Boards resident-app-02 (home), -09 (household), -10 (profile).
 *
 * A HOUSEHOLD MEMBER ADDED FROM THE APP IS PENDING. The register is the estate's:
 * a resident may say who lives with them, and the estate verifies it on board 31
 * before that person is anyone a guard admits on the household's word.
 */
class MeController extends Controller
{
    public function __construct(private readonly ResidentAccounts $accounts) {}

    public function show(DeviceContext $context): JsonResponse
    {
        return response()->json($this->profile($context));
    }

    public function update(Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        $data = $request->validate([
            'full_name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
        ]);

        $home['account']->forceFill(array_intersect_key($data, ['full_name' => true]))->save();

        // Contact details on the register; the name there is the estate's to change.
        if ($home['resident'] !== null) {
            $home['resident']->forceFill(array_intersect_key($data, ['phone' => true, 'email' => true]))->save();
        }

        return response()->json($this->profile($context));
    }

    public function household(DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $household = $home['household'];

        return response()->json([
            'household' => ['id' => $household->id, 'name' => $household->name],
            'unit' => ['id' => $home['unit']->id, 'reference' => $home['unit']->reference, 'phase' => $home['unit']->block],
            'members' => $household->residents()->orderByDesc('is_primary')->orderBy('full_name')->get()
                ->map(fn (Resident $r): array => $this->memberShape($r))->all(),
            'vehicles' => DB::connection('tenant')->table('household_vehicles')->where('household_id', $household->id)->orderBy('plate')->get()
                ->map(fn (object $v): array => $this->vehicleShape($v))->all(),
            'emergency_contacts' => DB::connection('tenant')->table('household_emergency_contacts')->where('household_id', $household->id)->orderBy('name')->get()
                ->map(fn (object $c): array => $this->contactShape($c))->all(),
            'pending_approvals' => DB::connection('tenant')->table('gate_approval_requests')
                ->where('unit_id', $home['unit']->id)->where('status', 'pending')->where('respond_by', '>=', Carbon::now())
                ->orderBy('requested_at')->get()
                ->map(static fn (object $a): array => [
                    'id' => (int) $a->id,
                    'visitor_name' => (string) $a->visitor_name,
                    'purpose' => $a->purpose,
                    'vehicle_plate' => $a->vehicle_plate,
                    'post_name' => $a->post_name,
                    'guard_name' => (string) $a->guard_name,
                    'requested_at' => Carbon::parse((string) $a->requested_at)->toIso8601String(),
                    'respond_by' => Carbon::parse((string) $a->respond_by)->toIso8601String(),
                ])->all(),
        ]);
    }

    public function members(DeviceContext $context): JsonResponse
    {
        $household = $this->accounts->home($context)['household'];

        return response()->json(['items' => $household->residents()->orderByDesc('is_primary')->orderBy('full_name')->get()
            ->map(fn (Resident $r): array => $this->memberShape($r))->all()]);
    }

    public function addMember(Request $request, DeviceContext $context): JsonResponse
    {
        $household = $this->accounts->home($context)['household'];

        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:160'],
            'relationship' => ['required', 'string', 'in:spouse,child,parent,relative,tenant,domestic_staff,other'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
        ]);

        $member = new Resident;
        $member->forceFill([
            'household_id' => $household->id,
            'full_name' => $data['full_name'],
            'relationship' => $data['relationship'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'is_primary' => false,
            'status' => Resident::PENDING,
        ])->save();

        return response()->json($this->memberShape($member->refresh()), 201);
    }

    public function vehicles(DeviceContext $context): JsonResponse
    {
        $household = $this->accounts->home($context)['household'];

        return response()->json(['items' => DB::connection('tenant')->table('household_vehicles')->where('household_id', $household->id)->orderBy('plate')->get()
            ->map(fn (object $v): array => $this->vehicleShape($v))->all()]);
    }

    public function addVehicle(Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        $data = $request->validate([
            'plate' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9 \-]+$/'],
            'make' => ['nullable', 'string', 'max:40'],
            'model' => ['nullable', 'string', 'max:40'],
            'colour' => ['nullable', 'string', 'max:24'],
        ]);

        $plate = strtoupper(preg_replace('/\s+/', ' ', trim($data['plate'])) ?? '');
        $table = DB::connection('tenant')->table('household_vehicles');
        $existing = (clone $table)->where('household_id', $home['household']->id)->where('plate', $plate)->first();

        $id = $existing->id ?? $table->insertGetId([
            'household_id' => $home['household']->id,
            'plate' => $plate,
            'make' => $data['make'] ?? null,
            'model' => $data['model'] ?? null,
            'colour' => $data['colour'] ?? null,
            'added_by_account_id' => $home['account']->id,
            'is_simulated' => $request->boolean('simulated'),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return response()->json($this->vehicleShape(DB::connection('tenant')->table('household_vehicles')->where('id', $id)->first()), $existing === null ? 201 : 200);
    }

    public function contacts(DeviceContext $context): JsonResponse
    {
        $household = $this->accounts->home($context)['household'];

        return response()->json(['items' => DB::connection('tenant')->table('household_emergency_contacts')->where('household_id', $household->id)->orderBy('name')->get()
            ->map(fn (object $c): array => $this->contactShape($c))->all()]);
    }

    public function addContact(Request $request, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'relationship' => ['nullable', 'string', 'max:40'],
            'phone' => ['required', 'string', 'regex:/^\+?[\d\s\-()]{7,20}$/'],
        ]);

        $id = DB::connection('tenant')->table('household_emergency_contacts')->insertGetId([
            'household_id' => $home['household']->id,
            'name' => $data['name'],
            'relationship' => $data['relationship'] ?? null,
            'phone' => $data['phone'],
            'added_by_account_id' => $home['account']->id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return response()->json($this->contactShape(DB::connection('tenant')->table('household_emergency_contacts')->where('id', $id)->first()), 201);
    }

    /** @return array<string, mixed> */
    private function profile(DeviceContext $context): array
    {
        $account = $context->residentOrFail();
        $unit = $account->unit_id === null ? null : Unit::query()->with('household')->find($account->unit_id);
        $resident = $account->resident_id === null ? null : Resident::query()->find($account->resident_id);
        $claim = $account->claim_id === null ? null : UnitClaim::query()->find($account->claim_id);

        return [
            'account' => [
                'id' => $account->id,
                'status' => $account->status,
                'channel' => $account->channel,
                'destination_hint' => ResidentSignIn::mask($account->destination),
                'full_name' => $account->full_name,
            ],
            'estate' => ['id' => $context->tenantId, 'name' => (string) Tenant::query()->whereKey($context->tenantId)->value('name')],
            'claim' => ResidentAccounts::claimShape($claim),
            'unit' => $unit === null ? null : ['id' => $unit->id, 'reference' => $unit->reference, 'phase' => $unit->block],
            'household' => $unit?->household === null ? null : ['id' => $unit->household->id, 'name' => $unit->household->name],
            'resident' => $resident === null ? null : $this->memberShape($resident),
        ];
    }

    /** @return array<string, mixed> */
    private function memberShape(Resident $r): array
    {
        return [
            'id' => $r->id,
            'full_name' => $r->full_name,
            'relationship' => $r->relationship,
            'is_primary' => (bool) $r->is_primary,
            'status' => $r->status,
            'phone' => $r->phone,
            'email' => $r->email,
        ];
    }

    /** @return array<string, mixed> */
    private function vehicleShape(object $v): array
    {
        return [
            'id' => (int) $v->id,
            'plate' => (string) $v->plate,
            'make' => $v->make,
            'model' => $v->model,
            'colour' => $v->colour,
        ];
    }

    /** @return array<string, mixed> */
    private function contactShape(object $c): array
    {
        return [
            'id' => (int) $c->id,
            'name' => (string) $c->name,
            'relationship' => $c->relationship,
            'phone' => (string) $c->phone,
        ];
    }
}
