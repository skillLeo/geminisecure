<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Services\Payroll\RateTable;
use App\Services\Payroll\StatutoryFilingRegister;
use App\Support\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;

/**
 * Guard payroll and accounting, Super Admin screens 28 to 30.
 *
 * Gemini Security paying its own guards. An estate's staff payroll is a
 * separate ledger in the estate database and never appears here; nothing in
 * this controller opens an estate connection.
 *
 * The screens are the approved boards' shape, not a shape of my own:
 *
 *   28  a tab strip, one exception banner, four KPI cards for the current
 *       run, then the list of runs
 *   29  one table of payslips for a single run, nothing else
 *   30  a tab strip and one card of statutory returns, none of them clickable
 *
 * D-021 is in force. The seeded statutory rates are 2026-04-DRAFT and
 * unverified, so a run may be CALCULATED and reviewed but never APPROVED.
 * Nothing here offers an approve action, and the reason is on the screen.
 */
class PayrollController extends Controller
{
    /**
     * Sortable columns on the pay-run list, mapped to the columns they sort.
     *
     * An allow-list rather than the request string, because `orderBy` takes a
     * column name straight into SQL.
     *
     * @var array<string, string>
     */
    private const RUN_SORTS = [
        'period' => 'period_start',
        'employees' => 'payslips_count',
        'gross' => 'gross_minor',
        'net' => 'net_minor',
        'status' => 'status',
    ];

    /** @var array<string, string> */
    private const SLIP_SORTS = [
        'employee' => 'guards.full_name',
        'client' => 'tenants.name',
        'gross' => 'payslips.gross_minor',
        'nis' => 'payslips.nis_minor',
        'nht' => 'payslips.nht_minor',
        'education_tax' => 'payslips.education_tax_minor',
        'paye' => 'payslips.paye_minor',
        'net' => 'payslips.net_minor',
    ];

    /**
     * Guard states that mean a payslip on this run needs a human look.
     *
     * @var list<string>
     */
    private const NOT_IN_GOOD_STANDING = ['suspended', 'licence_expired', 'inactive'];

