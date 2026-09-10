<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\DispatchController;
use Illuminate\Support\Facades\Route;

/*
| Dispatch — board screens super-admin-12 to 17.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
*/

Route::middleware('can:gemini.dispatch.view')->group(function () {
    /*
     * The live map.
     *
     * A read, and only a read. Nothing on it is a position: the pins are posts
     * and the posts do not move, so there is no endpoint here that could be
     * asked where a guard is standing.
     */
    Route::get('dispatch/map', [DispatchController::class, 'map'])->name('gemini.dispatch.map');

    Route::get('dispatch/coverage', [DispatchController::class, 'coverage'])
        ->name('gemini.dispatch.coverage');

    /*
     * Guard alertness and patrol monitoring.
     *
     * A read, and only a read. The screen reports what a guard has already
     * proved — a checkpoint they reached, a challenge they answered — and there
     * is no endpoint here that returns, or could return, where anyone is
     * standing.
     */
    Route::get('dispatch/alertness', [DispatchController::class, 'alertness'])
        ->name('gemini.dispatch.alertness');

    /*
     * The requests inbox.
     *
     * Reading the queue is `view`; emptying it is not. See the update group
     * below — the split is the same one the alert screens make, and for the
     * same reason.
     */
    Route::get('dispatch/requests', [DispatchController::class, 'requests'])
        ->name('gemini.dispatch.requests');

    Route::get('dispatch/alerts', [DispatchController::class, 'alerts'])->name('gemini.dispatch');

    Route::get('dispatch/alerts/{alert}', [DispatchController::class, 'alert'])
        ->whereNumber('alert')
        ->name('gemini.dispatch.alert');

    /*
     * Acting on an alert needs `update`, not `view`.
     *
     * The Admin Assistant holds View on dispatch and can watch the queue all
     * day; they may not record that a guard is responding. Gating the writes
     * on the same permission as the read would have handed that to them
     * silently, and the console would have had to remember not to draw the
     * button.
     */
    Route::middleware('can:gemini.dispatch.update')->group(function () {
        Route::post('dispatch/alerts/{alert}/acknowledge', [DispatchController::class, 'acknowledge'])
            ->whereNumber('alert')
            ->name('gemini.dispatch.alert.acknowledge');

        Route::post('dispatch/alerts/{alert}/resolve', [DispatchController::class, 'resolve'])
            ->whereNumber('alert')
            ->name('gemini.dispatch.alert.resolve');

        /*
         * Deciding a request takes a guard off post for eight days. That is a
         * roster change, so it needs `update` — an Admin Assistant may watch
         * this queue all day and may not empty it.
         */
        Route::post('dispatch/requests/{guardRequest}', [DispatchController::class, 'decide'])
            ->whereNumber('guardRequest')
            ->name('gemini.dispatch.request.decide');
    });
});
