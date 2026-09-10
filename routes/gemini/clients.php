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

/*
 * The management screens, each on `update` rather than `view`.
 *
 * Both the screen and the write it launches, deliberately. These boards are not
 * reports with an action bolted on: every panel on them is an editing control,
 * and a role that may only read a client has the client record next door which
 * shows the same facts. Opening a roster editor to someone who cannot save it
 * would be a screenful of controls that all end in a 403.
 */
Route::middleware('can:gemini.clients.update')->group(function () {
    Route::get('clients/{tenant}/guards', [ClientController::class, 'guards'])
        ->name('gemini.clients.guards');

    Route::post('clients/{tenant}/guards', [ClientController::class, 'saveGuards'])
        ->name('gemini.clients.guards.save');
});
