<?php

declare(strict_types=1);

use App\Models\Estate\Phase;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Services\Estate\EstateStructure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Add phase and import CSV — board 3 (12 §2, Wave 1, items 9 and 10)
|--------------------------------------------------------------------------
|
| Both bring addresses into existence, and an address is what a household is
| filed at and dues are billed to — so both are all-or-nothing. The tests
| below are mostly about what does NOT get written: a range that overlaps
| the estate, a file with one bad row, a preview that went stale.
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
});

it('adds a phase with its lot range in one transaction, and refuses a range that overlaps the estate', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    $this->actingAs($president)
        ->get(FacilitiesFixture::url('/estate'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canCreate', false)->where('preview', null));

    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/estate/phases'), ['name' => 'Phase 9', 'block_count' => 2])
        ->assertForbidden();

    $units = Unit::query()->count();

    // Lot 47 exists — the whole range is refused and nothing is created.
    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/estate/phases'), ['name' => 'Phase 9', 'block_count' => 2, 'lot_from' => 45, 'lot_to' => 50])
        ->assertSessionHasErrors('name');

    FacilitiesFixture::boot();

    expect(Unit::query()->count())->toBe($units)
        ->and(Phase::query()->where('name', 'Phase 9')->exists())->toBeFalse();

    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/estate/phases'), [
            'name' => 'Phase 9',
            'block_count' => 2,
            'lot_from' => 901,
            'lot_to' => 910,
            'street' => 'Hillside Drive',
        ])
        ->assertRedirect(FacilitiesFixture::url('/estate'));

    FacilitiesFixture::boot();

    $phase = Phase::query()->where('name', 'Phase 9')->firstOrFail();

    expect($phase->status)->toBe(Phase::NEW)
        ->and($phase->block_count)->toBe(2)
        ->and($phase->sequence)->toBe((int) Phase::query()->where('id', '!=', $phase->id)->max('sequence') + 1)
        ->and(Unit::query()->count())->toBe($units + 10)
        ->and(Unit::query()->where('block', 'Phase 9')->where('status', 'vacant')->count())->toBe(10)
        ->and(Unit::query()->where('reference', 'Lot 905')->value('street'))->toBe('Hillside Drive');

    // The board reads the new phase from the same GROUP BY as the rest.
    $this->actingAs($manager)
        ->get(FacilitiesFixture::url('/estate'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('phases', fn ($phases) => collect($phases)->contains(fn ($p) => $p['name'] === 'Phase 9' && $p['unit_count'] === 10 && $p['vacant'] === 10)));

    expect(fn () => app(EstateStructure::class)->addPhase('phase 9', 1, null, null, null, $manager))
        ->toThrow(DomainException::class, 'already exists');
});

it('previews a unit list, rejects a file with one bad row whole, and imports a clean one whole', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $units = Unit::query()->count();

    $bad = UploadedFile::fake()->createWithContent('units.csv', implode("\n", [
        'reference,phase,street,type,status',
        'Lot 701,Phase 7,Ridge Way,residential,vacant',
        'Lot 47,Phase 7,Ridge Way,residential,occupied',
        'Lot 702,Phase 7,Ridge Way,shop,vacant',
        'Lot 701,Phase 7,Ridge Way,residential,vacant',
    ]));

    $response = $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/estate/import/preview'), ['file' => $bad])
        ->assertRedirect();

    $location = (string) $response->headers->get('Location');

    // The preview names every problem against its line, and offers no commit.
    $this->actingAs($manager)
        ->get($location)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('preview.valid', false)
            ->where('preview.row_count', 4)
            ->where('preview.new_phases', ['Phase 7'])
            ->where('preview.errors', fn ($errors) => collect($errors)->pluck('line')->sort()->values()->all() === [3, 4, 5]));

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/estate/import/commit'), ['token' => $query['import']])
        ->assertSessionHasErrors('import');

    FacilitiesFixture::boot();

    expect(Unit::query()->count())->toBe($units)
        ->and(Phase::query()->where('name', 'Phase 7')->exists())->toBeFalse();

    // A clean file: previewed, then committed whole, with the phase it names.
    $good = UploadedFile::fake()->createWithContent('units.csv', implode("\n", [
        'reference,phase,street,type,status',
        'Lot 701,Phase 7,Ridge Way,residential,vacant',
        'Lot 702,phase 7,Ridge Way,,occupied',
        'Shop 1,Phase 7,Ridge Way,commercial,vacant',
    ]));

    $location = (string) $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/estate/import/preview'), ['file' => $good])
        ->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    $this->actingAs($manager)
        ->get($location)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('preview.valid', true)->where('preview.errors', []));

    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/estate/import/commit'), ['token' => $query['import']])
        ->assertRedirect(FacilitiesFixture::url('/estate'));

    FacilitiesFixture::boot();

    expect(Unit::query()->count())->toBe($units + 3)
        ->and(Phase::query()->where('name', 'Phase 7')->exists())->toBeTrue()
        // "phase 7" in the file filed under the phase as the estate spells it.
        ->and(Unit::query()->where('reference', 'Lot 702')->value('block'))->toBe('Phase 7')
        ->and(Unit::query()->where('reference', 'Shop 1')->value('type'))->toBe('commercial');

    // The preview is spent; the same token imports nothing twice.
    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/estate/import/commit'), ['token' => $query['import']])
        ->assertSessionHasErrors('import');

    FacilitiesFixture::boot();

    expect(Unit::query()->count())->toBe($units + 3);
});

it('refuses a preview that went stale between the two presses', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $structure = app(EstateStructure::class);

    $parsed = $structure->parseUnits("reference,phase\nLot 801,Phase 8\n");

    expect($parsed['valid'])->toBeTrue();

    // Somebody else took Lot 801 in the meantime.
    Unit::create(['reference' => 'Lot 801', 'block' => 'Phase 8', 'type' => 'residential', 'status' => 'vacant']);

    expect(fn () => $structure->importUnits($parsed['rows'], $manager))
        ->toThrow(DomainException::class, 'already exists');
});
