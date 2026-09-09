<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Dev\QuickLoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Central routes
|--------------------------------------------------------------------------
|
| Served from the central domain. Estate routes live in routes/tenant.php,
| behind an explicit subdomain constraint.
|
| The Gemini Console's own routes are split ONE FILE PER MODULE under
| routes/gemini/. That is not tidiness: the console is 45 screens, they are
| built module by module, and a single 400-line route file is a file every
| piece of that work has to touch at once. Each module's routes now live
| beside nothing but themselves.
|
| Every module route is gated by a permission from the role access matrix,
| named <console>.<module>.<verb>. The gate and the sidebar therefore read the
| same source: a module absent from the navigation is also unreachable by URL,
| rather than merely hidden.
|
| There is no registration route anywhere in this application. Accounts are
| issued by invitation and never self-created.
|
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
 * Quick login - LOCAL ONLY.
 *
 * An authentication bypass for comparing the 13 roles without maintaining 13
 * sets of credentials. Registered only when the application is local; the
 * controller asserts the same thing again, because this single line is exactly
 * what gets moved during a refactor.
 */
if (app()->isLocal()) {
    Route::get('dev/login/{role}', QuickLoginController::class)->name('dev.login');
}

Route::get('/', fn () => redirect()->route('gemini.dashboard'));

/*
 * The Gemini Console.
 *
 * Every module file is loaded inside this one auth group, so no module file
 * can forget the guard. A file that omitted `auth` on its own group would
 * publish its whole module, and that is not a mistake worth leaving available.
 */
Route::middleware(['auth'])->group(function () {
    foreach (glob(__DIR__.'/gemini/*.php') ?: [] as $module) {
        require $module;
    }
});
