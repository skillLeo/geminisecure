<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\DuressAlert;
use App\Models\GateEvent;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Services\Dispatch\PostCoverage;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| /simulator — Part C of the web deliverable
|--------------------------------------------------------------------------
|
| THREE THINGS ARE UNDER TEST. That the simulator is ABSENT — not refused,
| absent — on a host without the flag. That with the flag on it is the
| Director's and nobody else's. And that every event it fires goes through
| the real /api/v1 endpoint: the row that appears is one the endpoint made,
| flagged simulated, and a seeded burst fired twice makes its rows once.
|
*/

function simulatorEstate(): string
{
    $id = 'simtest';

    DB::table('tenants')->updateOrInsert(
        ['id' => $id],
        [
            'name' => 'Simulator Test Estate',
            'data' => json_encode(['name' => 'Simulator Test Estate']),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );

    return $id;
}

function simulatorGuard(): Guard
{
    static $n = 0;
    $n++;

    return Guard::create([
        'full_name' => 'Sim Guard '.$n,
        'employee_number' => 'GS-SIM-'.$n,
        'psra_number' => 'PSRA-SIM-'.$n,
        'psra_expires_on' => now()->addYear(),
        'employment_type' => 'full_time',
        'status' => 'active',
        'tenant_id' => simulatorEstate(),
    ]);
}

function simulatorUser(string $role): User
{
    $user = User::factory()->create([
        'console' => Console::Gemini->value,
        'status' => 'active',
    ]);

    $user->syncRoles([$role]);

    return $user->fresh();
}

beforeEach(function () {
    $this->seed(RbacMatrixSeeder::class);
    $this->withoutVite();
});

it('is the Director\'s, and refuses every other role', function () {
    simulatorEstate();

    $this->actingAs(simulatorUser(Role::DIRECTOR))
        ->get('/simulator')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Gemini/Simulator/Index')
            ->where('results', [])
            // Other suites leave their own committed estates behind in the
            // central database, so "at least this one" rather than "one".
            ->where('estates', fn ($estates) => collect($estates)->contains('id', simulatorEstate()))
            ->has('kinds', 5));

    // The Dispatcher runs the queue the simulator fills, and still may not
    // fill it: putting a panic into a client's live queue is a platform act.
    $this->actingAs(simulatorUser(Role::DISPATCHER))->get('/simulator')->assertForbidden();

    $this->actingAs(simulatorUser(Role::DISPATCHER))
        ->post('/simulator/alert', ['tenant_id' => simulatorEstate(), 'kind' => 'panic'])
        ->assertForbidden();

    expect(DuressAlert::query()->count())->toBe(0);
});

it('raises an alert through the real endpoint, flagged simulated, on a handset it hands back', function () {
    simulatorGuard();
    $director = simulatorUser(Role::DIRECTOR);

    $this->actingAs($director)
        ->post('/simulator/alert', ['tenant_id' => simulatorEstate(), 'kind' => 'medical', 'offline' => true])
        ->assertRedirect('/simulator');

    $alert = DuressAlert::query()->sole();

    // The endpoint made it — kind, estate, the offline capture and the skewed
    // clock all came through validation — and it says it is not real.
    expect($alert->kind)->toBe('medical')
        ->and($alert->tenant_id)->toBe(simulatorEstate())
        ->and((bool) $alert->is_simulated)->toBeTrue()
        ->and((bool) $alert->captured_offline)->toBeTrue()
        ->and((bool) $alert->clock_skewed)->toBeTrue();

    // And the handset it borrowed is gone: a working token left behind would
    // be a credential on the platform, and a guard shown carrying a device.
    expect(Guard::query()->sole()->tokens()->count())->toBe(0);

    $this->actingAs($director)
        ->get('/simulator')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('results', 1)
            ->where('results.0.outcome', 'created')
            ->where('results.0.status', 201));
});

