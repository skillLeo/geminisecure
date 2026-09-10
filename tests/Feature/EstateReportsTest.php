<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Http\Controllers\Estate\ReportsController;
use App\Models\Role;
use App\Models\User;
use App\Services\Navigation\ConsoleNavigation;
use Database\Seeders\RbacMatrixSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| A catalogue of seven reports, none of which generates yet
|--------------------------------------------------------------------------
|
| BOARD 29 IS THE ONE SCREEN WHOSE HONESTY IS THE WHOLE DELIVERABLE. It draws
| seven report cards and seven "Generate" controls; not one of them generates,
| and three of the seven have nothing on this platform to generate FROM — there
| is no budget model, no estate-side incident record and no document store. A
| screen like that fails in exactly one way: somebody trims the seven reasons
| down to "Coming soon", and a committee is then left unable to tell the four
| reports that are a fortnight of work from the three that need a decision
| first.
|
| So the reasons are asserted as content, not as presence. Seven cards, seven
| DIFFERENT sentences, every one long enough to say something, and every one
| naming what is actually missing rather than restating the card's own title.
|
| NO WRITE ROUTE EXISTS UNDER `estate.reports`, and that is asserted against the
| route table rather than trusted. A POST that answered a Generate with nothing
| would be worse than a control that says what it is waiting for; the day the
| first generator lands, this test is what makes somebody choose its verb
| deliberately.
|
| THE GATE IS PROVEN THROUGH THE MATRIX AND THE ROUTE TABLE TOGETHER, rather
| than by provisioning a second estate database for a screen that reads no
| estate data at all. The route carries `can:estate.reports.view`; the seeded
| matrix decides who holds it. Both halves are checked here, over all seven
| estate roles — including the Admin Assistant, whose em dash on board 24's
| Reports row means the item is absent from their sidebar entirely (D-044,
| EstateNavigationTest).
|
*/

uses(DatabaseTransactions::class);

