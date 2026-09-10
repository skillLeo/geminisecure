<?php

declare(strict_types=1);

namespace App\Services\Devices;

use App\Models\Guard;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Str;

/**
 * Binding a handset to a guard, and issuing the token it speaks with.
 *
 * THE MISSING HALF OF /api/v1. Every endpoint there is behind `auth:sanctum`
 * and an ability, and until this existed nothing could mint a token — so the
 * API was unreachable by anything, including the simulator that was written to
 * exercise it. `simulate:alerts` had been answering 401 since the day the
 * endpoints were closed, and reporting it as a failed run nobody read.
 *
 * ONE DEVICE PER GUARD, AND BINDING A NEW ONE REVOKES THE OLD. `Guard::
 * deviceIsBound()` already says why: the binding is what stops one phone
 * starting shifts for several people. A guard who replaces a lost handset must
 * end up with exactly one working token, or the lost one keeps clocking them in.
 *
 * THE ABILITIES ARE THE WHOLE OF WHAT A HANDSET CAN DO, and they are granted
 * per kind of app rather than per guard. A Guard App handset raises alerts,
 * adjudicates scans, writes the gate log and clocks on; it cannot read a
 * balance, because no endpoint it holds an ability for returns one — invariant
 * 2, enforced at the token as well as at the payload.
 *
 * THE PLAINTEXT IS RETURNED ONCE AND NEVER STORED. Sanctum keeps a hash; if the
 * value is lost the handset is enrolled again. That is the correct trade and it
 * is why this returns the token rather than writing it anywhere.
 */
class DeviceEnrolment
{
    /**
     * What a Guard App handset may do.
     *
     * Deliberately short, and every entry maps to one endpoint. A new ability
     * here is a new thing a stolen handset can do, so the list is the place to
     * argue about it rather than the routes file.
     */
    public const GUARD_ABILITIES = [
        'alerts:raise',
        'passes:verify',
        'shifts:clock',
    ];

    /**
     * And what a Resident App handset may do.
     *
     * A resident raises a panic alert and nothing else on this list. They do not
     * adjudicate arrivals at the gate and they do not clock anybody on — those
     * are a guard's acts, and a resident's token must not be able to ask the
     * system to perform them.
     */
    public const RESIDENT_ABILITIES = [
        'alerts:raise',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Bind a handset to this guard and issue its token.
     *
     * @return array{device_id: string, token: string}
     */
    public function enrol(Guard $guard, ?string $deviceLabel = null): array
    {
        if ($guard->status !== 'active') {
            throw new DomainException(
                $guard->full_name.' is '.$guard->statusLabel().'. A handset is bound to somebody who is '.
                'working, and a token issued to a suspended guard is a credential nobody is watching.'
            );
        }

        /*
         * Revoked BEFORE the new one is minted, not after. A failure between the
         * two must leave a guard with no handset rather than with two — the
         * first is an enrolment they will retry in a minute, the second is a
         * lost phone that still clocks them in.
         */
        $this->revoke($guard);

        $deviceId = (string) Str::uuid();

        $token = $guard->createToken(
            name: $deviceLabel ?? ('Handset — '.$guard->employee_number),
            abilities: self::GUARD_ABILITIES,
        );

        $guard->forceFill([
            'device_id' => $deviceId,
            'device_label' => $deviceLabel ?? $guard->device_label,
        ])->save();

        /*
         * Audited, because this is the moment a physical object becomes able to
         * raise a life-safety alert in a client's name. "Who enrolled that
         * handset, and when" is the first question asked after one is misused.
         */
        $this->audit->record(
            action: 'device.enrolled',
            entityType: 'Guard',
            entityId: (string) $guard->id,
            before: [],
            after: [
                'device_id' => $deviceId,
                'device_label' => $guard->device_label,
                'abilities' => self::GUARD_ABILITIES,
            ],
            tenantId: $guard->tenant_id,
        );

        return [
            'device_id' => $deviceId,
            'token' => $token->plainTextToken,
        ];
    }

    /**
     * Take a handset out of service.
     *
     * The device binding goes with the tokens. Leaving `device_id` set on a
     * guard whose token has been revoked would make `AdoptionRollup` count them
     * as carrying a bound handset — a client's Guard App coverage reading higher
     * than the number of phones that can actually sign in.
     */
    public function revoke(Guard $guard): void
    {
        $guard->tokens()->delete();

        if ($guard->device_id !== null) {
            $guard->forceFill(['device_id' => null])->save();
        }
    }
}
