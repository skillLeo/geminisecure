<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\GuardController;
use Illuminate\Support\Facades\Route;

/*
| Guard workforce — board screens super-admin-18 to 27.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
*/

Route::middleware('can:gemini.guard_workforce.view')->group(function () {
    Route::get('guards', [GuardController::class, 'index'])->name('gemini.guard_workforce');

    // Before the {guard} route: "compliance" is not a number, but keeping the
    // literal first means it can never be read as one.
    Route::get('guards/compliance', [GuardController::class, 'compliance'])
        ->name('gemini.guard_workforce.compliance');

    Route::get('guards/{guard}', [GuardController::class, 'show'])
        ->whereNumber('guard')
        ->name('gemini.guard_workforce.show');

    // A read, on `view`, even though the screen exists to launch a write. The
    // register offers "Take action" to every role that may read it, and a link
    // that 403s the reader who followed it is a dead end the screen invited
    // them into. The write below carries the stronger verb instead.
    Route::get('guards/{guard}/compliance', [GuardController::class, 'complianceAction'])
        ->whereNumber('guard')
        ->name('gemini.guard_workforce.compliance_action');
});

/*
 * The two writes, each a verb stronger than the screen that launches it.
 *
 * Suspending an officer changes an employment record and appends to the audit
 * log; adding one creates an employee. Neither belongs to a role that may only
 * read the roster, and neither is reachable by GET.
 */
Route::middleware('can:gemini.guard_workforce.update')->group(function () {
    Route::post('guards/{guard}/suspend', [GuardController::class, 'suspend'])
        ->whereNumber('guard')
        ->name('gemini.guard_workforce.suspend');
});
