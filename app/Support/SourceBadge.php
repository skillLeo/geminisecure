<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Says, on a web screen, that the data below it came off a mobile device — and
 * whether a real one produced it.
 *
 * WHY THE CLIENT ASKED FOR THIS. Roughly forty web screens display data the
 * Guard App and the Resident App generate, and neither app exists yet. The
 * simulator drives the same /api/v1 endpoints the real apps will call, which is
 * the right way to build against them — and it means a reviewer looking at the
 * live map cannot tell, from the screen alone, whether the alert in front of
 * them came from a handset at a gate or from `simulate:alerts`. The badge says
 * so, at a glance and without asking.
 *
 * IT IS COMPUTED FROM THE ROWS ON THE SCREEN, NEVER FROM THE ENVIRONMENT. The
 * tempting shortcut is `app()->isLocal()`, and it would be wrong twice: a
 * demonstration on a staging host would claim its simulated data was real, and
 * a locally-run integration test against a real handset would claim the
 * opposite. Only the rows know, and four tables carry `is_simulated` for
 * exactly this — `duress_alerts`, `alertness_checks`, `checkpoint_scans` and
 * `gate_events`.
 *
 * MIXED COUNTS AS SIMULATED. A queue holding nine real alerts and one simulated
 * one is not a real queue: the figure a reviewer is about to quote includes a
 * number nobody dialled. Any is enough.
 */
final class SourceBadge
{
    /** Data the Guard App produces: alerts, scans, gate decisions, shifts. */
    public const GUARD = 'guard';

    /** Data the Resident App produces: panic, passes, bookings, requests. */
    public const RESIDENT = 'resident';

    /**
     * The badge for a Guard App screen.
     *
     * @param  bool  $simulated  whether ANY row on the screen was simulated
     * @return array{source: string, simulated: bool}
     */
    public static function guard(bool $simulated): array
    {
        return ['source' => self::GUARD, 'simulated' => $simulated];
    }

    /**
     * The badge for a Resident App screen.
     *
     * @return array{source: string, simulated: bool}
     */
    public static function resident(bool $simulated): array
    {
        return ['source' => self::RESIDENT, 'simulated' => $simulated];
    }

    /**
     * Whether anything in this collection was simulated.
     *
     * Takes what the screen has ALREADY FETCHED rather than issuing a second
     * query. A badge that ran its own `exists()` against the same table would
     * be answering about the table rather than about the rows in front of the
     * reader — and on a filtered screen those are different questions.
     *
     * @param  iterable<int, object|array<string, mixed>>  $rows
     */
    public static function anySimulated(iterable $rows): bool
    {
        foreach ($rows as $row) {
            $flag = is_array($row) ? ($row['is_simulated'] ?? false) : ($row->is_simulated ?? false);

            if ((bool) $flag) {
                return true;
            }
        }

        return false;
    }
}
