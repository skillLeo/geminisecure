<?php

declare(strict_types=1);

namespace App\Services\Passes;

use App\Models\Estate\Unit;
use App\Models\Estate\VisitorPass;
use App\Models\Tenant;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Issuing, cancelling and checking visitor passes (13 D1, D3).
 *
 * ISSUE signs the eight-field payload with the site's current key and stores
 * the exact token the QR code carries. VERIFY ONLINE runs the same
 * `OfflinePassVerifier` a handset runs, then adds the two things only the
 * server knows — cancelled, and already used — and, for a single-use pass,
 * consumes it only when the guard records the admission, never on the scan.
 */
class VisitorPasses
{
    /** What a resident may issue a pass for. */
    public const CATEGORIES = ['single', 'recurring', 'contractor', 'delivery'];

    /**
     * A resident's own e-pass — never issued from the visitor form, never
     * restricted (a resident's own entry is exempt, `RestrictionPolicy`), and
     * reusable for its day.
     */
    public const RESIDENT = 'resident';

    /** How long a resident's e-pass runs before the app is handed a fresh one. */
    public const RESIDENT_PASS_HOURS = 24;

    /** The longest a pass may run. Matches the grace a retired key's public half is served for. */
    public const MAX_DAYS = PassSigningKeys::RETIRED_KEY_GRACE_DAYS;

    public function __construct(
        private readonly PassSigningKeys $keys,
        private readonly OfflinePassVerifier $verifier,
    ) {}

    /**
     * @param  array{category: string, visitor_name: string, visitor_phone?: string|null, purpose?: string|null, vehicle_plate?: string|null, valid_from: Carbon, valid_to: Carbon}  $details
     */
    public function issue(string $tenantId, Unit $unit, ?int $residentId, string $residentName, array $details, ?string $idempotencyKey = null, bool $simulated = false): VisitorPass
    {
        if (! in_array($details['category'], [...self::CATEGORIES, self::RESIDENT], true)) {
            throw new DomainException('A pass is for a single visit, a recurring visitor, a contractor or a delivery.');
        }

        $from = $details['valid_from']->copy()->utc();
        $to = $details['valid_to']->copy()->utc();

        if (! $to->greaterThan($from)) {
            throw new DomainException('A pass ends after it starts.');
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw new DomainException('A pass runs for at most '.self::MAX_DAYS.' days. A visitor who comes every month gets a new pass each month.');
        }

        $passId = (string) Str::uuid();
        $nonce = bin2hex(random_bytes(16));
        $singleUse = ! in_array($details['category'], ['recurring', self::RESIDENT], true);

        // Signed with the version current NOW, and the version goes in the payload.
        $version = $this->keys->currentVersion($tenantId);

        $segment = PassToken::encodePayload([
            'pass_id' => $passId,
            'tenant_id' => $tenantId,
            'site_id' => $tenantId,
            'valid_from' => $from->toIso8601ZuluString(),
            'valid_to' => $to->toIso8601ZuluString(),
            'single_use' => $singleUse,
            'nonce' => $nonce,
            'key_version' => $version,
        ]);

        $signed = $this->keys->sign($tenantId, $segment);

        if ($signed['key_version'] !== $version) {
            // A rotation landed between reading the version and signing. Try once more.
            return $this->issue($tenantId, $unit, $residentId, $residentName, $details, $idempotencyKey, $simulated);
        }

        return VisitorPass::create([
            'pass_id' => $passId,
            'unit_id' => $unit->id,
            'household_id' => $unit->household?->id,
            'issued_by_resident_id' => $residentId,
            'issued_by_name' => $residentName,
            'category' => $details['category'],
            'visitor_name' => $details['visitor_name'],
            'visitor_phone' => $details['visitor_phone'] ?? null,
            'purpose' => $details['purpose'] ?? null,
            'vehicle_plate' => $details['vehicle_plate'] ?? null,
            'valid_from' => $from,
            'valid_to' => $to,
            'single_use' => $singleUse,
            'nonce' => $nonce,
            'key_version' => $version,
            'token' => $segment.'.'.PassToken::base64url($signed['signature']),
            'code' => $this->code($tenantId, $unit),
            'status' => VisitorPass::ACTIVE,
            'idempotency_key' => $idempotencyKey,
            'is_simulated' => $simulated,
        ]);
    }

