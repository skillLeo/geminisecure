<?php

declare(strict_types=1);

namespace App\Api;

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\GateEventController;
use App\Http\Controllers\Api\V1\PassKeyController;
use App\Http\Controllers\Api\V1\PassVerificationController;
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
     * @return array<string, array{method: string, uri: string, action: array{0: class-string, 1: string}, apps: list<string>, capability: string|null, access: string, write: bool, legacy?: bool, pending?: bool, throttle: string, status: int, where?: array<string, string>, summary: string, request: array<string, string>, response: array<string, string>, errors: array<string, string>, offline: string}>
     */
    public static function endpoints(): array
    {
        return [
            ...self::devices(),
            ...self::legacyGuard(),
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
            'standing_orders.acknowledge' => [
                'method' => 'POST', 'uri' => 'standing-orders/{set}/acknowledge', 'action' => [StandingOrderController::class, 'acknowledge'],
                'apps' => [AppMatrix::GUARD], 'capability' => 'orders', 'access' => AppMatrix::WRITE,
                'write' => true, 'legacy' => true, 'throttle' => 'api-shift-clock', 'status' => 200, 'where' => ['set' => '[0-9]+'],
                'summary' => 'Acknowledge the version of an order set the guard read. Legacy form of `POST /standing-orders/{version}/acknowledge`.',
                'request' => ['version' => 'integer · required · the version on screen'],
                'response' => [
                    'set' => 'the order set',
                    'version' => 'the version acknowledged',
                    'acknowledged_at' => 'when — the first acknowledgement of this version, however many were sent',
                    'server_time' => 'the server\'s time',
                    'device_time' => 'the handset\'s time, as sent',
                    'clock_skewed' => 'true when the two disagree by more than two minutes',
                ],
                'errors' => ['409 orders_changed' => 'Revised since the guard read them, or not their post\'s orders.'],
                'offline' => 'Queue; if the orders were revised meanwhile the sync answers 409 and the app shows the new version.',
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
}
