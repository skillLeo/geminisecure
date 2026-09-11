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

    /*
     * The PDF is a READ of an immutable record, so it sits with the other
     * reads: an invoice's lines, total and reference never change, and a
     * correction is a credit note of its own. It still writes the entry every
     * export writes (12 §1).
     */
    Route::get('billing/invoices/{invoice}/pdf', [BillingController::class, 'invoicePdf'])
        ->whereNumber('invoice')
        ->name('gemini.billing_subscriptions.invoice.pdf');
});

/*
| The two writes (12 §2, Wave 4). Both reach outside this console — one sends a
| client an email, the other changes what they owe — so both are `update` and
| both are audited. Neither edits the invoice: a raised invoice is never edited,
| and a correction is a credit note, which is a new posted record of its own.
*/
Route::middleware('can:gemini.billing_subscriptions.update')->group(function () {
    Route::post('billing/invoices/{invoice}/resend', [BillingController::class, 'resendInvoice'])
        ->whereNumber('invoice')
        ->name('gemini.billing_subscriptions.invoice.resend');

    Route::post('billing/invoices/{invoice}/credit-note', [BillingController::class, 'creditNote'])
        ->whereNumber('invoice')
        ->name('gemini.billing_subscriptions.invoice.credit_note');
});

/*
| No POST, PATCH or DELETE, and the absence is the enforcement.
|
| A raised invoice is a posted record. There is no edit route and no delete
| route, not even a guarded one — a correction is a credit note, which is a new
| posted record of its own. A route that exists but is gated is one refactor
| away from being reachable.
*/
