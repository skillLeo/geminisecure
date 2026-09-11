<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Account;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitCollectionFlag;
use App\Services\Documents\Documents;
use App\Services\Estate\Collections;
use App\Services\Estate\Dues;
use App\Services\Estate\Receipts;
use App\Services\Exports\Exporter;
use App\Support\MoneyFormatter;
use Brick\Money\Money;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dues & ledger — board screens community-admin-05, 06 and 35.
 *
 * EVERY FIGURE ON THESE SCREENS COMES FROM POSTED JOURNAL LINES. Not one is
 * read from `charges` or `payments`: those tables hold the billing — a due
 * date, a receipt number, a method — and the ledger holds the money. A balance
 * summed from the billing records would agree with the accounts by coincidence,
 * and coincidence is what fails at an audit. `EstateArrearsTest` re-sums every
 * total below in raw SQL to prove it.
 *
 * POSTING A CHARGE NEEDS `create`, NOT `view`. A committee member may read the
 * arrears all day and may not add to what a household owes; the Property
 * Manager holds neither, by platform invariant rather than estate preference.
 */
class DuesController extends Controller
{
    /**
     * Why the collection controls that are still inert are inert.
     *
     * Boards 7 and 8 are built, so the ledger's plan and dunning controls are
     * links now and these two sentences no longer say "board 7" and "board 8"
     * about screens that exist. What stays inert is what is genuinely missing:
     * a register of every plan, and a last-reminder date on the arrears list.
     */
    private const NO_PLAN_REGISTER_YET = 'Not built yet — a register of every payment plan in the estate. Each household\'s plan is its own screen, board 7, and opens from that unit\'s ledger under "Place on payment plan".';

    private const NO_LAST_REMINDER_YET = 'Not carried onto this list yet. Every notice sent is logged verbatim on Dunning & reminders, board 8, which is where the last one sent to this household can be read.';

    private const NO_RESTRICT_YET = 'Not built yet — restriction stops guest passes at a gate. It is never applied from a list screen without the household in front of you.';

    /** Arrears command centre — board community-admin-05. */
    public function arrears(Request $request, Dues $dues): Response
    {
        return inertia('Estate/Dues/Arrears', [
            'estate' => ['name' => (string) tenant()->name],
            ...$dues->arrearsBoard($request->string('phase')->toString(), $request->boolean('overdue')),
            'canCharge' => $request->user()->can('estate.dues_ledger.create'),

            /*
             * EXPORTING IS ITS OWN VERB (12 §1). A file naming who owes what
             * leaves the estate and this system cannot recall it — what it can
             * do, and does, is record that it left, who took it and what was in
             * it. That entry is the trail, so the control is live for whoever
             * holds `export` and says so to everybody else.
             */
            'canExport' => $request->user()->can('estate.dues_ledger.export'),
            'exportBlockedReason' => 'An export leaves this estate as a file naming who owes what, so it needs Dues & ledger export access. You are able to read this screen.',
            'reasons' => [
                'plan' => self::NO_PLAN_REGISTER_YET,
                'dunning' => self::NO_LAST_REMINDER_YET,
                'restrict' => self::NO_RESTRICT_YET,
            ],
        ]);
    }

    /**
     * The arrears register as a file — board 5's Export (12 §1).
     *
     * The filters travel with it, because a file that says "arrears" and holds
     * one phase is a file somebody will read as the estate's whole position.
     * The scope recorded in the audit entry says which.
     */
    public function exportArrears(Request $request, Dues $dues, Exporter $exporter): StreamedResponse
    {
        $phase = $request->string('phase')->toString();
        $overdue = $request->boolean('overdue');
        $board = $dues->arrearsBoard($phase, $overdue);

        $rows = array_map(static fn (array $row): array => [
            $row['unit'],
            $row['household'],
            $row['resident'],
            $row['bucket_label'],
            number_format($row['balance_minor'] / 100, 2, '.', ''),
            $row['last_payment'] ?? '',
        ], $board['rows']);

        $scope = 'Arrears register, '.($phase === '' ? 'all phases' : $phase).($overdue ? ', 90+ days only' : '');

        return $exporter->csv(
            scope: $scope,
            headers: ['Unit', 'Household', 'Primary resident', 'Ageing', 'Balance JMD', 'Last payment'],
            rows: $rows,
            filename: 'arrears-'.now()->format('Y-m-d').'.csv',
        );
    }

