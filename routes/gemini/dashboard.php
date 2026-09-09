<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\DashboardController;
use Illuminate\Support\Facades\Route;

/*
| Dashboard — board screens super-admin-02, 03.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
*/

Route::get('dashboard', DashboardController::class)
    ->middleware('can:gemini.dashboard.view')
    ->name('gemini.dashboard');
