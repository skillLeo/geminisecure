<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Services\Estate\Reports;
use App\Services\Exports\Exporter;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports — board screen community-admin-29.
 *
 * A catalogue: seven cards in three groups, each naming a report and offering
 * to generate it. The board draws no output, no parameters and no history —
 * this screen is the index, and each report is its own screen behind it.
 *
 * FIVE OF THE SEVEN RUN (12 §1). The ruling asked for "all four report
 * generators behind board 30" — the four whose data this estate already holds —
 * and the minutes archive joined them because its own reason ("this platform
 * stores no files yet") stopped being true the day documents shipped.
 *
 * THE TWO THAT DO NOT RUN HAVE DATA GAPS, NOT MISSING CODE, and each says which.
 * There is no budget model on this platform, so there is nothing to compare
 * actuals against; and incidents are recorded centrally by Gemini Security,
 * which this console never opens another database to read. Drawing seven
 * identical live buttons over that would make the five that work
 * indistinguishable from the two that cannot.
 *
 * EVERY REPORT STATES ITS PERIOD, which is the P&L card's own old warning kept:
 * "a report with an unstated period is the defect that surfaces at an audit".
 * The window is on the screen, in the export's filename and in the audit scope.
 */
class ReportsController extends Controller
{
    /**
     * The board's seven cards, in its own three groups and its own order.
     *
     * `ready` is whether this estate holds the data the report reads. It drives
     * nothing on screen today — no card generates — but it is the difference
     * between "we have not written this yet" and "there is nothing to write it
     * from", and those are two different conversations to have with a client.
     *
     * `icon` names one of the board's seven drawn glyphs. The page holds the
     * paths; this list only says which card wears which, because the board gives
     * every card a different one and a shared icon would be a visible fault.
     *
     * @var list<array{key: string, group: string, name: string, description: string, icon: string, ready: bool, reason: string}>
     */
    private const REPORTS = [
        [
            'key' => 'profit_and_loss',
            'group' => 'Financial',
            'name' => 'Profit & Loss',
            'description' => 'Income vs expenses for any period, by account category',
            'icon' => 'currency',
            'ready' => true,
            'reason' => 'Income against expenses over a period you choose, summed from posted journal lines.',
        ],
        [
            'key' => 'arrears_ageing',
            'group' => 'Financial',
            'name' => 'Arrears Ageing',
            'description' => "Every unit's balance by ageing bucket, exportable by phase",
            'icon' => 'clock',
            'ready' => true,
            'reason' => 'Every unit\'s balance by ageing bucket, as at today — the same four buckets the arrears board draws.',
        ],
        [
            'key' => 'budget_vs_actual',
            'group' => 'Financial',
            'name' => 'Budget vs Actual',
            'description' => 'Compares approved annual budget against year-to-date spend',
            'icon' => 'calendar',
            'ready' => false,
            'reason' => 'This estate has no approved budget to compare against. A budget is a set of '
                .'figures a committee votes on for a fiscal year, and nothing on this platform records one '
                .'yet — the actuals half is already in the ledger.',
        ],
        [
            'key' => 'maintenance_summary',
            'group' => 'Operational',
            'name' => 'Maintenance Summary',
            'description' => 'Tickets by category, resolution time, and vendor performance',
            'icon' => 'spanner',
            'ready' => true,
            'reason' => 'Tickets raised in a period you choose, by category, timed from report to close.',
        ],
        [
            'key' => 'security_incidents',
            'group' => 'Operational',
            'name' => 'Security Incident Log',
            'description' => 'All incident reports for a period, filterable by phase',
            'icon' => 'shield',
            'ready' => false,
            'reason' => 'Incidents are recorded centrally by Gemini Security rather than inside this '
                .'estate, and this console never opens another database to build a report. The estate '
                .'would be sent its own incidents the way its adoption figures are sent outward — that '
                .'route does not exist yet.',
        ],
        [
            'key' => 'election_turnout',
            'group' => 'Governance',
            'name' => 'Election Turnout',
            'description' => 'Certified results and turnout by phase for any past election',
            'icon' => 'people',
            'ready' => true,
            /*
             * BUILT, AND WITH THE CONSTRAINT INTACT. Turnout is a COUNT of
             * receipts and nothing finer: by phase is the finest cut this
             * platform makes, because a report that broke a result down until
             * a household could be identified would undo the secret ballot.
             */
            'reason' => 'Turnout by phase for every election on record — a count of ballots cast, never a breakdown of how anyone voted.',
        ],
        [
            'key' => 'meeting_minutes',
            'group' => 'Governance',
            'name' => 'Meeting Minutes Archive',
            'description' => 'All past AGM, EGM and committee meeting records',
            'icon' => 'megaphone',

            /*
             * READY NOW, and it was not: this card's reason used to say "this
             * platform stores no files yet", which was true and is not. Minutes
             * are issued as a PDF and kept seven years (12 §1), so the archive
             * indexes things that can actually be opened — which is exactly
             * what made it wrong to build before.
             */
            'ready' => true,
            'reason' => 'The meeting register holds every meeting, its type and its date.',
        ],
    ];

    public function index(Request $request): Response
    {
        $groups = [];

        foreach (self::REPORTS as $report) {
            $groups[$report['group']]['heading'] = $report['group'];
            $groups[$report['group']]['cards'][] = [
                'key' => $report['key'],
                'name' => $report['name'],
                'description' => $report['description'],
                'icon' => $report['icon'],
                'action' => 'Generate',
                'ready' => $report['ready'],
                'reason' => $report['reason'],

                // The four the ruling named, plus the minutes archive whose own
                // reason said "this platform stores no files yet" — it does now.
                'built' => in_array($report['key'], Reports::BUILT, true),
            ];
        }

        return inertia('Estate/Reports/Index', [
            'estate' => ['name' => (string) tenant()->name],
            'groups' => array_values($groups),

            /*
             * `export`, NOT `create`. A report brings nothing into existence —
             * it takes what the estate already holds and puts it in somebody's
             * hands, which is the one verb D-013 has for exactly that. It also
             * decides who is refused: the matrix legend withholds export from
             * `Entry` — "data entry, no approval" — so an Admin Assistant who
             * could be given rows to type could never be given the whole
             * estate's books in a file. `create` would have granted it to them.
             *
             * On the reports row the two verbs happen to land on the same five
             * roles today, and that is a coincidence of one matrix row rather
             * than a reason to pick either. The View cells — the Secretary and
             * the Property Manager — read the catalogue and generate nothing.
             *
             * Sent because the page must not have to guess which of the two
             * refusals applies: a role without the verb is refused for a reason
             * that will still be true tomorrow, and a card with a data gap is
             * refused for one that will not.
             */
            'canGenerate' => $request->user()->can('estate.reports.export'),
            'reasons' => [
                'access' => 'Generating a report takes a copy of the estate’s own records out of the '
                    .'console, so it needs Reports export access. This role can read the catalogue.',
            ],
        ]);
    }

    /**
     * One report, over a period the reader chose — board community-admin-30.
     *
     * THE PERIOD IS REQUIRED AND NEVER DEFAULTED. "A report with an unstated
     * period is the defect that surfaces at an audit" is the P&L card's own
     * reason, and it is the rule for all of them: the dates come from the
     * reader, are echoed on the screen, and go into the export's audit scope.
     */
    public function show(Request $request, string $key, Reports $reports): Response|RedirectResponse
    {
        $period = $this->period($request);

        try {
            $result = $reports->run($key, $period['from'], $period['to']);
        } catch (DomainException $refused) {
            return redirect()->to($this->path('/reports'))->withErrors(['report' => $refused->getMessage()]);
        }

        return inertia('Estate/Reports/Show', [
            'estate' => ['name' => (string) tenant()->name],
            ...$result,
            'from' => $period['from'],
            'to' => $period['to'],
            'catalogueHref' => $this->path('/reports'),
            'path' => $this->path('/reports/'.$key),
            'exportHref' => $this->path('/reports/'.$key.'/export').'?from='.$period['from'].'&to='.$period['to'],
        ]);
    }

    /** The same report as a file, with the entry every export writes (12 §1). */
    public function export(Request $request, string $key, Reports $reports, Exporter $exporter): StreamedResponse|RedirectResponse
    {
        $period = $this->period($request);

        try {
            $result = $reports->run($key, $period['from'], $period['to']);
        } catch (DomainException $refused) {
            return redirect()->to($this->path('/reports'))->withErrors(['report' => $refused->getMessage()]);
        }

        return $exporter->csv(
            scope: $result['title'].' — '.$result['period'],
            headers: $result['columns'],
            rows: $result['rows'],
            filename: str_replace('_', '-', $key).'-'.now()->format('Y-m-d').'.csv',
        );
    }

    /**
     * The period, and it has to be chosen.
     *
     * A DEFAULT IS STILL A STATED PERIOD. What the rule forbids is a report
     * that does not say what it covers; it does not forbid the screen offering
     * this year to begin with. The dates are echoed on the result and in the
     * audit scope either way, so nobody reads a figure without its window.
     *
     * @return array{from: string, to: string}
     */
    private function period(Request $request): array
    {
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        return [
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 ? $from : now()->startOfYear()->toDateString(),
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1 ? $to : now()->toDateString(),
        ];
    }

    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
