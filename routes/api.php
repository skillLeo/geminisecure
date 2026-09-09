<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\PassVerificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| /api/v1 - the mobile client API
|--------------------------------------------------------------------------
|
| Consumed by the Guard App and the Resident App, and by the event simulator,
| which calls these same endpoints so that nothing built against simulated
| data has to be rewritten when the real apps arrive.
|
| Every endpoint here delegates to the same services the Inertia controllers
| use. Business rules live in services precisely so the web console and the
| mobile clients cannot drift into different answers.
|
| INVARIANT 2: no endpoint reachable by a guard may return a monetary amount -
| not a balance, not an ageing bucket, not a payment history. A guard receives
| access_restricted as a boolean and nothing more. This is enforced here, at
| the API layer, and not by omitting the figure from a screen.
|
*/

Route::prefix('v1')->name('api.v1.')->middleware('auth:sanctum')->group(function () {
    /*
     * Alert intake.
     *
     * Behind a token, and behind a device-scoped ability. This endpoint was
     * briefly open while device enrolment was unwritten, which made a
     * life-safety path into a denial-of-service surface: anyone who could
     * reach the host could flood the dispatch queue, and a real panic would
     * have arrived in a queue full of noise.
     *
     * Safety functions must never depend on connectivity or consent. They do
     * depend on knowing which device is speaking — an alert whose source
     * cannot be identified cannot be dispatched to anyone.
     *
     * Either app may raise one: a guard's duress button and a resident's panic
     * button are the same event to dispatch, so 'ability' (any of) rather than
     * 'abilities' (all of).
     */
    Route::post('alerts', [AlertController::class, 'store'])
        ->middleware('ability:alerts:raise')
        ->name('alerts.store');

    /*
     * Scan verdict. Returns admit / restricted / deny and NEVER an amount.
     * Runs inside tenancy, so the household is read from that estate's own
     * database and no other.
     *
     * Guard handsets only. A resident's token must not be able to ask the
     * system to adjudicate arrivals at the gate.
     */
    Route::post('passes/verify', [PassVerificationController::class, 'verify'])
        ->middleware('ability:passes:verify')
        ->name('passes.verify');
});
