<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Account;
use App\Models\Estate\Employee;
use App\Models\Estate\PayrollException;
use App\Models\Estate\PayrollRun;
use App\Models\Estate\PayrollRunLine;
use App\Models\Estate\StatutoryFiling;
use App\Models\StatutoryRateVersion;
use App\Models\User;
use App\Services\Payroll\PayrollCalculator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The estate's own staff payroll — boards 13, 14, 15, 16 and 37.
 *
 * THE ONE DOOR. Every run is calculated here, approved here and disbursed
 * here, and the money leaves through `Ledger::post()` exactly as a bill does.
 * Nothing in this class writes a journal row itself and nothing outside it
 * writes a payslip.
 *
 * FOUR REFUSALS, AND EACH ONE IS A DIFFERENT PERSON'S PROBLEM
 * ===========================================================
 *
 * 1. A run with an unresolved exception cannot be calculated. Board 14 says so
 *    in its own banner — "This run can't proceed to calculation until each item
 *    below is resolved or the employee is excluded" — and it is the estate's
 *    own control: a missing timesheet calculated anyway pays somebody for hours
 *    nobody recorded.
 *
 * 2. A run cannot be approved by the person who prepared it. Board 15's banner
 *    is explicit: "Prepared by Tracey Reid — awaiting your approval. As a
 *    second approver, this run cannot be disbursed until you review and approve
 *    it." That is a SECOND-PERSON control, not a permission level: the
 *    Treasurer holds `payroll.approve` and still may not approve their own
 *    work. Holding the permission and being a different human are two different
 *    conditions and both are checked.
 *
 * 3. A run cannot be approved while its rate version is unverified. This is
 *    Q-002 and D-021, unchanged: the rates are seeded `2026-04-DRAFT` because
 *    nobody has yet supplied two worked payslips either side of the PAYE
 *    threshold to check them against. A run reaches `calculated` and stops
 *    there, with the reason on the control rather than a button that silently
 *    does nothing. `approvalRefusal()` is what a screen prints.
 *
 * 4. A posted run cannot be re-posted, re-approved or edited. Its journal is
 *    append-only at the database, so a second disbursement would be a second
 *    J$323,005 that nothing can unpost.
 *
 * WHAT IS STORED AND WHAT IS COMPUTED
 * ===================================
 *
 * `PayrollCalculator` is not reimplemented here — it already computes a payslip
 * in the order that makes the figures right (NIS first, because Education Tax
 * and PAYE are both charged on income after it). This class decides WHO is in a
 * run and WHEN, and hands each gross to that calculator.
 *
 * A run's `gross_minor` and `net_minor` are stored, and its lines' five figures
 * are stored, because a payslip is a statement issued to a person on a date.
 * Recomputing it later against whatever rate version is current would silently
 * restate what somebody was told they earned. The control totals every screen
 * prints are summed from those lines on read, never stored a second time — so
 * a run header that disagreed with its own lines is a test failure rather than
 * a number a committee reads.
 *
 * ONE STATUTORY PAYABLE, NOT FOUR. Board 16's accounting note describes the S01
 * as clearing "the four statutory payable control accounts", and the approved
 * chart of accounts carries one: 2100 Statutory Deductions Payable. The chart
 * is what every other estate screen already posts against and board 25 draws,
 * so it is not reshaped to match a note. The four-way split lives on the
 * payslip line and on the filing, which is the sub-ledger 2100 is the control
 * for, and `EstatePayrollTest` asserts the tie both ways. See DECISIONS.md.
 */
class Payroll
{
    /** Gross pay. The estate's own staff, never Gemini's guards. */
    public const EXPENSE = '5000';

    /** What has been withheld and not yet remitted. */
    public const STATUTORY_PAYABLE = '2100';

    /** Where net pay leaves from, and where a remittance leaves from. */
    public const BANK = '1000';

    /**
     * Monthly. Every rate on board 37 is quoted "/mo" and every run on board 13
     * is a calendar month, so the PAYE threshold is divided by twelve.
     */
    public const PERIODS_PER_YEAR = 12;

