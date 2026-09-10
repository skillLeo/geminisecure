<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Reports — board screen community-admin-29.
 *
 * A catalogue: seven cards in three groups, each naming a report and offering
 * to generate it. The board draws no output, no parameters and no history —
 * this screen is the index, and each report is its own screen behind it.
 *
 * NONE OF THE SEVEN GENERATES YET, AND EVERY CARD SAYS SO IN ITS OWN WORDS.
 * That is the honest position rather than a gap: a report is not a button, it
 * is a period, a scope, an output format and a figure somebody will act on.
 * Four of the seven have their data already — the ledger, the ageing, the
 * maintenance queue and the election are all in this estate's database — and
 * three do not: there is no budget model, no security-incident model inside an
 * estate, and no minutes document store. Drawing seven identical live buttons
 * over that would make the four that could work indistinguishable from the
 * three that cannot.
 *
 * SO EACH REASON NAMES WHAT IS ACTUALLY MISSING. "Not built yet" on all seven
 * would tell a committee nothing; "the estate has no approved budget to compare
 * against" tells them what to do next. `gate:interactivity` requires a reason on
 * every inert control, and this is what that rule is for.
 *
 * THE REPORTS THEMSELVES ARE NOT IN THIS ENGAGEMENT. The brief is 85 web
 * screens and this is the eighty-fifth; seven report generators, each with its
 * own parameters and its own export, are seven more screens and are scoped
 * separately. What exists here is the catalogue the client approved, wired to
 * the permission model, saying truthfully what each report would need.
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
            'reason' => 'Not built yet — the ledger holds everything this needs, and a P&L still has to '
                .'say which period it covers and how it groups. A report with an unstated period is the '
                .'defect that surfaces at an audit.',
        ],
        [
            'key' => 'arrears_ageing',
            'group' => 'Financial',
            'name' => 'Arrears Ageing',
            'description' => "Every unit's balance by ageing bucket, exportable by phase",
            'icon' => 'clock',
            'ready' => true,
            'reason' => 'Not built yet as an export. The figures exist and are on screen now — the arrears '
                .'board draws these same four buckets from the same posted lines. What this card adds is a '
                .'file somebody sends to an auditor, which needs a format nobody has specified.',
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
            'reason' => 'Not built yet. The queue holds every ticket, its category and its resolution '
                .'time, and the maintenance board already derives the average from them. This card is the '
                .'same arithmetic over a chosen period rather than the last thirty days.',
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
            'reason' => 'Not built yet, and it carries a constraint the others do not: turnout is a COUNT '
                .'of receipts and nothing finer. A report that broke a result down until a household could '
                .'be identified would undo the secret ballot, so this one needs its aggregation agreed '
                .'before it is written.',
        ],
        [
            'key' => 'meeting_minutes',
            'group' => 'Governance',
            'name' => 'Meeting Minutes Archive',
            'description' => 'All past AGM, EGM and committee meeting records',
            'icon' => 'megaphone',
            'ready' => false,
            'reason' => 'The meeting register holds every meeting, its type and its date, but minutes are '
                .'a document and this platform stores no files yet. An archive of records it cannot hold '
                .'would be an index of things nobody can open.',
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
             * Sent even though nothing on the screen is live yet, because the
             * page must not have to guess. A role that could generate a report
             * and one that could not draw the same seven inert cards today, and
             * the day the first generator lands the difference has to already be
             * on the payload rather than being remembered then.
             */
            'canGenerate' => $request->user()->can('estate.reports.export'),
            'reasons' => [
                'access' => 'Generating a report takes a copy of the estate’s own records out of the '
                    .'console, so it needs Reports export access. This role can read the catalogue.',
            ],
        ]);
    }
}
