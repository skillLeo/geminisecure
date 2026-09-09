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
| The role access matrix is READ ONLY here. Editing RBAC changes who can reach
| what across every client on the platform; it is a privileged, audited write
| and it does not get built as a side effect of a display screen.
*/

Route::middleware('can:gemini.platform_settings.view')->group(function () {
    Route::get('settings/roles', [PlatformSettingsController::class, 'roleMatrix'])
        ->name('gemini.platform_settings');
});
