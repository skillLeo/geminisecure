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
});
