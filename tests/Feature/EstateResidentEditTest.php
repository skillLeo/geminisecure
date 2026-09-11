<?php

declare(strict_types=1);

use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Models\Role;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Database\Seeders\Estate\ResidentsSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Correcting a resident's record — board 38 (12 §2, Wave 2)
|--------------------------------------------------------------------------
|
| IT EDITS WHO SOMEBODY IS, NOT WHETHER THEY ARE AUTHORISED. Verification is
| decided where a claim is reviewed, by somebody whose name and date go on the
| decision. Biometric consent is the person's and never the office's (D-022).
| This suite holds both lines.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    // Households and their people live in these two, which the facilities
    // fixture does not run — this suite is about a household, so it runs them.
    (new EstateFinanceSeeder)->run();
    (new ResidentsSeeder)->run();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('corrects a record without touching verification or consent, and moves the primary rather than dropping it', function () {
    $secretary = FacilitiesFixture::viewer(Role::SECRETARY);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    $unit = Unit::query()->whereHas('household')->orderBy('id')->firstOrFail();
    $slug = $unit->slug();

    $primary = Resident::query()
        ->whereHas('household', fn ($q) => $q->where('unit_id', $unit->id))
        ->orderByDesc('is_primary')
        ->firstOrFail();

    $before = [$primary->status, $primary->verified_at?->toDateTimeString(), (bool) $primary->biometric_consent];

    $this->actingAs($secretary)
        ->get(FacilitiesFixture::url('/residents/'.$slug))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canEdit', true)
            ->has('people'));

    // A reader of the register corrects nobody.
    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/residents/'.$slug.'/people/'.$primary->id), [
            'full_name' => 'Nobody', 'relationship' => 'owner', 'is_primary' => true,
        ])
        ->assertForbidden();

    $this->actingAs($secretary)
        ->post(FacilitiesFixture::url('/residents/'.$slug.'/people/'.$primary->id), [
            'full_name' => 'Andrea M. Fletcher',
            'email' => 'ANDREA@EXAMPLE.COM',
            'phone' => '(876) 555 0144',
            'relationship' => 'owner',
            'moved_in_on' => '2024-03-01',
            'is_primary' => true,
        ])
        ->assertRedirect(FacilitiesFixture::url('/residents/'.$slug));

    FacilitiesFixture::boot();

    $primary = Resident::query()->findOrFail($primary->id);

    expect($primary->full_name)->toBe('Andrea M. Fletcher')
        ->and($primary->email)->toBe('andrea@example.com')
        ->and($primary->phone)->toBe('(876) 555 0144')

        // UNTOUCHED: verification and consent are decided elsewhere.
        ->and([$primary->status, $primary->verified_at?->toDateTimeString(), (bool) $primary->biometric_consent])->toBe($before);

    // Neither an email nor a phone is a household nobody can reach.
    $this->actingAs($secretary)
        ->post(FacilitiesFixture::url('/residents/'.$slug.'/people/'.$primary->id), [
            'full_name' => 'Andrea M. Fletcher', 'email' => '', 'phone' => '', 'relationship' => 'owner', 'is_primary' => true,
        ])
        ->assertSessionHasErrors('full_name');

    // A household cannot have NO primary: unticking the only one is refused.
    $this->actingAs($secretary)
        ->post(FacilitiesFixture::url('/residents/'.$slug.'/people/'.$primary->id), [
            'full_name' => 'Andrea M. Fletcher', 'phone' => '(876) 555 0144', 'relationship' => 'owner',
        ])
        ->assertSessionHasErrors('full_name');

    FacilitiesFixture::boot();

    expect(Resident::query()->findOrFail($primary->id)->is_primary)->toBeTrue();

    // Making a second member primary MOVES it off the first.
    $second = Resident::query()->create([
        'household_id' => $primary->household_id,
        'full_name' => 'Dane Fletcher',
        'phone' => '(876) 555 0145',
        'relationship' => 'spouse',
        'is_primary' => false,
        'status' => Resident::VERIFIED,
        'biometric_consent' => false,
    ]);

    $this->actingAs($secretary)
        ->post(FacilitiesFixture::url('/residents/'.$slug.'/people/'.$second->id), [
            'full_name' => 'Dane Fletcher', 'phone' => '(876) 555 0145', 'relationship' => 'spouse', 'is_primary' => true,
        ])
        ->assertRedirect();

    FacilitiesFixture::boot();

    expect(Resident::query()->findOrFail($second->id)->is_primary)->toBeTrue()
        ->and(Resident::query()->findOrFail($primary->id)->is_primary)->toBeFalse()
        ->and(Resident::query()->where('household_id', $primary->household_id)->where('is_primary', true)->count())->toBe(1);

    // Somebody else's household member is not reachable through this unit.
    $stranger = Resident::query()->where('household_id', '!=', $primary->household_id)->first();

    if ($stranger !== null) {
        $this->actingAs($secretary)
            ->post(FacilitiesFixture::url('/residents/'.$slug.'/people/'.$stranger->id), [
                'full_name' => 'Renamed', 'phone' => '1', 'relationship' => 'owner', 'is_primary' => true,
            ])
            ->assertSessionHasErrors('full_name');

        FacilitiesFixture::boot();

        expect(Resident::query()->findOrFail($stranger->id)->full_name)->not->toBe('Renamed');
    }

    $second->delete();
});