beforeEach(function () {
    if (! Role::query()->where('name', Role::TREASURER)->exists()) {
        Artisan::call('db:seed', ['--class' => RbacMatrixSeeder::class, '--force' => true]);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/** A user holding exactly one estate role. */
function reportsViewer(string $roleName): User
{
    $user = User::factory()->create(['console' => Console::Estate->value, 'status' => 'active']);
    $user->syncRoles([$roleName]);

    return $user->fresh();
}

/**
 * The controller's own card list.
 *
 * Read off the class rather than off a rendered page, because what is under
 * test is the CONTENT of the seven reasons and not the markup around them. A
 * page assertion would pass on a screen that rendered every reason into a title
 * nobody could read.
 *
 * @return list<array<string, mixed>>
 */
function reportCards(): array
{
    $cards = (new ReflectionClass(ReportsController::class))->getConstant('REPORTS');

    expect($cards)->toBeArray();

    return $cards;
}

it('draws the board’s seven reports in the board’s three groups', function () {
    $cards = reportCards();

    expect($cards)->toHaveCount(7);

    // The board's own order, group by group. Not alphabetical, not by
    // readiness — the order a committee reads them in.
    expect(array_column($cards, 'name'))->toBe([
        'Profit & Loss',
        'Arrears Ageing',
        'Budget vs Actual',
        'Maintenance Summary',
        'Security Incident Log',
        'Election Turnout',
        'Meeting Minutes Archive',
    ]);

    expect(array_column($cards, 'group'))->toBe([
        'Financial', 'Financial', 'Financial',
        'Operational', 'Operational',
        'Governance', 'Governance',
    ]);
});

it('gives every card its own icon, because the board draws seven different ones', function () {
    $icons = array_column(reportCards(), 'icon');

    expect(array_unique($icons))->toHaveCount(7);
});

it('never leaves a control inert and silent', function () {
    foreach (reportCards() as $card) {
        $reason = $card['reason'];

        // Long enough to be an explanation rather than a label. "Coming soon"
        // is 11 characters and is exactly what this bar exists to refuse.
        expect(mb_strlen($reason))->toBeGreaterThan(80);

        // A reason that only restates the card's own name explains nothing.
        expect(mb_strtolower(trim($reason)))->not->toBe(mb_strtolower($card['name']));
    }
});

it('gives each report its own reason rather than one sentence seven times', function () {
    $reasons = array_column(reportCards(), 'reason');

    expect(array_unique($reasons))->toHaveCount(7);
});

it('says which three reports have no data on this platform to generate from', function () {
    $ready = [];

    foreach (reportCards() as $card) {
        $ready[$card['key']] = $card['ready'];
    }

    /*
     * The distinction the client actually has to act on. Budget vs Actual needs
     * a budget model nobody has been asked to build; the incident log lives in
     * the central database and this console opens no other estate's; minutes are
     * a document and this platform stores no files. The other four are reading
     * rows that already exist.
     */
    expect($ready)->toBe([
        'profit_and_loss' => true,
        'arrears_ageing' => true,
        'budget_vs_actual' => false,
        'maintenance_summary' => true,
        'security_incidents' => false,
        'election_turnout' => true,
        'meeting_minutes' => false,
    ]);
});

it('registers exactly one reports route, and it only reads', function () {
    $reports = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'estate.reports'));

    /*
     * Two registrations of the same one route: production resolves an estate by
     * subdomain and local by path (routes/tenant.php), and both register the
     * same closure. What matters is that neither is a write.
     */
    expect($reports)->not->toBeEmpty();

    foreach ($reports as $route) {
        expect($route->methods())->toContain('GET');
        expect($route->methods())->not->toContain('POST');

        // The gate, on the route rather than only in the controller.
        expect($route->gatherMiddleware())->toContain('can:estate.reports.view');
    }
});

it('does not let a module key that matches a route name take down the whole console', function () {
    /*
     * THE FAULT THIS SCREEN ACTUALLY FOUND, and it had nothing to do with
     * reports.
     *
     * `ConsoleNavigation` builds an href per module as `<console>.<module_key>`
     * and runs from `HandleInertiaRequests` on every full page load. Every other
     * estate module is grouped — `estate.residents.index`, `estate.payroll.runs`
     * — so `Route::has()` answered false and it returned '#'. `estate.reports`
     * was the first estate route whose name matched its module key exactly, and
     * an estate route carries `{tenant}` on the host or in the path. The moment
     * the route existed, `route()` threw out of shared middleware and EVERY
     * estate screen answered 500. A fidelity sweep caught it mid-run.
     *
     * So this asserts the service over the whole estate matrix rather than over
     * reports: no module, present or future, may produce an href that throws.
     */
    $viewer = reportsViewer(Role::COMMUNITY_SUPER_ADMIN);

    $items = app(ConsoleNavigation::class)->for($viewer, Console::Estate);

    expect($items)->not->toBeEmpty();

    $reports = collect($items)->firstWhere('key', 'reports');

    // Present, because the matrix gives this role Reports — and inert here,
    // because an estate's identity is not something a module key carries. The
    // Estate Console draws its own sidebar from EstateNavigation, which knows
    // the tenant and builds /reports properly.
    expect($reports)->not->toBeNull();
    expect($reports['href'])->toBe('#');
});

it('opens the catalogue to the five roles the matrix gives Reports, and refuses the Admin Assistant', function () {
    // Board 24's Reports row: Full to the Community Super Admin, President,
    // Vice President and Treasurer, View to the Secretary and the Property
    // Manager, and an em dash to the Admin Assistant.
    $mayRead = [
        Role::COMMUNITY_SUPER_ADMIN, Role::PRESIDENT, Role::VICE_PRESIDENT,
        Role::SECRETARY, Role::PROPERTY_MANAGER, Role::TREASURER,
    ];

    foreach ($mayRead as $roleName) {
        expect(reportsViewer($roleName)->can('estate.reports.view'))
            ->toBeTrue("{$roleName} should be able to read the report catalogue");
    }

    expect(reportsViewer(Role::ESTATE_ADMIN_ASSISTANT)->can('estate.reports.view'))->toBeFalse();
});

it('withholds generating from the two roles that only hold View', function () {
    /*
     * `export`, not `create` — a report brings nothing into existence, it takes
     * a copy of what the estate already holds out of the console, and the matrix
     * legend withholds export from Entry for that reason. The Secretary and the
     * Property Manager read this catalogue and generate nothing from it.
     */
    foreach ([Role::SECRETARY, Role::PROPERTY_MANAGER] as $roleName) {
        $viewer = reportsViewer($roleName);

        expect($viewer->can('estate.reports.view'))->toBeTrue();
        expect($viewer->can('estate.reports.export'))->toBeFalse();
    }

    foreach ([Role::COMMUNITY_SUPER_ADMIN, Role::PRESIDENT, Role::TREASURER] as $roleName) {
        expect(reportsViewer($roleName)->can('estate.reports.export'))->toBeTrue();
    }
});
