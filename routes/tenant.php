<?php

declare(strict_types=1);

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
| The group carries an explicit domain constraint. Without one, these routes
| match by URI on EVERY host, so a bare "/" here would shadow the central
| Gemini Console route and return 404 on the central domain — Laravel matches
| the URI first and only then runs the middleware that rejects the host.
|
| Tenant identity comes from the subdomain and from nowhere else. It is never
| read from a request parameter, a hidden field or a query string.
|
*/

Route::domain('{tenant}.'.config('app.estate_domain'))
    ->middleware([
        'web',
        InitializeTenancyBySubdomain::class,
        PreventAccessFromCentralDomains::class,
    ])
    ->group(function () {
        Route::get('/', function () {
            return 'Estate console for '.tenant('name');
        })->name('estate.home');
    });
