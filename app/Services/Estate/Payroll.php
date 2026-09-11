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
use App\Services\Audit\AuditLogger;
use App\Services\Payroll\PayrollCalculator;
use Brick\Money\Money;
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
 * 3. A run cannot be approved against a rate card that is not verified. Q-002
 *    is ruled (D-082): the 2025-04 and 2026-04 cards carry TAJ's published
 *    periodic thresholds and are verified, so a run in either period can be
 *    approved. A card seeded from its annual figure alone — 2024-04, 2027-04 —
 *    is not, and a run falling in one stops at `calculated` with the reason on
 *    the control until somebody records TAJ's figures for it.
 *
 *    AND THE FIRST LIVE RUN CARRIES AN ACKNOWLEDGEMENT (Part F of the ruling).
 *    The figures reconcile to the cent against TAJ's tables, and nobody at the
 *    client has countersigned them, so the first run approved in an estate asks
 *    the approver to confirm it was reconciled against current TAJ tables,
 *    naming the card. Not a block — a tick, recorded on the run with the name
 *    and the card — and never asked again once one run carries it.
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
     * The employer's own NIS, NHT, Education Tax and HEART (D-083).
     *
     * Debited on approval and credited to 2100 beside what was withheld — the
     * ruling on Q-002 put employer contributions on the S01, and a payable the
     * S01 clears has to have been credited first.
     */
    public const EMPLOYER_CONTRIBUTIONS = '5010';

    /**
     * Monthly. Every rate on board 37 is quoted "/mo" and every run on board 13
     * is a calendar month, so a run reads TAJ's published MONTHLY threshold —
     * 158,530.00 on the 2026-04 card — rather than dividing the annual one.
     */
    public const PERIODS_PER_YEAR = 12;

    public function __construct(
        private readonly Ledger $ledger,
        private readonly AuditLogger $audit,
    ) {}

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
     * The payroll calendar: the period the next run would cover.
     *
     * CALENDAR MONTHS, ONE OPEN AT A TIME (12 §2, Wave 1 — "a payroll calendar
     * of periods and close dates"). This estate pays monthly; a period runs
     * from the first to the last day of the month, closes on that last day,
     * and is paid on it. The next period is the month after the latest run,
     * whatever its state — or this month, for an estate that has never run
     * one — so the calendar cannot skip a month or run one twice.
     *
     * Returned with why it cannot be started, when it cannot: a run still open
     * (a month is closed before the next is opened), or no rate card in force
     * on its pay date.
     *
     * @return array<string, mixed>
     */
    public function calendar(): array
    {
        $latest = PayrollRun::query()->orderByDesc('period_start')->first();

        $start = $latest === null
            ? Carbon::today()->startOfMonth()
            : $latest->period_start->copy()->addMonthNoOverflow()->startOfMonth();

        $end = $start->copy()->endOfMonth();

        $open = PayrollRun::query()->where('status', '!=', PayrollRun::PAID)->orderBy('period_start')->first();

        $card = StatutoryRateVersion::inForceOn($end->toDateString());

        return [
            'slug' => strtolower($start->format('M-Y')),
            'label' => $start->format('F Y'),
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'pay_date' => $end->format('M j, Y'),
            'closes_on' => $end->format('M j, Y'),
            'rate_card' => $card === null ? null : $card->effective_from->format('Y-m').($card->is_verified ? '' : ' (unverified)'),
            'blocked_by' => match (true) {
                $open !== null => sprintf(
                    '%s is still open (%s). A month is closed — calculated, approved and paid — before the next is started.',
                    $open->period_label,
                    $this->statusLabel($open),
                ),
                $card === null => 'No statutory rate card is in force on '.$end->format('M j, Y').'. Gemini records the rates; a run cannot be calculated without them.',
                default => null,
            },
        ];
    }

    /**
     * Start the next run on the calendar — board 13's "Start new run".
     *
     * A DRAFT, prepared by whoever pressed the button. Nothing is calculated
     * and nothing posts: the run is then taken through exceptions (board 14),
     * calculation and approval (board 15) as every run is. Refused for the
     * calendar's own reasons, so the button and the service agree.
     */
    public function startRun(User $by): PayrollRun
    {
        $calendar = $this->calendar();

        if ($calendar['blocked_by'] !== null) {
            throw new DomainException($calendar['blocked_by']);
        }

        if (PayrollRun::query()->where('slug', $calendar['slug'])->exists()) {
            throw new DomainException($calendar['label'].' already has a run. A month is paid once.');
        }

        $end = Carbon::parse($calendar['period_end']);

        return PayrollRun::create([
            'reference' => 'PR-'.strtoupper(str_replace('-', '', $calendar['slug'])),
            'slug' => $calendar['slug'],
            'period_label' => $calendar['label'],
            'period_start' => $calendar['period_start'],
            'period_end' => $calendar['period_end'],
            'periods_per_year' => self::PERIODS_PER_YEAR,
            'statutory_rate_version_id' => StatutoryRateVersion::forPayDate($end)->id,
            'status' => PayrollRun::DRAFT,
            'gross_minor' => 0,
            'net_minor' => 0,
            'currency' => 'JMD',
            'prepared_by' => $by->getKey(),
            'prepared_by_name' => $by->name,
        ]);
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

            /*
             * Part F of the Q-002 ruling. On the estate's first live run the
             * approver ticks a sentence naming the card the run was reconciled
             * against; the server refuses the approval without it, so the tick
             * cannot be skipped by posting straight to the route.
             */
            'acknowledgement' => [
                'required' => $run->status === PayrollRun::CALCULATED && $this->needsReconciliationAcknowledgement(),
                'statement' => $this->reconciliationStatement($run),
            ],
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
        if ($filing->remittanceMinor() === 0) {
            return $filing->period_label;
        }

        // The ruling's own list: an S01 covers PAYE, NIS, NHT, Education Tax
        // and HEART — the employer's share on the same return (D-083).
        return $filing->period_label.' · PAYE, NIS, NHT, Education Tax, HEART';
    }

    /* ------------------------------------------------------------------ */
    /* board 37 — the employee register */
    /* ------------------------------------------------------------------ */

    /**
     * Put somebody on the payroll — board 37's "Add employee" (12 §1).
     *
     * THE CONSENT CHECKBOX IS THE RULING AND IT IS NOT DECORATION. A bank
     * account number and an NIS number are personal data the estate is about to
     * hold for seven years, and the old inert reason was right that this
     * console had no consented intake for them. The consent is what makes the
     * intake one: it is required, it is recorded with the person who took it
     * and the moment they did, and without it the write is refused — not
     * accepted-with-a-warning, refused.
     *
     * THE BANK DETAILS ARE OPTIONAL, THE CONSENT IS NOT. An employee can be put
     * on the register before their bank sends the account — payroll then pays
     * them by another arrangement — and a register that refused to list them
     * would understate the estate's wage bill. What is never optional is having
     * asked before holding what they did give.
     *
     * NOTHING HERE POSTS. An employee is a register row; the journal is raised
     * when a run is approved, against the rate in force on that day.
     *
     * @param  array<string, mixed>  $fields
     */
    public function addEmployee(array $fields, User $by): Employee
    {
        $name = trim((string) ($fields['full_name'] ?? ''));
        $title = trim((string) ($fields['job_title'] ?? ''));

        if ($name === '' || $title === '') {
            throw new DomainException('An employee has a name and a job title. The title is what a payslip and the payroll journal both print.');
        }

        if (! ($fields['consent'] ?? false)) {
            throw new DomainException(
                'Tick the consent box. A bank account number and an NIS number are this person\'s data, held for seven '.
                'years, and the estate records that they were asked before it holds any of it.'
            );
        }

        if (Employee::query()->whereRaw('LOWER(full_name) = ?', [strtolower($name)])->where('status', Employee::ACTIVE)->exists()) {
            throw new DomainException($name.' is already on the payroll. Two rows for one person is two salaries and two sets of deductions.');
        }

        $rate = Money::of((string) ($fields['monthly_rate'] ?? '0'), 'JMD');

        if ($rate->isNegativeOrZero()) {
            throw new DomainException('A monthly rate is a positive amount. It is what every run gross-to-net works down from.');
        }

        $nis = trim((string) ($fields['nis_number'] ?? ''));
        $account = trim((string) ($fields['bank_account_number'] ?? ''));
        $bank = trim((string) ($fields['bank_name'] ?? ''));

        if (($account === '') !== ($bank === '')) {
            throw new DomainException('A bank account needs both the bank and the number, or neither. Half of one pays nobody.');
        }

        return DB::connection('tenant')->transaction(function () use ($name, $title, $fields, $rate, $nis, $account, $bank, $by): Employee {
            $employee = Employee::create([
                'full_name' => $name,
                'job_title' => $title,
                'employment_type' => (string) ($fields['employment_type'] ?? Employee::FULL_TIME),
                'bank_name' => $bank === '' ? null : $bank,
                'bank_account_number' => $account === '' ? null : $account,
                'nis_number' => $nis === '' ? null : $nis,
                'monthly_rate_minor' => $rate->getMinorAmount()->toInt(),
                'currency' => 'JMD',
                'employed_since' => ($fields['employed_since'] ?? null) ?: Carbon::today()->toDateString(),
                'status' => Employee::ACTIVE,
            ]);

            /*
             * THE CONSENT IS AUDITED, not stored as a boolean on the row. A
             * flag says "yes" forever and says nothing about when, or who was
             * standing there; the audit entry names the officer, the moment and
             * the employee, which is what a data-protection question a year
             * from now actually asks.
             */
            $this->audit->record(
                action: 'estate.employee_added',
                entityType: 'Employee',
                entityId: (string) $employee->id,
                after: [
                    'name' => $name,
                    'consent_taken_by' => (string) $by->name,
                    'consent_at' => Carbon::now()->toDateTimeString(),
                    'holds_bank_details' => $account !== '',
                    'holds_nis' => $nis !== '',
                ],
            );

            return $employee;
        });
    }

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

        /*
         * THE CARD IN FORCE ON THE PAY DATE, chosen now rather than when the run
         * was created. The threshold changes every 1 April and income tax is
         * assessed on a calendar year, so a March run and an April run are taxed
         * on different cards; the pay date is the period end (D-082).
         */
        $rates = StatutoryRateVersion::forPayDate($run->period_end);
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

            /*
             * HEART is decided on the payroll, not the person — the employer's
             * whole monthly payroll against a statutory floor (Q-016).
             */
            $heartApplies = $rates->heartAppliesTo(
                (int) $employees->sum('monthly_rate_minor'),
                self::PERIODS_PER_YEAR,
            );

            foreach ($employees as $employee) {
                $slip = $calculator->payslip($employee->monthly_rate_minor, $rates, self::PERIODS_PER_YEAR);

                $employer = $calculator->employerCost(
                    $employee->monthly_rate_minor,
                    $rates,
                    self::PERIODS_PER_YEAR,
                    $heartApplies,
                );

                $run->lines()->create([
                    'employee_id' => $employee->id,
                    'gross_minor' => $slip['gross_minor'],
                    'nis_minor' => $slip['nis_minor'],
                    'nht_minor' => $slip['nht_minor'],
                    'education_tax_minor' => $slip['education_tax_minor'],
                    'paye_minor' => $slip['paye_minor'],
                    'net_minor' => $slip['net_minor'],

                    // The estate's own share. Stored beside the slip because the
                    // S01 remits it and 2100 carries it, and never shown to the
                    // employee as a deduction — none of it came out of their pay.
                    'employer_nis_minor' => $employer['nis_minor'],
                    'employer_nht_minor' => $employer['nht_minor'],
                    'employer_education_tax_minor' => $employer['education_tax_minor'],
                    'employer_heart_minor' => $employer['heart_minor'],

                    'currency' => $employee->currency,
                    'paye_note' => $slip['paye_note'],
                ]);

                $gross += $slip['gross_minor'];
                $net += $slip['net_minor'];
            }

            /*
             * The card this run was calculated against is stored on it, so the
             * run reproduces exactly and approval checks the card the payslips
             * actually came from rather than whatever is current.
             */
            $run->forceFill([
                'status' => PayrollRun::CALCULATED,
                'statutory_rate_version_id' => $rates->id,
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

        $version = $run->rateVersion();

        if (! $version->is_verified) {
            return sprintf(
                'This run was calculated against the %s rate card, which carries no verified TAJ periodic '.
                'figures, so it cannot be approved. Record TAJ\'s published thresholds for that card and '.
                'verify it; everything else about the run is finished.',
                $this->rateCardLabel($version),
            );
        }

        return null;
    }

    /**
     * Whether the next approval in this estate is its first live one — Part F.
     *
     * "Live" means approved on this platform with the acknowledgement recorded.
     * Months posted as seeded history never carried it and do not count, which
     * is also why the demonstration estate still asks on its August run.
     */
    public function needsReconciliationAcknowledgement(): bool
    {
        return ! PayrollRun::query()
            ->where('status', PayrollRun::PAID)
            ->whereNotNull('reconciliation_acknowledged_at')
            ->exists();
    }

    /** The sentence an approver ticks on the first live run, naming the card. */
    public function reconciliationStatement(PayrollRun $run): string
    {
        return sprintf(
            'I confirm this run has been reconciled against the current TAJ tables — rate card %s.',
            $this->rateCardLabel($run->rateVersion()),
        );
    }

    /** "TAJ 2026/27 (2026-04)" — the label and the month it took effect. */
    private function rateCardLabel(StatutoryRateVersion $version): string
    {
        return $version->label.' ('.$version->effective_from->format('Y-m').')';
    }

    /**
     * Approve a run and post it, in one act.
     *
     * ONE ENTRY, FOUR LINES, AND IT IS THE WHOLE RUN. Gross debits the payroll
     * expense; the employer's own contributions debit 5010; 2100 is credited
     * with what was withheld AND what the employer owes on top (D-083); net
     * credits the bank. Debits equal credits by construction — gross plus the
     * employer share on one side, net plus both liabilities on the other — and
     * the database refuses the entry if they ever do not.
     *
     * THE FIRST LIVE RUN NEEDS `$reconciled`. Part F of the Q-002 ruling: not a
     * block, an acknowledgement, recorded with the approver's name and the card
     * it was reconciled against. Checked here, so it cannot be skipped by
     * posting to the route without the tick.
     */
    public function approve(PayrollRun $run, User $by, bool $reconciled = false): PayrollRun
    {
        $refusal = $this->approvalRefusal($run, $by);

        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        $acknowledging = $this->needsReconciliationAcknowledgement();

        if ($acknowledging && ! $reconciled) {
            throw new DomainException(
                'This is the first live pay run in this estate, so it needs the approver to confirm it has been '.
                'reconciled against the current TAJ tables. '.$this->reconciliationStatement($run)
            );
        }

        $lines = $run->lines()->get();

        if ($lines->isEmpty()) {
            throw new DomainException('This run has no payslips on it. There is nothing to pay.');
        }

        $gross = (int) $lines->sum('gross_minor');
        $net = (int) $lines->sum('net_minor');
        $withheld = $gross - $net;
        $employer = (int) $lines->sum(static fn (PayrollRunLine $line): int => $line->employerContributionsMinor());

        $memo = 'Payroll — '.$run->period_label;
        $card = $this->rateCardLabel($run->rateVersion());

        return DB::connection('tenant')->transaction(function () use ($run, $by, $gross, $net, $withheld, $employer, $memo, $acknowledging, $card): PayrollRun {
            $postings = [Posting::debit(self::EXPENSE, $gross, $memo)];

            if ($employer > 0) {
                $postings[] = Posting::debit(self::EMPLOYER_CONTRIBUTIONS, $employer, $memo.' — employer contributions');
            }

            $postings[] = Posting::credit(
                self::STATUTORY_PAYABLE,
                $withheld + $employer,
                $memo.' — withheld and employer contributions',
            );
            $postings[] = Posting::credit(self::BANK, $net, $memo.' — net pay');

            $entry = $this->ledger->post(
                memo: $memo,
                postings: $postings,
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
                ...($acknowledging ? [
                    'reconciliation_acknowledged_at' => now(),
                    'reconciliation_acknowledged_by' => $by->getKey(),
                    'reconciliation_acknowledged_by_name' => $by->name,
                    'reconciliation_rate_version' => $card,
                ] : []),
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
     * DR 2100 / CR 1000 for the exact total the run withheld AND the employer
     * contributed on top (D-083) — which returns the payable to nil for that
     * period, and is the assertion board 16's whole screen turns on even though
     * it prints no figures at all.
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

        $total = $filing->remittanceMinor();
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

    /**
     * The card a run paid today would use — the selector, never "the latest".
     *
     * "The latest" was the old answer, and on the day this changed it would
     * have been the 2027-04 card: a version not yet in force, seeded from its
     * annual figure alone.
     */
    public function currentRates(): StatutoryRateVersion
    {
        return StatutoryRateVersion::forPayDate(Carbon::today());
    }
}
