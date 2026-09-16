<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Guard;
use App\Models\Shift;
use App\Support\Geo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The guard's roster, pre-flight, breaks and open shifts (13 D2).
 *
 * Boards guard-app-01 (clock-in, dashboard), -05 (my hours), -06 (open shifts),
 * -10 (clock-in denied, flagged). Clocking on and off is `ShiftController`.
 *
 * INVARIANT 2. A shift carries a post, a site and times. No rate, no pay: hours
 * are reported as hours, and what they are worth is the payslip's.
 */
class ShiftsController extends Controller
{
    /** How early a guard may clock on before the rostered start. */
    private const EARLIEST_MINUTES = 60;

    /** The longest range `GET /shifts/me` answers, in days. */
    private const MAX_RANGE_DAYS = 31;

    /** How far ahead open shifts are offered. */
    private const OPEN_DAYS_AHEAD = 14;

    /** Rest a guard should have between shifts, for the claim warning. */
    private const REST_HOURS = 11;

    public function current(DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();
        $now = Carbon::now();

        $shift = Shift::query()->with(['post', 'estate'])
            ->where('guard_id', $guard->id)
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->orderByDesc('actual_start')
            ->first()
            ?? Shift::query()->with(['post', 'estate'])
                ->where('guard_id', $guard->id)
                ->whereNull('actual_start')
                ->where('rostered_end', '>=', $now)
                ->orderBy('rostered_start')
                ->first();

        if ($shift === null) {
            return response()->json(['shift' => null]);
        }

        $previousNote = Shift::query()
            ->where('post_id', $shift->post_id)
            ->where('id', '!=', $shift->id)
            ->whereNotNull('actual_end')
            ->whereNotNull('handover_note')
            ->orderByDesc('actual_end')
            ->value('handover_note');

        $openBreak = DB::connection('mysql')->table('shift_breaks')->where('shift_id', $shift->id)->whereNull('ended_at')->first();

        $colleagues = Shift::query()->with(['officer', 'post'])
            ->where('tenant_id', $shift->tenant_id)
            ->where('guard_id', '!=', $guard->id)
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->orderBy('post_id')
            ->get()
            ->map(static fn (Shift $s): array => ['name' => (string) $s->officer?->full_name, 'post' => (string) $s->post?->name])
            ->values()
            ->all();

        return response()->json([
            'shift' => [
                ...$this->shape($shift),
                'on_break' => $openBreak !== null,
                'break_minutes' => $this->breakMinutes($shift),
                'previous_handover_note' => $previousNote,
                'orders_to_acknowledge' => $this->ordersToAcknowledge($guard),
                'colleagues' => $colleagues,
            ],
        ]);
    }

    public function mine(Request $request, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();

        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            throw ApiError::unprocessable('range_too_long', 'Ask for at most '.self::MAX_RANGE_DAYS.' days at a time.');
        }

        $shifts = Shift::query()->with(['post', 'estate'])
            ->where('guard_id', $guard->id)
            ->whereBetween('rostered_start', [$from, $to])
            ->orderBy('rostered_start')
            ->get();