    /**
     * Guard states that mean the guard was employed during the period and so
     * should appear on the run.
     *
     * @var list<string>
     */
    private const ON_THE_ROSTER = ['active', 'on_leave', 'licence_expired', 'suspended'];

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));
        [$sort, $direction] = $this->sortFrom($request, self::RUN_SORTS, 'period', 'desc');

        $runs = PayrollRun::query()->with('rateVersion')->withCount('payslips');

        if ($search !== '') {
            $runs->where(function ($query) use ($search): void {
                $query->where('period_label', 'like', '%'.$search.'%')
                    ->orWhere('reference', 'like', '%'.$search.'%')
                    ->orWhere('status', 'like', '%'.$search.'%');
            });
        }

        $listed = $runs->orderBy(self::RUN_SORTS[$sort], $direction)->get();

        /*
         * The KPI cards and the banner describe the CURRENT run, not the
         * filtered list. On the board the four figures are the top row's, not
         * the sum of the three rows below them, and a search that silently
         * rewrote the headline totals would be reporting a different thing
         * under the same four labels.
         */
        $current = PayrollRun::query()->with('rateVersion')->orderByDesc('period_start')->first();
        $currentExceptions = $current === null ? [] : $this->exceptionsFor($current);

        return inertia('Gemini/Payroll/Index', [
            'kpis' => $this->kpis($current),
            'banner' => $this->banner($current, $currentExceptions),
            'runs' => $listed->map(function (PayrollRun $run) use ($current, $currentExceptions): array {
                /*
                 * Exceptions are recomputed only for a run still under review.
                 * An approved run's badge says when it was approved, so the
                 * work of finding its exceptions would be thrown away.
                 */
                $exceptions = match (true) {
                    $current !== null && $run->id === $current->id => $currentExceptions,
                    in_array($run->status, ['draft', 'calculated'], true) => $this->exceptionsFor($run),
                    default => [],
                };

                $badge = $this->badgeFor($run, count($exceptions));

                return [
                    'id' => $run->id,
                    'period' => $run->period_label,
                    'employees' => number_format((int) $run->payslips_count),
                    'gross' => MoneyFormatter::fromMinor($run->gross_minor, $run->currency),
                    'net' => MoneyFormatter::fromMinor($run->net_minor, $run->currency),
                    'badge_class' => $badge['class'],
                    'badge_label' => $badge['label'],
                    'blocked_reason' => $run->blockedReason(),
                    /*
                     * "Review & approve" only when approval is genuinely
                     * available. While D-021 stands it never is, so the link
                     * says what it actually does. It restores itself the day
                     * an accountant signs the rates off.
                     */
                    'action_label' => match (true) {
                        $run->canBeApproved() => 'Review & approve',
                        $run->status === 'calculated' || $run->status === 'draft' => 'Review',
                        default => 'View',
                    },
                ];
            })->all(),
            'filters' => ['q' => $search, 'sort' => $sort, 'dir' => $direction],
        ]);
    }

    public function show(Request $request, PayrollRun $run): Response
    {
        $search = trim((string) $request->query('q', ''));
        [$sort, $direction] = $this->sortFrom($request, self::SLIP_SORTS, 'client', 'asc');

        $periodEnd = $run->period_end;

        /*
         * The query builder, not Eloquent.
         *
         * This selects the guard's name and standing and the estate's name
         * alongside the payslip's own columns. Hydrating a Payslip would hand
         * back model objects carrying attributes the class does not declare,
         * and the join is what makes sorting by employee or client a database
         * sort rather than a second sort in PHP over an already-sorted set.
         *
         * A LEFT join to tenants: a payslip's tenant_id is nullable, because a
         * guard between postings is a real state and not a broken row.
         */
        $query = DB::connection('mysql')
            ->table('payslips')
            ->join('guards', 'guards.id', '=', 'payslips.guard_id')
            ->leftJoin('tenants', 'tenants.id', '=', 'payslips.tenant_id')
            ->where('payslips.payroll_run_id', $run->id)
            ->select(
                'payslips.id',
                'payslips.guard_id',
                'payslips.currency',
                'payslips.gross_minor',
                'payslips.nis_minor',
                'payslips.nht_minor',
                'payslips.education_tax_minor',
                'payslips.paye_minor',
                'payslips.net_minor',
                'payslips.paye_note',
                'guards.full_name',
                'guards.status as guard_status',
                'guards.psra_expires_on',
                'tenants.name as estate_name',
            );

        if ($search !== '') {
            $query->where(function ($inner) use ($search): void {
                $inner->where('guards.full_name', 'like', '%'.$search.'%')
                    ->orWhere('tenants.name', 'like', '%'.$search.'%');
            });
        }

        $query->orderBy(self::SLIP_SORTS[$sort], $direction);

        // A stable tiebreak, so two guards at the same client or on the same
        // gross never swap places between one request and the next.
        if ($sort !== 'employee') {
            $query->orderBy('guards.full_name');
        }

        $payslips = $query->get()->map(function (object $row) use ($periodEnd): array {
            $lapsed = $this->licenceLapsedBefore($row, $periodEnd);
            $standing = ! in_array((string) $row->guard_status, self::NOT_IN_GOOD_STANDING, true);
            $exception = $lapsed || ! $standing;

            $currency = (string) $row->currency;

            return [
                'id' => (int) $row->id,
                'guard_id' => (int) $row->guard_id,
                'employee' => (string) $row->full_name,
                'initials' => $this->initials((string) $row->full_name),
                'estate' => $row->estate_name === null ? 'Unassigned' : (string) $row->estate_name,
                'gross' => MoneyFormatter::fromMinor((int) $row->gross_minor, $currency),
                'nis' => MoneyFormatter::fromMinor((int) $row->nis_minor, $currency),
                'nht' => MoneyFormatter::fromMinor((int) $row->nht_minor, $currency),
                'education_tax' => MoneyFormatter::fromMinor((int) $row->education_tax_minor, $currency),
                'paye' => MoneyFormatter::fromMinor((int) $row->paye_minor, $currency),
                'net' => MoneyFormatter::fromMinor((int) $row->net_minor, $currency),
                /*
                 * Most guards fall under the PAYE threshold, so a bare 0.00
                 * reads as a defect to the person holding the payslip. The
                 * board has no room for a second line, so the sentence that
                 * names the threshold rides on the cell itself.
                 */
                'paye_note' => $row->paye_note === null ? null : (string) $row->paye_note,
                'exception' => $exception,
                'exception_reason' => $exception ? $this->paidWhileLapsedReason($row, $periodEnd) : null,
            ];
        })->all();

        return inertia('Gemini/Payroll/Show', [
            'run' => [
                'id' => $run->id,
                'reference' => $run->reference,
                'period' => $run->period_label,
                'status' => $run->status,
                'blocked_reason' => $run->blockedReason(),
            ],
            /*
             * Whether the employee cell is a link at all.
             *
             * The guard roster is its own module with its own permission, and
             * a role can hold payroll without holding it. Asking the same gate
             * the route asks means the cell is a plain cell for those roles
             * rather than a link that lands on a 403.
             */
            'can_open_guards' => Gate::allows('gemini.guard_workforce.view'),
            'payslips' => $payslips,
            'filters' => ['q' => $search, 'sort' => $sort, 'dir' => $direction],
        ]);
    }

    /**
     * Statutory filings, board screen super-admin-30.
     *
     * The board's body is a tab strip and one card of rows, and that is all
     * this renders. Every row is a POSTED RECORD or an obligation to produce
     * one: nothing on it is clickable, and there is no edit and no delete, not
     * even a disabled one. A greyed-out delete would tell the reader that
     * deleting a filed return is a thing this system does and they merely lack
     * the right to do it. It is not.
     *
     * The topbar's "Start new filing" is inert and carries the real reason,
     * which today is D-021: a return is prepared from an APPROVED run, and no
     * run can be approved while the statutory rates are a draft.
     */
    public function filings(Request $request, StatutoryFilingRegister $register): Response
    {
        $year = $this->yearFrom($request);

        /*
         * The sorting keys the register used are dropped here rather than in
         * the service. The service needs them to order the list; the screen
         * would only be able to misuse them.
         */
        $rows = array_map(
            static fn (array $row): array => Arr::except($row, ['sort_bucket', 'sort_due', 'sort_code']),
            $register->rows($year),
        );

        return inertia('Gemini/Payroll/Filings', [
            'filings' => $rows,
            'blockedReason' => $register->blockedReason(),
            'filters' => ['year' => $year],
            'years' => $register->years(),
        ]);
    }

    /**
     * The statutory rate table — board screen super-admin-31.
     *
     * D-021 is the subject of this screen rather than a caveat on it: the rates
     * are a DRAFT nobody qualified has checked against a worked example, and no
     * run may be approved against them. The screen shows the arithmetic
     * honestly enough to be checked and states plainly that it has not been.
     * There is deliberately no means of approving it here, and no route that
     * could grow into one.
     *
     * `rates` returns null only when no version has ever been recorded, which
     * is a genuine first-use state rather than an error.
     */
    public function rates(RateTable $table): Response
    {
        return inertia('Gemini/Payroll/Rates', [
            'table' => $table->forVersion(),
        ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * The year a register is narrowed to, or null for all of them.
     *
     * Read from the URL rather than from a control, because the filings board
     * draws no filter and inventing one would be authoring a piece of design
     * nobody approved. It is still a real, linkable filter — /payroll/filings
     * ?year=2025 is a page somebody can bookmark — and when it matches nothing
     * the screen says which years do hold returns and offers to clear it.
     *
     * Anything that is not a plausible year is ignored rather than rejected: a
     * mistyped URL should show the register, not an error page.
     */
    private function yearFrom(Request $request): ?int
    {
        $year = $request->query('year');

        if (! is_string($year) || ! ctype_digit($year)) {
            return null;
        }

        $value = (int) $year;

        return $value >= 2000 && $value <= 2100 ? $value : null;
    }

    /**
     * The four KPI cards, in the order the board draws them.
     *
     * Deductions are gross less net in MINOR UNITS and formatted once. They
     * are never re-derived from the two formatted strings, and never from a
     * float: the payslip table below has to add up to these figures exactly.
     *
     * @return list<array<string, mixed>>
     */
    private function kpis(?PayrollRun $run): array
    {
        $employees = $run === null ? 0 : $run->payslips()->count();
        $grossMinor = $run === null ? 0 : $run->gross_minor;
        $netMinor = $run === null ? 0 : $run->net_minor;
        $currency = $run === null ? MoneyFormatter::DEFAULT_CURRENCY : $run->currency;

        return [
            [
                'key' => 'employees',
                'icon' => 'guards',
                'stroke' => 1.7,
                'value' => number_format($employees),
                'label' => 'Employees',
            ],
            [
                'key' => 'gross',
                'icon' => 'currency',
                'stroke' => 1.7,
                'value' => MoneyFormatter::fromMinor($grossMinor, $currency),
                'label' => 'Total gross',
            ],
            [
                'key' => 'deductions',
                'icon' => 'billing',
                'stroke' => 1.7,
                'value' => MoneyFormatter::fromMinor($grossMinor - $netMinor, $currency),
                'label' => 'Total deductions',
            ],
            [
                'key' => 'net',
                'icon' => 'check',
                'stroke' => 3,
                'value' => MoneyFormatter::fromMinor($netMinor, $currency),
                'label' => 'Total net pay',
            ],
        ];
    }

    /**
     * The one banner the board draws, carrying whatever is actually wrong.
     *
     * The board shows a single exception banner, so this returns a single
     * alert rather than a stack: a screen that grows a second red bar whenever
     * two things are true at once is a different layout, not the approved one.
     * Exceptions lead, because they name a person whose pay is wrong; the
     * D-021 approval block rides on the same line rather than being lost.
     *
     * @param  list<array{name: string, reason: string}>  $exceptions
     * @return array{title: string, detail: string}|null
     */
    private function banner(?PayrollRun $run, array $exceptions): ?array
    {
        if ($run === null) {
            return null;
        }

        $blocked = $run->blockedReason();

        if ($exceptions !== []) {
            $count = count($exceptions);
            $first = $exceptions[0];

            $title = $count === 1
                ? $first['name'].' — 1 exception in this run'
                : sprintf(
                    '%s and %d other%s — %d exceptions in this run',
                    $first['name'],
                    $count - 1,
                    $count === 2 ? '' : 's',
                    $count,
                );

            $detail = $count === 1
                ? $first['reason']
                : 'Each is suspended, out of licence, or missing from the run entirely. Settle them before it is approved.';

            if ($blocked !== null) {
                $detail .= ' Approval is blocked too: the statutory rates for this period are unverified.';
            }

            return ['title' => $title, 'detail' => $detail];
        }

        if ($blocked !== null) {
            return [
                'title' => 'Approval blocked — statutory rates for this period are unverified',
                'detail' => sprintf(
                    'An accountant must sign off the %s rates before this run can be approved. The figures below are calculated and can be reviewed in the meantime.',
                    $run->rateVersion->label,
                ),
            ];
        }

        return null;
    }

    /**
     * Everything about this run that a reviewer has to settle before it is
     * approved, in two kinds.
     *
     * Paid but out of standing: the guard is on the run and drew a full
     * period's pay while suspended, off-roster, or out of licence.
     *
     * On the roster but unpaid: the guard was employed for the period and has
     * no payslip on the run at all, so the period is silently unpaid. This is
     * the harder one to notice, because the row simply is not there.
     *
     * @return list<array{name: string, reason: string}>
     */
    private function exceptionsFor(PayrollRun $run): array
    {
        $periodEnd = $run->period_end;
        $periodEndDate = $periodEnd->toDateString();

        $paid = DB::connection('mysql')
            ->table('payslips')
            ->join('guards', 'guards.id', '=', 'payslips.guard_id')
            ->where('payslips.payroll_run_id', $run->id)
            ->where(function ($query) use ($periodEndDate): void {
                $query->whereIn('guards.status', self::NOT_IN_GOOD_STANDING)
                    ->orWhere('guards.psra_expires_on', '<', $periodEndDate);
            })
            ->orderBy('guards.full_name')
            ->select('guards.full_name', 'guards.status', 'guards.psra_expires_on')
            ->get();

        $unpaid = DB::connection('mysql')
            ->table('guards')
            ->whereIn('guards.status', self::ON_THE_ROSTER)
            ->where(function ($query) use ($periodEndDate): void {
                $query->whereNull('guards.hired_on')->orWhere('guards.hired_on', '<=', $periodEndDate);
            })
            ->whereNotExists(function ($query) use ($run): void {
                $query->selectRaw('1')
                    ->from('payslips')
                    ->whereColumn('payslips.guard_id', 'guards.id')
                    ->where('payslips.payroll_run_id', $run->id);
            })
            ->orderBy('guards.full_name')
            ->select('guards.full_name', 'guards.status', 'guards.psra_expires_on')
            ->get();

        $exceptions = [];

        foreach ($paid as $guard) {
            $exceptions[] = [
                'name' => (string) $guard->full_name,
                'reason' => $this->paidWhileLapsedReason($guard, $periodEnd),
            ];
        }

        foreach ($unpaid as $guard) {
            $exceptions[] = [
                'name' => (string) $guard->full_name,
                'reason' => $this->unpaidReason($guard),
            ];
        }

        usort($exceptions, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $exceptions;
    }

    /** Why a guard who WAS paid on this run still needs a look. */
    private function paidWhileLapsedReason(object $guard, Carbon $periodEnd): string
    {
        $expiry = $this->expiry($guard);

        if ($expiry !== null && $expiry->lt($periodEnd)) {
            return sprintf(
                'PSRA licence expired %s, before the period closed on %s — but paid for the whole period.',
                $expiry->format('j M Y'),
                $periodEnd->format('j M Y'),
            );
        }

        return sprintf(
            '%s on the roster, but paid for the whole period.',
            $this->statusLabel((string) $guard->status),
        );
    }

    /** Why a guard with NO payslip on this run needs a look. */
    private function unpaidReason(object $guard): string
    {
        $expiry = $this->expiry($guard);

        return match ((string) $guard->status) {
            'licence_expired' => $expiry === null
                ? 'PSRA licence expired — no payslip in this run, so the period is unpaid.'
                : sprintf(
                    'PSRA licence expired %s — no payslip in this run, so the period is unpaid.',
                    $expiry->format('j M Y'),
                ),
            'suspended' => 'Suspended — no payslip in this run, so the period is unpaid.',
            default => 'On the roster for this period, with no payslip in this run.',
        };
    }

    private function licenceLapsedBefore(object $guard, Carbon $periodEnd): bool
    {
        $expiry = $this->expiry($guard);

        return $expiry !== null && $expiry->lt($periodEnd);
    }

    private function expiry(object $guard): ?Carbon
    {
        $raw = $guard->psra_expires_on ?? null;

        return $raw === null ? null : Carbon::parse((string) $raw);
    }

    /**
     * The run's status badge, in the board's own vocabulary.
     *
     * @return array{class: string, label: string}
     */
    private function badgeFor(PayrollRun $run, int $exceptionCount): array
    {
        if ($exceptionCount > 0) {
            return [
                'class' => 'exception',
                'label' => $exceptionCount.' exception'.($exceptionCount === 1 ? '' : 's'),
            ];
        }

        $on = $run->approved_at === null ? '' : ' '.$run->approved_at->format('M j');

        return match ($run->status) {
            'paid' => ['class' => 'approved', 'label' => 'Paid'.$on],
            'approved' => ['class' => 'approved', 'label' => 'Approved'.$on],
            'calculated' => ['class' => 'pending', 'label' => 'Calculated'],
            default => ['class' => 'draft', 'label' => 'Draft'],
        };
    }

    /**
     * The sort column and direction, both from the URL so a sorted view can be
     * linked, reloaded and shared.
     *
     * @param  array<string, string>  $allowed
     * @return array{0: string, 1: string}
     */
    private function sortFrom(Request $request, array $allowed, string $default, string $defaultDirection): array
    {
        $sort = (string) $request->query('sort', $default);

        if (! array_key_exists($sort, $allowed)) {
            $sort = $default;
        }

        $direction = strtolower((string) $request->query('dir', $defaultDirection)) === 'asc' ? 'asc' : 'desc';

        return [$sort, $direction];
    }

    /** Two characters, uppercase, as the board draws an avatar. */
    private function initials(string $name): string
    {
        $parts = array_values(array_filter(explode(' ', $name)));

        return strtoupper(implode('', array_map(
            static fn (string $part): string => mb_substr($part, 0, 1),
            array_slice($parts, 0, 2),
        )));
    }

    private function statusLabel(string $status): string
    {
        return ucfirst(str_replace('_', ' ', $status));
    }
}
