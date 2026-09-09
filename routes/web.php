<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Gemini\AuditLogController;
use App\Http\Controllers\Gemini\ClientController;
use App\Http\Controllers\Gemini\DashboardController;
use App\Http\Controllers\Gemini\GuardController;
use App\Http\Controllers\Gemini\PlatformSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Gemini Console routes
|--------------------------------------------------------------------------
|
| Served from the central domain. Estate routes live in routes/tenant.php,
| behind an explicit subdomain constraint.
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

Route::get('/', fn () => redirect()->route('gemini.dashboard'));

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)
        ->middleware('can:gemini.dashboard.view')
        ->name('gemini.dashboard');

    Route::get('clients', [ClientController::class, 'index'])
        ->middleware('can:gemini.clients.view')
        ->name('gemini.clients');

    Route::get('clients/{tenant}', [ClientController::class, 'show'])
        ->middleware('can:gemini.clients.view')
        ->name('gemini.clients.show');

    Route::get('audit', AuditLogController::class)
        ->middleware('can:gemini.access_audit_log.view')
        ->name('gemini.access_audit_log');

    Route::get('settings/roles', [PlatformSettingsController::class, 'roleMatrix'])
        ->middleware('can:gemini.platform_settings.view')
        ->name('gemini.platform_settings');

    Route::middleware('can:gemini.guard_workforce.view')->group(function () {
        Route::get('guards', [GuardController::class, 'index'])->name('gemini.guard_workforce');
        Route::get('guards/compliance', [GuardController::class, 'compliance'])
            ->name('gemini.guard_workforce.compliance');
        Route::get('guards/{guard}', [GuardController::class, 'show'])
            ->whereNumber('guard')
            ->name('gemini.guard_workforce.show');
    });
});
