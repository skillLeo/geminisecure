<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Account;
use App\Models\Estate\BankReconciliation;
use App\Models\Estate\BankStatementLine;
use App\Models\Estate\Bill;
use App\Models\Estate\MaintenanceTicket;
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
    private const NO_CREATE_ACCESS = 'Adding a supplier or recording a bill changes what the estate owes and needs Accounting create access. You are able to read this screen.';

    private const NO_IMPORT_STATEMENT_YET = 'Not built yet — importing a statement means parsing a bank\'s own file format, and a mis-parsed line becomes a false match. The lines on screen were entered from the statement.';

    private const NO_RECEIPT_YET = 'Not built yet — a payment receipt is a document the supplier keeps, and needs a template and a retention rule before it needs a link.';

    /*
     * The work order behind a bill. Boards 17 and 18 are built, so on Bills &
     * payments it is a link for anybody holding Facilities view — which every
     * role that reads Accounting does today — and this sentence is only ever
     * the refusal for a role that does not. On a vendor's ledger it is a hover.
     */
    private const NO_TICKET_ACCESS = 'The work order behind this bill is on the Facilities module\'s maintenance screen, which needs Facilities view access. The ticket number is on the record, so the trace survives either way.';

    private const TICKET_NOTE = 'The work order behind this bill. It opens from Bills & payments, or from the maintenance queue under Facilities.';

    /** The supplier register — board community-admin-26. */
    public function vendors(Request $request, Payables $payables): Response
    {
        return inertia('Estate/Accounting/Vendors', [
            'estate' => ['name' => (string) tenant()->name],
            ...$payables->vendorsBoard(),
            'canCreate' => $request->user()->can('estate.accounting_posting.create'),
            'blockedReason' => self::NO_CREATE_ACCESS,
        ]);
    }

    /** One vendor's sub-ledger — board community-admin-39. */
    public function vendor(Request $request, Vendor $vendor, Payables $payables): Response
    {
        return inertia('Estate/Accounting/Vendor', [
            'estate' => ['name' => (string) tenant()->name],
            ...$payables->vendorBoard($vendor),
            ...$this->billForm($payables),
            'canCreate' => $request->user()->can('estate.accounting_posting.create'),
            'canEdit' => $request->user()->can('estate.accounting_posting.update'),
            'blockedReason' => self::NO_CREATE_ACCESS,
            'editBlockedReason' => 'Editing a supplier changes who the estate is allowed to pay, and needs Accounting update access. You are able to read this record.',
            'reasons' => [
                'ticket' => self::TICKET_NOTE,
            ],
        ]);
    }

    /**
     * Edit a supplier — board 27's second action (12 §2, Wave 2).
     *
     * The TRN rule is stated on the screen, because filling one in is what
     * unblocks payment and clearing one blocks it again. Deactivating is the
     * only way off the register; a supplier with an open bill cannot take it.
     */
    public function editVendor(Request $request, Vendor $vendor, Payables $payables): RedirectResponse
    {
        $fields = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            'trn' => ['nullable', 'string', 'max:20'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:160'],
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        try {
            $payables->editVendor($vendor, $fields);
        } catch (DomainException $refused) {
            return back()->withErrors(['name' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/accounting/vendors/'.$vendor->id))
            ->with('success', $vendor->name.' updated.'.($vendor->trn === null
                ? ' No TRN is on file, so bills can be recorded against them and payment will refuse until one is.'
                : ' TRN '.$vendor->trn.' is on file, so bills against them can be paid.'));
    }

    /** Bills & payments — board community-admin-27. */
    public function bills(Request $request, Payables $payables): Response
    {
        return inertia('Estate/Accounting/Bills', [
            'estate' => ['name' => (string) tenant()->name],
            ...$payables->billsBoard(),
            ...$this->billForm($payables),
            'canPay' => $request->user()->can('estate.accounting_posting.create'),
            'canApprove' => $request->user()->can('estate.accounting_posting.approve'),
            // The work order is board 18, behind the Facilities gate rather than
            // this module's — so whether it is a link is asked of that gate.
            'canOpenTicket' => $request->user()->can('estate.facilities.view'),
            'blockedReason' => 'Paying a bill moves money out of the estate\'s bank account and needs Accounting create access. You are able to read this screen.',
            'reasons' => [
                'receipt' => self::NO_RECEIPT_YET,
                'ticket' => self::NO_TICKET_ACCESS,
            ],
        ]);
    }

    /**
     * What recording a bill has to choose from: the register, the expense
     * accounts, and the open work orders (12 §2, Wave 1 — "expense account
     * and work order chosen deliberately"). Names and codes only; what a
     * vendor is owed is the board's own figure, not the form's.
     *
     * @return array<string, mixed>
     */
    private function billForm(Payables $payables): array
    {
        return [
            'billVendors' => Vendor::query()
                ->where('status', Vendor::ACTIVE)
                ->orderBy('name')
                ->get(['id', 'name', 'trn'])
                ->map(static fn (Vendor $vendor): array => ['id' => $vendor->id, 'name' => $vendor->name, 'has_trn' => $vendor->hasTrn()])
                ->all(),
            'expenseAccounts' => Account::query()
                ->where('type', Account::EXPENSE)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['code', 'name'])
                ->map(static fn (Account $account): array => ['code' => $account->code, 'label' => $account->code.' — '.$account->name])
                ->all(),
            'openTickets' => MaintenanceTicket::query()
                ->whereNull('closed_at')
                ->orderByDesc('number')
                ->limit(50)
                ->get(['number', 'title'])
                ->map(static fn (MaintenanceTicket $ticket): array => [
                    'number' => $ticket->number,
                    'label' => $ticket->label(),
                ])
                ->all(),
        ];
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

    /** Put a supplier on the register — board 26's "Add vendor". */
    public function addVendor(Request $request, Payables $payables): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'category' => ['nullable', 'string', 'max:64'],
            'trn' => ['nullable', 'string', 'max:24'],
            'contact_name' => ['nullable', 'string', 'max:160'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:190'],
        ]);

        try {
            $vendor = $payables->addVendor(
                name: $data['name'],
                category: $data['category'] ?? null,
                trn: $data['trn'] ?? null,
                contactName: $data['contact_name'] ?? null,
                contactPhone: $data['contact_phone'] ?? null,
                contactEmail: $data['contact_email'] ?? null,
            );
        } catch (DomainException $refused) {
            $field = str_contains($refused->getMessage(), 'TRN') ? 'trn' : 'name';

            return back()->withErrors([$field => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/accounting/vendors/'.$vendor->id))
            ->with('success', $vendor->name.' added to the register'.($vendor->hasTrn() ? '.' : ' — no TRN yet, so bills can be recorded and not paid until it arrives.'));
    }

    /**
     * Record an invoice — boards 27 and 39. A draft: no journal until it is
     * approved. The expense account and the work order are chosen here, as
     * ruled, and the vendor is chosen on board 27 or fixed on board 39.
     */
    public function recordBill(Request $request, Payables $payables): RedirectResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer'],
            'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'due_on' => ['required', 'date'],
            'account' => ['required', 'string', 'max:16'],
            'ticket_number' => ['nullable', 'integer'],
        ]);

        $vendor = Vendor::query()->findOrFail($data['vendor_id']);

        $ticket = isset($data['ticket_number'])
            ? MaintenanceTicket::query()->where('number', (int) $data['ticket_number'])->first()
            : null;

        if (isset($data['ticket_number']) && $ticket === null) {
            return back()->withErrors(['ticket_number' => 'No work order carries that number.'])->withInput();
        }

        try {
            $bill = $payables->recordBill(
                vendor: $vendor,
                amount: Money::of((string) $data['amount'], 'JMD'),
                description: $data['description'],
                dueOn: $data['due_on'],
                account: $data['account'],
                ticketId: $ticket?->number,
                ticketLabel: $ticket?->label(),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['amount' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path($request->boolean('from_vendor') ? '/accounting/vendors/'.$vendor->id : '/accounting/bills'))
            ->with('success', 'Bill '.$bill->reference.' recorded against '.$vendor->name.' as a draft. Approve it to post the liability.');
    }

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
