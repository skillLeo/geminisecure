<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\PayrollController;
use Illuminate\Support\Facades\Route;

/*
| Payroll and accounting — board screens super-admin-28 to 31.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
|
| D-021 stands: the 2026-04 statutory rates are seeded as DRAFT and approval
| is deliberately blocked pending a client ruling. There is no approve route,
| and adding one is not a decision this file gets to make.
*/

Route::middleware('can:gemini.payroll_accounting.view')->group(function () {
    Route::get('payroll', [PayrollController::class, 'index'])->name('gemini.payroll_accounting');

    Route::get('payroll/{run}', [PayrollController::class, 'show'])
        ->whereNumber('run')
        ->name('gemini.payroll_accounting.show');
});