it('records an arrival and clocks a shift through the endpoints, and the coverage board says so', function () {
    $guard = simulatorGuard();
    $post = Post::create(['tenant_id' => simulatorEstate(), 'name' => 'Main Gate', 'type' => 'gate', 'is_active' => true]);
    $shift = Shift::create([
        'tenant_id' => simulatorEstate(),
        'guard_id' => $guard->id,
        'post_id' => $post->id,
        'rostered_start' => now()->subHour(),
        'rostered_end' => now()->addHours(11),
        'status' => 'rostered',
    ]);
    $director = simulatorUser(Role::DIRECTOR);

    // The override is the arrival that carries a reason; the endpoint refuses
    // one without, so this proves the catalogue entry reaches it whole.
    $this->actingAs($director)
        ->post('/simulator/gate-event', ['tenant_id' => simulatorEstate(), 'arrival' => 7, 'subject' => 'Gate2 Contractor Ltd'])
        ->assertRedirect('/simulator');

    $event = GateEvent::query()->sole();

    expect($event->verdict)->toBe('override')
        ->and($event->subject)->toBe('Gate2 Contractor Ltd')
        ->and((bool) $event->is_simulated)->toBeTrue()
        ->and($event->post_id)->toBe($post->id);

    $this->actingAs($director)
        ->post('/simulator/shifts', ['tenant_id' => simulatorEstate()])
        ->assertRedirect('/simulator');

    $shift->refresh();

    expect($shift->actual_start)->not->toBeNull()
        ->and((bool) $shift->is_simulated)->toBeTrue();

    // The post reads as manned, and the board carries the badge that says by
    // whom — Part C's gap, closed.
    $board = app(PostCoverage::class)->forViewer($director);
    $row = collect($board['rows'])->firstWhere('post', 'Main Gate');

    expect($board['sourceBadge'])->toBe(['source' => 'guard', 'simulated' => true])
        ->and($row['cells'][0]['class'])->toBe('covered');
});

it('runs a seeded night once, however many times the same seed is pressed', function () {
    simulatorGuard();
    Post::create(['tenant_id' => simulatorEstate(), 'name' => 'Main Gate', 'type' => 'gate', 'is_active' => true]);
    $director = simulatorUser(Role::DIRECTOR);

    /*
     * Other suites leave their own estates in the central database, with no
     * guard to borrow a handset from — the burst lands a share of its events
     * on those and is refused there, honestly. So the claims here are about
     * THIS estate's rows and about the burst being idempotent, not about a
     * count that depends on who ran first.
     */
    $rows = fn (): int => DuressAlert::query()->where('tenant_id', simulatorEstate())->count()
        + GateEvent::query()->where('tenant_id', simulatorEstate())->count();

    $this->actingAs($director)
        ->post('/simulator/ambient', ['seed' => 7, 'count' => 12])
        ->assertRedirect('/simulator');

    $after = $rows();

    expect($after)->toBeGreaterThan(0)
        ->and(DuressAlert::query()->where('is_simulated', false)->count())->toBe(0)
        ->and(GateEvent::query()->where('is_simulated', false)->count())->toBe(0);

    // Same seed, same keys: the endpoints hand back what they already made.
    $this->actingAs($director)
        ->post('/simulator/ambient', ['seed' => 7, 'count' => 12])
        ->assertRedirect('/simulator');

    expect($rows())->toBe($after);

    $this->actingAs($director)
        ->get('/simulator')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('results', 12)
            ->where('summary', fn ($summary) => str_starts_with((string) $summary, 'Seed 7, 12 event(s): 0 new, ')));

    // A different seed is a different night.
    $this->actingAs($director)
        ->post('/simulator/ambient', ['seed' => 8, 'count' => 12])
        ->assertRedirect('/simulator');

    expect($rows())->toBeGreaterThan($after);
});

it('refuses honestly when the estate has nobody to borrow a handset from', function () {
    simulatorEstate();
    $director = simulatorUser(Role::DIRECTOR);

    $this->actingAs($director)
        ->post('/simulator/alert', ['tenant_id' => simulatorEstate(), 'kind' => 'panic'])
        ->assertRedirect('/simulator');

    // Nothing was written by any other route, and the screen says why.
    expect(DuressAlert::query()->count())->toBe(0);

    $this->actingAs($director)
        ->get('/simulator')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('results.0.outcome', 'refused')
            ->where('results.0.status', null));
});

it('is absent — not refused, absent — on a host without the flag', function () {
    /*
     * The routes are registered at boot from `config('simulator.enabled')`, so
     * turning the flag off means booting again. Environment first, then a
     * fresh application; the suite's own setting is restored afterwards so the
     * tests that run after this one still reach the routes.
     */
    $set = static function (string $value): void {
        putenv('GS_SIMULATOR_ENABLED='.$value);
        $_ENV['GS_SIMULATOR_ENABLED'] = $value;
        $_SERVER['GS_SIMULATOR_ENABLED'] = $value;
    };

    $set('false');

    try {
        $this->refreshApplication();

        expect(config('simulator.enabled'))->toBeFalse();

        // 404 to a guest, where a registered route would answer with a
        // redirect to sign in: the route is not there to be gated.
        $this->get('/simulator')->assertNotFound();
        $this->post('/simulator/alert')->assertNotFound();
        $this->post('/simulator/ambient')->assertNotFound();
    } finally {
        $set('true');
    }
});
