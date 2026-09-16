<?php

declare(strict_types=1);

namespace App\Services\Devices;

use App\Api\ApiError;
use App\Api\AppMatrix;
use App\Models\Guard;
use App\Models\Shift;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Binding a handset to a guard, and issuing the token it speaks with.
 *
 * ONE DEVICE PER GUARD, AND BINDING A NEW ONE REVOKES THE OLD. `Guard::
 * deviceIsBound()` already says why: the binding is what stops one phone
 * starting shifts for several people. A guard who replaces a lost handset must
 * end up with exactly one working token, or the lost one keeps clocking them in.
 *
 * THE ABILITIES COME FROM THE APP MATRIX (13 D1). A Guard App handset carries
 * `AppMatrix::abilitiesFor(GUARD)` — computed, never listed here — so the token
 * and the routes cannot disagree about what a guard's phone may do.
 *
 * THREE WAYS IN (13 D1):
 *
 *   `device:enrol` at the office      the operator's bootstrap; prints a token
 *   a one-time enrolment code         `POST /devices/enrol` with the code, the
 *                                     handset's UID, platform and public key
 *   a REBIND                          a guard who already has a handset: the
 *                                     request waits for a supervisor, whose
 *                                     decision is recorded against the guard's
 *                                     shift, and only then does the old token die
 *
 * THE PLAINTEXT TOKEN IS RETURNED ONCE AND NEVER STORED. Sanctum keeps a hash.
 */
class DeviceEnrolment
{
    /** How long an enrolment code works. */
    public const CODE_HOURS = 24;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Bind a handset to this guard and issue its token.
     *
     * @param  array{device_uid?: string|null, platform?: string|null, public_key?: string|null}  $device
     * @return array{device_id: string, token: string, abilities: list<string>}
     */
    public function enrol(Guard $guard, ?string $deviceLabel = null, array $device = []): array
    {
        if ($guard->status !== 'active') {
            throw new DomainException(
                $guard->full_name.' is '.$guard->statusLabel().'. A handset is bound to somebody who is '.
                'working, and a token issued to a suspended guard is a credential nobody is watching.'
            );
        }

        /*
         * Revoked BEFORE the new one is minted, not after. A failure between the
         * two must leave a guard with no handset rather than with two.
         */
        $this->revoke($guard);

        $deviceId = (string) Str::uuid();
        $abilities = AppMatrix::abilitiesFor(AppMatrix::GUARD);

        $token = $guard->createToken(
            name: $deviceLabel ?? ('Handset — '.$guard->employee_number),
            abilities: $abilities,
        );

        $guard->forceFill([
            'device_id' => $deviceId,
            'device_label' => $deviceLabel ?? $guard->device_label,
            'device_uid' => $device['device_uid'] ?? null,
            'device_platform' => $device['platform'] ?? null,
            'device_public_key' => $device['public_key'] ?? null,
            'device_enrolled_at' => Carbon::now(),
        ])->save();

        $this->audit->record(
            action: 'device.enrolled',
            entityType: 'Guard',
            entityId: (string) $guard->id,
            before: [],
            after: [
                'device_id' => $deviceId,
                'device_label' => $guard->device_label,
                'device_uid' => $device['device_uid'] ?? null,
                'platform' => $device['platform'] ?? null,
                'abilities' => $abilities,
            ],
            tenantId: $guard->tenant_id,
        );

        return ['device_id' => $deviceId, 'token' => $token->plainTextToken, 'abilities' => $abilities];
    }

