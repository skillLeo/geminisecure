<?php

declare(strict_types=1);

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\User;
use App\Services\Gemini\SecurityOperations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The cross-client rota, and the scope that narrows it
|--------------------------------------------------------------------------
|
| Board super-admin-24 draws every post at every client in one grid. Two things
| about it are worth asserting rather than looking at:
|
|   1. A post whose guard cannot stand it reads Open. An expired PSRA licence
|      is a hard block, and the failure mode is silent: a rota that quietly
|      showed that guard covering their gate all week would be the platform
|      asserting coverage that legally does not exist, and it would look
|      completely normal on the screen.
|
|   2. A Head of Security is scoped to assigned sites, and the narrowing has to
|      happen in the QUERY. Filtering a rendered list would leave every other
|      client's rota one URL away.
|
| RefreshDatabase is deliberately NOT used: this suite shares gs_platform_test
| with the rest of the console and dropping the schema mid-run would take other
| tests with it. Each test runs inside a transaction and leaves nothing behind.
|
*/

uses(DatabaseTransactions::class);

/**
 * An estate row, written straight to the table.
 *
 * Not Tenant::create(): creating a tenant through the model fires stancl's
 * provisioning, which builds a database and a MySQL user per estate. This test
 * needs a row to join against, not an estate.
 */
function rosterEstate(string $id, string $name, string $status = 'active'): string
{
    DB::connection('mysql')->table('tenants')->insert([
        'id' => $id,
        'name' => $name,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function rosterPost(string $tenantId, string $name, string $type = 'gate'): Post
{
    return Post::create([
        'tenant_id' => $tenantId,
        'name' => $name,
        'type' => $type,
        'is_active' => true,
    ]);
}

function rosterGuard(string $name, Post $post, string $status = 'active'): Guard
{
    $suffix = random_int(100000, 999999);

    return Guard::create([
        'full_name' => $name,
        'employee_number' => 'GS-R'.$suffix,
        'psra_number' => 'PSRA-R'.$suffix,
        'psra_expires_on' => now()->addYear()->toDateString(),
        'employment_type' => 'full_time',
        'status' => $status,
        'tenant_id' => $post->tenant_id,
        'post_id' => $post->id,
    ]);
}

/** A Gemini user whose single role carries the given scope. */
function rosterViewer(AccessScope $scope, ?string $assignedTo = null): User
{
    $role = Role::findOrCreate('test.roster_'.$scope->value.random_int(1000, 9999), 'web');
    $role->forceFill(['console' => Console::Gemini->value, 'scope_default' => $scope->value])->save();

    $user = User::factory()->create(['console' => Console::Gemini->value, 'status' => 'active']);
    $user->syncRoles([$role->name]);

    if ($assignedTo !== null) {
        EstateAssignment::create([
            'user_id' => $user->id,
            'tenant_id' => $assignedTo,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    return $user->fresh();
}

beforeEach(function () {
    $this->operations = new SecurityOperations;
    $this->estate = rosterEstate('rostertest'.random_int(100, 999), 'Roster Test Estate');
});

it('reads Open on every day of a post whose guard has a lapsed licence', function () {
    $post = rosterPost($this->estate, 'Main Gate');
    rosterGuard('Lapsed Officer', $post, 'licence_expired');

    $roster = $this->operations->roster(rosterViewer(AccessScope::All));

    $group = collect($roster['groups'])->firstWhere('id', $this->estate);
    $line = $group['lines'][0];

    expect($line['cells'])->toHaveCount(7);

    foreach ($line['cells'] as $cell) {
        expect($cell['label'])->toBe('Open')
            ->and($cell['tone'])->toBe('open')
            // The reason, not just the colour: an empty post and an unlicensed
            // one are the same red chip and different problems to solve.
            ->and($cell['title'])->toContain('licence has expired');
    }
});

it('names the guard on a post they can stand', function () {
    $post = rosterPost($this->estate, 'Main Gate');
    rosterGuard('Marcia Grant', $post);

    $roster = $this->operations->roster(rosterViewer(AccessScope::All));
    $line = collect($roster['groups'])->firstWhere('id', $this->estate)['lines'][0];

    expect($line['cells'][0]['label'])->toBe('M. Grant')
        ->and($line['cells'][0]['tone'])->toBe('day');
});

it('draws a patrol as a night line and a gate as a day line', function () {
    rosterGuard('Nightly Walker', rosterPost($this->estate, 'Patrol', 'patrol'));

    $roster = $this->operations->roster(rosterViewer(AccessScope::All));
    $line = collect($roster['groups'])->firstWhere('id', $this->estate)['lines'][0];

    expect($line['cells'][0]['tone'])->toBe('night');
});

it('gives an unstaffed post no rota line while still counting it as a post', function () {
    rosterPost($this->estate, 'Service Gate');

    $roster = $this->operations->roster(rosterViewer(AccessScope::All));

    // No standing assignment, so no line: the grid describes who stands what.
    expect(collect($roster['groups'])->firstWhere('id', $this->estate))->toBeNull();

    // The card above the grid still counts it, and the gap between the two
    // figures is exactly the point of drawing both.
    $posts = (int) collect($roster['kpis'])->firstWhere('label', 'Posts, platform-wide')['value'];

    expect($posts)->toBeGreaterThanOrEqual(1);
});

it('keeps a scoped role out of a client they are not assigned to', function () {
    $mine = rosterEstate('rostermine'.random_int(100, 999), 'Assigned Estate');
    $theirs = rosterEstate('rosterother'.random_int(100, 999), 'Unassigned Estate');

    rosterGuard('Assigned Officer', rosterPost($mine, 'Main Gate'));
    rosterGuard('Other Officer', rosterPost($theirs, 'Main Gate'));

    $roster = $this->operations->roster(rosterViewer(AccessScope::AssignedSites, $mine));

    $ids = collect($roster['groups'])->pluck('id')->all();

    expect($ids)->toContain($mine)
        ->and($ids)->not->toContain($theirs)

        // The screen is told it is narrowed, so an empty rota can say "none at
        // your sites" instead of "no posts are staffed yet".
        ->and($roster['scoped'])->toBeTrue();
});

it('shows a role with every site both clients', function () {
    $mine = rosterEstate('rosterboth'.random_int(100, 999), 'First Estate');
    $theirs = rosterEstate('rosterboth2'.random_int(100, 999), 'Second Estate');

    rosterGuard('First Officer', rosterPost($mine, 'Main Gate'));
    rosterGuard('Second Officer', rosterPost($theirs, 'Main Gate'));

    $ids = collect($this->operations->roster(rosterViewer(AccessScope::All))['groups'])
        ->pluck('id')
        ->all();

    expect($ids)->toContain($mine)->and($ids)->toContain($theirs);
});
