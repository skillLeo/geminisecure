<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Models\Shift;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * Clocking on and off a post, from a handset.
 *
 * The other half of what makes the Gemini console live: `AlertIntake` records
 * what goes wrong at an estate, `GateLog` records who comes in, and this records
 * whether anybody was there at all. `PostCoverage` and the dispatch map read
 * `shifts.actual_start`; until something wrote it, every post on the live map
 * was rostered and none was manned.
 *
 * BOTH TRANSITIONS ARE IDEMPOTENT AND NEITHER OVERWRITES AN EARLIER ONE, for
 * `AlertIntake::acknowledge()`'s reason: the first clock-in is the one that
 * happened. A double tap, a retry from a tunnel, or a handset syncing a queue it
 * captured offline must not rewrite when somebody arrived — that timestamp is
 * what a shift is paid against and what a coverage report is built from.
 *
 * A MOCK LOCATION IS RECORDED, NEVER REFUSED. A guard whose handset is
 * reporting a spoofed position is a serious thing, and the instinct is to
 * reject the clock-in. That would be wrong twice over: it would leave a post
 * reading as unmanned when somebody is standing at it, and it would tell
 * whoever spoofed the location that they had been caught. The shift starts, the
 * flag is set, and it surfaces where it belongs — in front of a supervisor.
 *
 * GEOFENCING IS DEFERRED (D-033), and this is where that shows. The distance is
 * STORED whenever a device sends one, so the day a rule is written there is
 * history to write it against; nothing here refuses a clock-in for being far
 * from the post, because no distance has been agreed and refusing on an invented
 * one would strand a guard outside a gate they are standing at.
 */
class ShiftClock
{
    /** Started from the handset at the post, which is the ordinary way. */
    public const METHOD_APP = 'app';

    /** Started by a supervisor on the guard's behalf. */
    public const METHOD_MANUAL = 'manual';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Record that a guard has arrived and started work.
     *
     * Returns the shift unchanged if it already carries a start. That is not an
     * error: a handset with a queued clock-in reconnects and sends it again,
     * and answering with a failure would show a guard that their arrival had
     * not registered when it had.
     */
    public function clockIn(
        Shift $shift,
        ?CarbonInterface $at = null,
        string $method = self::METHOD_APP,
        ?int $geofenceDistanceMetres = null,
        bool $mockLocation = false,
    ): Shift {
        if ($shift->actual_start !== null) {
            return $shift;
        }

        $shift->forceFill([
            'actual_start' => $at ?? Carbon::now(),
            'status' => 'on_duty',
            'start_method' => $method,
            'geofence_distance_m' => $geofenceDistanceMetres,
            'mock_location_flag' => $mockLocation,
        ])->save();

        /*
         * Audited only when something about it is worth a supervisor's
         * attention. A guard clocking on at their own post from their own
         * handset is the system working, and logging four hundred of those a
         * week would bury the two that matter.
         */
        if ($mockLocation || $method === self::METHOD_MANUAL) {
            $this->audit->record(
                action: 'shift.clocked_in',
                entityType: 'Shift',
                entityId: (string) $shift->id,
                before: ['actual_start' => null],
                after: [
                    'actual_start' => $shift->actual_start?->toIso8601String(),
                    'start_method' => $method,
                    'mock_location_flag' => $mockLocation,
                ],
                tenantId: $shift->tenant_id,
            );
        }

        return $shift;
    }

    /**
     * Record that a guard has finished.
     *
     * REFUSED IF THEY NEVER STARTED, and this one is a real refusal rather than
     * a quiet return. A shift with an end and no beginning is a row nothing can
     * interpret: it cannot be paid, cannot be counted as coverage, and would
     * appear on a roster as a guard who left without arriving. The handset has
     * a queued clock-in to send first.
     */
    public function clockOut(Shift $shift, ?CarbonInterface $at = null): Shift
    {
        if ($shift->actual_start === null) {
            throw new DomainException(
                'This shift has no start to end. A clock-out without a clock-in is a shift nobody can '.
                'pay or count as coverage — the handset has an arrival still to send.'
            );
        }

        if ($shift->actual_end !== null) {
            return $shift;
        }

        $shift->forceFill([
            'actual_end' => $at ?? Carbon::now(),
            'status' => 'completed',
        ])->save();

        return $shift;
    }
}
