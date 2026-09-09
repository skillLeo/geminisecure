<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AlertController;
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

Route::prefix('v1')->name('api.v1.')->group(function () {
    /*
     * Alert intake is deliberately unauthenticated for now.
     *
     * Device enrolment and token issue are Phase 3 work. Leaving this open
     * until then is a KNOWN GAP, recorded so it cannot be forgotten: a panic
     * endpoint that anyone can post to is a denial-of-service surface, and it
     * must be behind auth:sanctum with a device-scoped token before anything
     * ships. Safety functions must never depend on connectivity or consent,
     * but they do depend on knowing which device is speaking.
     *
     * TODO(Phase 3): ->middleware('auth:sanctum')
     */
    Route::post('alerts', [AlertController::class, 'store'])->name('alerts.store');
});
