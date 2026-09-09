<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\BillingController;
use Illuminate\Support\Facades\Route;

/*
| Billing and subscriptions — board screens super-admin-32 to 35.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
|
| Dunning and suspension gate BILLING features only. Nothing in this module
| may withhold entry, a pass or a safety function over money owed.
*/

Route::middleware('can:gemini.billing_subscriptions.view')->group(function () {
    Route::get('billing', [BillingController::class, 'index'])->name('gemini.billing_subscriptions');
});