    public function __construct(private readonly Ledger $ledger) {}

    /* ------------------------------------------------------------------ */
    /* board 13 — the run list */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function runsBoard(): array
    {
        $runs = PayrollRun::query()
            ->withCount('lines')
            ->orderByDesc('period_start')
            ->get();

        $latestPaid = $runs->firstWhere('status', PayrollRun::PAID);

        return [
            'kpis' => [
                [
                    'key' => 'employees',
                    'value' => (string) Employee::query()->where('status', Employee::ACTIVE)->count(),
                    'label' => 'Employees on payroll',
                ],
                [
                    'key' => 'next_pay_date',
                    'value' => $this->nextPayDate()?->format('M j') ?? '—',
                    'label' => 'Next pay date',
                ],
                [
                    'key' => 'last_net',
                    'value_minor' => $latestPaid === null ? 0 : (int) $latestPaid->net_minor,
                    'label' => 'Last run — net total',
                ],
                [
                    'key' => 'pending',
                    'value' => (string) $runs->where('status', PayrollRun::CALCULATED)->count(),
                    'label' => 'Pending your approval',
                ],
            ],

            'rows' => $runs->map(fn (PayrollRun $run): array => [
                'id' => $run->id,
                'slug' => $run->slug,
                'period' => $run->period_label,
                'status' => $run->status,
                'status_label' => $this->statusLabel($run),
                'employees' => $run->lines_count,

                /*
                 * A run nobody has calculated has no gross and no net, and the
                 * board prints an em dash rather than a zero. Zero is a figure —
                 * it says the estate owes its staff nothing this month — and
                 * this run says nothing at all yet.
                 */
                'gross_minor' => $run->status === PayrollRun::DRAFT || $run->status === PayrollRun::EXCEPTIONS
                    ? null
                    : $run->gross_minor,
                'net_minor' => $run->status === PayrollRun::DRAFT || $run->status === PayrollRun::EXCEPTIONS
                    ? null
                    : $run->net_minor,
                'action' => $this->rowAction($run),
            ])->all(),
        ];
    }

    /**
     * The badge board 13 draws, which is not simply the stored status.
     *
     * "2 exceptions" counts rows; "Pending approval" is what `calculated`
     * means to the person reading the list. The stored value stays the
     * lifecycle state and the label is derived, the same way a maintenance
     * ticket's "Overdue" is derived from its SLA rather than stored as a state
     * nobody can transition out of.
     */
    private function statusLabel(PayrollRun $run): string
    {
        if ($run->status === PayrollRun::EXCEPTIONS) {
            $count = $run->exceptions()->where('status', PayrollException::UNRESOLVED)->count();

            return $count.' exception'.($count === 1 ? '' : 's');
        }

        return match ($run->status) {
            PayrollRun::CALCULATED => 'Pending approval',
            PayrollRun::PAID => 'Paid',
            default => 'Draft',
        };
    }

    /** What the row's link offers to do about this run. */
    private function rowAction(PayrollRun $run): string
    {
        return match ($run->status) {
            PayrollRun::EXCEPTIONS => 'Resolve exceptions',
            PayrollRun::CALCULATED => 'Review & approve',
            default => 'View',
        };
    }

    /**
     * The next pay date, which is the end of the earliest unpaid period.
     *
     * Derived rather than stored. A stored "next pay date" is a second copy of
     * something the runs already say, and it is the copy that goes stale the
     * moment a period is added or paid.
     */
    private function nextPayDate(): ?Carbon
    {
        $next = PayrollRun::query()
            ->where('status', '!=', PayrollRun::PAID)
            ->orderBy('period_end')
            ->first();

        return $next?->period_end;
    }

