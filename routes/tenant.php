<?php

declare(strict_types=1);

use App\Http\Controllers\Estate\AccountingController;
use App\Http\Controllers\Estate\DashboardController as EstateDashboardController;
use App\Http\Controllers\Estate\DuesController;
use App\Http\Controllers\Estate\RecordController;
use App\Http\Middleware\EnsureEstateAccess;
use App\Http\Middleware\ForgetTenantRouteParameter;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Estate Console routes
|--------------------------------------------------------------------------
|
| The SAME routes, reachable two ways.
|
|   Production:  phoenixpark.geminisecure.com/...     (subdomain)
|   Local:       localhost:8000/estate/phoenixpark/...  (path)
|
| Subdomains are right for production — they give each community its own
| hostname and let the session cookie be scoped per estate. They are wrong for
| local development: *.localhost does not resolve on Windows, a hosts entry
| needs administrator rights, and a second registrable domain means the session
| cookie does not travel, which is a login loop rather than a login screen.
|
| So the path form exists ONLY in local, and both forms register the same
| controllers. Nothing about the application knows which one it is serving.
|
| Tenant identity comes from the resolved subdomain or path segment and from
| nowhere else. It is never read from a request parameter, hidden field or
| query string.
|
*/

/** The routes themselves, registered identically under both resolvers. */
$estateRoutes = function (): void {
    Route::get('/', EstateDashboardController::class)->name('estate.home');

    /*
     * Dues & ledger — boards 5, 6 and 35.
     *
     * Reading the arrears is `view`. POSTING A CHARGE IS NOT: it adds to what a
     * household owes, so it needs `create`, and the Property Manager holds
     * neither by platform invariant rather than estate preference (D-010).
     */
    Route::middleware('can:estate.dues_ledger.view')
        ->prefix('finance')
        ->name('estate.dues.')
        ->group(function (): void {
            Route::get('arrears', [DuesController::class, 'arrears'])->name('arrears');

            Route::get('units/{unit}', [DuesController::class, 'unit'])
                ->whereNumber('unit')
                ->name('unit');

            Route::get('charges/new', [DuesController::class, 'newCharge'])->name('charge.new');

            Route::post('charges', [DuesController::class, 'postCharge'])
                ->middleware('can:estate.dues_ledger.create')
                ->name('charge.store');
        });

    /*
     * Accounting — boards 25 to 28.
     *
     * A separate permission module from Dues & ledger, and deliberately: D-010
     * split them so a Property Manager could be refused the estate's books
     * while still seeing the vendor costs they commission.
     */
    Route::middleware('can:estate.accounting_posting.view')
        ->prefix('accounting')
        ->name('estate.accounting.')
        ->group(function (): void {
            Route::get('chart-of-accounts', [AccountingController::class, 'chart'])->name('chart');
        });

    Route::prefix('records')
        ->name('estate.records.')
        ->whereNumber('id')
        ->group(function () {
            Route::get('residents/{id}', [RecordController::class, 'resident'])->name('resident');
            Route::get('households/{id}', [RecordController::class, 'household'])->name('household');
            Route::get('units/{id}', [RecordController::class, 'unit'])->name('unit');
            Route::get('charges/{id}', [RecordController::class, 'charge'])->name('charge');
            Route::get('journals/{id}', [RecordController::class, 'journal'])->name('journal');
        });
};

/*
 * PRODUCTION SHAPE: one hostname per estate.
 *
 * The group carries an explicit domain constraint. Without one these routes
 * match by URI on every host, so a bare "/" here would shadow the central
 * Gemini Console route — Laravel matches the URI first and only then runs the
 * middleware that rejects the host.
 */
Route::domain('{tenant}.'.config('app.estate_domain'))
    ->middleware([
        'web',
        InitializeTenancyBySubdomain::class,
        PreventAccessFromCentralDomains::class,
        ForgetTenantRouteParameter::class,
        'auth',
        EnsureEstateAccess::class,
    ])
    ->group($estateRoutes);

/*
 * LOCAL SHAPE: same host, estate in the path.
 *
 * Registered only in local. In every other environment an estate is reachable
 * by its own hostname and nothing else, so this cannot become a way around the
 * subdomain boundary in production.
 *
 * InitializeTenancyByPath requires {tenant} to be the FIRST route parameter,
 * which is why the prefix carries it directly.
 */
if (app()->isLocal()) {
    Route::prefix('estate/{tenant}')
        ->middleware([
            'web',
            InitializeTenancyByPath::class,
            'auth',
            EnsureEstateAccess::class,
        ])
        ->group($estateRoutes);
}
