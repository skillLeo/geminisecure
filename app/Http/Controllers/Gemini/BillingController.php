<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Gemini\BillingOverview;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Billing and subscriptions — Super Admin board screen 32.
 *
 * Gemini Security billing its client estates. Resident dues are a different
 * ledger in a different database and never appear here.
 *
 * Thin by design: every figure comes from BillingOverview, so the /api/v1
 * endpoints can serve the same numbers without either surface reimplementing
 * the money rules.
 */
class BillingController extends Controller
{
    /**
     * Why the invoice screen's own controls are inert.
     *
     * Resending an invoice sends a client an email; issuing a credit note
     * posts a financial record against their account. Both are real acts with
     * consequences outside this console, and neither is built. A control that
     * looks live and does nothing on a screen about money is worse here than
     * anywhere else on the platform.
     */
    private const NO_INVOICE_WRITE = 'Not built yet — resending an invoice emails the client, and a credit note posts a financial record. Both need an approval path first.';

    /**
     * A tier price is not this screen's to change.
     *
     * Editing one re-prices every estate on that tier from an effective date.
     * It belongs to the package builder's approval path, not to the rate card
     * that displays it.
     */
    private const NO_PLAN_WRITE = 'Not editable here — changing a tier price re-prices every client on it, which needs an effective date and an approval path.';

    /**
     * D-023: manual recording is the day-one path.
     *
     * Card capture sits behind a PaymentGateway interface with a null
     * implementation, so there is nothing to capture into. Offering to add one
     * would be offering a capability the platform does not have.
     */
    private const NO_PAYMENT_METHOD_WRITE = 'Not built yet — card capture is behind a payment gateway that has no implementation. Settlement details are recorded manually for now.';

    public function index(Request $request, BillingOverview $overview): Response
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        // The search runs here, against the database, not in the browser
        // against a page of rows already sent: a term that matches an invoice
        // from two years ago has to be able to find it.
        $search = trim($request->string('q')->toString());

        return inertia('Gemini/Billing/Index', [
            'kpis' => $overview->kpis(),
            'rows' => $overview->invoiceRows($search),
            'search' => $search,
        ]);
    }

    /**
     * One invoice — board screen super-admin-33.
     *
     * A posted record. There is no update or delete route behind this screen
     * and there must never be one: a correction to a raised invoice is a
     * credit note, which is a new posted record of its own.
     *
     * 404 rather than an empty screen for an id that does not exist. An
     * invoice reference is the kind of thing people paste from an email, and a
     * page that renders blank for a wrong one tells the reader their invoice
     * was deleted.
     */
    public function invoice(BillingOverview $overview, int $invoice): Response
    {
        $detail = $overview->invoice($invoice);

        abort_if($detail === null, 404);

        return inertia('Gemini/Billing/Invoice', [
            'invoice' => $detail,
            'writeDisabledReason' => self::NO_INVOICE_WRITE,
        ]);
    }

    /**
     * The subscription tiers — board screen super-admin-34.
     *
     * A read. Editing a tier's price re-prices every estate on it, which is a
     * privileged, audited write with an effective date and belongs to the
     * package builder's own approval path rather than to the screen that
     * displays the rate card.
     */
    public function plans(BillingOverview $overview): Response
    {
        return inertia('Gemini/Billing/Plans', [
            ...$overview->plans(),
            'writeDisabledReason' => self::NO_PLAN_WRITE,
        ]);
    }

    /**
     * Every client's settlement instrument — board screen super-admin-35.
     *
     * D-023: manual recording is the day-one path and card capture sits behind
     * a PaymentGateway interface with a null implementation. So the row
     * controls are inert and say so — a screen that offered to add a card
     * would be offering a capability the platform does not have.
     */
    public function paymentMethods(BillingOverview $overview): Response
    {
        return inertia('Gemini/Billing/PaymentMethods', [
            'rows' => $overview->paymentMethods(),
            'writeDisabledReason' => self::NO_PAYMENT_METHOD_WRITE,
        ]);
    }
}