    /* ------------------------------------------------------------------ */
    /* board 14 — pre-run exceptions */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function exceptionsBoard(PayrollRun $run): array
    {
        $rows = $run->exceptions()->with('employee')->orderBy('id')->get();
        $blocking = $rows->where('status', PayrollException::UNRESOLVED);

        return [
            'run' => [
                'id' => $run->id,
                'slug' => $run->slug,
                'period' => $run->period_label,
                'status' => $run->status,
            ],
            'blocking' => $blocking->count(),
            'rows' => $rows->map(fn (PayrollException $row): array => [
                'id' => $row->id,
                'title' => ($row->employee->full_name ?? 'Employee').' — '.$this->exceptionTitle($row),
                'detail' => $row->detail,
                'status' => $row->status,
                'resolved' => ! $row->blocksCalculation(),
                'resolution_note' => $row->resolution_note,
                'actions' => $this->exceptionActions($row),
            ])->all(),

            /*
             * The board draws its "Continue to calculation" control at half
             * opacity with the reason in the label itself. The reason is
             * computed rather than fixed, because a run whose last exception
             * was just resolved has to be able to proceed without a reload
             * telling it why it cannot.
             */
            'canCalculate' => $blocking->isEmpty() && $run->status !== PayrollRun::PAID,
            'calculateReason' => $this->calculationRefusal($run, $blocking->count()),
        ];
    }

    /** Board 14's own two headings, by stored type. */
    private function exceptionTitle(PayrollException $row): string
    {
        return match ($row->type) {
            PayrollException::MISSING_TIMESHEET => 'missing timesheet',
            PayrollException::OVERTIME_ANOMALY => 'overtime anomaly',
            default => str_replace('_', ' ', $row->type),
        };
    }

    /**
     * The two buttons board 14 puts on each row, and they differ by kind.
     *
     * A missing timesheet is entered or the person is excluded; an overtime
     * anomaly is reviewed or approved as it stands. Both pairs resolve — see
     * `PayrollException`'s docblock on why excluding is not resolving.
     *
     * @return list<array{key: string, label: string, primary: bool}>
     */
    private function exceptionActions(PayrollException $row): array
    {
        if ($row->type === PayrollException::MISSING_TIMESHEET) {
            return [
                ['key' => 'enter', 'label' => 'Enter manually', 'primary' => true],
                ['key' => 'exclude', 'label' => 'Exclude', 'primary' => false],
            ];
        }

        return [
            ['key' => 'review', 'label' => 'Review hours', 'primary' => true],
            ['key' => 'accept', 'label' => 'Approve as-is', 'primary' => false],
        ];
    }

    private function calculationRefusal(PayrollRun $run, int $blocking): ?string
    {
        if ($run->status === PayrollRun::PAID) {
            return 'This run has already been paid. A pay run is calculated once; a correction is a '.
                'later run, never a recalculation of one that has already left the bank.';
        }

        if ($blocking === 0) {
            return null;
        }

        return 'Continue to calculation (resolve all first) — '.$blocking.' item'.
            ($blocking === 1 ? '' : 's').' still unresolved. A missing timesheet calculated anyway '.
            'pays somebody for hours nobody recorded.';
    }

