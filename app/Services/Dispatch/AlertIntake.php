<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Events\AlertRaised;
use App\Models\DuressAlert;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Accepts an alert raised on a mobile device.
 *
 * The single entry point for /api/v1 and for the simulator alike, so a
 * simulated alert travels exactly the path a real one will and nothing built
 * against the simulator has to be rewritten when the apps arrive.
 */
class AlertIntake
{
    /**
     * Device and server clocks are considered to disagree beyond this.
     *
     * Two minutes tolerates ordinary drift and a slow upload without masking a
     * device whose clock is genuinely wrong.
     */
    public const CLOCK_SKEW_TOLERANCE_SECONDS = 120;

    public function record(
        string $tenantId,
        string $kind,
        ?int $guardId = null,
        ?string $raisedByName = null,
        ?string $unitReference = null,
        ?float $latitude = null,
        ?float $longitude = null,
        ?CarbonInterface $deviceTime = null,
        bool $capturedOffline = false,
        ?string $idempotencyKey = null,
        bool $isSimulated = false,
    ): DuressAlert {
        /*
         * Idempotency first.
         *
         * An offline device retries on reconnect, and a panic alert duplicated
         * by a retry wastes a dispatcher's attention at the worst moment. The
         * key is client-generated, so retries are safe by construction rather
         * than by the server guessing which rows look alike.
         */
        if ($idempotencyKey !== null) {
            $existing = DuressAlert::where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $serverTime = Carbon::now();

        $alert = DuressAlert::create([
            'tenant_id' => $tenantId,
            'kind' => $kind,
            'guard_id' => $guardId,
            'raised_by_name' => $raisedByName,
            'unit_reference' => $unitReference,
            'status' => 'open',
            'latitude' => $latitude,
            'longitude' => $longitude,

            /*
             * Both times are kept. The device's clock is never rewritten to
             * match the server's: a disagreement is evidence, and silently
             * correcting it destroys the only record that it happened.
             */
            'device_time' => $deviceTime,
            'server_time' => $serverTime,
            'clock_skewed' => $this->clockSkewed($deviceTime, $serverTime),

            'captured_offline' => $capturedOffline,
            'idempotency_key' => $idempotencyKey,
            'is_simulated' => $isSimulated,
        ]);

        /*
         * Broadcast AFTER the row is committed, and only for a genuinely new
         * alert — the idempotent early return above never reaches this line.
         *
         * A retry from a flaky device must not make the queue flash a second
         * time for an alert the dispatcher is already looking at.
         */
        AlertRaised::dispatch($alert);

        return $alert;
    }

    private function clockSkewed(?CarbonInterface $deviceTime, CarbonInterface $serverTime): bool
    {
        if ($deviceTime === null) {
            return false;
        }

        return abs($deviceTime->diffInSeconds($serverTime)) > self::CLOCK_SKEW_TOLERANCE_SECONDS;
    }
}
