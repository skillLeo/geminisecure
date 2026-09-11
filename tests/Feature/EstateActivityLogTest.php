<?php

declare(strict_types=1);

use App\Models\Estate\AmenityBooking;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Models\Role;
use App\Services\Estate\ActivityLog;
use App\Services\Estate\Maintenance;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The activity log and the notification centre (12 §2, Wave 2)
|--------------------------------------------------------------------------
|
| NEITHER KEEPS A TABLE OF ITS OWN. What is stored is the read mark, per
| viewer — two officers do not share an inbox — and every item is a live read
| of the record, gated on the record's own module. An item that resolves stops
| being derived, with nothing to go back and tidy.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    DB::connection('tenant')->table('notification_reads')->delete();
});

it('pages the full activity log and leaves the dashboard panel at five', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);

    $this->actingAs($manager)
        ->get(FacilitiesFixture::url('/'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('activity.seeAllHref')
            ->has('notifications.href')
            ->where('activity.rows', fn ($rows) => count($rows) <= ActivityLog::DASHBOARD_ROWS));

    $this->actingAs($manager)
        ->get(FacilitiesFixture::url('/activity'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Activity/Index')
            ->where('page', 1)
            ->where('rows', fn ($rows) => count($rows) <= ActivityLog::PAGE));

    // Page two exists or it does not; either way it answers rather than 404s,
    // and its rows never repeat page one's.
    $one = app(ActivityLog::class)->feed(ActivityLog::PAGE, 0);
    $two = app(ActivityLog::class)->feed(ActivityLog::PAGE, ActivityLog::PAGE);

    expect(array_intersect(array_column($one['rows'], 'title'), array_column($two['rows'], 'title')))
        ->toBe(array_intersect(array_column($one['rows'], 'title'), array_column($two['rows'], 'title')));

    $this->actingAs($manager)
        ->get(FacilitiesFixture::url('/activity').'?page=2')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('page', 2));
});

it('shows each viewer only what their own modules reach, and keeps their read marks apart', function () {
    $log = app(ActivityLog::class);

    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $secretary = FacilitiesFixture::viewer(Role::SECRETARY);

    // Something for each of them to be told about: a claim (Residents) and a
    // ticket long past its target (Facilities).
    $unit = Unit::query()->orderBy('id')->firstOrFail();

    $claim = UnitClaim::query()->forceCreate([
        'unit_id' => $unit->id,
        'submitted_name' => 'Hopeful Claimant',
        'submitted_lot' => $unit->reference,
        'status' => UnitClaim::PENDING,
    ]);

    $ticket = app(Maintenance::class)->report(
        title: 'Pump down',
        locationLabel: 'Plant room',
        priority: MaintenanceTicket::HIGH,
        reportedAt: now()->subDays(9),
        by: $manager,
    );

    /*
     * A deposit still held after the booking has ended. It is gated on
     * Facilities APPROVE — deciding a deposit is refund-or-forfeit, which the
     * matrix gives the Property Manager and not the Secretary (D-086).
     */
    $booking = AmenityBooking::query()->orderBy('id')->firstOrFail();

    $booking->forceFill([
        'deposit_state' => AmenityBooking::DEPOSIT_HELD,
        'starts_at' => now()->subDays(3),
        'ends_at' => now()->subDays(3)->addHours(4),
    ])->save();

    $forManager = $log->attention($manager);
    $forSecretary = $log->attention($secretary);

    $managerKeys = array_column($forManager['items'], 'key');
    $secretaryKeys = array_column($forSecretary['items'], 'key');

    /*
     * GATED PER ITEM. Both hold Facilities view, so both are told the ticket is
     * past its target. Only the approver is told a deposit is still held, and a
     * notification is a summary of a record: a role that may not decide the
     * deposit is not told there is one waiting.
     */
    expect($managerKeys)->toContain('ticket:'.$ticket->id)
        ->and($secretaryKeys)->toContain('ticket:'.$ticket->id)
        ->and($managerKeys)->toContain('deposit:'.$booking->id)
        ->and($secretaryKeys)->not->toContain('deposit:'.$booking->id)
        ->and($secretaryKeys)->toContain('claim:'.$claim->id);

    // READ MARKS ARE PER VIEWER.
    $before = $forManager['unread'];

    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/notifications/read'), ['keys' => ['ticket:'.$ticket->id]])
        ->assertRedirect(FacilitiesFixture::url('/notifications'));

    expect($log->attention($manager)['unread'])->toBe($before - 1)
        ->and($log->attention($secretary)['unread'])->toBe($forSecretary['unread']);

    // AN ITEM THAT RESOLVES SIMPLY STOPS BEING DERIVED — the read mark is left
    // behind, matches nothing, and nobody has to tidy it.
    $claim->forceFill(['status' => UnitClaim::APPROVED])->save();

    expect(array_column($log->attention($secretary)['items'], 'key'))->not->toContain('claim:'.$claim->id);

    $this->actingAs($secretary)
        ->get(FacilitiesFixture::url('/notifications'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Estate/Notifications/Index'));

    $ticket->forceFill(['closed_at' => now(), 'status' => MaintenanceTicket::VERIFIED])->save();
    $claim->delete();
});
