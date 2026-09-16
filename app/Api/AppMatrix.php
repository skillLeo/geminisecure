<?php

declare(strict_types=1);

namespace App\Api;

/**
 * What each mobile app may do — the one source every device ability derives from (13 D1).
 *
 * "Scopes from the matrix. `DeviceEnrolment::GUARD_ABILITIES` is hard-coded and
 * `DeviceAbilities::forGuard` lacks `shifts:clock`. Derive abilities from the
 * role's permissions — one source of truth."
 *
 * WHY THIS IS NOT A ROW IN THE WEB ROLE MATRIX. `App\Enums\Console` records the
 * ruling that the Guard App and the Resident App are not consoles: each has one
 * implicit role (an officer on shift; a resident of a household), no role
 * switching, and no screen that grants or withholds anything. Putting two
 * phantom roles into `roles` would draw them on boards 24 and 45, where nobody
 * can change them. So the apps get their own matrix, in the same notation, and
 * it is the ONLY place an ability is decided:
 *
 *   write   the app may read and change it → abilities `{capability}:read`, `{capability}:write`
 *   read    the app may read it            → ability  `{capability}:read`
 *   none    the app may not reach it       → no ability
 *
 * The token a handset is issued carries `abilitiesFor(app)`; every route in the
 * `Catalogue` names a capability and an access, and its ability middleware is
 * computed from those — never written by hand. `AppMatrixTest` fails if a route
 * asks for an ability no app can hold, if an ability string appears anywhere
 * outside this derivation, or if the two lists disagree.
 */
final class AppMatrix
{
    public const GUARD = 'guard';

    public const RESIDENT = 'resident';

    public const WRITE = 'write';

    public const READ = 'read';

    public const NONE = 'none';

    /**
     * capability => [what it covers, guard, resident]
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const CAPABILITIES = [
        'alerts' => ['Raise, and cancel within the grace period, a panic or duress alert', self::WRITE, self::WRITE],
        'gate' => ['Verify passes, record entries, exits and overrides, search units, read the gate log', self::WRITE, self::NONE],
        'passes' => ['Issue, share, cancel and read visitor passes; the resident\'s own e-pass', self::NONE, self::WRITE],
        'approvals' => ['Answer a guard\'s walk-up entry request for the resident\'s own unit', self::NONE, self::WRITE],
        'shifts' => ['Read the roster, pre-flight, clock on and off, take breaks, claim open shifts', self::WRITE, self::NONE],
        'orders' => ['Read and acknowledge standing orders for the guard\'s posts', self::WRITE, self::NONE],
        'patrol' => ['Read a site\'s checkpoints and scan them', self::WRITE, self::NONE],
        'alertness' => ['Answer a random alertness check', self::WRITE, self::NONE],
        'presence' => ['Report on-post activity and read the guard\'s own activity summary', self::WRITE, self::NONE],
        'incidents' => ['File incident reports with media, and read the guard\'s own', self::WRITE, self::NONE],
        'requests' => ['Raise leave and equipment requests, and read the guard\'s own', self::WRITE, self::NONE],
        'payslips' => ['Read the guard\'s own payslips — their own wage, and nobody else\'s', self::READ, self::NONE],
        'messages' => ['Read and send messages with dispatch', self::WRITE, self::NONE],
        'sync' => ['Upload a queue captured offline, and pull what changed', self::WRITE, self::NONE],
        'household' => ['The resident\'s own profile, household members, vehicles and emergency contacts', self::NONE, self::WRITE],
        'dues' => ['The resident\'s OWN household\'s invoices, balance, statement and receipts', self::NONE, self::READ],
        'payments' => ['Start a payment and manage AutoPay for the resident\'s own household', self::NONE, self::WRITE],
        'tickets' => ['Report maintenance problems with media, and follow them', self::NONE, self::WRITE],
        'notices' => ['Read notices and mark them read', self::NONE, self::WRITE],
        'meetings' => ['Read meetings and RSVP', self::NONE, self::WRITE],
        'elections' => ['Read elections, check eligibility, cast the household\'s ballot, read results', self::NONE, self::WRITE],
        'bookings' => ['Read amenities, book and cancel', self::NONE, self::WRITE],
    ];

    /**
     * Every ability a handset of this app is issued.
     *
     * @return list<string>
     */
    public static function abilitiesFor(string $app): array
    {
        $column = self::column($app);
        $abilities = [];

        foreach (self::CAPABILITIES as $capability => $cells) {
            $level = $cells[$column];

            if ($level === self::READ || $level === self::WRITE) {
                $abilities[] = self::ability($capability, self::READ);
            }

            if ($level === self::WRITE) {
                $abilities[] = self::ability($capability, self::WRITE);
            }
        }

        return $abilities;
    }

    /** The ability a route needing this access to this capability checks. */
    public static function ability(string $capability, string $access): string
    {
        if (! isset(self::CAPABILITIES[$capability]) || ! in_array($access, [self::READ, self::WRITE], true)) {
            throw new \InvalidArgumentException("No capability [{$capability}:{$access}] in the app matrix.");
        }

        return $capability.':'.$access;
    }

    /** Whether an app's handset may reach a capability at this access. */
    public static function allows(string $app, string $capability, string $access): bool
    {
        return in_array(self::ability($capability, $access), self::abilitiesFor($app), true);
    }

    private static function column(string $app): int
    {
        return match ($app) {
            self::GUARD => 1,
            self::RESIDENT => 2,
            default => throw new \InvalidArgumentException("No app [{$app}]."),
        };
    }
}
