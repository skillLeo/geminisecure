<?php

declare(strict_types=1);

use App\Api\AppMatrix;
use App\Api\Catalogue;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\Ballot;
use App\Models\Estate\BallotOption;
use App\Models\Estate\BallotPosition;
use App\Models\Estate\Charge;
use App\Models\Estate\Household;
use App\Models\Estate\Meeting;
use App\Models\Estate\Notice;
use App\Models\Estate\Payment;
use App\Models\Estate\Resident;
use App\Models\Estate\UnitClaim;
use App\Models\Estate\VisitorPass;
use App\Models\Guard;
use App\Models\Post;
use App\Models\ResidentAccount;
use App\Notifications\ResidentSignInCode;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Estate\Collections;
use App\Services\Estate\Residents;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\ApiContract;
use Tests\Support\FacilitiesFixture;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The Resident App's API, walked end to end — 13 D3
|--------------------------------------------------------------------------
|
| A resident signs in with a code, claims a unit, waits, is approved by the
| estate, and then uses every endpoint the Resident App's token reaches: the
| household, passes and the e-pass, a guard's walk-up request, dues, tickets,
| notices, meetings, a vote and its result, bookings, and panic.
|
| Every response is held to its catalogue entry, and the test fails at the end
| if a resident route in the catalogue was never called. A guard's token is
| refused on every resident-only route, at the ability.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    Notification::fake();

    // A lot with no payment plan over it — see GuardApiContractTest.
    $this->unit = collect(['Lot 63', 'Lot 3', 'Lot 9', 'Lot 88', 'Lot 47'])
        ->map(fn (string $reference) => FacilitiesFixture::unit($reference))
        ->first(fn ($unit) => ! app(Collections::class)->isProtected($unit));

    $this->household = Household::query()->firstOrCreate(['unit_id' => $this->unit->id], ['name' => 'Morgan household', 'access_restricted' => false]);
    $this->household->forceFill(['access_restricted' => false])->save();

    $GLOBALS['residentContractCalled'] = [];
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
    FacilitiesFixture::boot();

    VisitorPass::query()->where('unit_id', $this->unit->id)->delete();
    DB::connection('tenant')->table('gate_approval_requests')->where('unit_id', $this->unit->id)->delete();
    $this->household->forceFill(['access_restricted' => false])->save();
});

/**
 * @param  array<string, scalar>  $params
 * @param  array<string, mixed>  $body
 */
function asResident(TestCase $test, ?string $token, string $name, array $params = [], array $body = [], ?int $status = null): TestResponse
{
    $entry = Catalogue::endpoints()[$name];
    $uri = '/api/v1/'.preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $params[$m[1]], $entry['uri']);
    $headers = $entry['write'] ? ['Idempotency-Key' => 'res-'.bin2hex(random_bytes(8)), 'X-Device-Time' => now()->toIso8601String()] : [];

    app('auth')->forgetGuards();

    $client = $token === null ? $test : $test->withToken($token);

    $response = $entry['method'] === 'GET'
        ? $client->getJson($uri.($body === [] ? '' : '?'.http_build_query($body)), $headers)
        : $client->json($entry['method'], $uri, $body, $headers);

    $response->assertStatus($status ?? $entry['status']);

    ApiContract::assertMatches($response, $name);

    $GLOBALS['residentContractCalled'][$name] = true;

    FacilitiesFixture::boot();

    return $response;
}