    /** The receipt register as a file — board 6's receipts tab (12 §1). */
    public function exportReceipts(Request $request, Receipts $receipts, Exporter $exporter): StreamedResponse
    {
        // The whole sequence, not the register screen's recent page: a receipt
        // export that stopped at 200 would be a file the office reconciles
        // against and finds short, with nothing on it saying it was cut.
        $register = $receipts->register(limit: PHP_INT_MAX);

        $rows = array_map(static fn (array $row): array => [
            $row['receipt_no'],
            $row['unit'],
            $row['received_on'],
            $row['method_label'],
            number_format($row['amount_minor'] / 100, 2, '.', ''),
            $row['reference'] ?? '',
        ], $register['rows']);

        return $exporter->csv(
            scope: 'Receipt register, '.$receipts->prefix().' sequence, every receipt issued',
            headers: ['Receipt', 'Unit', 'Received', 'Method', 'Amount JMD', 'Reference'],
            rows: $rows,
            filename: 'receipts-'.now()->format('Y-m-d').'.csv',
        );
    }

    /**
     * One unit's ledger — board community-admin-06.
     *
     * Its plan and dunning controls are links to boards 7 and 8 now, behind the
     * same `dues_ledger.view` gate as this screen, so they need no reason here.
     */
    public function unit(Request $request, Unit $unit, Dues $dues, Collections $collections, Documents $documents): Response
    {
        $flag = $collections->flagFor($unit);

        return inertia('Estate/Dues/UnitLedger', [
            'estate' => ['name' => (string) tenant()->name],
            ...$dues->unitBoard($unit),
            'canRecord' => $request->user()->can('estate.payments.create'),

            /*
             * FLAGGING IS THE COMMITTEE'S ACT (12 §1). It carries a minute
             * reference, so it is `approve` on Dues & ledger and not the
             * office's `create` — the office prepares the case, the committee
             * agrees to stop chasing.
             */
            'canFlag' => $request->user()->can('estate.dues_ledger.approve'),
            'flagBlockedReason' => 'A hardship or dispute flag records a committee decision to stop chasing a household, so it needs Dues & ledger approval. You are able to read this ledger.',
            'flagKinds' => UnitCollectionFlag::KINDS,

            /*
             * The statement, and any already issued for this unit (12 §1).
             * Server-rendered and queued, so what the screen shows is the row
             * and its status rather than a file that may not exist yet.
             */
            'documents' => $documents->forSubject('unit', (string) $unit->id),
            'flag' => $flag === null ? null : [
                'id' => $flag->id,
                'kind' => $flag->kind,
                'kind_label' => $flag->kindLabel(),
                'headline' => $flag->headline(),
                'reason' => $flag->reason,
                'minute_reference' => $flag->minute_reference,
                'raised_by' => $flag->raised_by_name,
                'raised_at' => $flag->raised_at->format('M j, Y'),
            ],
        ]);
    }

