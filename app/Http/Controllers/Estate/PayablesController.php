<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\BankReconciliation;
use App\Models\Estate\BankStatementLine;
use App\Models\Estate\Bill;
use App\Models\Estate\Vendor;
use App\Services\Estate\Payables;
use Brick\Money\Money;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Vendors, bills and the bank statement — board screens 26, 27, 28 and 39.
 *
 * EVERY FIGURE ON THESE SCREENS COMES FROM POSTED JOURNAL LINES. Total payable
 * is the balance of 2000 Accounts Payable, a vendor's "currently owed" is that
 * vendor's own lines on the same account, and what one bill still owes is the
 * balance of the entries raised for it. Nothing is summed from the `bills` table
 * — a total taken from the billing records would agree with the accounts by
 * coincidence, and `EstatePayablesTest` re-sums each of these in raw SQL to
 * prove they are the same rows read two ways.
 *
 * APPROVING A BILL AND SIGNING OFF A RECONCILIATION NEED `approve`, NOT `create`.
 * Both are the moment a figure stops being a proposal: an approved bill is money
 * the estate owes, and a completed reconciliation is a period nobody re-opens.
 * D-013 separated the verb from `update` for exactly this — a role may prepare an
 * irreversible act without being able to commit it. The route file carries the
 * gates; a controller that checked them again would be a second place to keep in
 * step with the matrix.
 */
class PayablesController extends Controller
{
    /**
     * Why the controls on these screens that do nothing, do nothing.
     *
     * Each is a real act with a consequence outside the screen it is drawn on,
     * and each needs a form, a document or a table this phase has not built.
     */
    private const NO_ADD_VENDOR_YET = 'Not built yet — a vendor becomes payable the moment it exists, so adding one needs the TRN, the trade and the contact captured together rather than a blank row.';

    private const NO_EDIT_VENDOR_YET = 'Not built yet — editing a vendor changes who the estate is allowed to pay, and needs its own screen with the TRN rule stated on it.';

    private const NO_RECORD_BILL_YET = 'Not built yet — recording a bill needs the expense account and the work order chosen deliberately, which is a form and not a button.';

    private const NO_IMPORT_STATEMENT_YET = 'Not built yet — importing a statement means parsing a bank\'s own file format, and a mis-parsed line becomes a false match. The lines on screen were entered from the statement.';

    private const NO_RECEIPT_YET = 'Not built yet — a payment receipt is a document the supplier keeps, and needs a template and a retention rule before it needs a link.';

    private const NO_TICKET_YET = 'Not built yet — the work order behind this bill is board 17. The ticket number is on the record so the trace survives until that screen exists.';

    /** The supplier register — board community-admin-26. */
    public function vendors(Request $request, Payables $payables): Response
    {
        return inertia('Estate/Accounting/Vendors', [
            'estate' => ['name' => (string) tenant()->name],
            ...$payables->vendorsBoard(),
            'reasons' => [
                'add' => self::NO_ADD_VENDOR_YET,
            ],
        ]);
    }

    /** One vendor's sub-ledger — board community-admin-39. */
    public function vendor(Request $request, Vendor $vendor, Payables $payables): Response
    {
        return inertia('Estate/Accounting/Vendor', [
            'estate' => ['name' => (string) tenant()->name],
            ...$payables->vendorBoard($vendor),
            'reasons' => [
                'bill' => self::NO_RECORD_BILL_YET,
                'edit' => self::NO_EDIT_VENDOR_YET,
                'ticket' => self::NO_TICKET_YET,
            ],
        ]);
    }

    /** Bills & payments — board community-admin-27. */
    public function bills(Request $request, Payables $payables): Response
    {
        return inertia('Estate/Accounting/Bills', [
            'estate' => ['name' => (string) tenant()->name],
            ...$payables->billsBoard(),
            'canPay' => $request->user()->can('estate.accounting_posting.create'),
            'canApprove' => $request->user()->can('estate.accounting_posting.approve'),
            'blockedReason' => 'Paying a bill moves money out of the estate\'s bank account and needs Accounting create access. You are able to read this screen.',
            'reasons' => [
                'record' => self::NO_RECORD_BILL_YET,
                'receipt' => self::NO_RECEIPT_YET,
                'ticket' => self::NO_TICKET_YET,
            ],
        ]);
    }

