<?php

declare(strict_types=1);

namespace Database\Seeders\Estate;

use App\Models\Estate\Employee;
use App\Models\Estate\PayrollException;
use App\Models\Estate\PayrollRun;
use App\Models\Estate\StatutoryFiling;
use App\Models\StatutoryRateVersion;
use App\Models\User;
use App\Services\Estate\Ledger;
use App\Services\Estate\Payroll;
use App\Services\Estate\Posting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The estate's own four staff, five months of runs, and the returns — boards
 * 13, 14, 15, 16 and 37.
 *
 * THE PAYE FIGURES ARE NOT THE BOARD'S, AND THAT IS THE ONE THING TO READ
 * BEFORE ANYTHING ELSE HERE.
 *
 * Board 15 draws six money columns per employee. Five of them — gross, NIS,
 * NHT, Education Tax and the deduction total's arithmetic — this seeder
 * reproduces to the cent, because the rate card the board was drawn against and
 * the rate version seeded on this platform agree exactly: 3% NIS, 2% NHT, 2.25%
 * Education Tax on gross-less-NIS. The sixth, PAYE, does not, and the gap is
 * large enough that seeding the board's own figures would have made this
 * application withhold the wrong tax from four people:
 *
 *     Patricia Morgan   board 42,928   lawful 7,363
 *     Neil Anderson     board 22,044   lawful 0
 *     Wayne Thomas      board 20,420   lawful 0
 *     Simone Clarke     board      0   lawful 0
 *
 * The board's column is exactly 25% of (gross − NIS − NHT − Education Tax) for
 * the first three and zero for the fourth. Two things are wrong with that and
 * they compound. PAYE is charged on statutory income, which is gross less NIS
 * only — NHT and Education Tax are not deductible against it — and it is
 * charged on the EXCESS above the threshold, not on the whole. Simone Clarke's
 * zero identifies the arithmetic exactly: her 66,829 sits just under 69,230.77,
 * which is the annual threshold divided by 26. A FORTNIGHTLY divisor, applied
 * to monthly pay, as a cliff rather than a band.
 *
 * So this seeder uses `PayrollCalculator`, which implements the real rule and
 * whose order is load-bearing — see its docblock. The estate's own screens then
 * show the lawful figures and board 15's PAYE and Net columns differ from them.
 * That is recorded as a residual rather than resolved by reshaping the
 * calculation: an application that withholds J$85,392 a month where the law
 * asks J$7,363 is not a fidelity success. See DECISIONS.md and QUESTIONS.md
 * Q-002, which this makes concrete — it is no longer "we need worked payslips",
 * it is "your board's own PAYE column over-withholds by a factor of 5.8 and
 * here is the arithmetic that shows it".
 *
 * WHAT IS POSTED AND WHAT IS NOT. The three finished months post through
 * `Ledger::post()` exactly as a bill does, and the two live ones do not: the
 * September run is still stuck on its exceptions and the August run is
 * calculated and waiting for a second approver, which is the state board 15
 * exists to draw. Nothing here touches account 1200, so the estate's arrears
 * stay exactly where `EstateFinanceSeeder`'s own guard expects them.
 *
 * WHY MAY HAS THREE EMPLOYEES AND EVERY OTHER MONTH HAS FOUR. Because Wayne
 * Thomas was "Employed since Jun 2026", which board 37 says itself. Board 13's
 * May variance — 88,000 of gross and Thomas's exact net — follows from the
 * register rather than from an exclusion somebody had to invent.
 */
