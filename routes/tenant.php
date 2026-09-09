<?php

declare(strict_types=1);

use App\Http\Controllers\Estate\RecordController;
use App\Http\Middleware\EnsureEstateAccess;
use App\Http\Middleware\ForgetTenantRouteParameter;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Estate Console routes
|--------------------------------------------------------------------------
|
| Served from an estate's own subdomain: phoenixpark.geminisecure.test.
|
| The group carries an explicit domain constraint. Without one these routes
| match by URI on EVERY host, so a bare "/" here would shadow the central
| Gemini Console route and 404 the central domain — Laravel matches the URI
| first and only then runs the middleware that rejects the host.
|
| Tenant identity comes from the resolved subdomain and from nowhere else.
| It is never read from a request parameter, hidden field or query string.
|
| Middleware order is load-bearing:
|   InitializeTenancyBySubdomain    establishes which estate this is
|   PreventAccessFromCentralDomains rejects the central host
|   EnsureEstateAccess              rejects a user of a DIFFERENT estate
|
| The third is not redundant with the database boundary. The grant stops a
| cross-estate query; it cannot stop a user of estate A walking up to estate
| B's subdomain, where the connection is legitimately B's.
|
*/

Route::domain('{tenant}.'.config('app.estate_domain'))
    ->middleware([
        'web',
        InitializeTenancyBySubdomain::class,
        PreventAccessFromCentralDomains::class,
        ForgetTenantRouteParameter::class,
    ])
    ->group(function () {
        Route::get('/', fn () => 'Estate console for '.tenant('name'))->name('estate.home');

        Route::middleware(['auth', EnsureEstateAccess::class])
            ->prefix('records')
            ->name('estate.records.')
            ->whereNumber('id')
            ->group(function () {
                Route::get('residents/{id}', [RecordController::class, 'resident'])->name('resident');
                Route::get('households/{id}', [RecordController::class, 'household'])->name('household');
                Route::get('units/{id}', [RecordController::class, 'unit'])->name('unit');
                Route::get('charges/{id}', [RecordController::class, 'charge'])->name('charge');
                Route::get('journals/{id}', [RecordController::class, 'journal'])->name('journal');
            });
    });
