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
}
