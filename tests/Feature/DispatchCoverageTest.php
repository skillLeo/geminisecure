<?php

declare(strict_types=1);

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Dispatch\PostCoverage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The post coverage board
|--------------------------------------------------------------------------
|
| The failure this screen exists to prevent is a post reading as manned when
| nobody is standing it. Every test below is a different route to that same
| lie, and each one is a route the obvious implementation takes:
|
|   - answering both twelve-hour windows from `guards.post_id`, which carries
|     no time at all
|   - counting a rostered shift nobody clocked into as coverage
|   - rostering a guard whose licence has lapsed
|
| The last test is the counterweight: a shift rostered for LATER is not a hole,
| and colouring it red would make every afternoon look like a crisis and teach
| a dispatcher to ignore the colour.
|
*/

function seedCoverageEstate(string $id, string $name, string $status = 'active'): void
{
    DB::table('tenants')->updateOrInsert(
        ['id' => $id],
        ['name' => $name, 'parish' => 'St. Andrew', 'status' => $status, 'data' => '{}'],
    );
}

function coverageDirector(): User
{
    $role = Role::findOrCreate('test.coverage_director', 'web');
    $role->forceFill(['scope_default' => AccessScope::All->value])->save();

    $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $user->syncRoles([$role->name]);

    return $user->fresh();
}

/** @return array<string, mixed>|null */
function coverageRowFor(string $postName): ?array
{
    $board = (new PostCoverage)->forViewer(coverageDirector());

    return collect($board['rows'])->firstWhere('post', $postName);
}

beforeEach(function () {
    seedCoverageEstate('covtest', 'Coverage Test Estate');

    $this->post = Post::create([
        'tenant_id' => 'covtest',
        'name' => 'Coverage Test Gate',
        'type' => 'gate',
        'is_active' => true,
    ]);

    $this->officer = Guard::create([
        'full_name' => 'Coverage Officer',
        'employee_number' => 'GS-COV-1',
        'psra_number' => 'PSRA-COV-1',
        'psra_expires_on' => now()->addYear()->toDateString(),
        'status' => 'active',
        'tenant_id' => 'covtest',
        'post_id' => $this->post->id,
    ]);
});

afterEach(function () {
    Shift::query()->where('tenant_id', 'covtest')->delete();
    Guard::query()->where('employee_number', 'GS-COV-1')->delete();
    Post::query()->where('tenant_id', 'covtest')->delete();
    DB::table('tenants')->whereIn('id', ['covtest', 'covonboarding'])->delete();
});

it('reports a post with no roster as uncovered in both windows', function () {
    // The guard is assigned to the post. That is not coverage: an assignment
    // has no hours on it, and reading it as "manned tonight" is the mistake
    // this whole screen is built to avoid.
    $row = coverageRowFor('Coverage Test Gate');

    expect(collect($row['cells'])->pluck('class')->all())->toBe(['uncovered', 'uncovered'])
        ->and($row['fullyCovered'])->toBeFalse();
});

it('counts a started shift as coverage in the window it spans', function () {
    Shift::create([
        'tenant_id' => 'covtest',
        'guard_id' => $this->officer->id,
        'post_id' => $this->post->id,
        'rostered_start' => Carbon::today()->addHours(7),
        'rostered_end' => Carbon::today()->addHours(19),
        'actual_start' => Carbon::today()->addHours(7),
        'status' => 'active',
    ]);

    $row = coverageRowFor('Coverage Test Gate');

    expect($row['cells'][0]['class'])->toBe('covered')
        ->and($row['cells'][0]['label'])->toBe('Covered')
        ->and($row['assigned'])->toBe('Coverage Officer')
        // The night window still has no roster, so it is still a hole.
        ->and($row['cells'][1]['class'])->toBe('uncovered');
});

it('refuses to call a shift nobody clocked into coverage', function () {
    Shift::create([
        'tenant_id' => 'covtest',
        'guard_id' => $this->officer->id,
        'post_id' => $this->post->id,
        'rostered_start' => Carbon::today()->addHours(7),
        'rostered_end' => Carbon::today()->addHours(19),
        'actual_start' => null,
        'status' => 'rostered',
    ]);

    /*
     * Only meaningful once the window has begun. Frozen inside it so the test
     * asserts the rule rather than the hour it happened to run at.
     */
    Carbon::setTestNow(Carbon::today()->addHours(12));

    $row = coverageRowFor('Coverage Test Gate');

    expect($row['cells'][0]['class'])->toBe('uncovered')
        ->and($row['cells'][0]['label'])->toBe('Not started');

    Carbon::setTestNow();
});

it('does not report a shift rostered for later as a gap', function () {
    Shift::create([
        'tenant_id' => 'covtest',
        'guard_id' => $this->officer->id,
        'post_id' => $this->post->id,
        'rostered_start' => Carbon::today()->addHours(19),
        'rostered_end' => Carbon::today()->addHours(31),
        'actual_start' => null,
        'status' => 'rostered',
    ]);

    // Mid-afternoon: tonight's shift has not failed to start, it has not
    // started yet. A red pill here would cry wolf every single day.
    Carbon::setTestNow(Carbon::today()->addHours(15));

    $row = coverageRowFor('Coverage Test Gate');

    expect($row['cells'][1]['class'])->toBe('covered');

    Carbon::setTestNow();
});

it('names the guard and the reason when a post falls unmanned', function () {
    $this->officer->forceFill([
        'status' => 'licence_expired',
        'psra_expires_on' => now()->subDay()->toDateString(),
    ])->save();

    $row = coverageRowFor('Coverage Test Gate');

    // "Unassigned" alone tells a supervisor nothing they can act on.
    expect($row['assigned'])->toBe("Unassigned since Coverage Officer's licence expired");
});

it('tells a self-managed client apart from one still onboarding', function () {
    seedCoverageEstate('covonboarding', 'Onboarding Test Estate', 'onboarding');

    $board = (new PostCoverage)->forViewer(coverageDirector());
    $row = collect($board['rows'])->firstWhere('site', 'Onboarding Test Estate');

    // Both read as "no coverage" if collapsed, and only one of them is
    // anybody's problem.
    expect($row['cells'][0]['label'])->toBe('Not yet live')
        ->and($row['assigned'])->toBe('Onboarding — posts not yet built');
});
