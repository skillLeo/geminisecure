<?php

declare(strict_types=1);

use App\Models\Estate\DunningTemplate;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitCollectionFlag;
use App\Models\Role;
use App\Services\Estate\Collections;
use App\Services\Estate\Dues;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Hardship and dispute — board 6 (12 §1)
|--------------------------------------------------------------------------
|
| THE RULING: "a reason and a committee minute reference, it suppresses
| automated dunning, and it does not change collection behaviour." The last
| two are not in tension, and this suite holds both at once: the automated
| queue skips the household, and every figure about the debt is identical to
| the cent before and after.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    (new EstateFinanceSeeder)->run();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();

    // The tenant database is not rolled back, so this suite clears what it made.
    UnitCollectionFlag::query()->delete();
});

it('flags a household without moving one cent of what it owes, and lifts the flag again', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $assistant = FacilitiesFixture::viewer(Role::ESTATE_ADMIN_ASSISTANT);

    $unit = Unit::query()->where('reference', 'Lot 63')->firstOrFail();
    $dues = app(Dues::class);

    $before = [
        'balance' => $dues->balanceOf($unit)->getMinorAmount()->toInt(),
        'board' => $dues->unitBoard($unit),
        'arrears' => $dues->arrearsBoard('', false),
    ];

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/units/'.$unit->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canFlag', true)
            ->where('flag', null)
            ->has('flagKinds'));

    // Entry on Dues & ledger prepares the case; the committee agrees it.
    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/flag'), [
            'kind' => 'dispute', 'reason' => 'x', 'minute_reference' => 'Min. 1',
        ])
        ->assertForbidden();

    // A reason and a minute are both required, and each refusal says why.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/flag'), [
            'kind' => 'dispute', 'reason' => 'The August charge is disputed.', 'minute_reference' => '',
        ])
        ->assertSessionHasErrors('minute_reference');

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/flag'), [
            'kind' => 'dispute',
            'reason' => 'The household says the August maintenance charge was raised twice.',
            'minute_reference' => 'Min. 2026-09-03 §4',
        ])
        ->assertRedirect(FacilitiesFixture::url('/finance/units/'.$unit->id));

    FacilitiesFixture::boot();

    $flag = UnitCollectionFlag::query()->where('unit_id', $unit->id)->sole();

    expect($flag->kind)->toBe(UnitCollectionFlag::DISPUTE)
        ->and($flag->minute_reference)->toBe('Min. 2026-09-03 §4')
        ->and($flag->raised_by_name)->toBe($treasurer->name)
        ->and($flag->isInForce())->toBeTrue();

    /*
     * THE DEBT HAS NOT MOVED. Not the balance, not the ageing strip, not one
     * figure on the arrears board. A flag that quietly shrank a balance would
     * be a write-off nobody voted for.
     */
    expect($dues->balanceOf($unit)->getMinorAmount()->toInt())->toBe($before['balance'])
        ->and($dues->unitBoard($unit)['strip'])->toBe($before['board']['strip'])
        ->and($dues->unitBoard($unit)['bucket'])->toBe($before['board']['bucket'])
        ->and($dues->arrearsBoard('', false))->toBe($before['arrears']);

    // A second flag on top of the first would read as two episodes at once.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/flag'), [
            'kind' => 'hardship', 'reason' => 'Also this.', 'minute_reference' => 'Min. 5',
        ])
        ->assertSessionHasErrors('reason');

    // Lifted, the row stays — an episode keeps its end.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/units/'.$unit->id.'/flag/'.$flag->id.'/lift'), [
            'lifted_reason' => 'The duplicate charge was found and reversed.',
        ])
        ->assertRedirect();

    FacilitiesFixture::boot();

    $flag = UnitCollectionFlag::query()->findOrFail($flag->id);

    expect($flag->isInForce())->toBeFalse()
        ->and($flag->lifted_by_name)->toBe($treasurer->name)
        ->and($flag->lifted_reason)->toBe('The duplicate charge was found and reversed.')
        ->and($dues->balanceOf($unit)->getMinorAmount()->toInt())->toBe($before['balance']);
});

it('takes a flagged household out of the automated queue and leaves the deliberate send alone', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $collections = app(Collections::class);

    $queue = $collections->automatedQueue();

    // Somebody in arrears to flag. Every estate on the seed has one.
    $unitId = $queue['due'][0] ?? null;

    expect($unitId)->not->toBeNull()
        ->and($queue['suppressed'])->toBe([]);

    $unit = Unit::query()->findOrFail($unitId);

    $collections->flag($unit, UnitCollectionFlag::HARDSHIP, 'Lost employment; paying what they can.', 'Min. 2026-08-06 §2', $treasurer);

    $after = $collections->automatedQueue();

    expect($after['due'])->not->toContain($unitId)
        ->and(count($after['due']))->toBe(count($queue['due']) - 1)
        ->and(collect($after['suppressed'])->pluck('unit_id')->all())->toBe([$unitId])
        ->and($after['suppressed'][0]['headline'])->toBe('Hardship, under Min. 2026-08-06 §2');

    /*
     * A DELIBERATE SEND IS STILL ALLOWED, and that is the ruling's own shape:
     * what a flag stops is the machine sending a final demand at 09:00 on
     * Tuesday. A person pressing "Send reminder now" is making a decision with
     * their name against it, and the estate is allowed to make it.
     */
    $notice = $collections->send($unit, DunningTemplate::query()->where('is_active', true)->orderBy('stage')->firstOrFail(), $treasurer);

    expect($notice->unit_id)->toBe($unit->id);
});