class PayrollSeeder extends Seeder
{
    /**
     * Board 37's register, verbatim.
     *
     * The bank account and NIS numbers are the last four the board prints, on
     * the reasoning that a masked figure is the only part of either that a
     * screen is entitled to show — see `Payroll::maskedBank()`. Storing four
     * digits rather than a plausible full number also means this fixture cannot
     * be mistaken for a real account if it ever escapes a laptop.
     *
     * @var list<array{name: string, title: string, bank: string, account: string, nis: string, rate: int, since: string}>
     */
    private const STAFF = [
        [
            'name' => 'Patricia Morgan',
            'title' => 'Property Manager',
            'bank' => 'NCB',
            'account' => '3315',
            'nis' => '5208',
            'rate' => 185_000_00,
            'since' => '2024-03-01',
        ],
        [
            'name' => 'Neil Anderson',
            'title' => 'Head Groundskeeper',
            'bank' => 'Scotia',
            'account' => '7742',
            'nis' => '8631',
            'rate' => 95_000_00,
            'since' => '2023-01-01',
        ],
        [
            'name' => 'Wayne Thomas',
            'title' => 'Maintenance Technician',
            'bank' => 'NCB',
            'account' => '9047',
            'nis' => '3419',
            'rate' => 88_000_00,
            'since' => '2026-06-01',
        ],
        [
            'name' => 'Simone Clarke',
            'title' => 'Administrative Assistant',
            'bank' => 'Scotia',
            'account' => '2286',
            'nis' => '6072',
            'rate' => 72_000_00,
            'since' => '2024-02-01',
        ],
    ];

    /**
     * Board 13's five rows, newest first, with the state each is drawn in.
     *
     * @var list<array{slug: string, label: string, month: string, status: string}>
     */
    private const RUNS = [
        ['slug' => 'sep-2026', 'label' => 'September 2026', 'month' => '2026-09', 'status' => PayrollRun::EXCEPTIONS],
        ['slug' => 'aug-2026', 'label' => 'August 2026', 'month' => '2026-08', 'status' => PayrollRun::CALCULATED],
        ['slug' => 'jul-2026', 'label' => 'July 2026', 'month' => '2026-07', 'status' => PayrollRun::PAID],
        ['slug' => 'jun-2026', 'label' => 'June 2026', 'month' => '2026-06', 'status' => PayrollRun::PAID],
        ['slug' => 'may-2026', 'label' => 'May 2026', 'month' => '2026-05', 'status' => PayrollRun::PAID],
    ];

    /**
     * Board 14's two rows, verbatim, both against the September run.
     *
     * @var list<array{staff: string, type: string, detail: string, from: string, to: string}>
     */
    private const EXCEPTIONS = [
        [
            'staff' => 'Neil Anderson',
            'type' => PayrollException::MISSING_TIMESHEET,
            'detail' => 'Week of Sep 1–5 has no submitted hours for the grounds team',
            'from' => '2026-09-01',
            'to' => '2026-09-05',
        ],
        [
            'staff' => 'Wayne Thomas',
            'type' => PayrollException::OVERTIME_ANOMALY,
            'detail' => '22 overtime hours logged this period, more than double the 3-month average',
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ],
    ];

    public function run(): void
    {
        $rates = StatutoryRateVersion::on('mysql')
            ->orderByDesc('effective_from')
            ->first();

        if ($rates === null) {
            // Nothing to seed against, and inventing a rate card is the one
            // thing this file must never do. The central rates seeder owns it.
            return;
        }

        $staff = $this->seedStaff();
        $preparer = $this->preparer();

        $this->seedRuns($rates, $staff, $preparer);
        $this->seedFilings();
    }

    /**
     * @return array<string, Employee>
     */
    private function seedStaff(): array
    {
        $staff = [];

        foreach (self::STAFF as $row) {
            $staff[$row['name']] = Employee::updateOrCreate(
                ['full_name' => $row['name']],
                [
                    'job_title' => $row['title'],
                    'employment_type' => Employee::FULL_TIME,
                    'bank_name' => $row['bank'],
                    'bank_account_number' => $row['account'],
                    'nis_number' => $row['nis'],
                    'monthly_rate_minor' => $row['rate'],
                    'currency' => 'JMD',
                    'employed_since' => $row['since'],
                    'status' => Employee::ACTIVE,
                ],
            );
        }

        return $staff;
    }