    /* ------------------------------------------------------------------ */
    /* board 15 — one run, and every figure on it ties */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function runBoard(PayrollRun $run, ?User $viewer = null): array
    {
        $lines = $run->lines()->with('employee')->orderByDesc('gross_minor')->get();

        /*
         * SUMMED FROM THE LINES, NOT READ FROM THE HEADER. Board 15's four
         * summary boxes and its seven columns are the same figures twice, and
         * the whole point of this screen is that they agree. Reading the header
         * for the boxes and the lines for the table would make them agree by
         * construction and prove nothing.
         */
        $gross = (int) $lines->sum('gross_minor');
        $net = (int) $lines->sum('net_minor');
        $deductions = $gross - $net;

        return [
            'run' => [
                'id' => $run->id,
                'slug' => $run->slug,
                'period' => $run->period_label,
                'status' => $run->status,
                'prepared_by' => $run->prepared_by_name,
                'approved_by' => $run->approved_by_name,
                'approved_at' => $run->approved_at?->toDateString(),
            ],

            'summary' => [
                ['key' => 'employees', 'value' => (string) $lines->count(), 'label' => 'Employees'],
                ['key' => 'gross', 'value_minor' => $gross, 'label' => 'Total gross'],
                ['key' => 'deductions', 'value_minor' => $deductions, 'label' => 'Total deductions'],
                ['key' => 'net', 'value_minor' => $net, 'label' => 'Total net pay'],
            ],

            'lines' => $lines->map(fn (PayrollRunLine $line): array => [
                'id' => $line->id,
                'name' => $line->employee->full_name ?? 'Employee',
                'initials' => $this->initials($line->employee->full_name ?? ''),
                'role' => $line->employee->job_title ?? '',
                'gross_minor' => $line->gross_minor,
                'nis_minor' => $line->nis_minor,
                'nht_minor' => $line->nht_minor,
                'education_tax_minor' => $line->education_tax_minor,
                'paye_minor' => $line->paye_minor,
                'net_minor' => $line->net_minor,

                /*
                 * Why a zero is a zero. Simone Clarke's PAYE is nil because her
                 * pay is under the threshold, and a bare 0 on a payslip reads
                 * as a defect to the person holding it. `PayrollCalculator`
                 * writes the sentence; this passes it through.
                 */
                'paye_note' => $line->paye_note,
            ])->all(),

            'canApprove' => $this->mayApprove($run, $viewer),
            'approvalReason' => $this->approvalRefusal($run, $viewer),
        ];
    }

    private function initials(string $name): string
    {
        $parts = array_values(array_filter(explode(' ', $name)));

        return strtoupper(implode('', array_map(
            static fn (string $part): string => $part[0] ?? '',
            array_slice($parts, 0, 2),
        )));
    }

    /* ------------------------------------------------------------------ */
    /* board 16 — statutory filings */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function filingsBoard(): array
    {
        $filings = StatutoryFiling::query()
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [StatutoryFiling::DUE_SOON])
            ->orderBy('due_on')
            ->get();

