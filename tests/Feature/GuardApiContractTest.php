<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\VisitorPass;
use App\Models\Guard;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Models\Shift;
use App\Models\StatutoryRateVersion;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Estate\Collections;
use App\Services\Passes\VisitorPasses;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The Guard App's API, walked end to end — 13 D2
|--------------------------------------------------------------------------
|
| One guard's shift through every endpoint a Guard App token reaches: clock on,
| break, patrol, answer a check, verify and admit at the gate, file an incident
| with a photo, raise and cancel duress, ask for leave, read a payslip, message
| dispatch, and sync a queue captured offline.
|
| EVERY RESPONSE IS HELD TO ITS CATALOGUE ENTRY (`ApiContract`): a key the
| catalogue does not list fails, and no key on a guard route may name money
| except the guard's own payslip. EVERY WRITE carries an Idempotency-Key and the
| handset's clock, and the test fails at the end if a guard route in the
| catalogue was never called — so a new endpoint cannot ship uncontracted.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();

    $this->post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Contract Gate', 'type' => 'gate', 'is_active' => true]);
    $this->post->forceFill(['latitude' => 18.0179000, 'longitude' => -76.8099000, 'geofence_radius_m' => 5])->save();

    $this->guard = Guard::create([
        'full_name' => 'Kemar Contract',
        'employee_number' => 'GS-CTR-1',
        'psra_number' => 'PSRA-CTR-1',
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE,
        'post_id' => $this->post->id,
    ]);

    $this->token = app(DeviceEnrolment::class)->enrol($this->guard, 'Contract handset')['token'];

    /*
     * A lot with no payment plan standing over it. Other files leave plans on the
     * shared fixture's lots, and a plan being met lifts a restriction — which is
     * right, and would turn this test's amber verdict green for a reason of its own.
     */
    $this->unit = collect(['Lot 88', 'Lot 9', 'Lot 3', 'Lot 63', 'Lot 47'])
        ->map(fn (string $reference) => FacilitiesFixture::unit($reference))
        ->first(fn ($unit) => ! app(Collections::class)->isProtected($unit));

    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Fletcher household', 'access_restricted' => false]);
    $this->household->forceFill(['access_restricted' => false])->save();
    $this->resident = Resident::query()->firstOrCreate(
        ['household_id' => $this->household->id, 'full_name' => 'Andrea Fletcher'],
        ['relationship' => 'owner', 'is_primary' => true],
    );

    $GLOBALS['guardContractCalled'] = [];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    VisitorPass::query()->where('unit_id', $this->unit->id)->delete();
    DB::connection('tenant')->table('gate_approval_requests')->where('unit_id', $this->unit->id)->delete();
    $this->household->forceFill(['access_restricted' => false])->save();
});

/**
 * One call as the handset, held to its catalogue entry.
 *
 * @param  array<string, scalar>  $params
 * @param  array<string, mixed>  $body
 */
function asHandset(TestCase $test, string $token, string $name, array $params = [], array $body = [], ?int $status = null, array $headers = []): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);

    if ($entry['write']) {
        $headers += ['Idempotency-Key' => 'ctr-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()];
    }

    app('auth')->forgetGuards();

    $response = $entry['method'] === 'GET'
        ? $test->withToken($token)->getJson($uri.($body === [] ? '' : '?'.http_build_query($body)), $headers)
        : $test->withToken($token)->json($entry['method'], $uri, $body, $headers);

    $response->assertStatus($status ?? $entry['status']);

    ApiContract::assertMatches($response, $name);

    $GLOBALS['guardContractCalled'][$name] = true;

    FacilitiesFixture::boot();

    return $response;
}

