<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\GateEventController;
use App\Http\Controllers\Api\V1\PassVerificationController;
use App\Http\Controllers\Api\V1\ShiftController;
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

    /*
     * What the guard actually did, recorded after the scan gave its verdict.
     *
     * A SEPARATE CALL FROM `passes/verify` ON PURPOSE. A guard can be shown a
     * verdict and still not admit somebody — the visitor changes their mind, the
     * contractor has no paperwork, the car turns around — and a system that
     * logged the admission at the moment it answered the scan would fill the
     * gate log with arrivals that never happened. The verdict is advice; this is
     * the decision.
     *
     * Guard handsets only, and the same ability the scan needs: a resident's
     * token must not be able to write the estate's gate log.
     */
    Route::post('gate-events', [GateEventController::class, 'store'])
        ->middleware('ability:passes:verify')
        ->name('gate_events.store');

    /*
     * Clocking on and off a post.
     *
     * THE WRITER `shifts.actual_start` NEVER HAD. Post coverage, the dispatch
     * map and the Guard App adoption figure all read that column, so until this
     * existed every post on the live map was rostered and none was manned.
     *
     * Bound on the shift itself rather than taking a guard and a post, because
     * the roster is what says who is due where: a handset clocking in against a
     * shift that is not theirs is a different problem from one clocking in late,
     * and only the first is worth refusing.
     */
    Route::post('shifts/{shift}/clock-in', [ShiftController::class, 'clockIn'])
        ->whereNumber('shift')
        ->middleware('ability:shifts:clock')
        ->name('shifts.clock_in');

    Route::post('shifts/{shift}/clock-out', [ShiftController::class, 'clockOut'])
        ->whereNumber('shift')
        ->middleware('ability:shifts:clock')
        ->name('shifts.clock_out');
});
