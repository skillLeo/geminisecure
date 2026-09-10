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
});

/*
 * Taking on a client is a `create`, not an `update`. A role that may edit the
 * clients it already has is not thereby a role that may add one to the
 * platform's book.
 *
 * Registered BEFORE clients/{tenant}: "new" would otherwise be read as a
 * subdomain and 404 on a tenant nobody has created.
 */
Route::middleware('can:gemini.clients.create')->group(function () {
    Route::get('clients/new', [ClientController::class, 'create'])->name('gemini.clients.create');

    Route::post('clients/new', [ClientController::class, 'store'])->name('gemini.clients.store');
});

Route::middleware('can:gemini.clients.view')->group(function () {
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

    /*
     * Pricing a client. Both halves on `update`, because every panel on the
     * screen is an editing control and the client record next door already
     * shows the same facts to a reader who may not change them.
     */
    Route::get('clients/{tenant}/plan', [ClientController::class, 'plan'])
        ->name('gemini.clients.plan');

    Route::post('clients/{tenant}/plan', [ClientController::class, 'savePlan'])
        ->name('gemini.clients.plan.save');

    /*
     * Messaging a committee. On `update` because it reaches into the client's
     * own console and speaks on Gemini's behalf — a role that may only read a
     * client should not be able to tell them their guard has been suspended.
     */
    Route::get('clients/{tenant}/message', [ClientController::class, 'message'])
        ->name('gemini.clients.message');

    Route::post('clients/{tenant}/message', [ClientController::class, 'sendMessage'])
        ->name('gemini.clients.message.send');
});

/*
| There is no DELETE anywhere in this file, and that is deliberate.
|
| A client that leaves is `cancelled`, not removed: their invoices, their audit
| trail and the messages sent to them all refer to a tenant that has to keep
| existing. A subdomain is never reused either, so the row is the record that
| it was taken.
*/
