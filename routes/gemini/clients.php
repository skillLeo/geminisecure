<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\ClientController;
use Illuminate\Support\Facades\Route;

/*
| Clients — board screens super-admin-04 to 11.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
*/

Route::middleware('can:gemini.clients.view')->group(function () {
    Route::get('clients', [ClientController::class, 'index'])->name('gemini.clients');

    Route::get('clients/{tenant}', [ClientController::class, 'show'])->name('gemini.clients.show');
});