    /** Bank reconciliation — board community-admin-28. */
    public function reconciliation(Request $request, Payables $payables): Response
    {
        return inertia('Estate/Accounting/Reconciliation', [
            'estate' => ['name' => (string) tenant()->name],
            ...$payables->reconciliationBoard(),
            'canMatch' => $request->user()->can('estate.accounting_posting.create'),
            'canComplete' => $request->user()->can('estate.accounting_posting.approve'),
            'blockedReason' => 'Matching a bank line is an assertion about the estate\'s accounts and needs Accounting create access. You are able to read this screen.',
            'reasons' => [
                'import' => self::NO_IMPORT_STATEMENT_YET,
            ],

            /*
             * The scope banner's copy, verbatim from board 28. It is a promise
             * about this release rather than a description of the screen, so it
             * lives with the release and not in the page.
             */
            'scope' => [
                'title' => 'Manual matching in this release',
                'body' => 'Match each bank line to a GL transaction yourself for now — automated matching is planned for a future release, not this phase.',
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* the acts */
    /* ------------------------------------------------------------------ */

    /** Agree a bill, and post the liability. */
    public function approveBill(Request $request, Bill $bill, Payables $payables): RedirectResponse
    {
        try {
            $payables->approve($bill, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['bill' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/accounting/bills'))
            ->with('success', 'Bill '.$bill->reference.' approved.');
    }

    /** Pay one, and post the entry that moves the money. */
    public function payBill(Request $request, Bill $bill, Payables $payables): RedirectResponse
    {
        $data = $request->validate([
            /*
             * A decimal string, never a float. `Money::of` refuses a value it
             * cannot represent exactly, which is why the amount arrives as a
             * string and is not cast on the way in.
             */
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'string', 'in:cheque,bank,card,cash'],
            'paid_on' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:64'],
            'overpayment_reason' => ['nullable', 'string', 'max:190'],
        ]);

        try {
            $payables->pay(
                bill: $bill,
                amount: Money::of((string) $data['amount'], 'JMD'),
                method: $data['method'],
                paidOn: $data['paid_on'],
                by: $request->user(),
                reference: $data['reference'] ?? null,
                overpaymentReason: $data['overpayment_reason'] ?? null,
            );
        } catch (DomainException $refused) {
            /*
             * Against `amount`, because that is the field the person can change
             * — and where they cannot (a missing TRN), the message says so
             * rather than leaving them retrying a number that was never the
             * problem.
             */
            return back()->withErrors(['amount' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/accounting/bills'))
            ->with('success', 'Payment recorded against '.$bill->reference.'.');
    }

    /** Claim that a bank line and a posted entry are the same event. */
    public function matchLine(Request $request, BankStatementLine $line, Payables $payables): RedirectResponse
    {
        $data = $request->validate([
            'entry_ref' => ['required', 'string', 'max:40'],
        ]);

        try {
            $payables->match($line, $data['entry_ref']);
        } catch (DomainException $refused) {
            return back()->withErrors(['entry_ref' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/accounting/reconciliation'))
            ->with('success', 'Matched to '.$data['entry_ref'].'.');
    }

    /** Take the claim back. */
    public function unmatchLine(Request $request, BankStatementLine $line, Payables $payables): RedirectResponse
    {
        try {
            $payables->unmatch($line);
        } catch (DomainException $refused) {
            return back()->withErrors(['line' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/accounting/reconciliation'))
            ->with('success', 'Match removed.');
    }

    /** Sign the period off — refused while a difference remains. */
    public function completeReconciliation(
        Request $request,
        BankReconciliation $reconciliation,
        Payables $payables,
    ): RedirectResponse {
        try {
            $payables->complete($reconciliation, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reconciliation' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/accounting/reconciliation'))
            ->with('success', $reconciliation->periodLabel().' reconciliation completed.');
    }

    /**
     * Where a screen lives, in whichever shape this environment serves.
     *
     * Production gives each estate its own hostname; local serves them all from
     * one host with the estate in the path. See routes/tenant.php.
     */
    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