        $items = $shifts->map(fn (Shift $s): array => [
            ...$this->shape($s),
            'worked_hours' => $this->workedHours($s),
            'night_hours' => $this->nightHours($s),
        ])->all();

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'items' => $items,
            'totals' => [
                'worked_hours' => round(array_sum(array_column($items, 'worked_hours')), 2),
                'night_hours' => round(array_sum(array_column($items, 'night_hours')), 2),
                'shifts' => count($items),
            ],
        ]);
    }

    /**
     * Pre-flight before clock-in — boards guard-app-01, -10.
     *
     * ADVISORY, AND THE APP ACTS ON IT. `allowed: false` is what draws "Move
     * closer to clock in". The clock-in endpoint still records rather than
     * refuses, so a handset whose GPS is wrong can never leave a post reading as
     * unmanned; what it records is the distance and the flag, for a supervisor.
     */
    public function preflight(Request $request, int $shift, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();
        $record = $this->theirs($shift, $guard);

        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'mock_location' => ['nullable', 'boolean'],
            'device_uid' => ['nullable', 'string', 'max:120'],
        ]);

        $now = Carbon::now();
        $checks = [];

        $windowOpen = $now->greaterThanOrEqualTo($record->rostered_start->copy()->subMinutes(self::EARLIEST_MINUTES))
            && $now->lessThanOrEqualTo($record->rostered_end);
        $checks[] = ['key' => 'window', 'passed' => $windowOpen, 'detail' => $windowOpen
            ? 'Within the clock-in window.'
            : 'Clock-in opens '.self::EARLIEST_MINUTES.' minutes before the shift and closes when it ends.'];

        $deviceMatches = ($data['device_uid'] ?? null) === null || $guard->device_uid === null || $guard->device_uid === $data['device_uid'];
        $checks[] = ['key' => 'device', 'passed' => $deviceMatches, 'detail' => $deviceMatches
            ? 'This is the handset bound to you.'
            : 'This handset is not the one bound to you. Ask the office to rebind it.'];

        $post = $record->post;
        $distance = null;
        $within = null;
        $radius = (int) ($post->geofence_radius_m ?? 5);

        if ($post !== null && $post->latitude !== null && $post->longitude !== null && isset($data['latitude'], $data['longitude'])) {
            $distance = round(Geo::metresBetween((float) $post->latitude, (float) $post->longitude, (float) $data['latitude'], (float) $data['longitude']), 1);
            $within = $distance <= $radius;
            $checks[] = ['key' => 'geofence', 'passed' => $within, 'detail' => $within
                ? $distance.'m from '.$post->name.', within '.$radius.'m.'
                : $distance.'m from '.$post->name.'. You need to be within '.$radius.'m.'];
        } elseif ($post !== null && ($post->latitude === null || $post->longitude === null)) {
            $checks[] = ['key' => 'geofence', 'passed' => true, 'detail' => $post->name.' has not been surveyed, so your distance cannot be checked. Clock-in is recorded without one.'];
        } else {
            $checks[] = ['key' => 'geofence', 'passed' => false, 'detail' => 'No location from this handset. Allow location to clock in.'];
            $within = false;
        }

        $mock = (bool) ($data['mock_location'] ?? false);
        $checks[] = ['key' => 'location_integrity', 'passed' => ! $mock, 'detail' => $mock
            ? 'This device\'s location settings appear altered. You can still clock in; it will be flagged for review.'
            : 'No mock location detected.'];

        $blocking = array_filter($checks, static fn (array $c): bool => ! $c['passed'] && $c['key'] !== 'location_integrity');

        return response()->json([
            'shift_id' => $record->id,
            'allowed' => $blocking === [],
            'flagged_for_review' => $mock,
            'distance_m' => $distance,
            'radius_m' => $radius,
            'within_geofence' => $within,
            'checks' => $checks,
        ]);
    }

    public function breakStart(int $shift, DeviceContext $context): JsonResponse
    {
        $record = $this->theirs($shift, $context->guardOrFail());

        if ($record->actual_start === null || $record->actual_end !== null) {
            throw ApiError::conflict('shift_not_on_duty', 'A break is taken during a shift you are clocked on to.');
        }

        $open = DB::connection('mysql')->table('shift_breaks')->where('shift_id', $record->id)->whereNull('ended_at')->first();

        $id = $open->id ?? DB::connection('mysql')->table('shift_breaks')->insertGetId([
            'shift_id' => $record->id,
            'started_at' => $context->serverTime,
            'device_started_at' => $context->deviceTime,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return response()->json($this->breakShape((int) $id), $open === null ? 201 : 200);
    }

    public function breakEnd(int $shift, DeviceContext $context): JsonResponse
    {
        $record = $this->theirs($shift, $context->guardOrFail());

        $open = DB::connection('mysql')->table('shift_breaks')->where('shift_id', $record->id)->whereNull('ended_at')->orderByDesc('id')->first();

        if ($open === null) {
            throw ApiError::conflict('no_break_open', 'There is no break open on this shift to end.');
        }

        DB::connection('mysql')->table('shift_breaks')->where('id', $open->id)->update([
            'ended_at' => $context->serverTime,
            'device_ended_at' => $context->deviceTime,
            'updated_at' => Carbon::now(),
        ]);

        return response()->json($this->breakShape((int) $open->id));
    }

    public function open(DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();
        $now = Carbon::now();

        $mine = Shift::query()->where('guard_id', $guard->id)
            ->where('rostered_end', '>=', $now->copy()->subDay())
            ->get(['rostered_start', 'rostered_end']);

        $claims = DB::connection('mysql')->table('shift_claims')->where('guard_id', $guard->id)->pluck('status', 'shift_id');

        $items = Shift::query()->with(['post', 'estate'])
            ->whereNull('guard_id')
            ->where('tenant_id', $context->tenantId)
            ->whereBetween('rostered_start', [$now, $now->copy()->addDays(self::OPEN_DAYS_AHEAD)])
            ->orderBy('rostered_start')
            ->get()
            ->map(function (Shift $s) use ($mine, $claims): array {
                $restWarning = $mine->contains(fn (Shift $m): bool => abs($m->rostered_end->diffInHours($s->rostered_start, false)) < self::REST_HOURS
                    || abs($s->rostered_end->diffInHours($m->rostered_start, false)) < self::REST_HOURS);

                return [
                    'id' => $s->id,
                    'post' => ['id' => $s->post_id, 'name' => (string) $s->post?->name],
                    'site' => ['id' => (string) $s->tenant_id, 'name' => (string) ($s->estate->name ?? $s->tenant_id)],
                    'rostered_start' => $s->rostered_start->toIso8601String(),
                    'rostered_end' => $s->rostered_end->toIso8601String(),
                    'hours' => round($s->rostered_start->diffInMinutes($s->rostered_end) / 60, 2),
                    'claim_status' => $claims[$s->id] ?? null,
                    'rest_warning' => $restWarning,
                ];
            })
            ->all();

        return response()->json(['items' => $items]);
    }

    public function claim(int $shift, DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $open = Shift::query()->whereKey($shift)->where('tenant_id', $context->tenantId)->first();

        if ($open === null) {
            throw ApiError::notFound('not_found', 'No such open shift at your site.');
        }

        if ($open->guard_id !== null) {
            throw ApiError::conflict('shift_not_open', 'This shift has already been filled.');
        }

        if ($open->rostered_start->isPast()) {
            throw ApiError::conflict('shift_started', 'This shift has already started.');
        }

        $existing = DB::connection('mysql')->table('shift_claims')->where('shift_id', $open->id)->where('guard_id', $guard->id)->first();

        $id = $existing->id ?? DB::connection('mysql')->table('shift_claims')->insertGetId([
            'shift_id' => $open->id,
            'guard_id' => $guard->id,
            'status' => 'pending',
            'claimed_at' => $context->serverTime,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $claim = DB::connection('mysql')->table('shift_claims')->where('id', $id)->first();

        return response()->json([
            'claim_id' => (int) $claim->id,
            'shift_id' => (int) $claim->shift_id,
            'status' => (string) $claim->status,
            'claimed_at' => Carbon::parse((string) $claim->claimed_at)->toIso8601String(),
            'requires_approval' => true,
        ], $existing === null ? 201 : 200);
    }

    private function theirs(int $shiftId, Guard $guard): Shift
    {
        $shift = Shift::query()->with('post')->find($shiftId);

        if ($shift === null) {
            throw ApiError::notFound('not_found', 'No such shift.');
        }

        if ($shift->guard_id !== $guard->id) {
            throw ApiError::forbidden('not_your_shift', 'This shift is rostered to another guard.');
        }

        return $shift;
    }

    /** @return array<string, mixed> */
    private function shape(Shift $s): array
    {
        return [
            'id' => $s->id,
            'status' => (string) $s->status,
            'post' => ['id' => $s->post_id, 'name' => (string) $s->post?->name],
            'site' => ['id' => (string) $s->tenant_id, 'name' => (string) ($s->estate->name ?? $s->tenant_id)],
            'rostered_start' => $s->rostered_start->toIso8601String(),
            'rostered_end' => $s->rostered_end->toIso8601String(),
            'actual_start' => $s->actual_start?->toIso8601String(),
            'actual_end' => $s->actual_end?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function breakShape(int $id): array
    {
        $b = DB::connection('mysql')->table('shift_breaks')->where('id', $id)->first();

        return [
            'break_id' => (int) $b->id,
            'shift_id' => (int) $b->shift_id,
            'started_at' => Carbon::parse((string) $b->started_at)->toIso8601String(),
            'ended_at' => $b->ended_at === null ? null : Carbon::parse((string) $b->ended_at)->toIso8601String(),
        ];
    }

    private function breakMinutes(Shift $shift): int
    {
        return (int) DB::connection('mysql')->table('shift_breaks')->where('shift_id', $shift->id)->get()
            ->sum(static fn (object $b): int => (int) Carbon::parse((string) $b->started_at)->diffInMinutes($b->ended_at === null ? Carbon::now() : Carbon::parse((string) $b->ended_at)));
    }

    private function workedHours(Shift $s): float
    {
        if ($s->actual_start === null) {
            return 0.0;
        }

        $end = $s->actual_end ?? Carbon::now();

        return round(max(0, $s->actual_start->diffInMinutes($end) - $this->breakMinutes($s)) / 60, 2);
    }

    /** Hours worked between 22:00 and 06:00 — reported, not priced. */
    private function nightHours(Shift $s): float
    {
        if ($s->actual_start === null) {
            return 0.0;
        }

        $minutes = 0;
        $cursor = $s->actual_start->copy()->startOfMinute();
        $end = ($s->actual_end ?? Carbon::now())->copy();

        while ($cursor->lessThan($end)) {
            $hour = (int) $cursor->format('G');
            $minutes += ($hour >= 22 || $hour < 6) ? 1 : 0;
            $cursor->addMinute();
        }

        return round($minutes / 60, 2);
    }

    private function ordersToAcknowledge(Guard $guard): int
    {
        if ($guard->post_id === null) {
            return 0;
        }

        $sets = DB::connection('mysql')->table('standing_order_sets')->where('post_id', $guard->post_id)->get(['id', 'version']);

        return $sets->filter(fn (object $set): bool => ! DB::connection('mysql')->table('standing_order_acknowledgements')
            ->where('standing_order_set_id', $set->id)->where('guard_id', $guard->id)->where('version', $set->version)->exists())->count();
    }
}