it('walks every Guard App endpoint, each answer inside its catalogue allowlist and none carrying a household\'s money', function () {
    $t = $this->token;
    $site = FacilitiesFixture::ESTATE;

    $shift = Shift::create([
        'tenant_id' => $site, 'guard_id' => $this->guard->id, 'post_id' => $this->post->id,
        'rostered_start' => now()->subMinutes(10), 'rostered_end' => now()->addHours(12), 'status' => 'rostered',
    ]);

    $open = Shift::create([
        'tenant_id' => $site, 'guard_id' => null, 'post_id' => $this->post->id,
        'rostered_start' => now()->addDays(3), 'rostered_end' => now()->addDays(3)->addHours(12), 'status' => Shift::OPEN,
    ]);

    /* ── The roster and clocking on ─────────────────────────────── */

    expect(asHandset($this, $t, 'shifts.me.current')->json('shift.id'))->toBe($shift->id);

    $preflight = asHandset($this, $t, 'shifts.preflight', ['shift' => $shift->id], ['latitude' => 18.0179100, 'longitude' => -76.8099100, 'accuracy_m' => 3]);
    expect($preflight->json('allowed'))->toBeTrue()->and($preflight->json('within_geofence'))->toBeTrue();

    $far = asHandset($this, $t, 'shifts.preflight', ['shift' => $shift->id], ['latitude' => 18.0189, 'longitude' => -76.8099]);
    expect($far->json('allowed'))->toBeFalse()->and($far->json('distance_m'))->toBeGreaterThan(100);

    // A write with no Idempotency-Key, or no device clock, is refused before it happens.
    app('auth')->forgetGuards();
    $this->withToken($t)->postJson("/api/v1/shifts/{$shift->id}/break/start", [], ['X-Device-Time' => now()->toIso8601String()])
        ->assertStatus(422)->assertJsonPath('error.code', 'idempotency_key_required');
    app('auth')->forgetGuards();
    $this->withToken($t)->postJson("/api/v1/shifts/{$shift->id}/break/start", [], ['Idempotency-Key' => 'no-clock'])
        ->assertStatus(422)->assertJsonPath('error.code', 'device_time_required');

    asHandset($this, $t, 'shifts.break.start', ['shift' => $shift->id], [], 409);

    $clockIn = asHandset($this, $t, 'shifts.clock_in', ['shift' => $shift->id], ['geofence_distance_m' => 2]);
    expect($clockIn->json('status'))->toBe('on_duty')->and($clockIn->json('server_time'))->not->toBeNull();

    $break = asHandset($this, $t, 'shifts.break.start', ['shift' => $shift->id]);
    expect($break->json('ended_at'))->toBeNull();
    expect(asHandset($this, $t, 'shifts.break.end', ['shift' => $shift->id])->json('ended_at'))->not->toBeNull();
    asHandset($this, $t, 'shifts.break.end', ['shift' => $shift->id], [], 409);

    $mine = asHandset($this, $t, 'shifts.me', [], ['from' => now()->subDay()->toDateString(), 'to' => now()->addDay()->toDateString()]);
    expect($mine->json('totals.shifts'))->toBe(1);
    asHandset($this, $t, 'shifts.me', [], ['from' => now()->subDays(40)->toDateString(), 'to' => now()->toDateString()], 422);

    $offered = asHandset($this, $t, 'shifts.open');
    expect(collect($offered->json('items'))->pluck('id'))->toContain($open->id);
    expect(asHandset($this, $t, 'shifts.claim', ['shift' => $open->id])->json('status'))->toBe('pending');

    /* ── Orders, patrol, alertness, presence ────────────────────── */

    $setId = DB::connection('mysql')->table('standing_order_sets')->insertGetId([
        'title' => 'Contract Gate orders', 'category' => 'post_specific', 'summary' => 'Post orders',
        'tenant_id' => $site, 'post_id' => $this->post->id, 'version' => 1,
        'body' => 'Log every contractor out as well as in.', 'effective_on' => now()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $versionId = DB::connection('mysql')->table('standing_order_versions')->insertGetId([
        'standing_order_set_id' => $setId, 'version' => 1, 'title' => 'Contract Gate orders', 'summary' => 'Post orders',
        'body' => 'Log every contractor out as well as in.', 'effective_on' => now()->toDateString(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $orders = asHandset($this, $t, 'orders.current', ['site' => $site]);
    expect(collect($orders->json('items'))->firstWhere('set_id', $setId)['version_id'])->toBe($versionId);
    expect(asHandset($this, $t, 'orders.acknowledge', ['version' => $versionId])->json('version_id'))->toBe($versionId);
    asHandset($this, $t, 'standing_orders.index');
    asHandset($this, $t, 'orders.current', ['site' => 'phoenixpark'], [], 403);

    $checkpoint = DB::connection('mysql')->table('patrol_checkpoints')->insertGetId([
        'tenant_id' => $site, 'post_id' => $this->post->id, 'label' => 'Pool gate', 'code' => 'CTR-POOL-7F3A', 'sequence' => 1, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    asHandset($this, $t, 'patrol.checkpoints', ['site' => $site]);
    asHandset($this, $t, 'patrol.scan', ['checkpoint' => $checkpoint], ['code' => 'WRONG-TAG'], 422);
    expect(asHandset($this, $t, 'patrol.scan', ['checkpoint' => $checkpoint], ['code' => 'CTR-POOL-7F3A'])->json('tour.scanned_today'))->toBe(1);

    $check = DB::connection('mysql')->table('alertness_checks')->insertGetId([
        'guard_id' => $this->guard->id, 'shift_id' => $shift->id, 'outcome' => 'pending',
        'issued_at' => now(), 'respond_by' => now()->addMinutes(2), 'server_time' => now(),
    ]);

    // Coordinates are compared, not kept: the ping row has no column for them.
    $ping = asHandset($this, $t, 'presence.activity', [], ['state' => 'on_post', 'latitude' => 18.0179050, 'longitude' => -76.8099050, 'battery_pct' => 81]);
    expect($ping->json('within_geofence'))->toBeTrue()
        ->and($ping->json('pending_alertness_check.check_id'))->toBe($check)
        ->and(DB::connection('mysql')->getSchemaBuilder()->hasColumn('guard_presence_pings', 'latitude'))->toBeFalse();

    expect(asHandset($this, $t, 'alertness.respond', ['check' => $check], ['score' => 92])->json('outcome'))->toBe('passed');

    $late = DB::connection('mysql')->table('alertness_checks')->insertGetId([
        'guard_id' => $this->guard->id, 'shift_id' => $shift->id, 'outcome' => 'pending',
        'issued_at' => now()->subMinutes(5), 'respond_by' => now()->subMinutes(3), 'server_time' => now()->subMinutes(5),
    ]);
    asHandset($this, $t, 'alertness.respond', ['check' => $late], [], 409);
    expect(DB::connection('mysql')->table('alertness_checks')->where('id', $late)->value('outcome'))->toBe('missed');

    /* ── The gate ───────────────────────────────────────────────── */

    asHandset($this, $t, 'passes.keys', ['site' => $site]);

    $passes = app(VisitorPasses::class);
    $pass = $passes->issue($site, $this->unit, $this->resident->id, 'Andrea Fletcher', [
        'category' => 'single', 'visitor_name' => 'Marcia James', 'purpose' => 'Family visit',
        'valid_from' => Carbon::now()->subMinutes(5), 'valid_to' => Carbon::now()->addHours(2),
    ]);

    $verdict = asHandset($this, $t, 'gate.verify', [], ['pass' => $pass->token]);
    expect($verdict->json('verdict'))->toBe('valid')
        ->and($verdict->json('access_restricted'))->toBeFalse()
        ->and($verdict->json('household'))->toBe($this->household->name)
        ->and($verdict->json('unit'))->toBe($this->unit->reference);

    expect(asHandset($this, $t, 'gate.verify', [], ['pass' => $pass->code])->json('verdict'))->toBe('valid');

    $entry = asHandset($this, $t, 'gate.entry', [], ['pass_id' => $pass->pass_id, 'category' => 'Visitor']);
    expect($entry->json('reconciliation'))->toBe('consumed')->and($entry->json('pass_based'))->toBeTrue();

    expect(asHandset($this, $t, 'gate.verify', [], ['pass' => $pass->token])->json('verdict'))->toBe('already_used');

    // Online, a used pass admits nobody; offline it was already admitted, and the sync says what happened.
    asHandset($this, $t, 'gate.entry', [], ['pass_id' => $pass->pass_id, 'category' => 'Visitor'], 409);
    $reconciled = asHandset($this, $t, 'gate.entry', [], ['pass_id' => $pass->pass_id, 'category' => 'Visitor', 'verified_offline' => true]);
    expect($reconciled->json('reconciliation'))->toBe('pass_already_used')->and($reconciled->json('basis'))->toBe('QR pass · verified offline');

    // A restricted household: amber, and the boolean is all the guard learns.
    $this->household->forceFill(['access_restricted' => true])->save();
    $guest = $passes->issue($site, $this->unit, $this->resident->id, 'Andrea Fletcher', [
        'category' => 'single', 'visitor_name' => 'Delroy Guest', 'valid_from' => Carbon::now()->subMinute(), 'valid_to' => Carbon::now()->addHour(),
    ]);
    $amber = asHandset($this, $t, 'gate.verify', [], ['pass' => $guest->token]);
    expect($amber->json('verdict'))->toBe('restricted')->and($amber->json('tone'))->toBe('amber')->and($amber->json('access_restricted'))->toBeTrue();

    $found = asHandset($this, $t, 'gate.search', [], ['unit' => $this->unit->reference]);
    expect($found->json('items.0.access_restricted'))->toBeTrue()
        ->and($found->json('items.0.primary_resident'))->toBe($this->household->residents()->where('is_primary', true)->value('full_name'));
    asHandset($this, $t, 'gate.search', [], ['name' => 'Fletcher']);
    $this->household->forceFill(['access_restricted' => false])->save();

    asHandset($this, $t, 'gate.exit', [], ['category' => 'Visitor', 'subject' => 'Marcia James · Lot 47']);
    expect(asHandset($this, $t, 'gate.override', [], ['category' => 'Contractor', 'subject' => 'JPS crew', 'reason' => 'Power outage on the estate'])->json('verdict'))->toBe('override');

    $walkUp = asHandset($this, $t, 'gate.approvals.store', [], ['unit' => $this->unit->reference, 'visitor_name' => 'Courier — Tank Weld']);
    expect($walkUp->json('status'))->toBe('pending');
    asHandset($this, $t, 'gate.entry', [], ['approval_id' => $walkUp->json('approval_id'), 'category' => 'Delivery'], 409);

    DB::connection('tenant')->table('gate_approval_requests')->where('id', $walkUp->json('approval_id'))->update(['status' => 'approved', 'responded_at' => now(), 'responded_by_name' => 'Andrea Fletcher']);
    expect(asHandset($this, $t, 'gate.approvals.show', ['approval' => $walkUp->json('approval_id')])->json('status'))->toBe('approved');
    expect(asHandset($this, $t, 'gate.entry', [], ['approval_id' => $walkUp->json('approval_id'), 'category' => 'Delivery'])->json('basis'))->toBe('Pre-approved');

    expect(asHandset($this, $t, 'gate.activity')->json('counts.admitted'))->toBeGreaterThanOrEqual(3);

    asHandset($this, $t, 'passes.verify', [], ['household_id' => $this->household->id, 'pass_category' => 'guest']);
    asHandset($this, $t, 'gate_events.store', [], ['verdict' => 'admit', 'category' => 'Resident vehicle', 'subject' => 'Lot 47 · 5544 JX', 'basis' => 'Tag read']);

    /* ── Reports: incidents, duress ─────────────────────────────── */

    $incident = asHandset($this, $t, 'incidents.store', [], [
        'kind' => 'Attempted unauthorized access', 'severity' => 'med', 'location' => 'North fence',
        'detail' => 'Two men attempted to climb the north fence at 02:10 and left when challenged.',
    ]);
    expect($incident->json('shift_id'))->toBe($shift->id);

    app('auth')->forgetGuards();
    $media = $this->withToken($t)->post('/api/v1/incidents/'.$incident->json('id').'/media', [
        'file' => UploadedFile::fake()->image('north-fence.jpg', 64, 48),
    ], ['Accept' => 'application/json', 'Idempotency-Key' => 'media-1', 'X-Device-Time' => now()->toIso8601String()])->assertCreated();
    ApiContract::assertMatches($media, 'incidents.media');
    $GLOBALS['guardContractCalled']['incidents.media'] = true;
    FacilitiesFixture::boot();

    // On the ESTATE's private disk: tenancy roots `local` per estate while the request runs.
    $stored = (string) DB::connection('mysql')->table('incident_media')->where('id', $media->json('media_id'))->value('path');
    FacilitiesFixture::platform()->run(function () use ($stored, $media): void {
        expect(Storage::disk('local')->exists($stored))->toBeTrue()
            ->and(hash('sha256', (string) Storage::disk('local')->get($stored)))->toBe($media->json('sha256'));

        Storage::disk('local')->delete($stored);
    });
    FacilitiesFixture::boot();
    expect(asHandset($this, $t, 'incidents.me')->json('items.0.media_count'))->toBe(1);

    $duress = asHandset($this, $t, 'duress.store', [], ['mode' => 'silent']);
    expect($duress->json('kind'))->toBe('duress');
    expect(asHandset($this, $t, 'duress.cancel', ['alert' => $duress->json('id')])->json('status'))->toBe('false_alarm');

    $pressed = asHandset($this, $t, 'duress.store', [], ['mode' => 'audible']);
    DB::connection('mysql')->table('duress_alerts')->where('id', $pressed->json('id'))->update(['server_time' => now()->subSeconds(30)]);
    asHandset($this, $t, 'duress.cancel', ['alert' => $pressed->json('id')], [], 409);

    asHandset($this, $t, 'alerts.store', [], ['kind' => 'medical']);

    /* ── The guard's own: requests, pay, messages ───────────────── */

    asHandset($this, $t, 'requests.store', [], ['kind' => 'leave', 'subject' => 'vacation', 'starts_on' => now()->addMonth()->toDateString(), 'ends_on' => now()->addMonth()->addDays(4)->toDateString()]);
    asHandset($this, $t, 'requests.store', [], ['kind' => 'leave', 'subject' => 'sabbatical', 'starts_on' => now()->addMonth()->toDateString(), 'ends_on' => now()->addMonth()->toDateString()], 422);
    expect(asHandset($this, $t, 'requests.index')->json('items.0.days'))->toBe(5);

    (new StatutoryRatesSeeder)->run();
    $rates = StatutoryRateVersion::forPayDate('2026-08-31');

    foreach (['approved' => ['PR-CTR-08', '2026-08-01', '2026-08-31', 38_450_00], 'draft' => ['PR-CTR-09', '2026-09-01', '2026-09-30', 99_999_00]] as $status => [$ref, $from, $to, $gross]) {
        $run = new PayrollRun;
        $run->forceFill([
            'reference' => $ref, 'period_label' => $ref, 'period_start' => $from, 'period_end' => $to, 'periods_per_year' => 12,
            'statutory_rate_version_id' => $rates->id, 'status' => $status, 'gross_minor' => $gross, 'net_minor' => $gross - 5_000_00, 'currency' => 'JMD',
        ])->save();

        Payslip::create([
            'payroll_run_id' => $run->id, 'guard_id' => $this->guard->id, 'tenant_id' => $site,
            'gross_minor' => $gross, 'nis_minor' => 1_153_50, 'nht_minor' => 769_00, 'education_tax_minor' => 839_03,
            'paye_minor' => 2_238_47, 'pension_minor' => 0, 'net_minor' => $gross - 5_000_00, 'currency' => 'JMD',
        ]);
    }

    $slips = asHandset($this, $t, 'payslips.me');
    expect($slips->json('items'))->toHaveCount(1)
        ->and($slips->json('items.0.gross'))->toBe('38450.00')
        ->and($slips->json('items.0.deductions.nis'))->toBe('1153.50')
        ->and($slips->json('items.0.net'))->toBe('33450.00');

    DB::connection('mysql')->table('dispatch_messages')->insert([
        'tenant_id' => $site, 'direction' => 'broadcast', 'body' => 'Water lock-off 10 AM to 2 PM.', 'sent_at' => now()->subMinutes(20), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(asHandset($this, $t, 'messages.index')->json('unread'))->toBeGreaterThanOrEqual(1);
    expect(asHandset($this, $t, 'messages.store', [], ['body' => 'Barrier arm sticking at the main gate.'])->json('from'))->toBe('you');

    /* ── Offline: pull, then upload the queue ───────────────────── */

    $pulled = asHandset($this, $t, 'sync.pull', [], ['since' => now()->subHour()->toIso8601String()]);
    expect(collect($pulled->json('passes.revoked'))->pluck('pass_id'))->toContain($pass->pass_id)
        ->and($pulled->json('passes.keys'))->not->toBeEmpty();

    $operations = [
        ['id' => 'op-1', 'endpoint' => 'patrol.scan', 'params' => ['checkpoint' => $checkpoint], 'body' => ['code' => 'CTR-POOL-7F3A', 'captured_offline' => true], 'idempotency_key' => 'queued-scan-1', 'device_time' => now()->subMinutes(40)->toIso8601String()],
        ['id' => 'op-2', 'endpoint' => 'messages.store', 'body' => ['body' => 'Back on post after the outage.'], 'idempotency_key' => 'queued-msg-1', 'device_time' => now()->subMinutes(35)->toIso8601String()],
        ['id' => 'op-3', 'endpoint' => 'incidents.media', 'params' => ['incident' => $incident->json('id')], 'idempotency_key' => 'queued-media-1', 'device_time' => now()->subMinutes(30)->toIso8601String()],
        ['id' => 'op-4', 'endpoint' => 'shifts.me', 'idempotency_key' => 'queued-read-1', 'device_time' => now()->subMinutes(30)->toIso8601String()],
        ['id' => 'op-5', 'endpoint' => 'patrol.scan', 'params' => ['checkpoint' => 'pool'], 'body' => ['code' => 'x'], 'idempotency_key' => 'queued-bad-1', 'device_time' => now()->subMinutes(30)->toIso8601String()],
    ];

    $batch = asHandset($this, $t, 'sync.batch', [], ['operations' => $operations]);
    expect(collect($batch->json('results'))->pluck('outcome', 'id')->all())->toBe(['op-1' => 'ok', 'op-2' => 'ok', 'op-3' => 'refused', 'op-4' => 'refused', 'op-5' => 'refused'])
        ->and($batch->json('results.0.status'))->toBe(201)
        ->and($batch->json('results.2.body.error.code'))->toBe('endpoint_not_batchable')
        ->and($batch->json('results.4.body.error.code'))->toBe('params_invalid');

    // The device time of the queued scan is when it happened, not when it synced.
    $scan = DB::connection('mysql')->table('checkpoint_scans')->where('idempotency_key', 'queued-scan-1')->first();
    expect(Carbon::parse((string) $scan->device_time)->diffInMinutes(now()))->toBeGreaterThanOrEqual(39)
        ->and((bool) $scan->clock_skewed)->toBeTrue();

    // A batch retried after a lost answer does nothing twice.
    $again = asHandset($this, $t, 'sync.batch', [], ['operations' => array_slice($operations, 0, 2)]);
    expect($again->json('results.0.replayed'))->toBeTrue()->and($again->json('results.1.replayed'))->toBeTrue()
        ->and(DB::connection('mysql')->table('checkpoint_scans')->where('idempotency_key', 'queued-scan-1')->count())->toBe(1);

    /* ── Clocking off ───────────────────────────────────────────── */

    asHandset($this, $t, 'presence.summary');
    expect(asHandset($this, $t, 'shifts.clock_out', ['shift' => $shift->id], ['handover_note' => 'Barrier arm sticking; logged with dispatch.'])->json('status'))->toBe('completed');

    /* ── And nothing on the guard's side of the catalogue went uncalled ── */

    $guardRoutes = collect(Catalogue::endpoints())->filter(fn (array $e) => in_array(AppMatrix::GUARD, $e['apps'], true))->keys()->sort()->values()->all();

    expect(collect(array_keys($GLOBALS['guardContractCalled']))->sort()->values()->all())->toBe($guardRoutes);
});

it('refuses a Resident App token on every guard-only route, at the ability', function () {
    $account = ResidentAccount::query()->create([
        'tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'sms', 'destination' => '+18765550147',
        'full_name' => 'Andrea Fletcher', 'resident_id' => $this->resident->id, 'unit_id' => $this->unit->id,
        'status' => ResidentAccount::ACTIVE, 'verified_at' => now(),
    ]);
    $token = $account->createToken('resident-app', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    $guardOnly = collect(Catalogue::endpoints())->filter(fn (array $e) => $e['apps'] === [AppMatrix::GUARD]);

    expect($guardOnly->count())->toBeGreaterThan(30);

    foreach ($guardOnly as $name => $entry) {
        $uri = '/api/v1/'.preg_replace('/\{(\w+)\}/', '1', $entry['uri']);

        app('auth')->forgetGuards();
        $response = $this->withToken($token)->json($entry['method'], $uri, [], ['Idempotency-Key' => 'r-'.$name, 'X-Device-Time' => now()->toIso8601String()]);

        expect($response->status())->toBe(403, "[{$name}] answered a resident token with {$response->status()}")
            ->and($response->json('error.code'))->toBeIn(['missing_ability', 'wrong_app'], "[{$name}]");

        FacilitiesFixture::boot();
    }
});

it('names no money on any guard route but the guard\'s own payslip, in the catalogue itself', function () {
    foreach (Catalogue::endpoints() as $name => $entry) {
        if (! in_array(AppMatrix::GUARD, $entry['apps'], true) || $name === 'payslips.me') {
            continue;
        }

        foreach (array_keys($entry['response']) as $path) {
            expect(ApiContract::namesMoney($path))->toBeFalse("Invariant 2: [{$name}] documents `{$path}`.");
        }
    }

    // The scan itself: it catches money by word, and does not cry wolf on `allowed`.
    foreach (['balance', 'items.*.amount_due', 'arrears_bucket', 'total_minor', 'household.outstanding'] as $money) {
        expect(ApiContract::namesMoney($money))->toBeTrue($money);
    }

    foreach (['allowed', 'admit_allowed', 'access_restricted', 'checks.*.passed', 'break_minutes'] as $safe) {
        expect(ApiContract::namesMoney($safe))->toBeFalse($safe);
    }
});