    /**
     * Flag a household for hardship or dispute — board 6 (12 §1).
     *
     * THE DEBT DOES NOT MOVE. No journal is raised here and none is reversed:
     * the balance, the ageing strip and the arrears board are exactly what they
     * were, because the household still owes what it owes. What stops is the
     * estate's automated chasing.
     */
    public function flagUnit(Request $request, Unit $unit, Collections $collections): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', array_keys(UnitCollectionFlag::KINDS))],
            'reason' => ['required', 'string', 'max:500'],
            'minute_reference' => ['required', 'string', 'max:80'],
        ]);

        try {
            $collections->flag($unit, $data['kind'], $data['reason'], $data['minute_reference'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->unitPath($unit))
            ->with('success', $unit->reference.' is flagged under '.$data['minute_reference'].'. Automated reminders stop; the balance and the ageing are unchanged, and a notice can still be sent deliberately.');
    }

    /** Lift a flag. The row stays — a flag is an episode, and it keeps its end. */
    public function liftFlag(Request $request, Unit $unit, UnitCollectionFlag $flag, Collections $collections): RedirectResponse
    {
        $data = $request->validate([
            'lifted_reason' => ['nullable', 'string', 'max:500'],
        ]);

        if ($flag->unit_id !== $unit->id) {
            return back()->withErrors(['lifted_reason' => 'That flag belongs to another unit.']);
        }

        try {
            $collections->liftFlag($flag, (string) ($data['lifted_reason'] ?? ''), $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['lifted_reason' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->unitPath($unit))
            ->with('success', 'The flag on '.$unit->reference.' is lifted. Automated reminders resume from the next run.');
    }

    /** Post a charge — board community-admin-35. */
    public function newCharge(Request $request, Dues $dues): Response
    {
        $unit = $request->filled('unit')
            ? Unit::where('reference', $request->string('unit')->toString())->first()
            : Unit::query()->orderBy('id')->first();

        return inertia('Estate/Dues/NewCharge', [
            'estate' => ['name' => (string) tenant()->name],
            'unit' => $unit === null ? null : $dues->unitHeader($unit),
            'currentBalanceMinor' => $unit === null ? 0 : $dues->balanceOf($unit)->getMinorAmount()->toInt(),
            'accounts' => Account::query()
                ->where('type', Account::INCOME)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['code', 'name'])
                ->map(fn (Account $a): array => ['code' => $a->code, 'label' => $a->code.' — '.$a->name])
                ->all(),
            'types' => [
                ['value' => 'dues', 'label' => 'Maintenance fee'],
                ['value' => 'special_assessment', 'label' => 'Special assessment'],
                ['value' => 'fine', 'label' => 'Fine'],
                ['value' => 'amenity', 'label' => 'Amenity fee'],
            ],
            'canPost' => $request->user()->can('estate.dues_ledger.create'),
            'blockedReason' => 'Posting a charge adds to what a household owes and needs Dues & ledger create access. You are able to read this screen.',

            // The phases a whole-phase charge may reach, off the register.
            'phases' => Unit::query()
                ->whereNotNull('block')
                ->distinct()
                ->orderBy('block')
                ->pluck('block')
                ->all(),

            /*
             * The preview a bulk charge was taken against, held in the session
             * rather than re-derived: the list the treasurer agreed to is the
             * list that posts, and a register that changed between the two
             * presses refuses rather than silently billing a different set.
             */
            'bulkPreview' => $request->filled('preview')
                ? $request->session()->get('charge-bulk.'.$request->string('preview')->toString())
                : null,
            'previewToken' => $request->string('preview')->toString() ?: null,
        ]);
    }

    /**
     * Who a whole-phase or whole-estate charge would reach. NOTHING IS POSTED.
     *
     * Step one of two (12 §2). A charge against the whole estate is hundreds of
     * journal entries at once, and the list — which units, how many, what it
     * comes to — is what a treasurer needs in front of them before the press.
     */
    public function previewBulk(Request $request, Dues $dues): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'string', 'in:phase,estate'],
            'phase' => ['nullable', 'string', 'max:64'],
            'type' => ['required', 'string', 'in:dues,special_assessment,fine,amenity'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['required', 'string', 'max:200'],
            'due_on' => ['required', 'date'],
            'account' => ['required', 'string'],
        ]);

        try {
            $preview = $dues->bulkPreview($data['scope'], $data['phase'] ?? null, Money::of((string) $data['amount'], 'JMD'));
        } catch (DomainException $refused) {
            return back()->withErrors(['amount' => $refused->getMessage()])->withInput();
        }

        $token = Str::random(20);

        $request->session()->put('charge-bulk.'.$token, [
            ...$preview,
            'type' => $data['type'],
            'amount' => (string) $data['amount'],
            'amount_label' => MoneyFormatter::fromMinor(Money::of((string) $data['amount'], 'JMD')->getMinorAmount()->toInt()),
            'total_label' => MoneyFormatter::fromMinor($preview['total_minor']),
            'description' => $data['description'],
            'due_on' => $data['due_on'],
            'account' => $data['account'],
        ]);

        return redirect()->to($this->chargePath().'?preview='.$token);
    }

    /** Post the previewed charge to every unit on it — all of them, or none. */
    public function postBulk(Request $request, Dues $dues): RedirectResponse
    {
        $token = $request->string('token')->toString();
        $preview = $request->session()->get('charge-bulk.'.$token);

        if (! is_array($preview)) {
            return redirect()
                ->to($this->chargePath())
                ->withErrors(['amount' => 'That preview has expired or has already been posted. Nothing was raised — take it again.']);
        }

        try {
            $result = $dues->chargeMany(
                unitIds: array_column($preview['units'], 'id'),
                amount: Money::of((string) $preview['amount'], 'JMD'),
                description: (string) $preview['description'],
                dueOn: (string) $preview['due_on'],
                type: (string) $preview['type'],
                account: (string) $preview['account'],
                by: $request->user(),
            );
        } catch (DomainException $refused) {
            return redirect()->to($this->chargePath())->withErrors(['amount' => $refused->getMessage()]);
        }

        // Spent, so a second press cannot bill the estate twice.
        $request->session()->forget('charge-bulk.'.$token);

        return redirect()
            ->to($this->arrearsPath())
            ->with('success', sprintf(
                '%s posted to %d unit(s) — %s in total. Each unit has its own charge and its own journal entry.',
                $preview['description'],
                $result['posted'],
                MoneyFormatter::fromMinor($result['total_minor']),
            ));
    }

    private function chargePath(): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().'/finance/charges/new'
            : '/finance/charges/new';
    }

    private function arrearsPath(): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().'/finance/arrears'
            : '/finance/arrears';
    }

    /** Post it. A real state change, and a journal entry. */
    public function postCharge(Request $request, Dues $dues): RedirectResponse
    {
        $data = $request->validate([
            'unit' => ['required', 'string'],
            'type' => ['required', 'string', 'in:dues,special_assessment,fine,amenity'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['required', 'string', 'max:200'],
            'due_on' => ['required', 'date'],
            'account' => ['required', 'string'],
        ]);

        $unit = Unit::where('reference', $data['unit'])->firstOrFail();

        try {
            $dues->charge(
                unit: $unit,
                /*
                 * Constructed from a decimal string, never a float. `Money::of`
                 * refuses a value it cannot represent exactly, which is the
                 * whole reason the amount arrives as a string and not a
                 * cast-to-float.
                 */
                amount: Money::of((string) $data['amount'], 'JMD'),
                description: $data['description'],
                dueOn: $data['due_on'],
                type: $data['type'],
                account: $data['account'],
                by: $request->user(),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['amount' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->unitPath($unit))
            ->with('success', 'Charge posted to '.$unit->reference.'.');
    }

    /**
     * Record money taken at the office — board 6's "Record manual payment".
     *
     * The day-one collection path: no card gateway exists (Q-012), so a
     * household's dues arrive as cash, a cheque or a transfer and are keyed
     * here. `estate.payments.create` on the route. The receipt number is not
     * asked for — it is allocated at posting from the estate's sequence.
     */
    public function recordPayment(Request $request, Unit $unit, Dues $dues): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', 'string', 'in:cash,cheque,bank'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:60'],
        ]);

        try {
            $payment = $dues->receive(
                unit: $unit,
                amount: Money::of((string) $data['amount'], 'JMD'),
                method: $data['method'],
                receivedAt: $data['received_on'],
                by: $request->user(),
                reference: isset($data['reference']) && trim($data['reference']) !== '' ? trim($data['reference']) : null,
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['payment' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->unitPath($unit))
            ->with('success', sprintf(
                'Payment of %s recorded against %s — receipt %s.',
                MoneyFormatter::fromMinor($payment->amount_minor),
                $unit->reference,
                $payment->receipt_no,
            ));
    }

    /** The receipt register — board 5's Receipts tab. */
    public function receipts(Request $request, Receipts $receipts): Response
    {
        return inertia('Estate/Dues/Receipts', [
            'estate' => ['name' => (string) tenant()->name],
            ...$receipts->register(),
            'canRecord' => $request->user()->can('estate.payments.create'),
            'canExport' => $request->user()->can('estate.payments.export'),
            'exportBlockedReason' => 'An export leaves this estate as a file naming who paid what, so it needs Payments export access. You are able to read this register.',
            'reasons' => [
                'plan' => self::NO_PLAN_REGISTER_YET,
                'document' => 'Not built yet — the printed receipt is a document a resident keeps, and it arrives with the statement PDF: server-rendered, queued, kept seven years.',
            ],
        ]);
    }

    /**
     * Where a unit's ledger lives, in whichever shape this environment serves.
     *
     * Production gives each estate its own hostname; local serves them all from
     * one host with the estate in the path. See routes/tenant.php.
     */
    private function unitPath(Unit $unit): string
    {
        $path = '/finance/units/'.$unit->id;

        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
