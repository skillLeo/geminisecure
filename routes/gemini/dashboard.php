<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\ActivityController;
use App\Http\Controllers\Gemini\DashboardController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard — board screens super-admin-02, 03.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
*/

Route::middleware('can:gemini.dashboard.view')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('gemini.dashboard');

    // The full feed behind the dashboard's "Recent activity" panel. NOT the
    // audit log — that is its own module, its own permission, and append-only.
    Route::get('dashboard/activity', ActivityController::class)->name('gemini.dashboard.activity');
});
