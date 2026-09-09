<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Events\AlertRaised;
use App\Models\DuressAlert;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * An alert's life: raised on a device, then moved through its states.
 *
 * `record()` is the single entry point for /api/v1 and for the simulator
 * alike, so a simulated alert travels exactly the path a real one will and
 * nothing built against the simulator has to be rewritten when the apps
 * arrive.
 *
 * `acknowledge()` and `resolve()` are the other end of the same path. They
 * live here rather than in the console controller because the Guard App will
 * shortly call them too — an acknowledgement tapped on a handset and one
 * recorded by a dispatcher must produce the same row and the same audit
 * entry, and two implementations would eventually disagree about that.
 *
 * Both transitions are IDEMPOTENT and never overwrite an earlier one. The
 * first acknowledgement is the one that happened; a second click, a double
 * submit or a retry from a flaky connection must not rewrite who responded or
 * when, because that timestamp is the evidence of how long the person waited.
 */
class AlertIntake
{
    public function __construct(private readonly AuditLogger $audit) {}

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

    /**
     * Record that someone has taken responsibility for this alert.
     *
     * Returns the alert unchanged if it already carries an acknowledgement or
     * has already been closed. Neither is an error: the dispatcher's screen
     * polls every three seconds, so a guard's acknowledgement can land between
     * the click and the request, and refusing it with an exception would show
     * a failure for something that in fact went right.
     */
    public function acknowledge(DuressAlert $alert, User $actor): DuressAlert
    {
        if ($alert->acknowledged_at !== null || $this->isClosed($alert)) {
            return $alert;
        }

        $before = ['status' => $alert->status, 'acknowledged_at' => null];

        $alert->forceFill([
            'status' => 'acknowledged',
            'acknowledged_by' => $actor->id,
            'acknowledged_at' => Carbon::now(),
        ])->save();

        /*
         * Audited, because this is a claim about a life-safety response.
         * "Marcus was acknowledged as responding at 6:44" is a statement
         * someone may later have to answer for, and the log is the only place
         * that records who made it from a console rather than from a handset.
         */
        /*
         * `alert.*`, not `dispatch.*`.
         *
         * The audit log's reader classifies an entry by the domain the action
         * names before the dot, and `alert` is the domain it already knows as
         * Dispatch. A name of my own would still render, but under a category
         * of its own, and the log would start describing dispatch in two
         * vocabularies.
         */
        $this->audit->record(
            action: 'alert.acknowledged',
            entityType: 'DuressAlert',
            entityId: (string) $alert->id,
            before: $before,
            after: [
                'status' => $alert->status,
                'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
            ],
            tenantId: $alert->tenant_id,
        );

        return $alert;
    }

    /**
     * Close an alert out.
     *
     * `false_alarm` is a first-class outcome rather than a failure: a resident
     * who pressed panic and is safe has used the system correctly, and burying
     * that under a generic "resolved" would lose the one signal that tells
     * dispatch which alerts to review.
     */
    public function resolve(DuressAlert $alert, User $actor, ?string $note = null, bool $falseAlarm = false): DuressAlert
    {
        if ($this->isClosed($alert)) {
            return $alert;
        }

        $before = ['status' => $alert->status, 'resolved_at' => null];

        $alert->forceFill([
            'status' => $falseAlarm ? 'false_alarm' : 'resolved',
            'resolved_at' => Carbon::now(),
            'resolution_note' => $note,

            /*
             * Closing an alert nobody acknowledged still records who was
             * there. Leaving acknowledged_by null would read, later, as though
             * the alert closed itself.
             */
            'acknowledged_by' => $alert->acknowledged_by ?? $actor->id,
        ])->save();

        $this->audit->record(
            action: 'alert.resolved',
            entityType: 'DuressAlert',
            entityId: (string) $alert->id,
            before: $before,
            after: [
                'status' => $alert->status,
                'resolved_at' => $alert->resolved_at?->toIso8601String(),
                'resolution_note' => $note,
            ],
            tenantId: $alert->tenant_id,
        );

        return $alert;
    }

    private function isClosed(DuressAlert $alert): bool
    {
        return in_array($alert->status, ['resolved', 'false_alarm'], true);
    }

    private function clockSkewed(?CarbonInterface $deviceTime, CarbonInterface $serverTime): bool
    {
        if ($deviceTime === null) {
            return false;
        }

        return abs($deviceTime->diffInSeconds($serverTime)) > self::CLOCK_SKEW_TOLERANCE_SECONDS;
    }
}
