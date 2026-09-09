<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Dev\QuickLoginController;
use App\Http\Controllers\Gemini\AuditLogController;
use App\Http\Controllers\Gemini\BillingController;
use App\Http\Controllers\Gemini\ClientController;
use App\Http\Controllers\Gemini\DashboardController;
use App\Http\Controllers\Gemini\DispatchController;
use App\Http\Controllers\Gemini\GuardController;
use App\Http\Controllers\Gemini\PayrollController;
use App\Http\Controllers\Gemini\PlatformSettingsController;
use App\Http\Controllers\Gemini\ReportController;
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

    Route::get('billing', [BillingController::class, 'index'])
        ->middleware('can:gemini.billing_subscriptions.view')
        ->name('gemini.billing_subscriptions');

    Route::middleware('can:gemini.dispatch.view')->group(function () {
        Route::get('dispatch/alerts', [DispatchController::class, 'alerts'])->name('gemini.dispatch');
        Route::get('dispatch/alerts/{alert}', [DispatchController::class, 'alert'])
            ->whereNumber('alert')
            ->name('gemini.dispatch.alert');
    });

    Route::middleware('can:gemini.payroll_accounting.view')->group(function () {
        Route::get('payroll', [PayrollController::class, 'index'])->name('gemini.payroll_accounting');
        Route::get('payroll/{run}', [PayrollController::class, 'show'])->whereNumber('run')->name('gemini.payroll_accounting.show');
    });

    Route::get('reports', [ReportController::class, 'index'])
        ->middleware('can:gemini.cross_tenant_reports.view')
        ->name('gemini.cross_tenant_reports');

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
