<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\PlatformSettingsController;
use Illuminate\Support\Facades\Route;

/*
| Platform settings — board screens super-admin-42 to 45.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
|
| `gemini.platform_settings` is the module's LANDING screen (42, Pricing &
| rates), not the role matrix. It served /settings/roles for a while, which put
| the sidebar's Platform settings link on a sub-screen and left the module with
| no front door — and, because the fidelity harness resolves each board by route
| name, made every measurement of board 42 a measurement of board 45.
|
| THE THREE WRITES ARE `configure` AND NOT `update` (12 §2, Wave 4). Each one
| changes what is true for clients other than the one in front of you — a tier
| price re-prices every estate on that tier, a package row changes what every
| current and future client on it receives — so they are not data entry, and the
| verb says so. Each carries an effective date and none is retroactive: an
| invoice already raised was raised at the price in force.
|
| THE ROLE MATRIX IS STILL A READ, and stays one. Editing RBAC changes who can
| reach what across every client on the platform, and the matrix is seeded from
| the approved wireframes rather than typed — a screen that let it be edited
| would be a second source of truth for the one table the `can:` middleware
| resolves against.
*/

Route::middleware('can:gemini.platform_settings.view')->group(function () {
    Route::get('settings', [PlatformSettingsController::class, 'index'])
        ->name('gemini.platform_settings');

    Route::get('settings/packages', [PlatformSettingsController::class, 'packages'])
        ->name('gemini.platform_settings.packages');

    Route::get('settings/line-items', [PlatformSettingsController::class, 'lineItems'])
        ->name('gemini.platform_settings.line_items');

    Route::get('settings/roles', [PlatformSettingsController::class, 'roleMatrix'])
        ->name('gemini.platform_settings.roles');
});

Route::middleware('can:gemini.platform_settings.configure')->group(function () {
    Route::post('settings/prices', [PlatformSettingsController::class, 'changePrice'])
        ->name('gemini.platform_settings.price');

    Route::post('settings/packages', [PlatformSettingsController::class, 'savePackages'])
        ->name('gemini.platform_settings.packages.save');

    Route::post('settings/line-items', [PlatformSettingsController::class, 'addLineItem'])
        ->name('gemini.platform_settings.line_items.add');

    Route::post('settings/line-items/{item}/end', [PlatformSettingsController::class, 'endLineItem'])
        ->whereNumber('item')
        ->name('gemini.platform_settings.line_items.end');
});
