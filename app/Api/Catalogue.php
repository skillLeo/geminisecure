<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\DuressController;
use App\Http\Controllers\Api\V1\GateEventController;
use App\Http\Controllers\Api\V1\Guard\GateController;
use App\Http\Controllers\Api\V1\Guard\IncidentsController;
use App\Http\Controllers\Api\V1\Guard\MessagesController;
use App\Http\Controllers\Api\V1\Guard\OrdersController;
use App\Http\Controllers\Api\V1\Guard\PatrolController;
use App\Http\Controllers\Api\V1\Guard\PayslipsController;
use App\Http\Controllers\Api\V1\Guard\PresenceController;
use App\Http\Controllers\Api\V1\Guard\RequestsController;
use App\Http\Controllers\Api\V1\Guard\ShiftsController;
use App\Http\Controllers\Api\V1\Guard\SyncController;
use App\Http\Controllers\Api\V1\PassKeyController;
use App\Http\Controllers\Api\V1\PassVerificationController;
use App\Http\Controllers\Api\V1\Resident\AuthController;
use App\Http\Controllers\Api\V1\Resident\BookingsController;
use App\Http\Controllers\Api\V1\Resident\CommunityController;
use App\Http\Controllers\Api\V1\Resident\DuesController;
use App\Http\Controllers\Api\V1\Resident\ElectionsController;
use App\Http\Controllers\Api\V1\Resident\MeController;
use App\Http\Controllers\Api\V1\Resident\PassesController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\StandingOrderController;

/**
 * Every /api/v1 endpoint, described once (13 D1, C4).
 *
 * THIS IS THE SPECIFICATION, AND EVERYTHING ELSE READS IT:
 *
 *   routes/api.php            registers each route from its entry — method, path,
 *                             middleware, and the ability computed from
 *                             `capability` + `access` through `AppMatrix`
 *   MOBILE_HANDOFF.md         `php artisan api:handoff` writes the endpoint tables
 *                             from these entries, and `MobileHandoffTest` fails
 *                             when the file and the catalogue disagree
 *   the contract tests        every response is checked against `response`: a key
 *                             not listed is a failure, which is the allowlist that
 *                             enforces invariant 2 on every guard route
 *
 * An entry:
 *
 *   method, uri, action       the route
 *   apps                      which apps' tokens reach it (empty: no token)
 *   capability, access        what it needs from `AppMatrix`
 *   write                     writes take Idempotency-Key and X-Device-Time
 *   legacy                    the first seven endpoints, built before the headers
 *                             existed: both are honoured, neither is required
 *   pending                   a Resident App account that has not been approved may reach it
 *   throttle                  the rate limiter
 *   status                    the success status
 *   request                   field => "type · required|optional · what it is"
 *   response                  key path => what it is. `items.*.id` is every item's id.
 *   errors                    "status code" => when; the common errors are listed once
 *   offline                   what the app does with no signal
 */
final class Catalogue
{
    /** How far a handset's clock may disagree before `clock_skewed` is true. */
    public const CLOCK_SKEW_SECONDS = 120;

    /** The errors any authenticated endpoint can return, documented once. */
    public const COMMON_ERRORS = [
        '401 unauthenticated' => 'No token, or a token that has been revoked.',
        '403 missing_ability' => 'The token does not carry the ability this endpoint needs.',
        '403 wrong_app' => 'A Guard App token on a Resident App endpoint, or the reverse.',
        '403 guard_not_active' => 'The guard the token belongs to is suspended, on leave or no longer employed.',
        '403 account_pending' => 'A Resident App account whose unit claim is not yet approved.',
        '403 account_suspended' => 'The estate withdrew this resident\'s access.',
        '403 no_site' => 'The token\'s guard or account is not attached to an estate on this platform.',
        '404 not_found' => 'Nothing at this address, or nothing this handset may see — the two are deliberately the same.',
        '409 request_in_progress' => 'A write with this Idempotency-Key is still being handled. Retry with the same key.',
        '422 validation_failed' => 'A field is missing or malformed. `errors` maps each field to its messages.',
        '422 device_time_required' => 'A write without X-Device-Time.',
        '422 device_time_invalid' => 'X-Device-Time is not an ISO 8601 timestamp.',
        '422 idempotency_key_required' => 'A write without Idempotency-Key.',
        '422 idempotency_key_reused' => 'The key was already used for a different request. Use a new key for a new act.',
        '429 rate_limited' => 'Too many requests from this handset. `Retry-After` says when; retry with the same key.',
    ];

    /**
     * @return array<string, array{method: string, uri: string, action: array{0: class-string, 1: string}, apps: list<string>, capability: string|null, access: string, write: bool, legacy?: bool, pending?: bool, opaque?: bool, batch?: bool, throttle: string, status: int, where?: array<string, string>, summary: string, request: array<string, string>, response: array<string, string>, errors: array<string, string>, offline: string}>
     */
    public static function endpoints(): array
    {
        $endpoints = [
            ...self::devices(),
            ...self::legacyGuard(),
            ...self::guardShifts(),
            ...self::guardPost(),
            ...self::guardGate(),
            ...self::guardReports(),
            ...self::guardSelf(),
            ...self::guardSync(),
            ...self::residentAccount(),
            ...self::residentPasses(),
            ...self::residentDues(),
            ...self::residentCommunity(),
        ];

        /*
         * Every write's response carries both clocks — `DeviceTime` adds them to
         * each successful JSON object a write returns — so every write's entry
         * documents them, whether or not its controller also sets them.
         */
        foreach ($endpoints as $name => $entry) {
            if ($entry['write']) {
                $endpoints[$name]['response'] += self::CLOCKS;
            }
        }

        return $endpoints;
    }

    /** The response keys a write echoes: the server's clock beside the handset's. */
    private const CLOCKS = [
        'server_time' => 'the server\'s time',
        'device_time' => 'the handset\'s time, as sent in X-Device-Time',
        'clock_skewed' => 'true when the two disagree by more than two minutes',
    ];

