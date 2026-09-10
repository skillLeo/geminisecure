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

    Route::get('billing/plans', [BillingController::class, 'plans'])
        ->name('gemini.billing_subscriptions.plans');

    Route::get('billing/payment-methods', [BillingController::class, 'paymentMethods'])
        ->name('gemini.billing_subscriptions.payment_methods');

    // After the literals. `whereNumber` already keeps them apart, but ordering
    // the literals first means that constraint never becomes load-bearing.
    Route::get('billing/invoices/{invoice}', [BillingController::class, 'invoice'])
        ->whereNumber('invoice')
        ->name('gemini.billing_subscriptions.invoice');
});

/*
| No POST, PATCH or DELETE, and the absence is the enforcement.
|
| A raised invoice is a posted record. There is no edit route and no delete
| route, not even a guarded one — a correction is a credit note, which is a new
| posted record of its own. A route that exists but is gated is one refactor
| away from being reachable.
*/
