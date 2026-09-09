<?php

declare(strict_types=1);

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Dispatch\LiveMap;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The dispatch live map
|--------------------------------------------------------------------------
|
| Two things are being defended here, and only one of them is a feature.
|
|   1. THE MAP HOLDS NO POSITION. A pin says which post a guard is standing,
|      never where they are, and this asserts it over the whole rendered
|      payload rather than over the one field somebody remembered. A guard's
|      live coordinates are the most sensitive thing a security company could
|      hold on its own staff; the guarantee is that there is nothing to leak,
|      and a guarantee that rests on nobody adding a column is not one.
|
|   2. A site with nobody on it says so. The failure that matters on a
|      coverage picture is not a wrong pin, it is an ABSENT one — an unmanned
|      estate that renders as a quiet estate.
|
*/

/** A tenant row without provisioning: no database, no MySQL user, no side effects. */
function seedMapEstate(string $id, string $name, string $parish): void
{
    DB::table('tenants')->updateOrInsert(
        ['id' => $id],
        ['name' => $name, 'parish' => $parish, 'status' => 'active', 'data' => '{}'],
    );
}

function mapDirector(): User
{
    $role = Role::findOrCreate('test.map_director', 'web');
    $role->forceFill(['scope_default' => AccessScope::All->value])->save();

    $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $user->syncRoles([$role->name]);

    return $user->fresh();
}

beforeEach(function () {
    seedMapEstate('mapcovered', 'Covered Estate', 'St. Andrew');
    seedMapEstate('mapempty', 'Unmanned Estate', 'St. Thomas');

    $this->post = Post::create([
        'tenant_id' => 'mapcovered',
        'name' => 'Test Main Gate',
        'type' => 'gate',
        'is_active' => true,
    ]);

    $this->officer = Guard::create([
        'full_name' => 'Test Officer',
        'employee_number' => 'GS-MAP-1',
        'psra_number' => 'PSRA-MAP-1',
        'psra_expires_on' => now()->addYear()->toDateString(),
        'status' => 'active',
        'tenant_id' => 'mapcovered',
        'post_id' => $this->post->id,
    ]);

    $this->shift = Shift::create([
        'tenant_id' => 'mapcovered',
        'guard_id' => $this->officer->id,
        'post_id' => $this->post->id,
        'rostered_start' => now()->subHour(),
        'rostered_end' => now()->addHours(6),
        'actual_start' => now()->subHour(),
        'status' => 'active',
    ]);
});

afterEach(function () {
    Shift::query()->where('tenant_id', 'mapcovered')->delete();
    Guard::query()->where('employee_number', 'GS-MAP-1')->delete();
    Post::query()->whereIn('tenant_id', ['mapcovered', 'mapempty'])->delete();
    DB::table('tenants')->whereIn('id', ['mapcovered', 'mapempty'])->delete();
});

it('never puts a coordinate anywhere in the map payload', function () {
    $payload = strtolower((string) json_encode((new LiveMap)->forViewer(mapDirector())));

    /*
     * The whole rendered structure, not one field. A position could arrive on
     * a pin, on a site card, on the banner or on a KPI, and the point of
     * checking the serialised whole is that it catches the one nobody thought
     * of.
     */
    foreach (['latitude', 'longitude', '"lat"', '"lng"', 'coordinate', 'geofence'] as $forbidden) {
        expect($payload)->not->toContain($forbidden);
    }
});

it('labels a guard pin with the post they are standing, not a place', function () {
    $map = (new LiveMap)->forViewer(mapDirector());

    $site = collect($map['sites'])->firstWhere('key', 'mapcovered');
    $pin = collect($site['pins'])->firstWhere('dot', 'post');

    expect($pin['label'])->toBe('Test Officer · Test Main Gate');
});

it('draws the offline pin for a site with nobody on duty', function () {
    $map = (new LiveMap)->forViewer(mapDirector());

    $site = collect($map['sites'])->firstWhere('key', 'mapempty');

    // The failure worth catching is the silent one: an unmanned estate that
    // renders identically to a manned estate whose pins are simply off screen.
    expect(collect($site['pins'])->pluck('dot'))->toContain('offline')
        ->and(collect($site['pins'])->firstWhere('dot', 'offline')['label'])
        ->toBe('No Gemini guards on site');
});

it('does not count a rostered shift nobody started as a guard on duty', function () {
    $this->shift->forceFill(['actual_start' => null, 'status' => 'rostered'])->save();

    $map = (new LiveMap)->forViewer(mapDirector());

    // A roster is a plan. Drawing a pin for a shift that never began would put
    // a guard on the map who is not at the gate.
    expect($map['onDuty'])->toBeEmpty()
        ->and(collect($map['sites'])->firstWhere('key', 'mapcovered')['pins'])
        ->toHaveCount(1);
});

it('shows a site-scoped viewer only the estates assigned to them', function () {
    $role = Role::findOrCreate('test.map_site_scoped', 'web');
    $role->forceFill(['scope_default' => AccessScope::AssignedSites->value])->save();

    $viewer = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $viewer->syncRoles([$role->name]);

    // No assignments at all: a scoped viewer with nothing assigned sees an
    // empty platform, never everyone else's.
    expect((new LiveMap)->forViewer($viewer->fresh())['sites'])->toBeEmpty();
});
