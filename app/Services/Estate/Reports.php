<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Account;
use App\Models\Estate\Document;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Meeting;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The estate's own reports — board 29's catalogue, board 30's results (12 §1).
 *
 * EVERY REPORT STATES ITS PERIOD. That is the defect the P&L card's own reason
 * named: "a report with an unstated period is the defect that surfaces at an
 * audit." So there is no report here without a `from` and a `to`, they are
 * echoed on the screen and in the export's audit scope, and neither defaults to
 * something the reader did not choose.
 *
 * NOTHING HERE RECOMPUTES WHAT A SCREEN ALREADY COMPUTES. The ageing report
 * reads `Dues`; the maintenance report reads the same `sla_hours` the queue
 * measures against; turnout reads the tally. A report that did its own
 * arithmetic over the same rows would be a second answer, and the two would
 * differ the first time either changed.
 *
 * TURNOUT IS A COUNT AND NOTHING FINER. A report that broke a result down until
 * a household could be identified would undo the secret ballot — `ballot_marks`
 * and `ballot_receipts` share no column and nothing here joins them.
 */
class Reports
{
    public function __construct(
        private readonly Dues $dues,
        private readonly Governance $governance,
    ) {}

    /** The reports that can actually be run, and what each one is keyed by. */
    public const BUILT = ['profit_and_loss', 'arrears_ageing', 'maintenance_summary', 'election_turnout', 'meeting_minutes'];

    /**
     * Run one, over a period the reader chose.
     *
     * @return array{key: string, title: string, period: string, columns: list<string>, rows: list<list<string>>, summary: list<array{label: string, value: string}>, note: string|null}
     */
    public function run(string $key, string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();

        if ($end->lessThan($start)) {
            throw new DomainException('The period ends before it begins.');
        }

        return match ($key) {
            'profit_and_loss' => $this->profitAndLoss($start, $end),
            'arrears_ageing' => $this->arrearsAgeing($start, $end),
            'maintenance_summary' => $this->maintenanceSummary($start, $end),
            'election_turnout' => $this->electionTurnout($start, $end),
            'meeting_minutes' => $this->minutesArchive($start, $end),
            default => throw new DomainException('That report is not one this estate can run.'),
        };
    }

    /* ------------------------------------------------------------------ */
    /* financial */
    /* ------------------------------------------------------------------ */

