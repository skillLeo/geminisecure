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
| Every route here is a read. The role access matrix, the tier prices and the
| package template each change what is true for clients other than the one in
| front of you: editing RBAC changes who can reach what across every client on
| the platform, and a tier price re-prices every estate on that tier. Those are
| privileged, audited writes with effective dates, and they do not get built as
| a side effect of a display screen. When they are, they arrive on their own
| routes with a stronger verb than GET and their own permission.
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
