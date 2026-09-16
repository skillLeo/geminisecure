<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\OperationsController;
use Illuminate\Support\Facades\Route;

/*
| Security operations — board screens super-admin-24 to 27.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
|
| These four screens sit in the Guard workforce module and share its permission,
| but they are not records about one guard: they are the operational picture
| ACROSS clients — who is standing which post this week, which standing orders
| are in force, what the posts are reporting, and what has gone wrong. That is
| why they have a controller of their own rather than living beside the guard
| directory and the compliance queue.
|
| THE ROSTER HAS TWO WRITES (12 §2, Wave 5) and they are `update`, because
| putting a client's gate on the rota is not reading it. An OPEN shift is a real
| row with no officer on it: a post needing somebody is a fact this console has
| to hold, and a placeholder guard would show an empty gate as manned.
|
| THE INCIDENT LOG HAS TWO WRITES (12 §2, item 27): a structured intake, and a
| resolution that closes an incident for good.
*/

Route::middleware('can:gemini.guard_workforce.view')->group(function () {
    /*
     * Literal segments, all of them. `guards/{guard}` in routes/gemini/guards.php
     * is constrained to a number, so none of these can be read as a guard id —
     * but they are registered here as their own paths rather than relying on
     * that constraint alone.
     */
    Route::get('guards/roster', [OperationsController::class, 'roster'])
        ->name('gemini.guard_workforce.roster');

    Route::get('guards/standing-orders', [OperationsController::class, 'standingOrders'])
        ->name('gemini.guard_workforce.standing_orders');

    Route::get('guards/activity', [OperationsController::class, 'gateActivity'])
        ->name('gemini.guard_workforce.gate_activity');

    Route::get('guards/incidents', [OperationsController::class, 'incidents'])
        ->name('gemini.guard_workforce.incidents');

    // One order set, every version, every acknowledgement (12 §2, item 28).
    Route::get('guards/standing-orders/{set}', [OperationsController::class, 'standingOrder'])
        ->whereNumber('set')
        ->name('gemini.guard_workforce.standing_order');

    // One incident (12 §2, item 27). 404 outside the viewer's scope.
    Route::get('guards/incidents/{incident}', [OperationsController::class, 'incident'])
        ->whereNumber('incident')
        ->name('gemini.guard_workforce.incident');
});

/*
 * Standing orders (12 §2, item 28). Publishing a set is `create`; publishing
 * its next version, or recording a review, is `update`. A guard acknowledges
 * from the handset, over /api/v1, never from here.
 */
Route::post('guards/standing-orders', [OperationsController::class, 'createOrders'])
    ->middleware('can:gemini.guard_workforce.create')
    ->name('gemini.guard_workforce.standing_orders.create');

Route::post('guards/standing-orders/{set}/revise', [OperationsController::class, 'reviseOrders'])
    ->whereNumber('set')
    ->middleware('can:gemini.guard_workforce.update')
    ->name('gemini.guard_workforce.standing_orders.revise');

Route::post('guards/standing-orders/{set}/reviewed', [OperationsController::class, 'reviewOrders'])
    ->whereNumber('set')
    ->middleware('can:gemini.guard_workforce.update')
    ->name('gemini.guard_workforce.standing_orders.reviewed');

/*
 * Logging an incident brings an evidence record into existence: `create`.
 * Closing one records what was done about it: `update`.
 */
Route::post('guards/incidents', [OperationsController::class, 'logIncident'])
    ->middleware('can:gemini.guard_workforce.create')
    ->name('gemini.guard_workforce.incident.log');

Route::post('guards/incidents/{incident}/resolve', [OperationsController::class, 'resolveIncident'])
    ->whereNumber('incident')
    ->middleware('can:gemini.guard_workforce.update')
    ->name('gemini.guard_workforce.incident.resolve');

/*
 * `guard_workforce.update`, the same module these screens read under — there is
 * no separate operations module in the matrix, and inventing one here would be
 * a permission the role screen does not draw.
 */
Route::middleware('can:gemini.guard_workforce.update')->group(function () {
    Route::post('guards/roster/shifts', [OperationsController::class, 'postShift'])
        ->name('gemini.guard_workforce.roster.post');

    Route::post('guards/roster/shifts/{shift}/assign', [OperationsController::class, 'assignShift'])
        ->whereNumber('shift')
        ->name('gemini.guard_workforce.roster.assign');
});