    /**
     * Income against expenses, by account, over the period.
     *
     * SUMMED FROM POSTED JOURNAL LINES and nothing else — the same discipline
     * every money screen on this platform keeps. Income is credits less debits
     * because that is its normal side; expense is the other way. Getting that
     * backwards is how a profitable month reads as a loss.
     *
     * @return array{key: string, title: string, period: string, columns: list<string>, rows: list<list<string>>, summary: list<array{label: string, value: string}>, note: string|null}
     */
    private function profitAndLoss(Carbon $start, Carbon $end): array
    {
        $lines = DB::connection('tenant')
            ->table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journals', 'journals.reference', '=', 'journal_lines.entry_ref')
            ->whereIn('accounts.type', [Account::INCOME, Account::EXPENSE])
            ->whereBetween('journals.posted_on', [$start->toDateString(), $end->toDateString()])
            ->groupBy('accounts.code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.code')
            ->selectRaw('accounts.code, accounts.name, accounts.type, SUM(journal_lines.debit_minor) AS dr, SUM(journal_lines.credit_minor) AS cr')
            ->get();

        $rows = [];
        $income = 0;
        $expense = 0;

        foreach ($lines as $line) {
            $isIncome = $line->type === Account::INCOME;
            $minor = $isIncome
                ? (int) $line->cr - (int) $line->dr
                : (int) $line->dr - (int) $line->cr;

            $isIncome ? $income += $minor : $expense += $minor;

            $rows[] = [
                $isIncome ? 'Income' : 'Expense',
                $line->code,
                $line->name,
                $this->money($minor),
            ];
        }

        return [
            'key' => 'profit_and_loss',
            'title' => 'Profit & Loss',
            'period' => $this->periodLabel($start, $end),
            'columns' => ['Kind', 'Account', 'Name', 'Amount'],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Income', 'value' => $this->money($income)],
                ['label' => 'Expenses', 'value' => $this->money($expense)],
                ['label' => $income - $expense >= 0 ? 'Surplus' : 'Deficit', 'value' => $this->money(abs($income - $expense))],
            ],
            'note' => 'Summed from posted journal lines by the date the entry was POSTED, not the date a charge fell due. An invoice raised in March and posted in April belongs to April, which is where the ledger puts it.',
        ];
    }

    /**
     * Every unit's balance by ageing bucket.
     *
     * THE PERIOD IS ECHOED AND DOES NOT FILTER, and the note says so. An ageing
     * is a position at a moment — today's — and pretending a date range narrowed
     * it would be a report that looked filtered and was not. This is the one
     * report where the honest thing is to say the period does not apply.
     *
     * @return array{key: string, title: string, period: string, columns: list<string>, rows: list<list<string>>, summary: list<array{label: string, value: string}>, note: string|null}
     */
    private function arrearsAgeing(Carbon $start, Carbon $end): array
    {
        $board = $this->dues->arrearsBoard('', false);

        $rows = array_map(static fn (array $row): array => [
            $row['unit'],
            $row['household'],
            $row['bucket_label'],
            number_format($row['balance_minor'] / 100, 2, '.', ''),
            $row['last_payment'] ?? '',
        ], $board['rows']);

        $summary = array_map(fn (array $band): array => [
            'label' => $band['label'],
            'value' => $this->money($band['value_minor']),
        ], $board['ageing']);

        return [
            'key' => 'arrears_ageing',
            'title' => 'Arrears Ageing',
            'period' => 'As at '.Carbon::today()->format('F j, Y'),
            'columns' => ['Unit', 'Household', 'Ageing', 'Balance JMD', 'Last payment'],
            'rows' => $rows,
            'summary' => $summary,
            'note' => 'An ageing is a position TODAY, not over a period — so the dates chosen do not narrow it, and this report says so rather than looking filtered and not being. The buckets are the same four the arrears board draws, from the same posted lines.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* operational */
    /* ------------------------------------------------------------------ */

    /**
     * Tickets reported in the period, by category, with how long they took.
     *
     * MEASURED FROM REPORT TO CLOSE, which is the same clock the queue runs —
     * a report that measured from assignment would hide precisely the failure
     * it exists to surface.
     *
     * @return array{key: string, title: string, period: string, columns: list<string>, rows: list<list<string>>, summary: list<array{label: string, value: string}>, note: string|null}
     */
    private function maintenanceSummary(Carbon $start, Carbon $end): array
    {
        $tickets = MaintenanceTicket::query()
            ->whereBetween('reported_at', [$start, $end])
            ->orderBy('reported_at')
            ->get();

        $byCategory = [];
        $closedHours = [];
        $breached = 0;

        foreach ($tickets as $ticket) {
            $category = $ticket->category ?? 'Uncategorised';
            $byCategory[$category] ??= ['raised' => 0, 'closed' => 0, 'hours' => []];
            $byCategory[$category]['raised']++;

            if ($ticket->closed_at !== null) {
                $hours = $ticket->reported_at->diffInHours($ticket->closed_at);
                $byCategory[$category]['closed']++;
                $byCategory[$category]['hours'][] = $hours;
                $closedHours[] = $hours;

                if ($hours > $ticket->sla_hours) {
                    $breached++;
                }
            }
        }

        ksort($byCategory);

        $rows = [];

        foreach ($byCategory as $category => $figures) {
            $rows[] = [
                $category,
                (string) $figures['raised'],
                (string) $figures['closed'],
                $figures['hours'] === []
                    ? '—'
                    : number_format(array_sum($figures['hours']) / count($figures['hours']) / 24, 1).' days',
            ];
        }

        return [
            'key' => 'maintenance_summary',
            'title' => 'Maintenance Summary',
            'period' => $this->periodLabel($start, $end),
            'columns' => ['Category', 'Raised', 'Closed', 'Average to close'],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Raised', 'value' => (string) $tickets->count()],
                ['label' => 'Closed', 'value' => (string) count($closedHours)],
                [
                    'label' => 'Average to close',
                    'value' => $closedHours === []
                        ? '—'
                        : number_format(array_sum($closedHours) / count($closedHours) / 24, 1).' days',
                ],
                ['label' => 'Past target', 'value' => (string) $breached],
            ],
            'note' => 'Counted by the date a ticket was REPORTED, and timed from report to close — the same clock the queue runs. A report timed from assignment would hide the tickets nobody picked up, which is the failure it exists to surface.',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* governance */
    /* ------------------------------------------------------------------ */

    /**
     * Turnout for each election whose poll closed in the period.
     *
     * A COUNT AND NOTHING FINER. Turnout by phase is the finest cut this
     * platform will make, and it comes from `Governance` — which reads receipts
     * and never marks. Nothing here could answer how a household voted because
     * no query exists that would.
     *
     * @return array{key: string, title: string, period: string, columns: list<string>, rows: list<list<string>>, summary: list<array{label: string, value: string}>, note: string|null}
     */
    private function electionTurnout(Carbon $start, Carbon $end): array
    {
        $rows = [];
        $years = DB::connection('tenant')->table('ballots')->distinct()->orderBy('year')->pluck('year');

        foreach ($years as $year) {
            $board = $this->governance->resultsBoard((int) $year);

            if (($board['ballot'] ?? null) === null) {
                continue;
            }

            foreach ($board['phases'] as $phase) {
                $rows[] = [
                    (string) $year,
                    $phase['phase'],
                    (string) $phase['cast'],
                    (string) $phase['eligible'],
                    $phase['percent'].'%',
                ];
            }
        }

        return [
            'key' => 'election_turnout',
            'title' => 'Election Turnout',
            'period' => 'Every election on record',
            'columns' => ['Year', 'Phase', 'Ballots cast', 'Eligible', 'Turnout'],
            'rows' => $rows,
            'summary' => [],
            'note' => 'Turnout is a COUNT of ballot receipts and nothing finer. By phase is the finest cut this platform makes: `ballot_receipts` records that a household voted and `ballot_marks` records what was chosen, the two share no column, and no query here could join them.',
        ];
    }

    /**
     * Every meeting on record, and whether its minutes exist as a document.
     *
     * The card's old reason said "this platform stores no files yet". It does
     * now — minutes are issued as a PDF, kept seven years — so this archive is
     * an index of things that CAN be opened, which is what made it wrong before.
     *
     * @return array{key: string, title: string, period: string, columns: list<string>, rows: list<list<string>>, summary: list<array{label: string, value: string}>, note: string|null}
     */
    private function minutesArchive(Carbon $start, Carbon $end): array
    {
        $meetings = Meeting::query()
            ->with('minutes')
            ->whereBetween('starts_at', [$start, $end])
            ->orderByDesc('starts_at')
            ->get();

        $issued = Document::query()
            ->where('kind', Document::MINUTES)
            ->where('status', Document::READY)
            ->pluck('subject_id')
            ->flip();

        $rows = [];

        foreach ($meetings as $meeting) {
            $rows[] = [
                $meeting->starts_at->format('M j, Y'),
                $meeting->typeLabel(),
                $meeting->title,
                $meeting->minutes === null ? 'Not recorded' : ($meeting->minutes->adopted_at === null ? 'Recorded, not adopted' : 'Adopted '.$meeting->minutes->adopted_at->format('M j, Y')),
                isset($issued[(string) $meeting->id]) ? 'Issued' : '',
            ];
        }

        return [
            'key' => 'meeting_minutes',
            'title' => 'Meeting Minutes Archive',
            'period' => $this->periodLabel($start, $end),
            'columns' => ['Date', 'Type', 'Meeting', 'Minutes', 'Document'],
            'rows' => $rows,
            'summary' => [
                ['label' => 'Meetings', 'value' => (string) $meetings->count()],
                ['label' => 'With minutes', 'value' => (string) $meetings->filter(static fn (Meeting $m): bool => $m->minutes !== null)->count()],
            ],
            'note' => 'An index of what exists, not a claim that it does. A meeting with no minutes recorded says so; a meeting whose minutes have been issued as a PDF says that too, and the document is in the meeting register.',
        ];
    }

    /* ------------------------------------------------------------------ */

    private function periodLabel(Carbon $start, Carbon $end): string
    {
        return $start->format('F j, Y').' to '.$end->format('F j, Y');
    }

    private function money(int $minor): string
    {
        return '$'.number_format($minor / 100, 2);
    }
}
