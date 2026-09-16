<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Services\Dispatch\ShiftClock;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/shifts/{shift}/clock-in and /clock-out.
 *
 * What board guard-app-01 does when a guard taps "Clock in", and what makes the
 * dispatch map say a post is manned. Until this existed every post on that map
 * was rostered and none was worked, because `shifts.actual_start` had no writer.
 *
 * INVARIANT 2. A shift carries a guard, a post and two timestamps. It does not
 * carry a rate — `guards.standard_rate_minor` is central payroll's and is never
 * read here — so nothing a handset can call returns what anybody is paid.
 *
 * THE RESPONSE TELLS THE HANDSET WHAT THE SERVER THINKS THE TIME IS. A guard
 * clocking on from a device with a wrong clock has to be able to find out, and
 * the shift's own start is the server's. That is the same reason an alert echoes
 * `server_time`: the device is not the authority on when something happened.
 */
class ShiftController extends Controller
{
    public function clockIn(Request $request, Shift $shift, ShiftClock $clock, DeviceContext $context): JsonResponse
    {
        $this->assertTheirs($shift, $context);

        $data = $request->validate([
            /*
             * Distance from the post, in metres, where the handset knows it.
             * STORED AND NEVER ENFORCED — geofencing is deferred (D-033), so
             * this builds the history a rule would one day be written against
             * without refusing anybody on a threshold nobody has agreed.
             */
            'geofence_distance_m' => ['nullable', 'integer', 'min:0', 'max:100000'],

            /*
             * The handset saying its own location provider is being spoofed.
             * Recorded, never refused — see `ShiftClock`. A post reading as
             * unmanned while somebody stands at it is worse than a flag a
             * supervisor has to look at, and refusing would tell whoever
             * spoofed it that they had been caught.
             */
            'mock_location' => ['nullable', 'boolean'],
            'method' => ['nullable', 'string', 'in:app,manual'],

            // The simulator saying so, the way it does on an alert or a gate
            // event — so the coverage board's source badge can tell.
            'simulated' => ['nullable', 'boolean'],
        ]);

        $shift = $clock->clockIn(
            shift: $shift,
            method: $data['method'] ?? ShiftClock::METHOD_APP,
            geofenceDistanceMetres: isset($data['geofence_distance_m']) ? (int) $data['geofence_distance_m'] : null,
            mockLocation: $request->boolean('mock_location'),
            isSimulated: $request->boolean('simulated'),
        );

        return response()->json($this->payload($shift));
    }

    public function clockOut(Request $request, Shift $shift, ShiftClock $clock, DeviceContext $context): JsonResponse
    {
        $this->assertTheirs($shift, $context);

        $data = $request->validate([
            'handover_note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $shift = $clock->clockOut($shift, handoverNote: $data['handover_note'] ?? null);
        } catch (DomainException $e) {
            /*
             * 409 rather than 422. The request is well-formed and the handset
             * did nothing wrong — the shift is simply in a state that cannot
             * accept an ending yet, and the queued clock-in it still holds is
             * what resolves it. A validation error would send the guard looking
             * at their own input.
             */
            throw ApiError::conflict('shift_not_started', $e->getMessage());
        }

        return response()->json($this->payload($shift));
    }

    /**
     * A handset clocks its own guard's shifts and nobody else's (13 D1). Before
     * this, any guard token could start any shift on the platform.
     */
    private function assertTheirs(Shift $shift, DeviceContext $context): void
    {
        if ($shift->guard_id !== $context->guardOrFail()->id) {
            throw ApiError::forbidden('not_your_shift', 'This shift is rostered to another guard. A handset clocks its own guard\'s shifts.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'status' => $shift->status,
            'actual_start' => $shift->actual_start?->toIso8601String(),
            'actual_end' => $shift->actual_end?->toIso8601String(),
            'mock_location_flag' => (bool) $shift->mock_location_flag,
        ];
    }
}