    /**
     * A shift as every guard endpoint draws it.
     *
     * @return array<string, string>
     */
    private static function shiftKeys(string $prefix): array
    {
        return [
            $prefix.'id' => 'the shift',
            $prefix.'status' => '`rostered`, `open`, `on_duty`, `completed` or `missed`',
            $prefix.'post.id' => 'the post',
            $prefix.'post.name' => 'e.g. "Main Gate"',
            $prefix.'site.id' => 'the estate',
            $prefix.'site.name' => 'its name',
            $prefix.'rostered_start' => 'ISO 8601',
            $prefix.'rostered_end' => 'ISO 8601',
            $prefix.'actual_start' => 'the first clock-in the server recorded, or null',
            $prefix.'actual_end' => 'the clock-out, or null',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function guardShifts(): array
    {
        $guard = [AppMatrix::GUARD];
        $shiftWhere = ['shift' => '[0-9]+'];
        $notYours = ['403 not_your_shift' => 'The shift is rostered to another guard.', '404 not_found' => 'No such shift.'];
        $breakKeys = [
            'break_id' => 'the break',
            'shift_id' => 'its shift',
            'started_at' => 'the server\'s time the break started',
            'ended_at' => 'when it ended, or null while it runs',
        ];

        return [
            'shifts.me.current' => [
                'method' => 'GET', 'uri' => 'shifts/me/current', 'action' => [ShiftsController::class, 'current'],
                'apps' => $guard, 'capability' => 'shifts', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The dashboard: the shift the guard is on, or the next one rostered to them. Board guard-app-01.',
                'request' => [],
                'response' => [
                    'shift' => 'null when nothing is running or rostered ahead',
                    ...self::shiftKeys('shift.'),
                    'shift.on_break' => 'true while a break is open',
                    'shift.break_minutes' => 'breaks taken on this shift so far',
                    'shift.previous_handover_note' => 'what the last guard on this post left for the next, or null',
                    'shift.orders_to_acknowledge' => 'how many of the post\'s order sets this guard has not acknowledged at their current version',
                    'shift.colleagues.*.name' => 'another guard on duty at the same estate now',
                    'shift.colleagues.*.post' => 'their post',
                ],
                'errors' => [],
                'offline' => 'Show the cached response with its age. Clock-in works offline; this screen reflects it after the next sync.',
            ],
            'shifts.me' => [
                'method' => 'GET', 'uri' => 'shifts/me', 'action' => [ShiftsController::class, 'mine'],
                'apps' => $guard, 'capability' => 'shifts', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'My hours: the guard\'s shifts in a range, with hours worked and night hours. Board guard-app-05. Hours, never pay.',
                'request' => [
                    'from' => 'date · required (query) · YYYY-MM-DD',
                    'to' => 'date · required (query) · on or after `from`, at most 31 days later',
                ],
                'response' => [
                    'from' => 'YYYY-MM-DD',
                    'to' => 'YYYY-MM-DD',
                    ...self::shiftKeys('items.*.'),
                    'items.*.worked_hours' => 'clock-in to clock-out less breaks, two decimals',
                    'items.*.night_hours' => 'the part of that between 22:00 and 06:00',
                    'totals.worked_hours' => 'sum',
                    'totals.night_hours' => 'sum',
                    'totals.shifts' => 'count',
                ],
                'errors' => ['422 range_too_long' => 'More than 31 days asked for at once.'],
                'offline' => 'Serve the cached range.',
            ],
            'shifts.preflight' => [
                'method' => 'POST', 'uri' => 'shifts/{shift}/preflight', 'action' => [ShiftsController::class, 'preflight'],
                'apps' => $guard, 'capability' => 'shifts', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => $shiftWhere,
                'summary' => 'Before clock-in: the window, the handset binding, the geofence and location integrity. Advisory — boards guard-app-01 and -10. The coordinates are compared and not stored.',
                'request' => [
                    'latitude' => 'number · optional · omit when location is refused',
                    'longitude' => 'number · optional',
                    'accuracy_m' => 'integer · optional · the fix\'s accuracy in metres',
                    'mock_location' => 'boolean · optional · the OS reports a mocked location',
                    'device_uid' => 'string · optional · the enrolled install UID',
                ],
                'response' => [
                    'shift_id' => 'the shift',
                    'allowed' => 'false draws "Move closer to clock in"; the clock-in endpoint still records if sent',
                    'flagged_for_review' => 'true when the location looks mocked — clock-in proceeds and a supervisor reviews',
                    'distance_m' => 'metres from the post, or null',
                    'radius_m' => 'the post\'s geofence radius',
                    'within_geofence' => 'true, false, or null when the post has not been surveyed',
                    'checks.*.key' => '`window`, `device`, `geofence` or `location_integrity`',
                    'checks.*.passed' => 'boolean',
                    'checks.*.detail' => 'the sentence to show',
                ],
                'errors' => $notYours,
                'offline' => 'Run the window and device checks on the handset against the cached shift and post; clock in and let the server record the distance.',
            ],
            'shifts.break.start' => [
                'method' => 'POST', 'uri' => 'shifts/{shift}/break/start', 'action' => [ShiftsController::class, 'breakStart'],
                'apps' => $guard, 'capability' => 'shifts', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-shift-clock', 'status' => 201, 'where' => $shiftWhere,
                'summary' => 'Start a break. A break already open is returned as it is (200).',
                'request' => [],
                'response' => $breakKeys,
                'errors' => [...$notYours, '409 shift_not_on_duty' => 'The guard is not clocked on to this shift.'],
                'offline' => 'Queue with the device time of the tap.',
            ],
            'shifts.break.end' => [
                'method' => 'POST', 'uri' => 'shifts/{shift}/break/end', 'action' => [ShiftsController::class, 'breakEnd'],
                'apps' => $guard, 'capability' => 'shifts', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-shift-clock', 'status' => 200, 'where' => $shiftWhere,
                'summary' => 'End the open break.',
                'request' => [],
                'response' => $breakKeys,
                'errors' => [...$notYours, '409 no_break_open' => 'No break is open on this shift.'],
                'offline' => 'Queue after the break start it closes.',
            ],
            'shifts.open' => [
                'method' => 'GET', 'uri' => 'shifts/open', 'action' => [ShiftsController::class, 'open'],
                'apps' => $guard, 'capability' => 'shifts', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Open shifts at the guard\'s estate in the next 14 days. Board guard-app-06. Hours, not pay.',
                'request' => [],
                'response' => [
                    'items.*.id' => 'the open shift',
                    'items.*.post.id' => 'the post',
                    'items.*.post.name' => 'its name',
                    'items.*.site.id' => 'the estate',
                    'items.*.site.name' => 'its name',
                    'items.*.rostered_start' => 'ISO 8601',
                    'items.*.rostered_end' => 'ISO 8601',
                    'items.*.hours' => 'length in hours',
                    'items.*.claim_status' => 'null, or this guard\'s claim: `pending`, `approved`, `declined`',
                    'items.*.rest_warning' => 'true when it falls within 11 hours of another of the guard\'s shifts',
                ],
                'errors' => [],
                'offline' => 'Online only — an open shift is first come, and a cached list is stale.',
            ],
            'shifts.claim' => [
                'method' => 'POST', 'uri' => 'shifts/{shift}/claim', 'action' => [ShiftsController::class, 'claim'],
                'apps' => $guard, 'capability' => 'shifts', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201, 'where' => $shiftWhere,
                'summary' => 'Claim an open shift. A supervisor approves; the claim\'s status comes back on `/sync/pull`.',
                'request' => [],
                'response' => [
                    'claim_id' => 'the claim',
                    'shift_id' => 'the shift',
                    'status' => '`pending`',
                    'claimed_at' => 'ISO 8601',
                    'requires_approval' => 'always true',
                ],
                'errors' => [
                    '404 not_found' => 'No such shift at the guard\'s estate.',
                    '409 shift_not_open' => 'Already filled.',
                    '409 shift_started' => 'Already started.',
                ],
                'offline' => 'Online only.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function guardPost(): array
    {
        $guard = [AppMatrix::GUARD];
        $checkKeys = [
            'check_id' => 'the check',
            'outcome' => '`pending`, `passed`, `missed`, `failed` or `declined`',
            'issued_at' => 'ISO 8601',
            'respond_by' => 'two minutes after issue',
            'responded_at' => 'or null',
        ];

        return [
            'orders.current' => [
                'method' => 'GET', 'uri' => 'sites/{site}/standing-orders/current', 'action' => [OrdersController::class, 'current'],
                'apps' => $guard, 'capability' => 'orders', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['site' => '[a-z0-9]+'],
                'summary' => 'The orders in force for the guard\'s post and the company, each with its version id, and the guard\'s acknowledgement history. Boards guard-app-03 and -04.',
                'request' => [],
                'response' => [
                    'site_id' => 'the site',
                    'items.*.set_id' => 'the order set',
                    'items.*.version_id' => 'THE id to acknowledge',
                    'items.*.version' => 'its number',
                    'items.*.title' => 'title',
                    'items.*.effective_on' => 'YYYY-MM-DD',
                    'items.*.body' => 'the full text',
                    'items.*.requires_acknowledgement' => 'true for the post\'s own orders',
                    'items.*.acknowledged_at' => 'when this guard acknowledged THIS version, or null',
                    'history.*.set_id' => 'order set',
                    'history.*.title' => 'its title',
                    'history.*.version' => 'the version acknowledged',
                    'history.*.acknowledged_at' => 'when',
                ],
                'errors' => ['403 wrong_site' => 'A site other than the guard\'s own.'],
                'offline' => 'Cache and show the cached text; queue acknowledgements.',
            ],
            'orders.acknowledge' => [
                'method' => 'POST', 'uri' => 'standing-orders/{version}/acknowledge', 'action' => [OrdersController::class, 'acknowledge'],
                'apps' => $guard, 'capability' => 'orders', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-shift-clock', 'status' => 200, 'where' => ['version' => '[0-9]+'],
                'summary' => 'Acknowledge the version the guard read, by its version id. A revision published meanwhile has a different id, so an old one is refused.',
                'request' => [],
                'response' => [
                    'set_id' => 'the order set',
                    'version_id' => 'the version acknowledged',
                    'version' => 'its number',
                    'acknowledged_at' => 'the first acknowledgement of this version, however many were sent',
                ],
                'errors' => [
                    '404 not_found' => 'No such version.',
                    '409 orders_changed' => 'Revised since, not the guard\'s post, or the guard cannot stand the post (licence lapsed).',
                ],
                'offline' => 'Queue. If a revision landed meanwhile the sync answers 409; show the new version from `/sync/pull`.',
            ],
            'patrol.checkpoints' => [
                'method' => 'GET', 'uri' => 'sites/{site}/checkpoints', 'action' => [PatrolController::class, 'checkpoints'],
                'apps' => $guard, 'capability' => 'patrol', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['site' => '[a-z0-9]+'],
                'summary' => 'The site\'s active patrol checkpoints in tour order, with what this guard has scanned today. Board guard-app-02.',
                'request' => [],
                'response' => [
                    'site_id' => 'the site',
                    'items.*.id' => 'the checkpoint',
                    'items.*.label' => 'e.g. "Pool gate"',
                    'items.*.sequence' => 'tour order',
                    'items.*.post_id' => 'the post it belongs to, or null',
                    'items.*.last_scanned_at' => 'this guard\'s last scan today, or null',
                    'tour.total' => 'checkpoints',
                    'tour.scanned_today' => 'distinct checkpoints this guard scanned today',
                ],
                'errors' => ['403 wrong_site' => 'A site other than the guard\'s own.'],
                'offline' => 'Cache. The tag codes are not in this list — the tag itself carries its code.',
            ],
            'patrol.scan' => [
                'method' => 'POST', 'uri' => 'checkpoints/{checkpoint}/scan', 'action' => [PatrolController::class, 'scan'],
                'apps' => $guard, 'capability' => 'patrol', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201, 'where' => ['checkpoint' => '[0-9]+'],
                'summary' => 'Record a checkpoint scan. The code read from the QR or NFC tag must be the checkpoint\'s own.',
                'request' => [
                    'code' => 'string · required · what the tag encodes',
                    'captured_offline' => 'boolean · optional',
                ],
                'response' => [
                    'scan_id' => 'the scan',
                    'checkpoint_id' => 'the checkpoint',
                    'label' => 'its label',
                    'tour.total' => 'checkpoints',
                    'tour.scanned_today' => 'distinct checkpoints scanned today',
                ],
                'errors' => [
                    '404 not_found' => 'No such checkpoint at the guard\'s site.',
                    '422 code_mismatch' => 'The tag read belongs to a different checkpoint, or to none.',
                ],
                'offline' => 'Queue with the device time of the scan and `captured_offline: true`.',
            ],
            'alertness.respond' => [
                'method' => 'POST', 'uri' => 'alertness/{check}/respond', 'action' => [PresenceController::class, 'respond'],
                'apps' => $guard, 'capability' => 'alertness', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['check' => '[0-9]+'],
                'summary' => 'Answer a random alertness check within two minutes. Board guard-app-04. Any on-device identity score is sent as a number; no image or template ever leaves the handset.',
                'request' => ['score' => 'integer · optional · 0–100, derived on the device'],
                'response' => $checkKeys,
                'errors' => [
                    '404 not_found' => 'No such check for this guard.',
                    '409 check_expired' => 'The two minutes ran out; the check is recorded missed.',
                ],
                'offline' => 'Online only: a check answered after signal returns is late by definition. The sync records it as missed.',
            ],
            'presence.activity' => [
                'method' => 'POST', 'uri' => 'presence/activity', 'action' => [PresenceController::class, 'activity'],
                'apps' => $guard, 'capability' => 'presence', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Report on-post activity. Coordinates, when sent, are compared against the post\'s geofence and discarded — only `within_geofence` is kept. Answers with any pending alertness check.',
                'request' => [
                    'state' => 'string · required · `on_post`, `patrolling`, `on_break` or `away`',
                    'latitude' => 'number · optional',
                    'longitude' => 'number · optional',
                    'accuracy_m' => 'integer · optional',
                    'battery_pct' => 'integer · optional · 0–100',
                ],
                'response' => [
                    'ping_id' => 'the ping',
                    'shift_id' => 'the shift on duty, or null',
                    'state' => 'as sent',
                    'within_geofence' => 'true, false, or null when it could not be decided',
                    'pending_alertness_check' => 'null, or the check to answer now',
                    ...array_combine(array_map(static fn (string $k): string => 'pending_alertness_check.'.$k, array_keys($checkKeys)), $checkKeys),
                ],
                'errors' => [],
                'offline' => 'Drop, do not queue: a stale presence report is not presence.',
            ],
            'presence.summary' => [
                'method' => 'GET', 'uri' => 'guards/me/activity-summary', 'action' => [PresenceController::class, 'summary'],
                'apps' => $guard, 'capability' => 'presence', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The guard\'s own day: minutes on post, breaks, checkpoints, incidents, alertness. Board guard-app-04.',
                'request' => [],
                'response' => [
                    'date' => 'YYYY-MM-DD',
                    'shift_id' => 'today\'s shift, or null',
                    'on_post_minutes' => 'on duty less breaks',
                    'break_minutes' => 'breaks',
                    'checkpoints_scanned' => 'distinct today',
                    'checkpoints_total' => 'active at the site',
                    'incidents_filed' => 'today',
                    'alertness_passed' => 'today',
                    'alertness_missed' => 'today',
                    'last_activity_at' => 'last presence report, or null',
                ],
                'errors' => [],
                'offline' => 'Serve the cached summary with its age.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function guardGate(): array
    {
        $guard = [AppMatrix::GUARD];
        $event = [
            'id' => 'the gate event',
            'verdict' => '`admit`, `exit` or `override`',
            'basis' => '`QR pass`, `QR pass · verified offline`, `Pre-approved`, `guard decision`, or `Override — {reason}`',
            'pass_based' => 'true when the basis was a platform pass or approval',
            'occurred_at' => 'the server\'s time of the event',
            ...self::CLOCKS,
        ];
        $approval = [
            'approval_id' => 'the walk-up request',
            'status' => '`pending`, `approved`, `denied` or `expired`',
            'unit' => 'the unit',
            'household' => 'its household\'s name',
            'visitor_name' => 'as recorded',
            'requested_at' => 'ISO 8601',
            'respond_by' => 'three minutes after the request',
            'responded_at' => 'or null',
            'responded_by_name' => 'the resident who answered, or null',
            'guidance' => 'the sentence to show the guard',
        ];

        return [
            'gate.verify' => [
                'method' => 'POST', 'uri' => 'gate/verify', 'action' => [GateController::class, 'verify'],
                'apps' => $guard, 'capability' => 'gate', 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-gate-events', 'status' => 200,
                'summary' => 'Verify a signed pass (or its short code) online: signature, site, window, cancellation, use, and the household\'s access restriction. Does NOT admit — record the entry after. Board guard-app-07.',
                'request' => ['pass' => 'string · required · the QR token (`payload.signature`), or the short code, e.g. `PPV2-4471`'],
                'response' => [
                    'verdict' => '`valid`, `restricted`, `cancelled`, `already_used`, `expired`, `not_yet_valid`, `wrong_site`, `unknown_key`, `bad_signature`, `malformed` or `unknown_pass`',
                    'tone' => '`green`, `amber` (restricted) or `red`',
                    'headline' => 'what the guard is told',
                    'detail' => 'one sentence',
                    'admit_allowed' => 'true only for `valid`',
                    'pass' => 'null unless the pass is this estate\'s and on record',
                    'pass.pass_id' => 'UUID',
                    'pass.category' => '`single`, `recurring`, `contractor` or `delivery`',
                    'pass.visitor_name' => 'who it is for',
                    'pass.vehicle_plate' => 'or null',
                    'pass.purpose' => 'or null',
                    'pass.valid_from' => 'ISO 8601',
                    'pass.valid_to' => 'ISO 8601',
                    'pass.single_use' => 'boolean',
                    'pass.status' => '`active`, `used` or `cancelled`',
                    'unit' => 'the unit reference, or null',
                    'household' => 'the household\'s name, or null',
                    'access_restricted' => 'boolean, or null when the pass itself failed — the ONLY thing a guard learns about a household\'s standing',
                    'server_time' => 'the server\'s time',
                ],
                'errors' => [],
                'offline' => 'Verify on the handset with the cached public keys (`/sites/{site}/pass-keys`): the payload\'s eight fields and its Ed25519 signature, with no database. Cancellation and use are unknown offline; `/sync/pull` delivers the revoked list and `/gate/entry` reconciles.',
            ],
            'gate.entry' => [
                'method' => 'POST', 'uri' => 'gate/entry', 'action' => [GateController::class, 'entry'],
                'apps' => $guard, 'capability' => 'gate', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-gate-events', 'status' => 201,
                'summary' => 'Record an admission — on a pass, on an approved walk-up request, or on the guard\'s own decision. A single-use pass is consumed here.',
                'request' => [
                    'category' => 'string · required · e.g. `Visitor`, `Contractor`, `Delivery`, `Resident`',
                    'pass_id' => 'string · optional · the pass admitted on',
                    'approval_id' => 'integer · optional · an approved walk-up request',
                    'subject' => 'string · required without a pass or approval · who came in',
                    'verified_offline' => 'boolean · optional · the pass was verified on the handset with no signal',
                    'post_id' => 'integer · optional · defaults to the guard\'s post',
                ],
                'response' => [
                    ...$event,
                    'reconciliation' => 'null without a pass; else `consumed`, `valid`, or — offline only — `pass_cancelled`, `pass_already_used`, `pass_expired`, `household_restricted`, `pass_unknown`: tell the guard and dispatch sees it',
                    'access_restricted' => 'boolean, or null without a pass',
                ],
                'errors' => [
                    '404 not_found' => 'An online entry on a pass or request that does not exist.',
                    '409 pass_not_admissible' => 'Online, on a pass that is cancelled, used, expired or restricted. Use `/gate/override`.',
                    '409 approval_not_granted' => 'The walk-up request is not approved.',
                    '422 subject_required' => 'No pass, no approval and no subject.',
                    '403 wrong_site' => 'A post at another estate.',
                ],
                'offline' => 'Queue with `verified_offline: true` and the device time of the admission. The server records it and answers `reconciliation`.',
            ],
            'gate.exit' => [
                'method' => 'POST', 'uri' => 'gate/exit', 'action' => [GateController::class, 'exit'],
                'apps' => $guard, 'capability' => 'gate', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-gate-events', 'status' => 201,
                'summary' => 'Record a departure.',
                'request' => [
                    'category' => 'string · required',
                    'subject' => 'string · required',
                    'pass_id' => 'string · optional',
                    'post_id' => 'integer · optional',
                ],
                'response' => $event,
                'errors' => ['403 wrong_site' => 'A post at another estate.'],
                'offline' => 'Queue with the device time.',
            ],
            'gate.override' => [
                'method' => 'POST', 'uri' => 'gate/override', 'action' => [GateController::class, 'override'],
                'apps' => $guard, 'capability' => 'gate', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-gate-events', 'status' => 201,
                'summary' => 'Admit against the system\'s advice, with a reason. Its own verdict on the gate log.',
                'request' => [
                    'category' => 'string · required',
                    'subject' => 'string · required',
                    'reason' => 'string · required · 5–160 characters',
                    'pass_id' => 'string · optional',
                    'post_id' => 'integer · optional',
                ],
                'response' => $event,
                'errors' => ['403 wrong_site' => 'A post at another estate.'],
                'offline' => 'Queue with the device time.',
            ],
            'gate.search' => [
                'method' => 'GET', 'uri' => 'gate/search', 'action' => [GateController::class, 'search'],
                'apps' => $guard, 'capability' => 'gate', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Find a unit or household. `access_restricted` is a boolean and the ONLY thing returned about a household\'s standing — no figure, no bucket, no wording implying money.',
                'request' => [
                    'unit' => 'string · required without `name` (query) · part of a unit reference',
                    'name' => 'string · required without `unit` (query) · part of a household or resident name, 2+ characters',
                ],
                'response' => [
                    'items.*.unit' => 'the unit reference',
                    'items.*.household' => 'the household\'s name',
                    'items.*.primary_resident' => 'the primary resident\'s name, or null',
                    'items.*.access_restricted' => 'boolean',
                    'items.*.expected_visitors.*.visitor_name' => 'an active pass valid today',
                    'items.*.expected_visitors.*.category' => 'its category',
                    'items.*.expected_visitors.*.valid_to' => 'ISO 8601',
                ],
                'errors' => [],
                'offline' => 'Online only.',
            ],
            'gate.activity' => [
                'method' => 'GET', 'uri' => 'gate/activity', 'action' => [GateController::class, 'activity'],
                'apps' => $guard, 'capability' => 'gate', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Today\'s gate log at the guard\'s estate, newest first.',
                'request' => ['limit' => 'integer · optional (query) · 1–100, default 50'],
                'response' => [
                    'date' => 'YYYY-MM-DD',
                    'counts.admitted' => 'today',
                    'counts.exited' => 'today',
                    'counts.overridden' => 'today',
                    'counts.denied' => 'today',
                    'items.*.id' => 'the event',
                    'items.*.verdict' => 'verdict',
                    'items.*.category' => 'category',
                    'items.*.subject' => 'who',
                    'items.*.basis' => 'on what',
                    'items.*.pass_based' => 'boolean',
                    'items.*.guard_name' => 'recorded by',
                    'items.*.post_name' => 'at',
                    'items.*.occurred_at' => 'server time',
                    'items.*.device_time' => 'handset time, or null',
                ],
                'errors' => [],
                'offline' => 'Show the cached log plus the queued events not yet synced, marked as such.',
            ],
            'gate.approvals.store' => [
                'method' => 'POST', 'uri' => 'gate/approvals', 'action' => [GateController::class, 'requestApproval'],
                'apps' => $guard, 'capability' => 'gate', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-gate-events', 'status' => 201,
                'summary' => 'A walk-up visitor: record them and ask the household, who answers in the Resident App. Boards guard-app-08 and -02. No photo is stored.',
                'request' => [
                    'unit' => 'string · required · the unit reference',
                    'visitor_name' => 'string · required',
                    'id_type' => 'string · optional',
                    'id_number' => 'string · optional',
                    'purpose' => 'string · optional',
                    'vehicle_plate' => 'string · optional',
                ],
                'response' => $approval,
                'errors' => ['404 not_found' => 'No such unit.'],
                'offline' => 'Online only — the household cannot be asked without signal. Apply the estate\'s policy.',
            ],
            'gate.approvals.show' => [
                'method' => 'GET', 'uri' => 'gate/approvals/{approval}', 'action' => [GateController::class, 'approval'],
                'apps' => $guard, 'capability' => 'gate', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['approval' => '[0-9]+'],
                'summary' => 'Poll a walk-up request. Past `respond_by` with no answer it reads `expired`.',
                'request' => [],
                'response' => $approval,
                'errors' => ['404 not_found' => 'No such request.'],
                'offline' => 'Online only.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function guardReports(): array
    {
        $guard = [AppMatrix::GUARD];
        $incident = [
            'id' => 'the incident',
            'kind' => 'as filed',
            'severity' => '`low`, `med` or `high`',
            'status' => '`open` or `resolved`',
            'detail' => 'the account',
            'location' => 'or null',
            'shift_id' => 'the shift on duty when filed, or null',
            'occurred_at' => 'ISO 8601',
            'resolution' => 'what was done, once resolved',
            'closed_at' => 'or null',
            'media_count' => 'attachments',
        ];
        $alert = [
            'id' => 'the alert',
            'kind' => '`duress` from a guard, `panic` from a resident',
            'mode' => '`silent` or `audible`',
            'status' => '`open`, `acknowledged`, `responding`, `resolved` or `false_alarm`',
            'cancellable_until' => 'ten seconds after the server received it',
            'cancelled_at' => 'or null',
            'acknowledged_at' => 'when dispatch took it, or null',
            'server_time' => 'the server\'s time of the alert',
            'device_time' => 'the handset\'s time of the press',
            'clock_skewed' => 'true when the two disagree by more than two minutes',
        ];

        return [
            'incidents.store' => [
                'method' => 'POST', 'uri' => 'incidents', 'action' => [IncidentsController::class, 'store'],
                'apps' => $guard, 'capability' => 'incidents', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'File an incident report. It lands in the same register the console keeps. Board guard-app-03.',
                'request' => [
                    'kind' => 'string · required · e.g. "Attempted unauthorized access"',
                    'severity' => 'string · required · `low`, `med` or `high`',
                    'detail' => 'string · required · at least 20 characters',
                    'location' => 'string · optional',
                    'latitude' => 'number · optional',
                    'longitude' => 'number · optional',
                    'occurred_at' => 'ISO 8601 · optional · defaults to now; not in the future',
                ],
                'response' => [...$incident, ...self::CLOCKS],
                'errors' => ['422 occurred_in_future' => '`occurred_at` more than five minutes ahead of the server.'],
                'offline' => 'Queue with the device time; upload media after the report syncs, against the id it returns.',
            ],
            'incidents.me' => [
                'method' => 'GET', 'uri' => 'incidents/me', 'action' => [IncidentsController::class, 'mine'],
                'apps' => $guard, 'capability' => 'incidents', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The guard\'s own reports from the last 90 days.',
                'request' => [],
                'response' => array_combine(array_map(static fn (string $k): string => 'items.*.'.$k, array_keys($incident)), $incident),
                'errors' => [],
                'offline' => 'Serve the cache plus queued reports.',
            ],
            'incidents.media' => [
                'method' => 'POST', 'uri' => 'incidents/{incident}/media', 'action' => [IncidentsController::class, 'media'],
                'apps' => $guard, 'capability' => 'incidents', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 201, 'where' => ['incident' => '[0-9]+'],
                'summary' => 'Attach a photo or video (multipart, field `file`). Stored privately and hashed on arrival.',
                'request' => ['file' => 'file · required · JPEG, PNG, HEIC, MP4 or MOV, at most 50 MB'],
                'response' => [
                    'media_id' => 'the attachment',
                    'incident_id' => 'the incident',
                    'filename' => 'as uploaded',
                    'content_type' => 'as detected',
                    'bytes' => 'size',
                    'sha256' => 'hash of the stored bytes',
                ],
                'errors' => ['404 not_found' => 'No incident of this guard\'s with that id.'],
                'offline' => 'Queue the file; upload after the incident has an id. Not batchable.',
            ],
            'duress.store' => [
                'method' => 'POST', 'uri' => 'duress', 'action' => [DuressController::class, 'store'],
                'apps' => [AppMatrix::GUARD, AppMatrix::RESIDENT], 'capability' => 'alerts', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-alerts', 'status' => 201,
                'summary' => 'Duress (guard) or panic (resident). Dispatch sees it at once. Cancellable for ten seconds. Guard board guard-app-03; resident panic.',
                'request' => [
                    'mode' => 'string · optional · `silent` (default) or `audible` — the handset\'s behaviour only',
                    'latitude' => 'number · optional',
                    'longitude' => 'number · optional',
                    'captured_offline' => 'boolean · optional',
                ],
                'response' => $alert,
                'errors' => [],
                'offline' => 'Queue with its key and `captured_offline: true`; send the moment any signal returns. Also call the local emergency number.',
            ],
            'duress.cancel' => [
                'method' => 'POST', 'uri' => 'duress/{alert}/cancel', 'action' => [DuressController::class, 'cancel'],
                'apps' => [AppMatrix::GUARD, AppMatrix::RESIDENT], 'capability' => 'alerts', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-alerts', 'status' => 200, 'where' => ['alert' => '[0-9]+'],
                'summary' => 'Cancel within ten seconds of the server receiving it, and before dispatch acknowledges.',
                'request' => [],
                'response' => $alert,
                'errors' => [
                    '404 not_found' => 'Not this handset\'s alert.',
                    '409 cancel_window_closed' => 'More than ten seconds. Call dispatch.',
                    '409 already_acknowledged' => 'Somebody is responding. Call dispatch.',
                ],
                'offline' => 'Not possible offline: the alert has not reached anyone to cancel.',
            ],
            'requests.index' => [
                'method' => 'GET', 'uri' => 'requests', 'action' => [RequestsController::class, 'index'],
                'apps' => $guard, 'capability' => 'requests', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The guard\'s leave, equipment and swap requests, with decisions, and their leave balance in days. Board guard-app-05.',
                'request' => [],
                'response' => [
                    'items.*.id' => 'the request',
                    'items.*.kind' => '`leave`, `equipment` or `shift_swap`',
                    'items.*.subject' => 'leave type, or the item',
                    'items.*.quantity' => 'equipment count, or null',
                    'items.*.starts_on' => 'YYYY-MM-DD or null',
                    'items.*.ends_on' => 'YYYY-MM-DD or null',
                    'items.*.days' => 'inclusive, or null',
                    'items.*.reason' => 'or null',
                    'items.*.certificate_attached' => 'boolean — the flag, never the document',
                    'items.*.status' => '`pending`, `approved`, `denied` or `info_requested`',
                    'items.*.decided_at' => 'or null',
                    'items.*.decision_note' => 'the supervisor\'s note, or null',
                    'items.*.created_at' => 'ISO 8601',
                    'leave.entitlement_days' => 'per year',
                    'leave.approved_days_this_year' => 'days',
                    'leave.remaining_days' => 'days',
                ],
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'requests.store' => [
                'method' => 'POST', 'uri' => 'requests', 'action' => [RequestsController::class, 'store'],
                'apps' => $guard, 'capability' => 'requests', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Raise a request. It reaches the dispatch inbox at once.',
                'request' => [
                    'kind' => 'string · required · `leave`, `equipment` or `shift_swap`',
                    'subject' => 'string · required · for leave: `vacation`, `sick`, `bereavement`, `maternity`, `paternity`, `unpaid`, `other`',
                    'quantity' => 'integer · required for equipment',
                    'starts_on' => 'date · required for leave',
                    'ends_on' => 'date · required for leave',
                    'reason' => 'string · optional',
                    'certificate_attached' => 'boolean · optional',
                ],
                'response' => [
                    'id' => 'the request', 'kind' => 'kind', 'subject' => 'subject', 'quantity' => 'or null',
                    'starts_on' => 'or null', 'ends_on' => 'or null', 'days' => 'or null', 'reason' => 'or null',
                    'certificate_attached' => 'boolean', 'status' => '`pending`', 'decided_at' => 'null',
                    'decision_note' => 'null', 'created_at' => 'ISO 8601',
                    'server_time' => 'the server\'s time', 'device_time' => 'the handset\'s time, as sent',
                ],
                'errors' => ['422 leave_type_unknown' => 'A leave subject not in the list.'],
                'offline' => 'Queue.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function guardSelf(): array
    {
        $guard = [AppMatrix::GUARD];
        $message = [
            'id' => 'the message',
            'direction' => '`broadcast`, `outbound` (dispatch to this guard) or `inbound` (this guard to dispatch)',
            'from' => '`dispatch` or `you`',
            'body' => 'text, at most 500',
            'sent_at' => 'ISO 8601',
            'read_at' => 'or null',
        ];

        return [
            'payslips.me' => [
                'method' => 'GET', 'uri' => 'payslips/me', 'action' => [PayslipsController::class, 'mine'],
                'apps' => $guard, 'capability' => 'payslips', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The guard\'s OWN payslips on approved runs, newest first, as decimal strings. The one route where a guard\'s handset reads money — their own wage. Board guard-app-05.',
                'request' => [],
                'response' => [
                    'items.*.id' => 'the payslip',
                    'items.*.run_reference' => 'the payroll run',
                    'items.*.period_label' => 'e.g. "September 2026"',
                    'items.*.period_start' => 'YYYY-MM-DD',
                    'items.*.period_end' => 'YYYY-MM-DD',
                    'items.*.status' => '`approved` or `paid`',
                    'items.*.currency' => '`JMD`',
                    'items.*.gross' => 'decimal string, e.g. "38450.00"',
                    'items.*.deductions.nis' => 'decimal string',
                    'items.*.deductions.nht' => 'decimal string',
                    'items.*.deductions.education_tax' => 'decimal string',
                    'items.*.deductions.paye' => 'decimal string',
                    'items.*.deductions.pension' => 'decimal string — an approved pension, zero unless one is on file',
                    'items.*.net' => 'decimal string',
                    'items.*.paye_note' => 'why PAYE is what it is, or null',
                ],
                'errors' => [],
                'offline' => 'Serve the cache from the secure store; never write a payslip to shared storage.',
            ],
            'messages.index' => [
                'method' => 'GET', 'uri' => 'messages', 'action' => [MessagesController::class, 'index'],
                'apps' => $guard, 'capability' => 'messages', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Broadcasts to the estate and the guard\'s own thread with dispatch, newest first. Board guard-app-04.',
                'request' => ['since' => 'ISO 8601 · optional (query) · only newer messages'],
                'response' => [
                    ...array_combine(array_map(static fn (string $k): string => 'items.*.'.$k, array_keys($message)), $message),
                    'unread' => 'messages from dispatch not yet read',
                ],
                'errors' => [],
                'offline' => 'Serve the cache plus queued outgoing messages.',
            ],
            'messages.store' => [
                'method' => 'POST', 'uri' => 'messages', 'action' => [MessagesController::class, 'store'],
                'apps' => $guard, 'capability' => 'messages', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Write to dispatch. Marks the thread read.',
                'request' => ['body' => 'string · required · at most 500'],
                'response' => [...$message, 'server_time' => 'the server\'s time', 'device_time' => 'the handset\'s time, as sent'],
                'errors' => [],
                'offline' => 'Queue with the device time.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function guardSync(): array
    {
        $guard = [AppMatrix::GUARD];

        return [
            'sync.batch' => [
                'method' => 'POST', 'uri' => 'sync/batch', 'action' => [SyncController::class, 'batch'],
                'apps' => $guard, 'capability' => 'sync', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200,
                'summary' => 'Upload the offline queue, in order. Each operation runs through its own endpoint with its own Idempotency-Key and device time; a retried batch replays. Stops at the first server failure.',
                'request' => [
                    'operations' => 'array · required · 1–50, in the order they happened',
                    'operations.*.id' => 'string · required · the app\'s own id for the operation, echoed back',
                    'operations.*.endpoint' => 'string · required · a catalogue name, e.g. `shifts.clock_in`, `gate.entry`, `patrol.scan`',
                    'operations.*.params' => 'object · optional · path parameters, e.g. `{"shift": 812}`',
                    'operations.*.body' => 'object · optional · the endpoint\'s request body',
                    'operations.*.idempotency_key' => 'string · required · the key chosen when the operation was queued',
                    'operations.*.device_time' => 'ISO 8601 · required · when it happened on the handset',
                ],
                'response' => [
                    'results.*.id' => 'the operation id, as sent',
                    'results.*.endpoint' => 'as sent',
                    'results.*.outcome' => '`ok`, `refused` (a 4xx answer — show it), `failed` (5xx — resend) or `not_attempted` (after a failure — resend)',
                    'results.*.status' => 'the HTTP status the endpoint answered, or null',
                    'results.*.replayed' => 'true when answered from a previous attempt with the same key',
                    'results.*.body' => 'the endpoint\'s own response body, exactly as documented for it',
                    'results.*.body.**' => 'opaque here — see the named endpoint',
                    'counts.ok' => 'count',
                    'counts.refused' => 'count',
                    'counts.failed' => 'count',
                    'counts.not_attempted' => 'count',
                    'server_time' => 'the server\'s time',
                ],
                'errors' => [],
                'offline' => 'This IS the offline path. Batchable: every guard write except `incidents.media` and sync itself; an unbatchable name comes back `refused` with `endpoint_not_batchable`.',
            ],
            'sync.pull' => [
                'method' => 'GET', 'uri' => 'sync/pull', 'action' => [SyncController::class, 'pull'],
                'apps' => $guard, 'capability' => 'sync', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'What changed since the last pull: shifts, order versions, messages, pending alertness checks, revoked passes and the current pass keys, request decisions and claim decisions.',
                'request' => ['since' => 'ISO 8601 · optional (query) · the previous `next_since`; default seven days'],
                'response' => [
                    'since' => 'as applied',
                    'server_time' => 'the server\'s time',
                    'next_since' => 'send this as `since` next time',
                    ...self::shiftKeys('shifts.*.'),
                    'orders.*.set_id' => 'order set',
                    'orders.*.version_id' => 'a version published since',
                    'orders.*.version' => 'number',
                    'orders.*.title' => 'title',
                    'orders.*.effective_on' => 'YYYY-MM-DD',
                    'orders.*.requires_acknowledgement' => 'boolean',
                    'messages.*.id' => 'message',
                    'messages.*.direction' => '`broadcast` or `outbound`',
                    'messages.*.body' => 'text',
                    'messages.*.sent_at' => 'ISO 8601',
                    'alertness_checks.*.check_id' => 'a check to answer now',
                    'alertness_checks.*.issued_at' => 'ISO 8601',
                    'alertness_checks.*.respond_by' => 'ISO 8601',
                    'passes.revoked.*.pass_id' => 'a still-unexpired pass cancelled or used since — refuse it offline from now on',
                    'passes.revoked.*.status' => '`cancelled` or `used`',
                    'passes.revoked.*.at' => 'when',
                    'passes.keys.*.version' => 'key version',
                    'passes.keys.*.public_key' => 'base64url Ed25519 public key',
                    'passes.keys.*.current' => 'boolean',
                    'requests.*.id' => 'a request decided since',
                    'requests.*.kind' => 'kind',
                    'requests.*.status' => 'decision',
                    'requests.*.decided_at' => 'ISO 8601',
                    'requests.*.decision_note' => 'or null',
                    'claims.*.claim_id' => 'a claim decided since',
                    'claims.*.shift_id' => 'the shift',
                    'claims.*.status' => '`approved` or `declined`',
                    'claims.*.decided_at' => 'ISO 8601',
                ],
                'errors' => [],
                'offline' => 'Pull first whenever signal returns, then upload the batch.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function devices(): array
    {
        return [
            'devices.enrol' => [
                'method' => 'POST', 'uri' => 'devices/enrol', 'action' => [DeviceController::class, 'enrol'],
                'apps' => [], 'capability' => null, 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-enrol', 'status' => 201,
                'summary' => 'Bind this handset to the guard an enrolment code was issued to, and receive its token. A guard who already has a handset gets a rebind request a supervisor must approve instead.',
                'request' => [
                    'enrolment_code' => 'string · required · the one-time code Gemini\'s office gave the guard',
                    'device_uid' => 'string · required · a stable identifier for this install, max 120',
                    'platform' => 'string · required · `ios` or `android`',
                    'public_key' => 'string · required · the handset\'s Ed25519 public key, base64url (32 bytes)',
                    'label' => 'string · optional · what the handset is, e.g. "iPhone 15 Pro · Main Gate"',
                ],
                'response' => [
                    'status' => '`enrolled`, or `pending_approval` for a rebind',
                    'token' => 'the bearer token — shown once, store it in the secure enclave. Absent while pending.',
                    'abilities.*' => 'the abilities the token carries, from the app matrix. Absent while pending.',
                    'guard.id' => 'the guard',
                    'guard.name' => 'their name, to confirm on screen',
                    'guard.employee_number' => 'GS-1041',
                    'site.id' => 'the estate the guard is posted to',
                    'site.name' => 'its name',
                    'rebind_request_id' => 'while pending: the request to poll',
                    'claim_secret' => 'while pending: present this to collect the token once approved. Shown once.',
                ],
                'errors' => [
                    '422 enrolment_code_invalid' => 'The code is unknown, expired or already used.',
                    '403 guard_not_active' => 'The code\'s guard is suspended or no longer employed.',
                    '422 public_key_invalid' => 'The public key is not 32 base64url-encoded bytes.',
                ],
                'offline' => 'Online only. Enrolment happens once, at the office.',
            ],
            'devices.enrol.status' => [
                'method' => 'POST', 'uri' => 'devices/enrol/{rebind}/collect', 'action' => [DeviceController::class, 'collect'],
                'apps' => [], 'capability' => null, 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-enrol', 'status' => 200, 'where' => ['rebind' => '[0-9]+'],
                'summary' => 'Poll a pending rebind, and collect the token once a supervisor approves it. The previous handset\'s token stops working at that moment.',
                'request' => [
                    'claim_secret' => 'string · required · from the enrol response',
                    'device_uid' => 'string · required · the same UID the request was made with',
                ],
                'response' => [
                    'status' => '`pending_approval`, `approved` (token below, once), `denied`, or `collected`',
                    'token' => 'present only on the first poll after approval',
                    'abilities.*' => 'with the token',
                    'decision_note' => 'the supervisor\'s note, when denied',
                ],
                'errors' => [
                    '404 not_found' => 'No such request, or the claim secret or device UID does not match.',
                ],
                'offline' => 'Online only.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function legacyGuard(): array
    {
        $shiftResponse = [
            'id' => 'the shift',
            'status' => '`on_duty` after clock-in, `completed` after clock-out',
            'actual_start' => 'the server\'s record of when the shift started — the FIRST clock-in, however many were sent',
            'actual_end' => 'when it ended, or null',
            'mock_location_flag' => 'true when the handset reported a mocked location at clock-in; recorded, never refused',
            'server_time' => 'the server\'s time',
            'device_time' => 'the handset\'s time, as sent',
            'clock_skewed' => 'true when the two disagree by more than two minutes',
        ];

        return [
            'alerts.store' => [
                'method' => 'POST', 'uri' => 'alerts', 'action' => [AlertController::class, 'store'],
                'apps' => [AppMatrix::GUARD, AppMatrix::RESIDENT], 'capability' => 'alerts', 'access' => AppMatrix::WRITE,
                'write' => true, 'legacy' => true, 'throttle' => 'api-alerts', 'status' => 201,
                'summary' => 'Raise a panic, duress, medical, fire or intrusion alert. A guard\'s duress and a resident\'s panic are the same event to dispatch.',
                'request' => [
                    'kind' => 'string · required · `panic`, `duress`, `medical`, `fire` or `intrusion`',
                    'unit_reference' => 'string · optional · the resident\'s unit, e.g. "Lot 47"; a resident token\'s own unit is used when omitted',
                    'latitude' => 'number · optional',
                    'longitude' => 'number · optional',
                    'captured_offline' => 'boolean · optional · true when the alert was raised with no signal and is being sent now',
                    'tenant_id' => 'string · optional, legacy · must equal the token\'s own estate when sent',
                    'guard_id' => 'integer · optional, legacy · must equal the token\'s own guard when sent',
                ],
                'response' => [
                    'id' => 'the alert',
                    'status' => '`open`',
                    'server_time' => 'the server\'s time',
                    'device_time' => 'the handset\'s time, as sent',
                    'clock_skewed' => 'true when the two disagree by more than two minutes',
                ],
                'errors' => [
                    '403 wrong_site' => '`tenant_id` or `guard_id` in the body names another estate or guard.',
                ],
                'offline' => 'Queue with its Idempotency-Key and `captured_offline: true`; send the moment signal returns. The device time is the moment of the press. Also call the local emergency number.',
            ],
            'passes.verify' => [
                'method' => 'POST', 'uri' => 'passes/verify', 'action' => [PassVerificationController::class, 'verify'],
                'apps' => [AppMatrix::GUARD], 'capability' => 'gate', 'access' => AppMatrix::WRITE,
                'write' => false, 'legacy' => true, 'throttle' => 'api-gate-events', 'status' => 200,
                'summary' => 'Legacy household verdict by household id. Superseded by `POST /gate/verify`, which verifies a signed pass; kept for the simulator.',
                'request' => [
                    'household_id' => 'integer · required',
                    'pass_category' => 'string · required · e.g. `guest`',
                ],
                'response' => [
                    'verdict' => '`admit` or `restricted`; `deny` when the household is unknown',
                    'tone' => '`green`, `amber` or `red`',
                    'headline' => 'what the guard is told',
                    'detail' => 'one sentence, or null',
                    'household' => 'the household\'s name',
                    'unit' => 'the unit reference',
                    'access_restricted' => 'boolean — the ONLY thing a guard is ever told about a household\'s standing',
                ],
                'errors' => ['404 deny' => 'No such household at this estate (body carries `verdict: deny`).'],
                'offline' => 'Not available offline. Use `POST /gate/verify` with a signed pass, which verifies on the handset.',
            ],
            'gate_events.store' => [
                'method' => 'POST', 'uri' => 'gate-events', 'action' => [GateEventController::class, 'store'],
                'apps' => [AppMatrix::GUARD], 'capability' => 'gate', 'access' => AppMatrix::WRITE,
                'write' => true, 'legacy' => true, 'throttle' => 'api-gate-events', 'status' => 201,
                'summary' => 'Record what the guard decided at the gate, after the verdict. Legacy form of `/gate/entry`, `/gate/exit` and `/gate/override`.',
                'request' => [
                    'verdict' => 'string · required · `admit`, `deny`, `override` or `exit`',
                    'category' => 'string · required · e.g. `Visitor`, `Contractor`, `Delivery`',
                    'subject' => 'string · required · who or what arrived',
                    'basis' => 'string · required · `QR pass`, `Pre-approved`, `Tag read` or `Guard decision`',
                    'reason' => 'string · required for `override`',
                    'post_id' => 'integer · optional · defaults to the guard\'s own post',
                    'tenant_id' => 'string · optional, legacy · must equal the token\'s estate',
                    'guard_id' => 'integer · optional, legacy · must equal the token\'s guard',
                ],
                'response' => [
                    'id' => 'the gate event',
                    'verdict' => 'as recorded',
                    'occurred_at' => 'the server\'s time of the event',
                    'pass_based' => 'true when the basis was a platform-issued pass or approval',
                    'server_time' => 'the server\'s time',
                    'device_time' => 'the handset\'s time, as sent',
                    'clock_skewed' => 'true when the two disagree by more than two minutes',
                ],
                'errors' => ['403 wrong_site' => 'A body field names another estate, guard or post.'],
                'offline' => 'Queue with its Idempotency-Key; the device time is when the guard decided.',
            ],
            'shifts.clock_in' => [
                'method' => 'POST', 'uri' => 'shifts/{shift}/clock-in', 'action' => [ShiftController::class, 'clockIn'],
                'apps' => [AppMatrix::GUARD], 'capability' => 'shifts', 'access' => AppMatrix::WRITE,
                'write' => true, 'legacy' => true, 'throttle' => 'api-shift-clock', 'status' => 200, 'where' => ['shift' => '[0-9]+'],
                'summary' => 'Clock on. The first clock-in is the one that happened; a replayed queue never moves it.',
                'request' => [
                    'geofence_distance_m' => 'integer · optional · metres from the post boundary; stored, not enforced here (see `/preflight`)',
                    'mock_location' => 'boolean · optional · the handset\'s own mock-location detection',
                    'method' => 'string · optional · `app` (default) or `manual`',
                ],
                'response' => $shiftResponse,
                'errors' => ['403 not_your_shift' => 'The shift is rostered to another guard.'],
                'offline' => 'Queue with its Idempotency-Key and the device time of the tap. The first clock-in wins.',
            ],
            'shifts.clock_out' => [
                'method' => 'POST', 'uri' => 'shifts/{shift}/clock-out', 'action' => [ShiftController::class, 'clockOut'],
                'apps' => [AppMatrix::GUARD], 'capability' => 'shifts', 'access' => AppMatrix::WRITE,
                'write' => true, 'legacy' => true, 'throttle' => 'api-shift-clock', 'status' => 200, 'where' => ['shift' => '[0-9]+'],
                'summary' => 'Clock off, with an optional handover note for the next shift.',
                'request' => [
                    'handover_note' => 'string · optional · max 500 · read by whoever relieves the post',
                ],
                'response' => $shiftResponse,
                'errors' => [
                    '403 not_your_shift' => 'The shift is rostered to another guard.',
                    '409 shift_not_started' => 'No clock-in is recorded. Send the queued clock-in first.',
                ],
                'offline' => 'Queue after the clock-in it follows; the sync batch preserves order.',
            ],
            'standing_orders.index' => [
                'method' => 'GET', 'uri' => 'standing-orders', 'action' => [StandingOrderController::class, 'index'],
                'apps' => [AppMatrix::GUARD], 'capability' => 'orders', 'access' => AppMatrix::READ,
                'write' => false, 'legacy' => true, 'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Every order set the guard works to: general orders and their post\'s. Superseded by `GET /sites/{site}/standing-orders/current`.',
                'request' => [],
                'response' => [
                    'orders.*.id' => 'the order set',
                    'orders.*.title' => 'its title',
                    'orders.*.version' => 'the current version',
                    'orders.*.effective_on' => 'YYYY-MM-DD',
                    'orders.*.body' => 'the full text',
                    'orders.*.requires_acknowledgement' => 'true for the guard\'s post orders',
                    'orders.*.acknowledged' => 'whether this guard has acknowledged THIS version',
                ],
                'errors' => [],
                'offline' => 'Cache the last response and show it; an acknowledgement made offline is queued.',
            ],
            'passes.keys' => [
                'method' => 'GET', 'uri' => 'sites/{site}/pass-keys', 'action' => [PassKeyController::class, 'index'],
                'apps' => [AppMatrix::GUARD], 'capability' => 'gate', 'access' => AppMatrix::READ,
                'write' => false, 'throttle' => 'api-reads', 'status' => 200, 'where' => ['site' => '[a-z0-9]+'],
                'summary' => 'The site\'s Ed25519 PUBLIC keys, every version a still-valid pass may carry. Cache them: they are what verifies a pass with no signal.',
                'request' => [],
                'response' => [
                    'site_id' => 'the site',
                    'algorithm' => '`Ed25519`',
                    'keys.*.version' => 'the key version a pass payload names',
                    'keys.*.public_key' => 'base64url, 32 bytes',
                    'keys.*.current' => 'true for the version new passes are signed with',
                    'refresh_after' => 'fetch again after this time',
                ],
                'errors' => ['403 wrong_site' => 'A site other than the guard\'s own.'],
                'offline' => 'Serve from cache. Refresh at every sync; a key the handset has never seen verifies as `unknown_key`.',
            ],
        ];
    }

    /**
     * Prefix every key of a shape.
     *
     * @param  array<string, string>  $shape
     * @return array<string, string>
     */
    private static function under(string $prefix, array $shape): array
    {
        return array_combine(array_map(static fn (string $k): string => $prefix.$k, array_keys($shape)), $shape);
    }

    /** @return array<string, array<string, mixed>> */
    private static function residentAccount(): array
    {
        $resident = [AppMatrix::RESIDENT];
        $claim = [
            'id' => 'the claim',
            'status' => '`pending`, `approved` or `rejected`',
            'submitted_unit' => 'as typed, e.g. "Phase 2 · Lot 47"',
            'submitted_name' => 'as typed',
            'decision_reason' => 'why it was rejected, or null',
            'document_requested' => 'the document the estate asked for, e.g. "photo ID", or null',
            'submitted_at' => 'ISO 8601',
        ];
        $member = [
            'id' => 'the resident on the register',
            'full_name' => 'name',
            'relationship' => '`owner`, `tenant`, `spouse`, `child`, …',
            'is_primary' => 'the household\'s primary resident',
            'status' => '`verified`, or `pending` until the estate verifies them',
            'phone' => 'or null',
            'email' => 'or null',
        ];
        $vehicle = ['id' => 'the vehicle', 'plate' => 'upper-cased', 'make' => 'or null', 'model' => 'or null', 'colour' => 'or null'];
        $contact = ['id' => 'the contact', 'name' => 'name', 'relationship' => 'or null', 'phone' => 'number'];
        $profile = [
            'account.id' => 'the account',
            'account.status' => '`pending` or `active`',
            'account.channel' => '`email` or `sms`',
            'account.destination_hint' => 'masked, e.g. "a•••@example.com"',
            'account.full_name' => 'or null',
            'estate.id' => 'the estate',
            'estate.name' => 'its name',
            'claim' => 'null until a unit is claimed',
            ...self::under('claim.', $claim),
            'unit' => 'null while pending',
            'unit.id' => 'the unit',
            'unit.reference' => 'e.g. "Lot 47"',
            'unit.phase' => 'e.g. "Phase 2"',
            'household' => 'null while pending',
            'household.id' => 'the household — the id `/households/{household}/…` takes',
            'household.name' => 'its name',
            'resident' => 'the register entry this account signs in as, or null',
            ...self::under('resident.', $member),
        ];

        return [
            'auth.otp.request' => [
                'method' => 'POST', 'uri' => 'auth/otp/request', 'action' => [AuthController::class, 'requestCode'],
                'apps' => [], 'capability' => null, 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-enrol', 'status' => 202,
                'summary' => 'Send a six-digit sign-in code by email or text. The answer is the same whether or not an account exists. Board resident-app-01.',
                'request' => [
                    'estate' => 'string · required · the estate\'s id, e.g. `phoenixpark` — the app ships the list or reads it from a QR code at the estate office',
                    'channel' => 'string · required · `email` or `sms`',
                    'destination' => 'string · required · the email address, or the number in any common format',
                ],
                'response' => [
                    'sent' => 'always true',
                    'channel' => 'as sent',
                    'destination_hint' => 'masked',
                    'expires_at' => 'ten minutes from issue',
                    'resend_after' => 'a request before this reuses the code already sent',
                ],
                'errors' => [
                    '404 estate_not_found' => 'No active estate by that id.',
                    '503 sms_unavailable' => 'No SMS provider on this platform — sign in with email.',
                    '429 rate_limited' => 'Ten requests a minute per address.',
                ],
                'offline' => 'Online only.',
            ],
            'auth.otp.verify' => [
                'method' => 'POST', 'uri' => 'auth/otp/verify', 'action' => [AuthController::class, 'verifyCode'],
                'apps' => [], 'capability' => null, 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-enrol', 'status' => 200,
                'summary' => 'Prove the code and receive this install\'s token. A new account is `pending` until the estate approves a unit claim; signing in again on the same install replaces its token.',
                'request' => [
                    'estate' => 'string · required',
                    'channel' => 'string · required',
                    'destination' => 'string · required · as sent to `/auth/otp/request`',
                    'code' => 'string · required · six digits',
                    'device_uid' => 'string · required · a stable id for this install',
                    'platform' => 'string · required · `ios` or `android`',
                ],
                'response' => [
                    'token' => 'the bearer token — store it in the keychain/keystore',
                    'abilities.*' => 'the Resident App\'s abilities, from the app matrix',
                    'account.id' => 'the account',
                    'account.status' => '`pending` or `active`',
                    'account.full_name' => 'or null',
                    'estate.id' => 'the estate',
                    'estate.name' => 'its name',
                    'claim' => 'null, or the account\'s latest claim',
                    ...self::under('claim.', $claim),
                    'next' => '`claim_unit`, `await_approval` or `home` — the screen to open',
                ],
                'errors' => [
                    '404 estate_not_found' => 'No active estate by that id.',
                    '422 otp_invalid' => 'Wrong code; the message says how many tries are left.',
                    '422 otp_expired' => 'Expired, used, or replaced by a newer code.',
                    '423 otp_locked' => 'Five wrong codes. Request a new one.',
                    '403 account_suspended' => 'The estate withdrew access.',
                ],
                'offline' => 'Online only.',
            ],
            'auth.claim_unit' => [
                'method' => 'POST', 'uri' => 'auth/claim-unit', 'action' => [AuthController::class, 'claimUnit'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true, 'pending' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Claim a unit. The estate reviews it on its claims screen; once approved, the account is active on its next request. A pending claim is returned as it is (200). Board resident-app-04.',
                'request' => [
                    'full_name' => 'string · required',
                    'lot' => 'string · required · e.g. `47` or `Lot 47`',
                    'phase' => 'string · optional · e.g. `Phase 2`',
                    'phone' => 'string · optional',
                    'relationship' => 'string · optional · `owner`, `tenant`, `spouse`, `child`, `parent`, `relative`, `other`',
                ],
                'response' => [
                    'account.id' => 'the account',
                    'account.status' => '`pending`',
                    'account.full_name' => 'as claimed',
                    ...self::under('claim.', $claim),
                ],
                'errors' => ['409 already_linked' => 'The account is already active on a unit.'],
                'offline' => 'Online only.',
            ],
            'me.show' => [
                'method' => 'GET', 'uri' => 'me', 'action' => [MeController::class, 'show'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::READ, 'write' => false, 'pending' => true,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The account, its claim, and once linked its unit, household and register entry. Poll while pending. Board resident-app-10.',
                'request' => [],
                'response' => $profile,
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'me.update' => [
                'method' => 'PATCH', 'uri' => 'me', 'action' => [MeController::class, 'update'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200,
                'summary' => 'Change the account\'s display name and the register\'s phone and email. The name on the register is the estate\'s.',
                'request' => [
                    'full_name' => 'string · optional',
                    'phone' => 'string · optional',
                    'email' => 'string · optional',
                ],
                'response' => $profile,
                'errors' => [],
                'offline' => 'Queue.',
            ],
            'me.household' => [
                'method' => 'GET', 'uri' => 'me/household', 'action' => [MeController::class, 'household'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The home screen\'s household: members, vehicles, emergency contacts, and any guard waiting on an answer at the gate. Boards resident-app-02, -09.',
                'request' => [],
                'response' => [
                    'household.id' => 'the household',
                    'household.name' => 'its name',
                    'unit.id' => 'the unit',
                    'unit.reference' => 'reference',
                    'unit.phase' => 'phase',
                    ...self::under('members.*.', $member),
                    ...self::under('vehicles.*.', $vehicle),
                    ...self::under('emergency_contacts.*.', $contact),
                    'pending_approvals.*.id' => 'a walk-up request to answer with `/gate/approval/{id}/respond`',
                    'pending_approvals.*.visitor_name' => 'who is at the gate',
                    'pending_approvals.*.purpose' => 'or null',
                    'pending_approvals.*.vehicle_plate' => 'or null',
                    'pending_approvals.*.post_name' => 'which gate',
                    'pending_approvals.*.guard_name' => 'the guard asking',
                    'pending_approvals.*.requested_at' => 'ISO 8601',
                    'pending_approvals.*.respond_by' => 'after this the estate\'s policy applies',
                ],
                'errors' => [],
                'offline' => 'Serve the cache. Pending approvals are live only.',
            ],
            'members.index' => [
                'method' => 'GET', 'uri' => 'household-members', 'action' => [MeController::class, 'members'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The household\'s members on the register.',
                'request' => [],
                'response' => self::under('items.*.', $member),
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'members.store' => [
                'method' => 'POST', 'uri' => 'household-members', 'action' => [MeController::class, 'addMember'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Add a household member. They are `pending` until the estate verifies them.',
                'request' => [
                    'full_name' => 'string · required',
                    'relationship' => 'string · required · `spouse`, `child`, `parent`, `relative`, `tenant`, `domestic_staff`, `other`',
                    'phone' => 'string · optional',
                    'email' => 'string · optional',
                ],
                'response' => $member,
                'errors' => [],
                'offline' => 'Queue.',
            ],
            'vehicles.index' => [
                'method' => 'GET', 'uri' => 'vehicles', 'action' => [MeController::class, 'vehicles'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The household\'s registered vehicles.',
                'request' => [],
                'response' => self::under('items.*.', $vehicle),
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'vehicles.store' => [
                'method' => 'POST', 'uri' => 'vehicles', 'action' => [MeController::class, 'addVehicle'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Register a vehicle. A plate already registered to the household is returned as it is (200).',
                'request' => [
                    'plate' => 'string · required · letters, digits, spaces, hyphens',
                    'make' => 'string · optional',
                    'model' => 'string · optional',
                    'colour' => 'string · optional',
                ],
                'response' => $vehicle,
                'errors' => [],
                'offline' => 'Queue.',
            ],
            'contacts.index' => [
                'method' => 'GET', 'uri' => 'emergency-contacts', 'action' => [MeController::class, 'contacts'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Who to call for this household. Never shown to a guard.',
                'request' => [],
                'response' => self::under('items.*.', $contact),
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'contacts.store' => [
                'method' => 'POST', 'uri' => 'emergency-contacts', 'action' => [MeController::class, 'addContact'],
                'apps' => $resident, 'capability' => 'household', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Add an emergency contact.',
                'request' => [
                    'name' => 'string · required',
                    'relationship' => 'string · optional',
                    'phone' => 'string · required',
                ],
                'response' => $contact,
                'errors' => [],
                'offline' => 'Queue.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function residentPasses(): array
    {
        $resident = [AppMatrix::RESIDENT];
        $pass = [
            'id' => 'the pass — the id the pass endpoints take',
            'pass_id' => 'UUID, in the signed payload',
            'category' => '`single`, `recurring`, `contractor`, `delivery`; `resident` for the e-pass',
            'visitor_name' => 'who it is for',
            'visitor_phone' => 'or null',
            'purpose' => 'or null',
            'vehicle_plate' => 'or null',
            'valid_from' => 'ISO 8601',
            'valid_to' => 'ISO 8601',
            'single_use' => 'boolean',
            'status' => '`active`, `used`, `cancelled` or `expired`',
            'code' => 'the short code a visitor can read out, e.g. `PPV2-4471`',
            'token' => 'the QR payload: `base64url(payload).base64url(signature)`',
            'share_count' => 'times shared',
            'used_at' => 'or null',
            'cancelled_at' => 'or null',
        ];
        $passErrors = ['404 not_found' => 'Not a pass of this household\'s.'];

        return [
            'visitor_passes.index' => [
                'method' => 'GET', 'uri' => 'visitor-passes', 'action' => [PassesController::class, 'index'],
                'apps' => $resident, 'capability' => 'passes', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The household\'s visitor passes from the last 30 days. Board resident-app-06.',
                'request' => ['status' => 'string · optional (query) · `active`, `used`, `cancelled` or `expired`'],
                'response' => self::under('items.*.', $pass),
                'errors' => [],
                'offline' => 'Serve the cache — the QR codes render offline.',
            ],
            'visitor_passes.store' => [
                'method' => 'POST', 'uri' => 'visitor-passes', 'action' => [PassesController::class, 'store'],
                'apps' => $resident, 'capability' => 'passes', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Issue a signed visitor pass. At most 31 days long. Board resident-app-05.',
                'request' => [
                    'category' => 'string · required · `single`, `recurring`, `contractor` or `delivery`',
                    'visitor_name' => 'string · required',
                    'visitor_phone' => 'string · optional',
                    'purpose' => 'string · optional',
                    'vehicle_plate' => 'string · optional',
                    'valid_from' => 'ISO 8601 · required',
                    'valid_to' => 'ISO 8601 · required · after `valid_from`',
                ],
                'response' => $pass,
                'errors' => [
                    '409 access_restricted' => 'The household is restricted for this category. The wording is the gate\'s; the app shows nothing more.',
                    '422 pass_refused' => 'Longer than 31 days, or otherwise refused; the message says why.',
                ],
                'offline' => 'Online only: a pass is signed by the server.',
            ],
            'visitor_passes.update' => [
                'method' => 'PATCH', 'uri' => 'visitor-passes/{pass}', 'action' => [PassesController::class, 'update'],
                'apps' => $resident, 'capability' => 'passes', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['pass' => '[0-9]+'],
                'summary' => 'Change what is not signed: the visitor\'s name, number, purpose or plate. A different window or category is a new pass.',
                'request' => [
                    'visitor_name' => 'string · optional',
                    'visitor_phone' => 'string · optional',
                    'purpose' => 'string · optional',
                    'vehicle_plate' => 'string · optional',
                ],
                'response' => $pass,
                'errors' => [...$passErrors, '409 pass_not_active' => 'Used, cancelled or expired.', '422 validation_failed' => 'A signed field (`valid_from`, `valid_to`, `category`) was sent.'],
                'offline' => 'Queue.',
            ],
            'visitor_passes.cancel' => [
                'method' => 'POST', 'uri' => 'visitor-passes/{pass}/cancel', 'action' => [PassesController::class, 'cancel'],
                'apps' => $resident, 'capability' => 'passes', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['pass' => '[0-9]+'],
                'summary' => 'Cancel a pass. Guards\' handsets learn of it at their next sync; online verification refuses it at once.',
                'request' => [],
                'response' => $pass,
                'errors' => [...$passErrors, '409 pass_already_used' => 'Nothing left to cancel.'],
                'offline' => 'Queue, and tell the resident the gate learns of it when both are online.',
            ],
            'visitor_passes.share' => [
                'method' => 'POST', 'uri' => 'visitor-passes/{pass}/share', 'action' => [PassesController::class, 'share'],
                'apps' => $resident, 'capability' => 'passes', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['pass' => '[0-9]+'],
                'summary' => 'Count a share and get the message to send. The app renders the QR image from `token` and hands both to the OS share sheet.',
                'request' => ['channel' => 'string · optional · `whatsapp`, `sms`, `email` or `copy`'],
                'response' => [
                    'pass_id' => 'UUID',
                    'share_count' => 'after this share',
                    'code' => 'short code',
                    'token' => 'QR payload',
                    'message' => 'the text to send the visitor',
                ],
                'errors' => [...$passErrors, '409 pass_not_active' => 'Used, cancelled or expired.'],
                'offline' => 'Share from cache; send the count when online.',
            ],
            'epass.show' => [
                'method' => 'GET', 'uri' => 'e-pass', 'action' => [PassesController::class, 'epass'],
                'apps' => $resident, 'capability' => 'passes', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The resident\'s own signed e-pass, good for 24 hours and reused until an hour before it ends. Never restricted. Board resident-app-08.',
                'request' => [],
                'response' => [...$pass, 'refresh_after' => 'fetch a new one after this'],
                'errors' => ['403 account_pending' => 'No register entry linked yet.'],
                'offline' => 'Show the cached pass until `valid_to`; a guard verifies it offline.',
            ],
            'approvals.respond' => [
                'method' => 'POST', 'uri' => 'gate/approval/{approval}/respond', 'action' => [PassesController::class, 'respond'],
                'apps' => $resident, 'capability' => 'approvals', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['approval' => '[0-9]+'],
                'summary' => 'Approve or deny a visitor a guard is holding at the gate. Board resident-app-07.',
                'request' => ['decision' => 'string · required · `approve` or `deny`'],
                'response' => [
                    'approval_id' => 'the request',
                    'status' => '`approved` or `denied`',
                    'visitor_name' => 'who',
                    'responded_at' => 'ISO 8601',
                ],
                'errors' => [
                    '404 not_found' => 'Not a request for this household.',
                    '409 approval_expired' => 'The three minutes ran out.',
                    '409 approval_decided' => 'Already answered.',
                ],
                'offline' => 'Online only — a late answer is no answer.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function residentDues(): array
    {
        $resident = [AppMatrix::RESIDENT];
        $payment = [
            'id' => 'the payment',
            'receipt_no' => 'e.g. `PPV-R-04471`',
            'amount' => 'decimal string',
            'currency' => '`JMD`',
            'method' => '`bank`, `cash`, `cheque` or `card`',
            'reference' => 'the payer\'s reference, or null',
            'received_at' => 'ISO 8601',
            'status' => '`recorded` or `reversed`',
        ];
        $household = ['404 not_found' => 'Not this account\'s household — the same answer as an id nobody has.'];

        return [
            'invoices.index' => [
                'method' => 'GET', 'uri' => 'invoices', 'action' => [DuesController::class, 'invoices'],
                'apps' => $resident, 'capability' => 'dues', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The household\'s charges, newest first, each with what is still owed on it (payments clear the oldest first). Board resident-app-11.',
                'request' => [],
                'response' => [
                    'currency' => '`JMD`',
                    'items.*.id' => 'the charge — the id `/invoices/{invoice}/pay-intent` takes',
                    'items.*.reference' => 'e.g. `DUES-2026-09-L47`',
                    'items.*.type' => '`dues`, `special_assessment`, `fine` or `amenity`',
                    'items.*.period' => '`2026-09`, or null',
                    'items.*.description' => 'text',
                    'items.*.amount' => 'decimal string',
                    'items.*.outstanding' => 'decimal string',
                    'items.*.due_on' => 'YYYY-MM-DD',
                    'items.*.status' => '`paid`, `part_paid`, `overdue` or `due`',
                ],
                'errors' => [],
                'offline' => 'Serve the cache with its age; never show a cached balance as current.',
            ],
            'households.balance' => [
                'method' => 'GET', 'uri' => 'households/{household}/balance', 'action' => [DuesController::class, 'balance'],
                'apps' => $resident, 'capability' => 'dues', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['household' => '[0-9]+'],
                'summary' => 'What the household owes, from the ledger. Board resident-app-11.',
                'request' => [],
                'response' => [
                    'household_id' => 'the household',
                    'unit' => 'reference',
                    'balance' => 'decimal string; negative is credit',
                    'currency' => '`JMD`',
                    'as_at' => 'YYYY-MM-DD',
                    'oldest_unpaid_due_on' => 'or null',
                    'days_overdue' => 'integer',
                    'on_payment_plan' => 'boolean',
                ],
                'errors' => $household,
                'offline' => 'Serve the cache with its age.',
            ],
            'households.statement' => [
                'method' => 'GET', 'uri' => 'households/{household}/statement', 'action' => [DuesController::class, 'statement'],
                'apps' => $resident, 'capability' => 'dues', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['household' => '[0-9]+'],
                'summary' => 'The running statement from the journal, newest first. Board resident-app-12.',
                'request' => ['limit' => 'integer · optional (query) · 1–200, default 50'],
                'response' => [
                    'unit' => 'reference',
                    'currency' => '`JMD`',
                    'closing_balance' => 'decimal string',
                    'items.*.date' => 'YYYY-MM-DD',
                    'items.*.description' => 'the line\'s memo',
                    'items.*.reference' => 'the journal entry',
                    'items.*.charge' => 'decimal string, or null',
                    'items.*.payment' => 'decimal string, or null',
                    'items.*.balance' => 'running, decimal string',
                ],
                'errors' => $household,
                'offline' => 'Serve the cache with its age.',
            ],
            'invoices.pay_intent' => [
                'method' => 'POST', 'uri' => 'invoices/{invoice}/pay-intent', 'action' => [DuesController::class, 'payIntent'],
                'apps' => $resident, 'capability' => 'payments', 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['invoice' => '[0-9]+'],
                'summary' => 'How to pay this charge. Dues are paid by hand on this platform (Q-012): the answer is the amount, the reference to quote and the estate\'s instructions — never a card form. Board resident-app-13.',
                'request' => [],
                'response' => [
                    'invoice_id' => 'the charge',
                    'online_payment_available' => 'false',
                    'reason' => 'why, in a sentence',
                    'amount_due' => 'decimal string still owed on this charge',
                    'currency' => '`JMD`',
                    'quote_reference' => 'what to write on the transfer or cheque',
                    'instructions' => 'the estate\'s own payment instructions',
                    'estate' => 'the estate\'s name',
                ],
                'errors' => ['404 not_found' => 'Not a charge of this household\'s.'],
                'offline' => 'Serve the cached instructions.',
            ],
            'payments.index' => [
                'method' => 'GET', 'uri' => 'payments', 'action' => [DuesController::class, 'payments'],
                'apps' => $resident, 'capability' => 'dues', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Payments recorded for the household, newest first. Board resident-app-14.',
                'request' => [],
                'response' => ['currency' => '`JMD`', ...self::under('items.*.', $payment)],
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'payments.receipt' => [
                'method' => 'GET', 'uri' => 'payments/{payment}/receipt', 'action' => [DuesController::class, 'receipt'],
                'apps' => $resident, 'capability' => 'dues', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['payment' => '[0-9]+'],
                'summary' => 'One receipt, as the estate issued it.',
                'request' => [],
                'response' => [
                    ...$payment,
                    'estate' => 'name',
                    'unit' => 'reference',
                    'household' => 'name',
                    'received_by_name' => 'who took it, or null',
                    'entered_at' => 'when it was keyed',
                ],
                'errors' => ['404 not_found' => 'Not a payment of this household\'s.'],
                'offline' => 'Serve the cache.',
            ],
            'autopay.store' => [
                'method' => 'POST', 'uri' => 'autopay', 'action' => [DuesController::class, 'autopay'],
                'apps' => $resident, 'capability' => 'payments', 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-writes', 'status' => 409,
                'summary' => 'AutoPay. Refused on this platform while dues are paid by hand (Q-012); the endpoint exists so the app can say so from the server rather than hide the control.',
                'request' => [],
                'response' => [],
                'errors' => ['409 autopay_unavailable' => 'Always, until a card gateway is ruled in.'],
                'offline' => 'Not applicable.',
            ],
            'autopay.destroy' => [
                'method' => 'DELETE', 'uri' => 'autopay/{autopay}', 'action' => [DuesController::class, 'cancelAutopay'],
                'apps' => $resident, 'capability' => 'payments', 'access' => AppMatrix::WRITE, 'write' => false,
                'throttle' => 'api-writes', 'status' => 404, 'where' => ['autopay' => '[0-9]+'],
                'summary' => 'Cancel AutoPay. No arrangement can exist, so this answers 404.',
                'request' => [],
                'response' => [],
                'errors' => ['404 not_found' => 'Always, while dues are paid by hand.'],
                'offline' => 'Not applicable.',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function residentCommunity(): array
    {
        $resident = [AppMatrix::RESIDENT];
        $ticket = [
            'id' => 'the ticket',
            'number' => 'e.g. 1042',
            'title' => 'title',
            'category' => 'or null',
            'location' => 'where',
            'description' => 'or null',
            'priority' => '`low`, `medium` or `high`',
            'status' => '`submitted`, `acknowledged`, `assigned`, `in_progress`, `completed`, `verified` or `cancelled`',
            'reported_at' => 'ISO 8601 — the SLA runs from here',
            'technician_name' => 'or null',
            'eta_starts_at' => 'or null',
            'eta_ends_at' => 'or null',
            'resolution' => 'or null',
            'closed_at' => 'or null',
            'media_count' => 'attachments',
            'timeline.*.event' => '`reported`, `acknowledged`, `assigned`, `started`, `resolved`, …',
            'timeline.*.status' => 'status after the event, or null',
            'timeline.*.note' => 'or null',
            'timeline.*.occurred_at' => 'ISO 8601',
        ];
        $header = [
            'id' => 'the ballot',
            'year' => 'year',
            'title' => 'title',
            'kind' => '`election` or `resolution`',
            'question' => 'for a resolution, or null',
            'stage' => 'machine stage',
            'stage_label' => 'e.g. "Voting open"',
            'opens_at' => 'or null',
            'closes_at' => 'or null',
        ];
        $booking = [
            'id' => 'the booking',
            'reference' => 'e.g. `BKG-2026-09-0004`',
            'amenity.id' => 'amenity',
            'amenity.name' => 'its name',
            'starts_at' => 'ISO 8601',
            'ends_at' => 'ISO 8601',
            'guests' => 'or null',
            'status' => '`pending`, `confirmed`, `declined`, `cancelled` or `completed`',
            'booking_fee' => 'decimal string',
            'deposit' => 'decimal string',
            'deposit_state' => '`none`, `awaiting`, `held`, `refunded` or `forfeited`',
            'currency' => '`JMD`',
            'cancellable_until' => 'the app may cancel until this moment',
            'declined_reason' => 'or null',
            'cancelled_at' => 'or null',
        ];

        return [
            'tickets.index' => [
                'method' => 'GET', 'uri' => 'tickets', 'action' => [CommunityController::class, 'tickets'],
                'apps' => $resident, 'capability' => 'tickets', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The household\'s maintenance tickets with their timelines. Board resident-app-16.',
                'request' => [],
                'response' => self::under('items.*.', $ticket),
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'tickets.store' => [
                'method' => 'POST', 'uri' => 'tickets', 'action' => [CommunityController::class, 'reportTicket'],
                'apps' => $resident, 'capability' => 'tickets', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Report a problem. It lands in the estate\'s maintenance queue. Board resident-app-15.',
                'request' => [
                    'title' => 'string · required',
                    'category' => 'string · optional · `plumbing`, `electrical`, `security`, `landscaping`, `roads`, `water`, `waste`, `amenity`, `other`',
                    'description' => 'string · optional',
                    'location' => 'string · optional · defaults to the unit',
                    'priority' => 'string · optional · `low`, `medium` (default) or `high`',
                ],
                'response' => $ticket,
                'errors' => [],
                'offline' => 'Queue; attach photos after it syncs.',
            ],
            'tickets.media' => [
                'method' => 'POST', 'uri' => 'tickets/{ticket}/media', 'action' => [CommunityController::class, 'ticketMedia'],
                'apps' => $resident, 'capability' => 'tickets', 'access' => AppMatrix::WRITE, 'write' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 201, 'where' => ['ticket' => '[0-9]+'],
                'summary' => 'Attach a photo or video (multipart, field `file`). Stored privately, hashed on arrival.',
                'request' => ['file' => 'file · required · JPEG, PNG, HEIC, MP4 or MOV, at most 50 MB'],
                'response' => [
                    'media_id' => 'the attachment',
                    'ticket_id' => 'the ticket',
                    'filename' => 'as uploaded',
                    'content_type' => 'as detected',
                    'bytes' => 'size',
                    'sha256' => 'hash',
                ],
                'errors' => ['404 not_found' => 'Not a ticket of this household\'s.'],
                'offline' => 'Queue the file.',
            ],
            'notices.index' => [
                'method' => 'GET', 'uri' => 'notices', 'action' => [CommunityController::class, 'notices'],
                'apps' => $resident, 'capability' => 'notices', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Published notices for the whole estate and the household\'s phase. Board resident-app-17.',
                'request' => [],
                'response' => [
                    'items.*.id' => 'the notice',
                    'items.*.kind' => '`general` or `urgent`',
                    'items.*.title' => 'title',
                    'items.*.body' => 'text',
                    'items.*.author_name' => 'who posted it',
                    'items.*.posted_as_role' => 'e.g. "Secretary", or null',
                    'items.*.audience' => '"Whole estate" or the phase',
                    'items.*.published_at' => 'ISO 8601',
                    'items.*.read_at' => 'when this resident read it, or null',
                    'unread' => 'count',
                ],
                'errors' => [],
                'offline' => 'Serve the cache; queue reads.',
            ],
            'notices.read' => [
                'method' => 'POST', 'uri' => 'notices/{notice}/read', 'action' => [CommunityController::class, 'readNotice'],
                'apps' => $resident, 'capability' => 'notices', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['notice' => '[0-9]+'],
                'summary' => 'Mark a notice read — board 30\'s "seen" count. Idempotent.',
                'request' => [],
                'response' => ['notice_id' => 'the notice', 'read_at' => 'the first read'],
                'errors' => ['404 not_found' => 'Not a notice for this household.'],
                'offline' => 'Queue.',
            ],
            'meetings.index' => [
                'method' => 'GET', 'uri' => 'meetings', 'action' => [CommunityController::class, 'meetings'],
                'apps' => $resident, 'capability' => 'meetings', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'General and phase meetings from the last six months and ahead, with agendas and the household\'s RSVP. Board resident-app-18.',
                'request' => [],
                'response' => [
                    'items.*.id' => 'the meeting',
                    'items.*.type' => '`agm`, `egm` or `phase`',
                    'items.*.title' => 'title',
                    'items.*.starts_at' => 'ISO 8601',
                    'items.*.venue' => 'or null',
                    'items.*.virtual_link' => 'or null',
                    'items.*.status' => '`scheduled`, `held` or `cancelled`',
                    'items.*.recording_enabled' => 'boolean — tell the resident before they join',
                    'items.*.agenda.*.start_time' => 'or null',
                    'items.*.agenda.*.text' => 'item',
                    'items.*.minutes_available' => 'boolean',
                    'items.*.my_rsvp' => '`attending`, `apologies`, `not_attending` or null',
                ],
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'meetings.rsvp' => [
                'method' => 'POST', 'uri' => 'meetings/{meeting}/rsvp', 'action' => [CommunityController::class, 'rsvp'],
                'apps' => $resident, 'capability' => 'meetings', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['meeting' => '[0-9]+'],
                'summary' => 'Say whether the household will attend. Not attendance — quorum is counted from the register taken at the meeting.',
                'request' => ['response' => 'string · required · `attending`, `apologies` or `not_attending`'],
                'response' => [
                    'meeting_id' => 'the meeting',
                    'response' => 'as recorded',
                    'responded_at' => 'ISO 8601',
                    'households_attending' => 'households that said they will attend',
                ],
                'errors' => ['404 not_found' => 'Not a meeting for this household.', '409 meeting_closed' => 'Started, held or cancelled.'],
                'offline' => 'Queue.',
            ],
            'elections.index' => [
                'method' => 'GET', 'uri' => 'elections', 'action' => [ElectionsController::class, 'index'],
                'apps' => $resident, 'capability' => 'elections', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Elections and resolutions past draft, with the paper and whether the household has voted — never how. Board resident-app-19.',
                'request' => [],
                'response' => [
                    ...self::under('items.*.', $header),
                    'items.*.voting_open' => 'boolean',
                    'items.*.has_voted' => 'boolean — turnout, not choice',
                    'items.*.results_published' => 'boolean',
                    'items.*.positions.*.id' => 'a position',
                    'items.*.positions.*.name' => 'e.g. "Phase 2 Representative"',
                    'items.*.positions.*.seat_count' => 'how many to mark at most',
                    'items.*.positions.*.options.*.id' => 'an option id to send',
                    'items.*.positions.*.options.*.label' => 'the candidate',
                    'items.*.options.*.id' => 'a resolution\'s option id',
                    'items.*.options.*.label' => 'e.g. "For"',
                ],
                'errors' => [],
                'offline' => 'Serve the cache. Voting is online only.',
            ],
            'elections.eligibility' => [
                'method' => 'GET', 'uri' => 'elections/{ballot}/eligibility', 'action' => [ElectionsController::class, 'eligibility'],
                'apps' => $resident, 'capability' => 'elections', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['ballot' => '[0-9]+'],
                'summary' => 'Whether the household may vote, under the estate\'s rules. Board resident-app-20.',
                'request' => [],
                'response' => [
                    'ballot_id' => 'the ballot',
                    'eligible' => 'boolean',
                    'reason' => 'e.g. "arrears >90 days", or null',
                    'checked_on' => 'YYYY-MM-DD',
                    'voting_open' => 'boolean',
                    'has_voted' => 'boolean',
                ],
                'errors' => ['404 not_found' => 'No such election.'],
                'offline' => 'Online only.',
            ],
            'elections.ballot' => [
                'method' => 'POST', 'uri' => 'elections/{ballot}/ballot', 'action' => [ElectionsController::class, 'cast'],
                'apps' => $resident, 'capability' => 'elections', 'access' => AppMatrix::WRITE, 'write' => true, 'opaque' => true, 'batch' => false,
                'throttle' => 'api-writes', 'status' => 201, 'where' => ['ballot' => '[0-9]+'],
                'summary' => 'Cast the household\'s paper. One per household. The answer names no mark, option or receipt, and the idempotency record keeps no trace of the body. Board resident-app-21.',
                'request' => ['option_ids' => 'array of integers · required · across every position, at most each position\'s seat count'],
                'response' => [
                    'ballot_id' => 'the ballot',
                    'voted' => 'true',
                    'voted_on' => 'YYYY-MM-DD — a date, never a time',
                ],
                'errors' => [
                    '403 not_eligible' => 'The estate\'s rules exclude the household; the reason is given.',
                    '409 poll_closed' => 'Not open.',
                    '409 already_voted' => 'The household\'s paper is in.',
                    '422 paper_invalid' => 'Blank, over-marked, or an option from another ballot.',
                ],
                'offline' => 'Online only. Never queue a vote.',
            ],
            'elections.results' => [
                'method' => 'GET', 'uri' => 'elections/{ballot}/results', 'action' => [ElectionsController::class, 'results'],
                'apps' => $resident, 'capability' => 'elections', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200, 'where' => ['ballot' => '[0-9]+'],
                'summary' => 'Certified, published results: counts per option, turnout and quorum.',
                'request' => [],
                'response' => [
                    ...$header,
                    'certified_at' => 'ISO 8601',
                    'published_at' => 'ISO 8601',
                    'outcome_statement' => 'or null',
                    'turnout.cast' => 'households',
                    'turnout.eligible' => 'households',
                    'turnout.percent' => 'integer',
                    'quorum.required' => 'households',
                    'quorum.met' => 'boolean',
                    'positions.*.id' => 'position',
                    'positions.*.name' => 'name',
                    'positions.*.seat_count' => 'seats',
                    'positions.*.options.*.id' => 'option',
                    'positions.*.options.*.label' => 'candidate',
                    'positions.*.options.*.votes' => 'marks',
                    'options.*.id' => 'option',
                    'options.*.label' => 'label',
                    'options.*.votes' => 'marks',
                ],
                'errors' => ['404 not_found' => 'No such election.', '409 results_not_published' => 'Not yet certified and published.'],
                'offline' => 'Serve the cache once fetched.',
            ],
            'amenities.index' => [
                'method' => 'GET', 'uri' => 'amenities', 'action' => [BookingsController::class, 'amenities'],
                'apps' => $resident, 'capability' => 'bookings', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'Bookable amenities and their terms, and whether the household may book. Board resident-app-22.',
                'request' => [],
                'response' => [
                    'may_book' => 'false while the household is restricted from bookings',
                    'items.*.id' => 'the amenity',
                    'items.*.name' => 'e.g. "Club House"',
                    'items.*.icon_key' => 'icon',
                    'items.*.capacity' => 'guests',
                    'items.*.opens_at' => 'HH:MM:SS',
                    'items.*.closes_at' => 'HH:MM:SS',
                    'items.*.booking_fee' => 'decimal string',
                    'items.*.deposit' => 'decimal string',
                    'items.*.currency' => '`JMD`',
                    'items.*.cancellation_hours' => 'or null',
                    'items.*.booking_window_days' => 'or null',
                ],
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'bookings.index' => [
                'method' => 'GET', 'uri' => 'bookings', 'action' => [BookingsController::class, 'index'],
                'apps' => $resident, 'capability' => 'bookings', 'access' => AppMatrix::READ, 'write' => false,
                'throttle' => 'api-reads', 'status' => 200,
                'summary' => 'The household\'s bookings. Board resident-app-23.',
                'request' => [],
                'response' => self::under('items.*.', $booking),
                'errors' => [],
                'offline' => 'Serve the cache.',
            ],
            'bookings.store' => [
                'method' => 'POST', 'uri' => 'bookings', 'action' => [BookingsController::class, 'store'],
                'apps' => $resident, 'capability' => 'bookings', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 201,
                'summary' => 'Request a booking. It is `pending` and holds the slot until the estate decides.',
                'request' => [
                    'amenity_id' => 'integer · required',
                    'starts_at' => 'ISO 8601 · required · in the future, within the booking window',
                    'ends_at' => 'ISO 8601 · required',
                    'guests' => 'integer · optional',
                    'notes' => 'string · optional',
                ],
                'response' => $booking,
                'errors' => ['404 not_found' => 'No such amenity.', '422 booking_refused' => 'Outside hours, over capacity, the slot is taken, or bookings are closed to the household; the estate\'s own sentence.'],
                'offline' => 'Online only — a slot is first come.',
            ],
            'bookings.cancel' => [
                'method' => 'POST', 'uri' => 'bookings/{booking}/cancel', 'action' => [BookingsController::class, 'cancel'],
                'apps' => $resident, 'capability' => 'bookings', 'access' => AppMatrix::WRITE, 'write' => true,
                'throttle' => 'api-writes', 'status' => 200, 'where' => ['booking' => '[0-9]+'],
                'summary' => 'Cancel within the booking\'s cancellation terms. A deposit already held stays held until the office refunds it.',
                'request' => [],
                'response' => $booking,
                'errors' => ['404 not_found' => 'Not a booking of this household\'s.', '409 cancellation_refused' => 'Inside the cancellation window, already started, or not cancellable.'],
                'offline' => 'Queue.',
            ],
        ];
    }
}
