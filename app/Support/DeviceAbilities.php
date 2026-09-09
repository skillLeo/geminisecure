<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What a device token is allowed to ask for.
 *
 * A token is not a general key to /api/v1. It is issued to one enrolled
 * handset belonging to one kind of user, and it carries only the abilities
 * that kind of user has a reason to exercise. A guard's phone may raise an
 * alert and adjudicate a gate arrival; a resident's phone may raise an alert
 * and nothing else.
 *
 * Held here rather than as string literals in the route file so that adding an
 * endpoint forces a decision about who may reach it, in a place where the
 * whole set is visible at once.
 */
final class DeviceAbilities
{
    /** Raise a duress or panic alert. Both apps; it is the same event to dispatch. */
    public const RAISE_ALERT = 'alerts:raise';

    /** Ask for an admit / restricted / deny verdict at the gate. Guards only. */
    public const VERIFY_PASS = 'passes:verify';

    /**
     * A guard handset.
     *
     * Deliberately short. Everything a guard's app does beyond these two is
     * either not built yet or is a read of their own record, and each will be
     * added here as its endpoint is written rather than granted in advance.
     *
     * @return list<string>
     */
    public static function forGuard(): array
    {
        return [self::RAISE_ALERT, self::VERIFY_PASS];
    }

    /**
     * A resident handset.
     *
     * No VERIFY_PASS. A resident must not be able to ask the system to
     * adjudicate arrivals at the gate — that is the guard's function, and a
     * resident who could call it could probe which of their neighbours are
     * restricted.
     *
     * @return list<string>
     */
    public static function forResident(): array
    {
        return [self::RAISE_ALERT];
    }
}