    /**
     * Verify at the gate, online: the offline checks, then cancellation and use.
     *
     * @return array{verdict: string, pass: VisitorPass|null, reason: string}
     */
    public function verifyOnline(string $tenantId, string $tokenOrCode, Carbon $now): array
    {
        $pass = str_contains($tokenOrCode, '.')
            ? null
            : VisitorPass::query()->where('code', strtoupper(trim($tokenOrCode)))->first();

        $token = $pass === null ? $tokenOrCode : $pass->token;

        $offline = $this->verifier->verify($token, [$tenantId => $this->keys->publicKeys($tenantId)], $tenantId, $now);

        if ($offline['verdict'] !== OfflinePassVerifier::VALID_OFFLINE) {
            return ['verdict' => $offline['verdict'], 'pass' => null, 'reason' => $this->reason($offline['verdict'])];
        }

        $pass ??= VisitorPass::query()->where('pass_id', $offline['pass_id'])->first();

        if ($pass === null) {
            // Signed by this site's key and unknown to its database: a restore, or a forgery with a stolen key.
            return ['verdict' => 'unknown_pass', 'pass' => null, 'reason' => 'The signature is this estate\'s, and no such pass is on record. Call the estate office.'];
        }

        if ($pass->status === VisitorPass::CANCELLED) {
            return ['verdict' => 'cancelled', 'pass' => $pass, 'reason' => 'The resident cancelled this pass.'];
        }

        if ($pass->status === VisitorPass::USED && $pass->single_use) {
            return ['verdict' => 'already_used', 'pass' => $pass, 'reason' => 'This single-visit pass was already used at '.$pass->used_at?->format('g:i A, M j').'.'];
        }

        return ['verdict' => 'valid', 'pass' => $pass, 'reason' => 'Valid pass.'];
    }

    /**
     * The resident's own e-pass: the one still good for at least an hour, or a new
     * one for the next day. Signed like any pass, so a guard verifies it offline.
     */
    public function residentPass(string $tenantId, Unit $unit, int $residentId, string $residentName, bool $simulated = false): VisitorPass
    {
        $now = Carbon::now();

        $current = VisitorPass::query()
            ->where('category', self::RESIDENT)
            ->where('issued_by_resident_id', $residentId)
            ->where('unit_id', $unit->id)
            ->where('status', VisitorPass::ACTIVE)
            ->where('valid_from', '<=', $now)
            ->where('valid_to', '>', $now->copy()->addHour())
            ->orderByDesc('valid_to')
            ->first();

        return $current ?? $this->issue($tenantId, $unit, $residentId, $residentName, [
            'category' => self::RESIDENT,
            'visitor_name' => $residentName,
            'purpose' => 'Resident',
            'valid_from' => $now->copy()->subMinutes(5),
            'valid_to' => $now->copy()->addHours(self::RESIDENT_PASS_HOURS),
        ], simulated: $simulated);
    }

    /** A single-use pass is consumed when the admission is recorded, not when it is scanned. */
    public function consume(VisitorPass $pass, Carbon $at): void
    {
        if ($pass->single_use && $pass->status === VisitorPass::ACTIVE) {
            $pass->forceFill(['status' => VisitorPass::USED, 'used_at' => $at])->save();
        }
    }

    public function cancel(VisitorPass $pass): VisitorPass
    {
        if ($pass->status === VisitorPass::USED) {
            throw new DomainException('This pass has already been used, so there is nothing left to cancel.');
        }

        if ($pass->status !== VisitorPass::CANCELLED) {
            $pass->forceFill(['status' => VisitorPass::CANCELLED, 'cancelled_at' => Carbon::now()])->save();
        }

        return $pass;
    }

    private function reason(string $verdict): string
    {
        return match ($verdict) {
            OfflinePassVerifier::EXPIRED => 'This pass has expired. Admitting now needs an override with a reason.',
            OfflinePassVerifier::NOT_YET_VALID => 'This pass is not valid yet.',
            OfflinePassVerifier::WRONG_SITE => 'This pass is for a different estate.',
            OfflinePassVerifier::UNKNOWN_KEY, OfflinePassVerifier::BAD_SIGNATURE => 'This pass was not issued by this estate. Do not admit on it.',
            default => 'This is not a pass code this estate issues.',
        };
    }

    /** "PPV2-4471": the estate's receipt prefix and phase, and four digits. Unique within the estate. */
    private function code(string $tenantId, Unit $unit): string
    {
        $prefix = (string) (Tenant::query()->whereKey($tenantId)->value('receipt_prefix') ?? strtoupper(substr($tenantId, 0, 3)));
        $phase = preg_match('/(\d+)/', (string) $unit->block, $m) === 1 ? $m[1] : '0';

        do {
            $code = substr(preg_replace('/[^A-Z]/', '', $prefix) ?: 'GS', 0, 4).$phase.'-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (VisitorPass::query()->where('code', $code)->exists());

        return $code;
    }
}
