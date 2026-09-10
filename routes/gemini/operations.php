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
| Every one of them is a read. There is no write route in this file and no
| placeholder for one: the screens that would create a shift, revise an order
| set or file an incident are not built, and the controls that would reach them
| are rendered visibly inert instead of pointing at a route that answers 404.
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
});