it('walks every Resident App endpoint from sign-in to a vote, each answer inside its catalogue allowlist', function () {
    $site = FacilitiesFixture::ESTATE;
    $email = 'resident.'.bin2hex(random_bytes(3)).'@example.com';

    /* ── Sign in by code ─────────────────────────────────────────── */

    asResident($this, null, 'auth.otp.request', [], ['estate' => 'nowhere', 'channel' => 'email', 'destination' => $email], 404);
    $sent = asResident($this, null, 'auth.otp.request', [], ['estate' => $site, 'channel' => 'email', 'destination' => $email]);
    expect($sent->json('destination_hint'))->toStartWith('r•••@');

    $code = null;
    Notification::assertSentOnDemand(ResidentSignInCode::class, function (ResidentSignInCode $n) use (&$code): bool {
        $code = $n->code;

        return true;
    });

    $verify = ['estate' => $site, 'channel' => 'email', 'destination' => strtoupper($email), 'device_uid' => 'res-install-1', 'platform' => 'ios'];

    asResident($this, null, 'auth.otp.verify', [], [...$verify, 'code' => $code === '000000' ? '111111' : '000000'], 422);
    $signedIn = asResident($this, null, 'auth.otp.verify', [], [...$verify, 'code' => $code]);

    expect($signedIn->json('account.status'))->toBe('pending')
        ->and($signedIn->json('next'))->toBe('claim_unit')
        ->and($signedIn->json('abilities'))->toBe(AppMatrix::abilitiesFor(AppMatrix::RESIDENT));

    $t = $signedIn->json('token');

    // A used code is gone.
    asResident($this, null, 'auth.otp.verify', [], [...$verify, 'code' => $code], 422);

    // No SMS provider in production reads as unavailable, not as a silent success.
    config(['services.sms.driver' => '']);
    asResident($this, null, 'auth.otp.request', [], ['estate' => $site, 'channel' => 'sms', 'destination' => '+1 876 555 0147'], 503);
    config(['services.sms.driver' => 'log']);

    /* ── Pending: the claim and nothing else ─────────────────────── */

    expect(asResident($this, $t, 'me.show')->json('claim'))->toBeNull();
    asResident($this, $t, 'me.household', [], [], 403);

    $claim = asResident($this, $t, 'auth.claim_unit', [], ['full_name' => 'Patricia Morgan', 'lot' => $this->unit->reference, 'phase' => $this->unit->block, 'relationship' => 'owner']);
    expect($claim->json('claim.status'))->toBe('pending');
    asResident($this, $t, 'auth.claim_unit', [], ['full_name' => 'Patricia Morgan', 'lot' => $this->unit->reference], 200);

    // The estate approves it, and the account is active on its next request.
    app(Residents::class)->approveClaim(UnitClaim::query()->findOrFail($claim->json('claim.id')));

    $me = asResident($this, $t, 'me.show');
    expect($me->json('account.status'))->toBe('active')
        ->and($me->json('household.id'))->toBe($this->household->id);

    $resident = Resident::query()->findOrFail($me->json('resident.id'));

    /* ── The household ───────────────────────────────────────────── */

    asResident($this, $t, 'me.update', [], ['full_name' => 'Pat Morgan', 'phone' => '+18765550100']);
    expect($resident->refresh()->phone)->toBe('+18765550100');

    asResident($this, $t, 'members.store', [], ['full_name' => 'Devon Morgan', 'relationship' => 'child']);
    expect(collect(asResident($this, $t, 'members.index')->json('items'))->firstWhere('full_name', 'Devon Morgan')['status'])->toBe('pending');

    $plate = 'PM '.random_int(1000, 9999);
    asResident($this, $t, 'vehicles.store', [], ['plate' => strtolower($plate), 'make' => 'Toyota', 'colour' => 'Silver']);
    asResident($this, $t, 'vehicles.store', [], ['plate' => $plate], 200);
    expect(collect(asResident($this, $t, 'vehicles.index')->json('items'))->pluck('plate'))->toContain($plate);

    asResident($this, $t, 'contacts.store', [], ['name' => 'Karen Morgan', 'relationship' => 'sister', 'phone' => '+1 876 555 0199']);
    asResident($this, $t, 'contacts.index');

    /* ── Passes, the e-pass, a guard at the gate ─────────────────── */

    $pass = asResident($this, $t, 'visitor_passes.store', [], [
        'category' => 'single', 'visitor_name' => 'Marcia James', 'purpose' => 'Family visit',
        'valid_from' => now()->toIso8601String(), 'valid_to' => now()->addHours(3)->toIso8601String(),
    ]);
    expect($pass->json('token'))->toContain('.')->and($pass->json('single_use'))->toBeTrue();

    asResident($this, $t, 'visitor_passes.update', ['pass' => $pass->json('id')], ['vehicle_plate' => '1234 AB']);
    asResident($this, $t, 'visitor_passes.update', ['pass' => $pass->json('id')], ['valid_to' => now()->addDays(3)->toIso8601String()], 422);
    expect(asResident($this, $t, 'visitor_passes.share', ['pass' => $pass->json('id')], ['channel' => 'whatsapp'])->json('share_count'))->toBe(1);
    expect(collect(asResident($this, $t, 'visitor_passes.index')->json('items'))->pluck('id'))->toContain($pass->json('id'));
    expect(asResident($this, $t, 'visitor_passes.cancel', ['pass' => $pass->json('id')])->json('status'))->toBe('cancelled');

    $epass = asResident($this, $t, 'epass.show');
    expect($epass->json('category'))->toBe('resident')->and($epass->json('single_use'))->toBeFalse();
    expect(asResident($this, $t, 'epass.show')->json('id'))->toBe($epass->json('id'));

    // A restricted household is told in the gate's words, and nothing more.
    $this->household->forceFill(['access_restricted' => true])->save();
    asResident($this, $t, 'visitor_passes.store', [], [
        'category' => 'single', 'visitor_name' => 'Guest', 'valid_from' => now()->toIso8601String(), 'valid_to' => now()->addHour()->toIso8601String(),
    ], 409)->assertJsonPath('error.code', 'access_restricted');
    $this->household->forceFill(['access_restricted' => false])->save();

    $walkUp = DB::connection('tenant')->table('gate_approval_requests')->insertGetId([
        'unit_id' => $this->unit->id, 'household_id' => $this->household->id, 'guard_id' => 1, 'guard_name' => 'Kemar Reid', 'post_name' => 'Main Gate',
        'visitor_name' => 'Courier — Tank Weld', 'status' => 'pending', 'requested_at' => now(), 'respond_by' => now()->addMinutes(3),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(collect(asResident($this, $t, 'me.household')->json('pending_approvals'))->pluck('id'))->toContain($walkUp);
    expect(asResident($this, $t, 'approvals.respond', ['approval' => $walkUp], ['decision' => 'approve'])->json('status'))->toBe('approved');
    asResident($this, $t, 'approvals.respond', ['approval' => $walkUp], ['decision' => 'deny'], 409);

    $panic = asResident($this, $t, 'duress.store', [], ['mode' => 'audible']);
    expect($panic->json('kind'))->toBe('panic');
    expect(asResident($this, $t, 'duress.cancel', ['alert' => $panic->json('id')])->json('status'))->toBe('false_alarm');

    /* ── Dues — read, and how to pay by hand ─────────────────────── */

    $charge = Charge::query()->create([
        'unit_id' => $this->unit->id, 'type' => 'dues', 'period' => now()->format('Y-m'), 'reference' => 'API-'.strtoupper(bin2hex(random_bytes(4))),
        'description' => 'Maintenance fee', 'amount_minor' => 12_500_00, 'currency' => 'JMD', 'due_on' => now()->addDays(10)->toDateString(), 'status' => 'outstanding',
    ]);

    $payment = new Payment;
    $payment->forceFill([
        'unit_id' => $this->unit->id, 'receipt_no' => 'FXT-API-'.strtoupper(bin2hex(random_bytes(4))), 'amount_minor' => 12_500_00, 'currency' => 'JMD',
        'method' => Payment::BANK, 'reference' => 'NCB transfer', 'received_at' => now()->subDay(), 'entered_at' => now(), 'status' => 'recorded',
    ])->save();

    $invoices = asResident($this, $t, 'invoices.index');
    expect(collect($invoices->json('items'))->firstWhere('id', $charge->id)['amount'])->toBe('12500.00');

    $household = $this->household->id;
    expect(asResident($this, $t, 'households.balance', ['household' => $household])->json('currency'))->toBe('JMD');
    asResident($this, $t, 'households.balance', ['household' => $household + 100000], [], 404);
    asResident($this, $t, 'households.statement', ['household' => $household], ['limit' => 10]);

    $intent = asResident($this, $t, 'invoices.pay_intent', ['invoice' => $charge->id]);
    expect($intent->json('online_payment_available'))->toBeFalse()
        ->and($intent->json('quote_reference'))->toContain($charge->reference);

    expect(collect(asResident($this, $t, 'payments.index')->json('items'))->pluck('id'))->toContain($payment->id);
    expect(asResident($this, $t, 'payments.receipt', ['payment' => $payment->id])->json('amount'))->toBe('12500.00');
    asResident($this, $t, 'autopay.store')->assertJsonPath('error.code', 'autopay_unavailable');
    asResident($this, $t, 'autopay.destroy', ['autopay' => 1]);

    /* ── Tickets, notices, meetings ──────────────────────────────── */

    $ticket = asResident($this, $t, 'tickets.store', [], ['title' => 'Street light out by the gate', 'category' => 'electrical', 'priority' => 'low']);
    expect($ticket->json('status'))->toBe('submitted')->and($ticket->json('timeline.0.event'))->toBe('reported');

    app('auth')->forgetGuards();
    $media = $this->withToken($t)->post('/api/v1/tickets/'.$ticket->json('id').'/media', [
        'file' => UploadedFile::fake()->image('street-light.jpg', 48, 48),
    ], ['Accept' => 'application/json', 'Idempotency-Key' => 'ticket-media-'.bin2hex(random_bytes(4)), 'X-Device-Time' => now()->toIso8601String()])->assertCreated();
    ApiContract::assertMatches($media, 'tickets.media');
    $GLOBALS['residentContractCalled']['tickets.media'] = true;
    FacilitiesFixture::boot();

    FacilitiesFixture::platform()->run(function () use ($ticket, $media): void {
        $path = (string) DB::connection('tenant')->table('ticket_media')->where('id', $media->json('media_id'))->value('path');
        expect(Storage::disk('local')->exists($path))->toBeTrue();
        Storage::disk('local')->delete($path);
        expect($ticket->json('id'))->toBeInt();
    });
    FacilitiesFixture::boot();

    expect(collect(asResident($this, $t, 'tickets.index')->json('items'))->firstWhere('id', $ticket->json('id'))['media_count'])->toBe(1);

    $notice = new Notice;
    $notice->forceFill([
        'kind' => Notice::URGENT, 'title' => 'Water lock-off Thursday', 'body' => 'NWC works on the main line, 10 AM to 2 PM.',
        'audience_scope' => Notice::ESTATE_WIDE, 'author_name' => 'Estate Secretary', 'published_at' => now()->subMinute(),
    ])->save();

    expect(collect(asResident($this, $t, 'notices.index')->json('items'))->firstWhere('id', $notice->id)['read_at'])->toBeNull();
    expect(asResident($this, $t, 'notices.read', ['notice' => $notice->id])->json('notice_id'))->toBe($notice->id);

    $meeting = new Meeting;
    $meeting->forceFill([
        'type' => Meeting::AGM, 'title' => 'Annual General Meeting '.now()->year, 'starts_at' => now()->addDays(30), 'venue' => 'Club House',
        'audience_scope' => Meeting::WHOLE_ESTATE, 'status' => Meeting::SCHEDULED, 'published_at' => now(),
    ])->save();

    expect(collect(asResident($this, $t, 'meetings.index')->json('items'))->firstWhere('id', $meeting->id)['my_rsvp'])->toBeNull();
    expect(asResident($this, $t, 'meetings.rsvp', ['meeting' => $meeting->id], ['response' => 'attending'])->json('households_attending'))->toBeGreaterThanOrEqual(1);

    /* ── An election ─────────────────────────────────────────────── */

    [$ballot, $options] = contractBallot('Contract Election '.bin2hex(random_bytes(2)), Ballot::VOTING_OPEN);

    expect(collect(asResident($this, $t, 'elections.index')->json('items'))->firstWhere('id', $ballot->id)['has_voted'])->toBeFalse();
    expect(asResident($this, $t, 'elections.eligibility', ['ballot' => $ballot->id])->json('eligible'))->toBeTrue();
    asResident($this, $t, 'elections.ballot', ['ballot' => $ballot->id], ['option_ids' => [$options[0]->id, $options[1]->id, $options[2]->id]], 422);
    asResident($this, $t, 'elections.ballot', ['ballot' => $ballot->id], ['option_ids' => [$options[0]->id]]);
    asResident($this, $t, 'elections.ballot', ['ballot' => $ballot->id], ['option_ids' => [$options[1]->id]], 409);
    asResident($this, $t, 'elections.results', ['ballot' => $ballot->id], [], 409);

    [$certified] = contractBallot('Certified Election '.bin2hex(random_bytes(2)), Ballot::CERTIFIED, published: true);
    expect(asResident($this, $t, 'elections.results', ['ballot' => $certified->id])->json('positions.0.options.0.votes'))->toBe(0);

    /* ── Amenities ───────────────────────────────────────────────── */

    $amenities = asResident($this, $t, 'amenities.index');
    $pool = collect($amenities->json('items'))->firstWhere('name', 'Pool Deck');
    $day = now()->addDays(6)->startOfDay();

    $booking = asResident($this, $t, 'bookings.store', [], [
        'amenity_id' => $pool['id'], 'starts_at' => $day->copy()->setTime(7, 0)->toIso8601String(), 'ends_at' => $day->copy()->setTime(8, 0)->toIso8601String(), 'guests' => 4,
    ]);
    expect($booking->json('status'))->toBe(AmenityBooking::PENDING);
    asResident($this, $t, 'bookings.store', [], ['amenity_id' => $pool['id'], 'starts_at' => $day->copy()->setTime(3, 0)->toIso8601String(), 'ends_at' => $day->copy()->setTime(4, 0)->toIso8601String()], 422);
    expect(collect(asResident($this, $t, 'bookings.index')->json('items'))->pluck('id'))->toContain($booking->json('id'));
    expect(asResident($this, $t, 'bookings.cancel', ['booking' => $booking->json('id')])->json('status'))->toBe(AmenityBooking::CANCELLED);
    asResident($this, $t, 'bookings.cancel', ['booking' => $booking->json('id')], [], 409);

    /* ── And nothing on the resident's side of the catalogue went uncalled ── */

    $residentRoutes = collect(Catalogue::endpoints())
        ->filter(fn (array $e, string $name) => in_array(AppMatrix::RESIDENT, $e['apps'], true) || str_starts_with($name, 'auth.'))
        ->keys()
        ->reject(fn (string $name) => $name === 'alerts.store')
        ->sort()->values()->all();

    expect(collect(array_keys($GLOBALS['residentContractCalled']))->sort()->values()->all())->toBe($residentRoutes);
});

it('refuses a Guard App token on every resident-only route, at the ability', function () {
    $post = Post::create(['tenant_id' => FacilitiesFixture::ESTATE, 'name' => 'Refusal Gate', 'type' => 'gate', 'is_active' => true]);
    $guard = Guard::create([
        'full_name' => 'Refused Guard', 'employee_number' => 'GS-REF-1', 'psra_number' => 'PSRA-REF-1',
        'psra_expires_on' => now()->addYear()->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
        'tenant_id' => FacilitiesFixture::ESTATE, 'post_id' => $post->id,
    ]);
    $token = app(DeviceEnrolment::class)->enrol($guard, 'Refusal handset')['token'];

    $residentOnly = collect(Catalogue::endpoints())->filter(fn (array $e) => $e['apps'] === [AppMatrix::RESIDENT]);

    expect($residentOnly->count())->toBeGreaterThan(35);

    foreach ($residentOnly as $name => $entry) {
        app('auth')->forgetGuards();
        $response = $this->withToken($token)->json($entry['method'], '/api/v1/'.preg_replace('/\{(\w+)\}/', '1', $entry['uri']), [], ['Idempotency-Key' => 'g-'.$name, 'X-Device-Time' => now()->toIso8601String()]);

        expect($response->status())->toBe(403, "[{$name}] answered a guard token with {$response->status()}")
            ->and($response->json('error.code'))->toBeIn(['missing_ability', 'wrong_app'], "[{$name}]");

        FacilitiesFixture::boot();
    }

    // And a pending account reaches its claim and `me`, and nothing else.
    $pending = ResidentAccount::query()->create(['tenant_id' => FacilitiesFixture::ESTATE, 'channel' => 'email', 'destination' => 'pending.'.bin2hex(random_bytes(3)).'@example.com', 'status' => ResidentAccount::PENDING]);
    $pendingToken = $pending->createToken('resident-app:pending', AppMatrix::abilitiesFor(AppMatrix::RESIDENT))->plainTextToken;

    foreach ($residentOnly as $name => $entry) {
        app('auth')->forgetGuards();
        $response = $this->withToken($pendingToken)->json($entry['method'], '/api/v1/'.preg_replace('/\{(\w+)\}/', '1', $entry['uri']), [], ['Idempotency-Key' => 'p-'.$name, 'X-Device-Time' => now()->toIso8601String()]);

        if ($entry['pending'] ?? false) {
            expect($response->status())->not->toBe(403, "[{$name}] should reach a pending account");
        } else {
            expect($response->json('error.code'))->toBe('account_pending', "[{$name}] answered a pending account with {$response->status()}");
        }

        FacilitiesFixture::boot();
    }
});

/**
 * A ballot with one two-seat position and three candidates.
 *
 * @return array{0: Ballot, 1: list<BallotOption>}
 */
function contractBallot(string $title, string $stage, bool $published = false): array
{
    $ballot = new Ballot;
    $ballot->forceFill([
        'year' => (int) now()->year, 'title' => $title, 'kind' => Ballot::ELECTION, 'stage' => $stage,
        'opens_at' => now()->subDay(), 'closes_at' => now()->addDays(2), 'eligible_households' => 5,
        'certified_at' => $stage === Ballot::CERTIFIED ? now()->subHour() : null,
        'published_at' => $published ? now()->subMinutes(30) : null,
    ])->save();

    $position = new BallotPosition;
    $position->forceFill(['ballot_id' => $ballot->id, 'name' => 'Estate Executive', 'seat_count' => 2, 'scope' => 'estate', 'sort_order' => 1])->save();

    $options = [];

    foreach (['Andre Clarke', 'Simone Grant', 'Winston Hall'] as $i => $label) {
        $option = new BallotOption;
        $option->forceFill(['ballot_id' => $ballot->id, 'ballot_position_id' => $position->id, 'label' => $label, 'sort_order' => $i])->save();
        $options[] = $option;
    }

    return [$ballot, $options];
}