    /**
     * Issue a one-time enrolment code for this guard. The code is returned once;
     * only its hash is kept.
     */
    public function issueCode(Guard $guard, ?User $by = null): string
    {
        if ($guard->status !== 'active') {
            throw new DomainException($guard->full_name.' is '.$guard->statusLabel().'. An enrolment code is issued to somebody who is working.');
        }

        // Grouped for reading aloud over a phone: "4F7K-9QXM".
        $code = strtoupper(Str::random(4).'-'.Str::random(4));
        $code = strtr($code, ['O' => '8', '0' => '9', 'I' => '7', 'L' => '6', '1' => '3']);

        DB::connection('mysql')->table('device_enrolment_codes')->insert([
            'guard_id' => $guard->id,
            'code_hash' => hash('sha256', $code),
            'expires_at' => Carbon::now()->addHours(self::CODE_HOURS),
            'issued_by' => $by?->getKey(),
            'issued_by_name' => $by?->name,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->audit->record(
            action: 'device.enrolment_code_issued',
            entityType: 'Guard',
            entityId: (string) $guard->id,
            after: ['expires_in_hours' => self::CODE_HOURS],
            tenantId: $guard->tenant_id,
        );

        return $code;
    }

    /**
     * A handset presenting an enrolment code (`POST /devices/enrol`).
     *
     * @param  array{enrolment_code: string, device_uid: string, platform: string, public_key: string, label?: string|null}  $request
     * @return array{status: string, guard: Guard, token?: string, abilities?: list<string>, rebind_request_id?: int, claim_secret?: string}
     */
    public function enrolWithCode(array $request): array
    {
        $code = DB::connection('mysql')->table('device_enrolment_codes')
            ->where('code_hash', hash('sha256', strtoupper(trim($request['enrolment_code']))))
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if ($code === null) {
            throw ApiError::unprocessable('enrolment_code_invalid', 'This enrolment code is not recognised, has expired or has already been used. Ask the office for a new one.');
        }

        $publicKey = strtr($request['public_key'], '+/', '-_');
        $decoded = base64_decode(strtr(rtrim($publicKey, '='), '-_', '+/').str_repeat('=', (4 - strlen(rtrim($publicKey, '=')) % 4) % 4), true);

        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw ApiError::unprocessable('public_key_invalid', 'The handset\'s public key must be an Ed25519 public key: 32 bytes, base64url-encoded.');
        }

        $guard = Guard::query()->findOrFail($code->guard_id);

        if ($guard->status !== 'active') {
            throw ApiError::forbidden('guard_not_active', $guard->full_name.' is not on active duty, so a handset cannot be bound to them.');
        }

        $device = ['device_uid' => $request['device_uid'], 'platform' => $request['platform'], 'public_key' => rtrim($publicKey, '=')];

        return DB::connection('mysql')->transaction(function () use ($code, $guard, $device, $request): array {
            DB::connection('mysql')->table('device_enrolment_codes')->where('id', $code->id)->update(['used_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

            /*
             * FIRST HANDSET, OR THE SAME ONE AGAIN: bound now. A guard re-enrolling
             * the phone already bound to them (a reinstall) is not a rebind.
             */
            if (! $guard->deviceIsBound() || ($guard->device_uid !== null && $guard->device_uid === $device['device_uid'])) {
                $result = $this->enrol($guard, $request['label'] ?? null, $device);

                return ['status' => 'enrolled', 'guard' => $guard, 'token' => $result['token'], 'abilities' => $result['abilities']];
            }

            /*
             * A DIFFERENT HANDSET FOR A GUARD WHO ALREADY HAS ONE. Not bound until a
             * supervisor says so: a lost phone reported by the guard and a cloned
             * code used by somebody else look identical from here.
             */
            $secret = Str::random(40);

            $requestId = DB::connection('mysql')->table('device_rebind_requests')->insertGetId([
                'guard_id' => $guard->id,
                'enrolment_code_id' => $code->id,
                'device_uid' => $device['device_uid'],
                'platform' => $device['platform'],
                'public_key' => $device['public_key'],
                'label' => $request['label'] ?? null,
                'claim_hash' => hash('sha256', $secret),
                'status' => 'pending',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            $this->audit->record(
                action: 'device.rebind_requested',
                entityType: 'Guard',
                entityId: (string) $guard->id,
                after: ['request' => $requestId, 'device_uid' => $device['device_uid'], 'platform' => $device['platform']],
                tenantId: $guard->tenant_id,
            );

            return ['status' => 'pending_approval', 'guard' => $guard, 'rebind_request_id' => $requestId, 'claim_secret' => $secret];
        });
    }

    /**
     * A supervisor's decision on a rebind, recorded against the guard's shift.
     *
     * THE SHIFT IS THE ONE THE GUARD IS ON NOW, or the next one rostered — the
     * shift the new handset will first clock. Recorded on the request so the
     * roster can say "handset replaced, approved by Owen Grant" beside it.
     */
    public function decideRebind(int $requestId, bool $approve, User $by, ?string $note = null): object
    {
        return DB::connection('mysql')->transaction(function () use ($requestId, $approve, $by, $note): object {
            $request = DB::connection('mysql')->table('device_rebind_requests')->where('id', $requestId)->lockForUpdate()->first();

            if ($request === null || $request->status !== 'pending') {
                throw new DomainException('That rebind request has already been decided.');
            }

            $shift = Shift::query()
                ->where('guard_id', $request->guard_id)
                ->where('rostered_end', '>=', Carbon::now())
                ->orderBy('rostered_start')
                ->first();

            DB::connection('mysql')->table('device_rebind_requests')->where('id', $requestId)->update([
                'status' => $approve ? 'approved' : 'denied',
                'decided_by' => $by->getKey(),
                'decided_by_name' => $by->name,
                'decided_at' => Carbon::now(),
                'decision_note' => $note,
                'shift_id' => $shift?->id,
                'updated_at' => Carbon::now(),
            ]);

            $guard = Guard::query()->findOrFail($request->guard_id);

            $this->audit->record(
                action: $approve ? 'device.rebind_approved' : 'device.rebind_denied',
                entityType: 'Guard',
                entityId: (string) $guard->id,
                after: ['request' => $requestId, 'shift' => $shift?->id, 'note' => $note],
                tenantId: $guard->tenant_id,
            );

            return DB::connection('mysql')->table('device_rebind_requests')->where('id', $requestId)->first();
        });
    }

    /**
     * The handset collecting its token after approval. The token is minted HERE,
     * at collection — the old handset keeps working until the new one is actually
     * in the guard's hand.
     *
     * @return array{status: string, token?: string, abilities?: list<string>, decision_note?: string|null}
     */
    public function collect(int $requestId, string $claimSecret, string $deviceUid): array
    {
        return DB::connection('mysql')->transaction(function () use ($requestId, $claimSecret, $deviceUid): array {
            $request = DB::connection('mysql')->table('device_rebind_requests')->where('id', $requestId)->lockForUpdate()->first();

            if ($request === null || ! hash_equals((string) $request->claim_hash, hash('sha256', $claimSecret)) || $request->device_uid !== $deviceUid) {
                throw ApiError::notFound('not_found', 'No such rebind request for this handset.');
            }

            if ($request->status !== 'approved') {
                return ['status' => $request->status === 'pending' ? 'pending_approval' : (string) $request->status, 'decision_note' => $request->decision_note];
            }

            $guard = Guard::query()->findOrFail($request->guard_id);
            $result = $this->enrol($guard, $request->label, [
                'device_uid' => $request->device_uid,
                'platform' => $request->platform,
                'public_key' => $request->public_key,
            ]);

            DB::connection('mysql')->table('device_rebind_requests')->where('id', $requestId)->update(['status' => 'collected', 'updated_at' => Carbon::now()]);

            return ['status' => 'approved', 'token' => $result['token'], 'abilities' => $result['abilities']];
        });
    }

    /**
     * Take a handset out of service. The device binding goes with the tokens.
     */
    public function revoke(Guard $guard): void
    {
        $guard->tokens()->delete();

        if ($guard->device_id !== null) {
            $guard->forceFill(['device_id' => null])->save();
        }
    }
}