    /**
     * Who prepared these runs.
     *
     * The Treasurer, because board 15 names Tracey Reid in its banner and gives
     * the sidebar footer "Treasurer & Accountant" on boards 37 and 40. It
     * matters beyond the label: `Payroll::approvalRefusal()` refuses to let a
     * run's preparer approve it, so seeding the preparer as whoever happens to
     * be first in the users table would silently make one estate role unable to
     * approve anything.
     */
    private function preparer(): ?User
    {
        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'estate.treasurer'))
            ->first();
    }

    /**
     * @param  array<string, Employee>  $staff
     */
    private function seedRuns(StatutoryRateVersion $rates, array $staff, ?User $preparer): void
    {
        $payroll = app(Payroll::class);

        foreach (self::RUNS as $row) {
            $start = Carbon::parse($row['month'].'-01');
            $end = $start->copy()->endOfMonth();

            $run = PayrollRun::updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'reference' => 'PR-'.strtoupper(str_replace('-', '', $row['slug'])),
                    'period_label' => $row['label'],
                    'period_start' => $start,
                    'period_end' => $end,
                    'periods_per_year' => Payroll::PERIODS_PER_YEAR,
                    'statutory_rate_version_id' => $rates->id,
                    'currency' => 'JMD',
                    'prepared_by' => $preparer?->getKey(),
                    'prepared_by_name' => $preparer === null ? 'Tracey Reid' : $preparer->name,
                ],
            );

            /*
             * Already dealt with on an earlier run of this seeder. A pay run
             * that has posted cannot be rebuilt — its journal is append-only —
             * and re-posting it would pay four people a second time.
             */
            if ($run->status === PayrollRun::PAID) {
                continue;
            }

            if ($row['status'] === PayrollRun::EXCEPTIONS) {
                $this->seedExceptions($run, $staff);
                $run->forceFill(['status' => PayrollRun::EXCEPTIONS])->save();

                continue;
            }

            /*
             * Calculated through the service rather than by writing lines here,
             * so the seeded figures are the ones the application itself would
             * produce. A fixture computed a second way is a fixture that agrees
             * with the code by coincidence.
             */
            $payroll->calculate($run);

            if ($row['status'] === PayrollRun::PAID) {
                $this->post($run, $preparer);
            }
        }
    }

    /**
     * Post a finished month without going through `Payroll::approve()`.
     *
     * DELIBERATE, AND IT IS THE ONE PLACE THIS SEEDER STEPS AROUND THE SERVICE.
     * `approve()` refuses while the rate version is unverified — that is Q-002
     * and D-021, and it is exactly the refusal board 15 exists to draw. But
     * three of these five months are drawn as ALREADY PAID, and an estate whose
     * ledger shows no payroll at all would make boards 13 and 16 unreachable:
     * board 16's whole argument is that an S01 clears what a run withheld, and
     * there would be nothing to clear.
     *
     * So the history is posted directly and the LIVE run is not. August stops
     * at `calculated` with the refusal on its control, which is the state a
     * reviewer needs to see; May, June and July are the months that happened
     * before this platform was asked the question.
     */
    private function post(PayrollRun $run, ?User $by): void
    {
        $lines = $run->lines()->get();
        $gross = (int) $lines->sum('gross_minor');
        $net = (int) $lines->sum('net_minor');

        if ($gross === 0) {
            return;
        }

        $memo = 'Payroll — '.$run->period_label;

        $entry = app(Ledger::class)->post(
            memo: $memo,
            postings: [
                Posting::debit(Payroll::EXPENSE, $gross, $memo),
                Posting::credit(Payroll::STATUTORY_PAYABLE, $gross - $net, $memo.' — withheld'),
                Posting::credit(Payroll::BANK, $net, $memo.' — net pay'),
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
            'approved_by' => $by?->getKey(),
            'approved_by_name' => 'Patrice Campbell',
            'approved_at' => $run->period_end,
            'journal_ref' => $entry->reference,
        ])->save();
    }

    /**
     * @param  array<string, Employee>  $staff
     */
    private function seedExceptions(PayrollRun $run, array $staff): void
    {
        foreach (self::EXCEPTIONS as $row) {
            $employee = $staff[$row['staff']] ?? null;

            if ($employee === null) {
                continue;
            }

            PayrollException::updateOrCreate(
                [
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'type' => $row['type'],
                ],
                [
                    'detail' => $row['detail'],
                    'period_start' => $row['from'],
                    'period_end' => $row['to'],
                    'status' => PayrollException::UNRESOLVED,
                ],
            );
        }
    }

    /**
     * Board 16's five rows.
     *
     * The August S01 carries the deduction breakdown the August run actually
     * produced, read back off its lines rather than restated — that is what
     * makes "the S01 clears what the run withheld" a fact this seeder cannot
     * accidentally break. The July pair carry July's, and the two that remit
     * nothing carry nothing.
     */
    private function seedFilings(): void
    {
        $august = PayrollRun::query()->where('slug', 'aug-2026')->first();
        $july = PayrollRun::query()->where('slug', 'jul-2026')->first();

        $rows = [
            [
                'code' => StatutoryFiling::S01,
                'title' => 'Statutory Deduction Remittance',
                'period' => 'August 2026',
                'start' => '2026-08-01',
                'end' => '2026-08-31',
                'due' => '2026-09-14',
                'status' => StatutoryFiling::DUE_SOON,
                'filed' => null,
                'run' => $august,
            ],
            [
                'code' => StatutoryFiling::S01,
                'title' => 'Statutory Deduction Remittance',
                'period' => 'July 2026',
                'start' => '2026-07-01',
                'end' => '2026-07-31',
                'due' => '2026-08-14',
                'status' => StatutoryFiling::FILED,
                'filed' => '2026-08-12',
                'run' => $july,
            ],
            [
                'code' => StatutoryFiling::S02,
                'title' => 'Monthly Reconciliation',
                'period' => 'July 2026',
                'start' => '2026-07-01',
                'end' => '2026-07-31',
                'due' => '2026-08-14',
                'status' => StatutoryFiling::FILED,
                'filed' => '2026-08-12',

                /*
                 * Linked to the run but carrying no figures. An S02 reconciles
                 * the S01 that was already remitted; giving it the same four
                 * amounts would make the register look as though July's
                 * deductions were paid twice.
                 */
                'run' => $july,
                'amounts' => false,
            ],
            [
                'code' => StatutoryFiling::GCT,
                'title' => 'Return',
                'period' => 'August 2026',
                'start' => '2026-08-01',
                'end' => '2026-08-31',
                'due' => '2026-09-01',
                'status' => StatutoryFiling::FILED,
                'filed' => '2026-09-01',
                'run' => null,
            ],
            [
                'code' => StatutoryFiling::P24,
                'title' => 'Annual Employer Return',
                'period' => 'Tax year 2025',
                'start' => '2025-01-01',
                'end' => '2025-12-31',
                'due' => '2027-03-31',
                'status' => StatutoryFiling::NOT_STARTED,
                'filed' => null,
                'run' => null,
            ],
        ];

        foreach ($rows as $row) {
            $run = $row['run'];
            $withAmounts = ($row['amounts'] ?? true) && $run instanceof PayrollRun;
            $lines = $withAmounts ? $run->lines()->get() : null;

            StatutoryFiling::updateOrCreate(
                ['form_code' => $row['code'], 'period_label' => $row['period']],
                [
                    'form_title' => $row['title'],
                    'period_start' => $row['start'],
                    'period_end' => $row['end'],
                    'due_on' => $row['due'],
                    'status' => $row['status'],
                    'filed_on' => $row['filed'],
                    'payroll_run_id' => $run?->id,
                    'employees_covered' => $lines?->count(),
                    'nis_minor' => $lines === null ? null : (int) $lines->sum('nis_minor'),
                    'nht_minor' => $lines === null ? null : (int) $lines->sum('nht_minor'),
                    'education_tax_minor' => $lines === null ? null : (int) $lines->sum('education_tax_minor'),
                    'paye_minor' => $lines === null ? null : (int) $lines->sum('paye_minor'),
                    'total_minor' => $lines === null
                        ? null
                        : (int) $lines->sum('gross_minor') - (int) $lines->sum('net_minor'),
                    'currency' => 'JMD',
                ],
            );
        }
    }
}
