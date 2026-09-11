<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Exports\Exporter;
use App\Services\Gemini\BillingOverview;
use App\Services\Gemini\InvoiceActions;
use App\Support\MoneyFormatter;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * Why a reader without the verb cannot use the invoice screen's controls.
     *
     * Resending an invoice sends a client an email; issuing a credit note posts
     * a financial record against their account. Both reach outside this console,
     * which is why both are `update` on Billing rather than a read.
     */
    private const NO_INVOICE_WRITE = 'Resending an invoice emails the client and a credit note changes what they owe, so both need Billing & subscriptions update access. You are able to read this invoice.';

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
    public function invoice(Request $request, BillingOverview $overview, InvoiceActions $actions, int $invoice): Response
    {
        $detail = $overview->invoice($invoice);

        abort_if($detail === null, 404);

        return inertia('Gemini/Billing/Invoice', [
            'invoice' => $detail,

            /*
             * The credit notes and the sends (12 §2, Wave 4). A raised invoice
             * is never edited, so what it is WORTH now is its total less its
             * credit notes — and both are shown, never one netted figure that
             * hides a correction. "Did they get it?" is the question a billing
             * conversation turns on, so the sends are here too.
             */
            ...$actions->creditsFor($invoice),
            'sends' => $actions->sendsFor($invoice),

            'canWrite' => $request->user()->can('gemini.billing_subscriptions.update'),
            'writeDisabledReason' => self::NO_INVOICE_WRITE,
        ]);
    }

    /**
     * Send the invoice to whoever holds the estate's account (12 §2, Wave 4).
     *
     * WHO IT GOES TO IS DERIVED. A free-text address on this screen would be
     * one nobody could check, and an invoice going to a typo is a conversation
     * about money that never happened.
     */
    public function resendInvoice(Request $request, int $invoice, InvoiceActions $actions): RedirectResponse
    {
        try {
            $addresses = $actions->resend($invoice, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['invoice' => $refused->getMessage()]);
        }

        return back()->with('success', 'Sent to '.implode(', ', $addresses).'. The send is on the record, so "did they get it?" has an answer.');
    }

    /** Issue a credit note against an invoice. The invoice itself is untouched. */
    public function creditNote(Request $request, int $invoice, InvoiceActions $actions): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:300'],
        ]);

        try {
            $note = $actions->creditNote($invoice, (string) $data['amount'], (string) $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['amount' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', $note['reference'].' issued for '.MoneyFormatter::fromMinor($note['amount_minor']).'. The invoice keeps its total — what it is worth now is that total less its credit notes.');
    }

    /**
     * The invoice as a PDF — board 35's "Download PDF".
     *
     * RENDERED ON DEMAND AND NOT STORED, which is the difference between this
     * and an estate's statement. An estate statement is a snapshot of a moving
     * balance and has to be kept as bytes; an invoice is immutable — its lines,
     * its total and its reference never change, and a credit note is a separate
     * record — so the PDF is reproducible from the row for as long as the row
     * exists. The row IS the retained record.
     *
     * It still writes the audit entry every export writes (12 §1).
     */
    public function invoicePdf(BillingOverview $overview, InvoiceActions $actions, int $invoice, Exporter $exporter): StreamedResponse
    {
        $detail = $overview->invoice($invoice);

        abort_if($detail === null, 404);

        $credits = $actions->creditsFor($invoice);

        $pdf = Pdf::loadView('documents.invoice', [
            'invoice' => $detail,
            'notes' => $credits['notes'],
            'creditedMinor' => $credits['credited_minor'],
        ])->setPaper('a4')->output();

        return $exporter->file(
            scope: 'Invoice '.$detail['reference'].' — '.$detail['client'],
            contents: $pdf,
            filename: $detail['reference'].'.pdf',
            contentType: 'application/pdf',
            rowCount: count($detail['lines']),
            tenants: [(string) $detail['client']],
        );
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