        return [
            'rows' => $filings->map(fn (StatutoryFiling $filing): array => [
                'id' => $filing->id,
                'code' => $filing->form_code,
                'title' => $filing->form_code.' — '.$filing->form_title,
                'subtitle' => $this->filingSubtitle($filing),
                'due' => $filing->filed_on === null
                    ? 'Due '.$filing->due_on->format('M j').($filing->due_on->year === now()->year ? '' : ', '.$filing->due_on->year)
                    : 'Filed '.$filing->filed_on->format('M j'),
                'status' => $filing->status,
                'status_label' => match ($filing->status) {
                    StatutoryFiling::FILED => 'Filed',
                    StatutoryFiling::DUE_SOON => 'Due soon',
                    default => 'Not started',
                },
                'warn' => $filing->status === StatutoryFiling::DUE_SOON,
                'total_minor' => $filing->total_minor,
            ])->all(),
        ];
    }

    /**
     * "August 2026 · NIS, NHT, Education Tax, PAYE" — the period, and what the
     * return covers where it covers deductions.
     */
    private function filingSubtitle(StatutoryFiling $filing): string
    {
        if ($filing->deductionsMinor() === 0) {
            return $filing->period_label;
        }

        return $filing->period_label.' · NIS, NHT, Education Tax, PAYE';
    }

    /* ------------------------------------------------------------------ */
    /* board 37 — the employee register */
    /* ------------------------------------------------------------------ */

    /**
     * @return array<string, mixed>
     */
    public function employeesBoard(): array
    {
        $employees = Employee::query()->orderBy('id')->get();

        return [
            'rows' => $employees->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'initials' => $this->initials($employee->full_name),
                'since' => 'Employed since '.$employee->employed_since->format('M Y'),
                'role' => $employee->job_title,
                'type' => $employee->employment_type === 'full_time' ? 'Full-time' : 'Part-time',

                /*
                 * MASKED AT THE BOUNDARY, never sent whole. A bank account
                 * number and an NIS number both identify a person to their
                 * bank and to the revenue authority; a register that lists
                 * four staff does not need either in full, and a payload is
                 * readable in a browser's network tab by anyone at the desk.
                 */
                'bank' => $this->maskedBank($employee),
                'nis' => $this->maskedNis($employee),
                'rate_minor' => $employee->monthly_rate_minor,
                'status' => $employee->status,
            ])->all(),
        ];
    }

    /** "NCB •••• 3315". */
    private function maskedBank(Employee $employee): string
    {
        if ($employee->bank_account_number === null) {
            return '—';
        }

        return trim(($employee->bank_name ?? '').' •••• '.substr($employee->bank_account_number, -4));
    }

    /** "••• •••5 208" — the board's own shape, last four with its own spacing. */
    private function maskedNis(Employee $employee): string
    {
        if ($employee->nis_number === null) {
            return '—';
        }

        $tail = substr(preg_replace('/\D/', '', $employee->nis_number) ?? '', -4);

        return '••• •••'.substr($tail, 0, 1).' '.substr($tail, 1);
    }

    /* ------------------------------------------------------------------ */
    /* the writes */
    /* ------------------------------------------------------------------ */

    /**
     * Work out every payslip in a run and store them.
     *
     * REFUSED WHILE ANY EXCEPTION IS UNRESOLVED. An excluded employee is left
     * out entirely rather than given a zero line, which is why board 13's May
     * run has three employees against the other months' four and differs by
     * exactly one person's figures.
     */
    public function calculate(PayrollRun $run): PayrollRun
    {
        if ($run->isPosted()) {
            throw new DomainException(
                'This run has already been paid. A pay run is calculated once; a correction is a later '.
                'run, never a recalculation of one whose money has already left the bank.'
            );
        }

        $blocking = $run->exceptions()->where('status', PayrollException::UNRESOLVED)->count();

        if ($blocking > 0) {
            throw new DomainException($this->calculationRefusal($run, $blocking) ?? 'This run cannot be calculated yet.');
        }

        $rates = $run->rateVersion();
        $calculator = app(PayrollCalculator::class);

        $excluded = $run->exceptions()
            ->where('status', PayrollException::EXCLUDED)
            ->pluck('employee_id')
            ->all();

        return DB::connection('tenant')->transaction(function () use ($run, $rates, $calculator, $excluded): PayrollRun {
            // Recalculating replaces the previous attempt outright. These are
            // not ledger rows — nothing has been posted while a run is still
            // being worked out — so a stale line from an earlier attempt would
            // simply be a payslip for a figure nobody is going to be paid.
            $run->lines()->delete();

            $gross = 0;
            $net = 0;

            /*
             * NOBODY IS PAID FOR A MONTH THEY HAD NOT STARTED. Wayne Thomas was
             * employed from June 2026, and a run for May that included him
             * would pay a man J$88,000 for a month he did not work there — and
             * would do it quietly, because every total on every screen would
             * still add up. Board 13 says so in its own figures: May is drawn
             * with three employees where the other months have four, and the
             * variance is exactly his gross and his net.
             *
             * Measured against the period END. Somebody who started on the 20th
             * is on that month's payroll; what they are paid for a part month is
             * a timesheet question, and a timesheet that is missing is what
             * board 14's first exception is for.
             */
            $employees = Employee::query()
                ->where('status', Employee::ACTIVE)
                ->where('employed_since', '<=', $run->period_end)
                ->whereNotIn('id', $excluded)
                ->orderBy('id')
                ->get();

            foreach ($employees as $employee) {
                $slip = $calculator->payslip(
                    $employee->monthly_rate_minor,
                    $rates,
                    self::PERIODS_PER_YEAR,
                );

                $run->lines()->create([
                    'employee_id' => $employee->id,
                    'gross_minor' => $slip['gross_minor'],
                    'nis_minor' => $slip['nis_minor'],
                    'nht_minor' => $slip['nht_minor'],
                    'education_tax_minor' => $slip['education_tax_minor'],
                    'paye_minor' => $slip['paye_minor'],
                    'net_minor' => $slip['net_minor'],
                    'currency' => $employee->currency,
                    'paye_note' => $slip['paye_note'],
                ]);

                $gross += $slip['gross_minor'];
                $net += $slip['net_minor'];
            }

            $run->forceFill([
                'status' => PayrollRun::CALCULATED,
                'gross_minor' => $gross,
                'net_minor' => $net,
            ])->save();

            return $run->refresh();
        });
    }

    /**
     * Whether this viewer may approve THIS run — permission and person, both.
     */
    public function mayApprove(PayrollRun $run, ?User $viewer): bool
    {
        return $this->approvalRefusal($run, $viewer) === null;
    }

    /**
     * Why not, in the words the screen prints on the control.
     *
     * Never a bare disabled button. Each of these is a different person's
     * problem to solve and saying which is the difference between a control
     * somebody can act on and one that looks broken.
     */
    public function approvalRefusal(PayrollRun $run, ?User $viewer): ?string
    {
        if ($run->isPosted()) {
            return 'This run has already been approved and paid.';
        }

        if ($run->status !== PayrollRun::CALCULATED) {
            return 'This run has not been calculated yet. There is nothing to approve until every '.
                'exception is resolved and the payslips have been worked out.';
        }

        if ($viewer === null || ! $viewer->can('estate.payroll.approve')) {
            return 'Approving a pay run releases money to four people and needs Payroll approval '.
                'access, which this role does not hold.';
        }

        /*
         * The second-person control, and it is separate from the permission.
         * Board 15's own banner is the requirement: the officer who prepared a
         * run is not the officer who releases it, however senior they are.
         */
        if ($run->prepared_by !== null && $run->prepared_by === $viewer->getKey()) {
            return 'You prepared this run, so you cannot also approve it. A second approver is what '.
                'stands between a typed figure and four people being paid it.';
        }

        if (! $run->rateVersion()->is_verified) {
            return 'The statutory rates this run used are still marked draft. Approval is blocked '.
                'until two worked payslips either side of the PAYE threshold confirm them — see '.
                'QUESTIONS.md Q-002. Everything else about the run is finished and correct.';
        }

        return null;
    }

    /**
     * Approve a run and post it, in one act.
     *
     * ONE ENTRY, SIX LINES, AND IT IS THE WHOLE RUN. Gross debits the payroll
     * expense; the four withholdings credit 2100 as a single figure; net
     * credits the bank. Debits equal credits by construction — net is gross
     * less deductions and nothing else — and the database refuses the entry if
     * they ever do not.
     */
    public function approve(PayrollRun $run, User $by): PayrollRun
    {
        $refusal = $this->approvalRefusal($run, $by);

        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        $lines = $run->lines()->get();

        if ($lines->isEmpty()) {
            throw new DomainException('This run has no payslips on it. There is nothing to pay.');
        }

        $gross = (int) $lines->sum('gross_minor');
        $net = (int) $lines->sum('net_minor');
        $deductions = $gross - $net;

        $memo = 'Payroll — '.$run->period_label;

        return DB::connection('tenant')->transaction(function () use ($run, $by, $gross, $net, $deductions, $memo): PayrollRun {
            $entry = $this->ledger->post(
                memo: $memo,
                postings: [
                    Posting::debit(self::EXPENSE, $gross, $memo),
                    Posting::credit(self::STATUTORY_PAYABLE, $deductions, $memo.' — withheld'),
                    Posting::credit(self::BANK, $net, $memo.' — net pay'),
                ],
                on: $run->period_end,
                source: Ledger::SOURCE_PAYROLL,
                sourceId: $run->id,
                by: $by,
            );

            $run->forceFill([
                'status' => PayrollRun::PAID,
                'gross_minor' => $gross,
                'net_minor' => $net,
                'approved_by' => $by->getKey(),
                'approved_by_name' => $by->name,
                'approved_at' => now(),
                'journal_ref' => $entry->reference,
            ])->save();

            return $run->refresh();
        });
    }

    /**
     * Send a run back to whoever prepared it.
     *
     * A REASON IS REQUIRED, and the refusal says why: "request changes" with no
     * changes named is a run bounced back to somebody who now has to guess what
     * is wrong with it.
     */
    public function requestChanges(PayrollRun $run, string $reason, User $by): PayrollRun
    {
        if ($run->isPosted()) {
            throw new DomainException('This run has already been paid and cannot be sent back.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(
                'Say what needs changing. A run sent back with no reason leaves the person who prepared '.
                'it guessing at which of four payslips you disagreed with.'
            );
        }

        $run->forceFill([
            'status' => PayrollRun::EXCEPTIONS,
            'changes_requested_reason' => $reason,
        ])->save();

        return $run->refresh();
    }

    /**
     * Deal with one exception — resolved into the run, or excluded from it.
     *
     * The note is kept whichever way it goes. The reason a technician's 22
     * overtime hours went through unchallenged is exactly what somebody asks
     * about six months later.
     */
    public function resolveException(
        PayrollException $exception,
        string $outcome,
        ?string $note,
        User $by,
    ): PayrollException {
        if ($exception->run->isPosted()) {
            throw new DomainException('This run has already been paid; its exceptions are part of the record.');
        }

        if (! in_array($outcome, [PayrollException::RESOLVED, PayrollException::EXCLUDED], true)) {
            throw new DomainException('An exception is either resolved into the run or excluded from it.');
        }

        $exception->forceFill([
            'status' => $outcome,
            'resolution_note' => trim((string) $note) ?: null,
            'resolved_at' => now(),
            'resolved_by' => $by->getKey(),
            'resolved_by_name' => $by->name,
        ])->save();

        return $exception->refresh();
    }

    /**
     * File a return and clear what it remits.
     *
     * DR 2100 / CR 1000 for the exact total the run withheld — which returns
     * the payable to nil for that period, and is the assertion board 16's whole
     * screen turns on even though it prints no figures at all.
     *
     * A return with nothing to remit — a GCT return, the annual P24 — is
     * recorded as filed and posts nothing. There is no entry to make, and a
     * zero-value journal is noise in a ledger somebody has to read in five
     * years.
     */
    public function fileReturn(StatutoryFiling $filing, User $by, Carbon|string|null $on = null): StatutoryFiling
    {
        if ($filing->status === StatutoryFiling::FILED) {
            throw new DomainException(
                $filing->form_code.' for '.$filing->period_label.' has already been filed. '.
                'Filing it twice would remit the same deductions twice.'
            );
        }

        $total = $filing->deductionsMinor();
        $filedOn = $on === null ? Carbon::today() : Carbon::parse($on);

        return DB::connection('tenant')->transaction(function () use ($filing, $by, $total, $filedOn): StatutoryFiling {
            $reference = null;

            if ($total > 0) {
                $memo = $filing->form_code.' remittance — '.$filing->period_label;

                $reference = $this->ledger->post(
                    memo: $memo,
                    postings: [
                        Posting::debit(self::STATUTORY_PAYABLE, $total, $memo),
                        Posting::credit(self::BANK, $total, $memo),
                    ],
                    on: $filedOn,
                    source: Ledger::SOURCE_PAYROLL_REMITTANCE,
                    sourceId: $filing->id,
                    by: $by,
                )->reference;
            }

            $filing->forceFill([
                'status' => StatutoryFiling::FILED,
                'filed_on' => $filedOn,
                'journal_ref' => $reference,
            ])->save();

            return $filing->refresh();
        });
    }

    /**
     * What the estate still owes the revenue authority, read from the ledger.
     *
     * The control account itself, not a sum of filings. A filing register that
     * disagreed with account 2100 is exactly the drift this returns rather than
     * hides.
     */
    public function outstandingStatutoryMinor(): int
    {
        return $this->ledger
            ->balanceOf(Account::query()->where('code', self::STATUTORY_PAYABLE)->firstOrFail())
            ->getMinorAmount()
            ->toInt();
    }

    /** The rate version a new run would use, and whether it may be approved. */
    public function currentRates(): StatutoryRateVersion
    {
        return StatutoryRateVersion::on('mysql')
            ->orderByDesc('effective_from')
            ->firstOrFail();
    }
}
