<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Meeting;
use App\Services\Estate\ActivityLog;
use App\Services\Estate\Dues;
use App\Services\Estate\Maintenance;
use App\Services\Estate\Residents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The Estate Console dashboard — board community-admin-02.
 *
 * EVERY FIGURE ON THIS SCREEN ALREADY HAD A HOME BEFORE THIS SCREEN EXISTED.
 * The five KPI tiles, the arrears-by-phase bars and the outstanding-dues
 * total are each read straight off a service that shipped earlier: `Residents`
 * for the unit counts, `Dues` for every money figure, `Maintenance` for the
 * open-ticket count, and the `Meeting` register for what is next. Nothing
 * here sums a charge or a payment a second time — composing this screen from
 * those services rather than recomputing anything is the whole point of
 * building it last.
 *
 * TWO CONTENT RESIDUALS ARE DRAWN AS THE DATA SAYS, NOT AS THE BOARD SAYS.
 * The board's "441 Occupied units, 98%" cannot be reproduced: this estate is
 * seeded 450 units and 433 occupied households — the same class of residual
 * D-056 already records on Phase 5's own card (78-of-70), recorded rather
 * than reshaping the estate to match a headline. And the board's "↑ 4%" trend
 * chip on Outstanding dues needs a PRIOR-PERIOD snapshot of the arrears
 * control total that nothing in this schema stores — inventing one would be
 * exactly the "invent a column for a number" failure D-033 exists to name, so
 * the chip is simply absent here rather than fabricated. See DECISIONS.md.
 *
 * THE ACTIVITY FEED AND THE BELL ARE `ActivityLog`'s, and neither keeps a
 * table of its own — the same discipline D-035's cross-tenant feed keeps. A
 * payment, an incident, a verification, a closed ticket and a booking are each
 * read from the table that already owns that fact; what still needs somebody is
 * derived the same way, so a claim approved on board 34 leaves the bell by
 * itself rather than leaving a stale notification standing. A category with
 * nothing to show is simply absent; never an invented row to fill the gap.
 */
class DashboardController extends Controller
{
    private const NO_NOTICE_ACCESS = 'Posting a notice reaches every household in the estate and needs Governance create access. You are able to read this dashboard.';

    private const NO_ADD_RESIDENT_ACCESS = 'Adding a resident puts a person on the estate\'s register and needs Residents create access. You are able to read this dashboard.';

    private const NO_CHARGE_ACCESS = 'Posting a charge adds to what a household owes and needs Dues & ledger create access. Reading this dashboard does not carry it.';

    private const NO_LEDGER_ACCESS = 'The arrears panel is Dues & ledger\'s own screen and needs Dues & ledger view access, which this role may not hold.';

    public function __invoke(Request $request, Dues $dues, Maintenance $maintenance, Residents $residents, ActivityLog $log): Response
    {
        $tenant = tenant();
        $user = $request->user();

        $structure = $residents->structureBoard();
        $units = (int) $structure['totals']['units'];
        $occupied = (int) $structure['totals']['occupied'];

        $ageing = $dues->ageing();
        $outstandingMinor = array_sum($ageing);
        $phaseTotals = $dues->arrearsByPhase();

        /*
         * The open-ticket count is the maintenance module's own, read back
         * rather than recounted. Board 17 prints it as a tile and board 2 prints
         * the same figure; two queries against the same table would agree today
         * and diverge the first time "open" stops meaning "not completed".
         */
        $openMaintenance = 0;

        foreach ($maintenance->queueBoard()['kpis'] as $kpi) {
            if ($kpi['key'] === 'open') {
                $openMaintenance = (int) $kpi['value'];

                break;
            }
        }

        $nextMeeting = Meeting::query()
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->first();

        $canViewLedger = $user->can('estate.dues_ledger.view');

        return inertia('Estate/Dashboard', [
            'estate' => ['name' => (string) $tenant->name],

            'kpis' => [
                ['key' => 'units', 'value' => $units, 'label' => 'Total units'],
                [
                    'key' => 'occupied',
                    'value' => $occupied,
                    'label' => 'Occupied units',
                    'trend' => $units === 0 ? null : (int) round($occupied / $units * 100),
                ],
                ['key' => 'dues', 'value_minor' => $outstandingMinor, 'label' => 'Outstanding dues'],
                ['key' => 'maintenance', 'value' => $openMaintenance, 'label' => 'Open maintenance'],
                [
                    'key' => 'meeting',
                    'value' => $nextMeeting?->starts_at->format('M j') ?? '—',
                    'label' => $nextMeeting === null
                        ? 'No meeting scheduled'
                        : 'Next meeting: '.$nextMeeting->typeLabel(),
                ],
            ],

            'arrears' => [
                'total_minor' => $outstandingMinor,
                'phases' => $this->phaseBars($phaseTotals),
                'canView' => $canViewLedger,
                'ledgerHref' => $canViewLedger ? $this->path('/finance/arrears') : null,
                'ledgerReason' => self::NO_LEDGER_ACCESS,
            ],

            'activity' => [
                'rows' => $log->feed(ActivityLog::DASHBOARD_ROWS)['rows'],
                'seeAllHref' => $this->path('/activity'),
            ],

            'quickActions' => [
                // Board 32 posts notices, so this is a link for whoever holds
                // the gate its POST carries, and the inert twin for the rest.
                'notice' => [
                    'href' => $user->can('estate.governance.create') ? $this->path('/governance/notices') : null,
                    'reason' => self::NO_NOTICE_ACCESS,
                ],
                'addResident' => [
                    'href' => $user->can('estate.residents.create') ? $this->path('/residents/new') : null,
                    'reason' => self::NO_ADD_RESIDENT_ACCESS,
                ],
                'newCharge' => [
                    'href' => $user->can('estate.dues_ledger.create') ? $this->path('/finance/charges/new') : null,
                    'reason' => self::NO_CHARGE_ACCESS,
                ],
            ],

            /*
             * THE BELL COUNTS WHAT THIS VIEWER HAS NOT SEEN, and only what
             * this viewer may see: a notification is a summary of a record,
             * and a role that may not read the record may not read the summary
             * either. Nothing here is stored — see `ActivityLog::attention()`.
             */
            'notifications' => [
                'count' => $log->attention($user)['unread'],
                'href' => $this->path('/notifications'),
            ],
        ]);
    }

    /** The full activity log — the dashboard panel's "See all" (12 §2, Wave 2). */
    public function activity(Request $request, ActivityLog $log): Response
    {
        $page = max(1, (int) $request->integer('page', 1));
        $feed = $log->feed(ActivityLog::PAGE, ($page - 1) * ActivityLog::PAGE);

        return inertia('Estate/Activity/Index', [
            'estate' => ['name' => (string) tenant()->name],
            'rows' => $feed['rows'],
            'page' => $page,
            'hasMore' => $feed['has_more'],
            'dashboardHref' => $this->path('/'),
            'path' => $this->path('/activity'),
        ]);
    }

    /** The notification centre — the topbar bell (12 §2, Wave 2). */
    public function notifications(Request $request, ActivityLog $log): Response
    {
        $attention = $log->attention($request->user());

        return inertia('Estate/Notifications/Index', [
            'estate' => ['name' => (string) tenant()->name],
            'items' => $attention['items'],
            'unread' => $attention['unread'],
            'dashboardHref' => $this->path('/'),
            'path' => $this->path('/notifications'),
        ]);
    }

    /** Mark items seen. Per viewer — two officers do not share an inbox. */
    public function markNotificationsRead(Request $request, ActivityLog $log): RedirectResponse
    {
        $keys = $request->validate([
            'keys' => ['required', 'array', 'max:200'],
            'keys.*' => ['string', 'max:64'],
        ])['keys'];

        $log->markRead($request->user(), $keys);

        return redirect()->to($this->path('/notifications'));
    }

    /* ------------------------------------------------------------------ */
    /* the bar chart */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, int>  $totals  phase name => minor units owed
     * @return list<array{key: string, label: string, value_minor: int, height_pct: int, over: bool}>
     */
    private function phaseBars(array $totals): array
    {
        if ($totals === []) {
            return [];
        }

        $max = max($totals);
        $sorted = array_values($totals);
        sort($sorted);
        $count = count($sorted);

        /*
         * The board's own amber cut needs a definition nothing on it states —
         * "phase.arrears_over_threshold ... NOT derivable from magnitude
         * alone ... an explicit flag or per-phase target is required". Rather
         * than invent a target dollar figure nobody set, the two phases the
         * board colours amber are exactly the two ABOVE THE ESTATE'S OWN
         * MEDIAN PHASE — a relative severity rule that needs no stored
         * column and reproduces the board on the seeded data. See
         * DECISIONS.md.
         */
        $median = $count % 2 === 1
            ? $sorted[intdiv($count, 2)]
            : ($sorted[intdiv($count, 2) - 1] + $sorted[intdiv($count, 2)]) / 2;

        $bars = [];

        foreach ($totals as $phase => $value) {
            $bars[] = [
                'key' => $phase,
                'label' => $phase,
                'value_minor' => $value,

                /*
                 * The board's tallest bar (Phase 2, its own largest phase)
                 * sits at 88% with headroom above it; every other bar is the
                 * same proportion of THIS ESTATE'S OWN largest phase, not of
                 * an invented chart maximum. Reproduces the board's 62/88/44/
                 * 71/11 exactly on the seeded arrears and rescales sensibly
                 * if they ever change.
                 */
                'height_pct' => $max === 0 ? 0 : (int) round($value / $max * 88),
                'over' => $value > $median,
            ];
        }

        return $bars;
    }

    /**
     * Where a screen lives, in whichever shape this environment serves.
     *
     * Production gives each estate its own hostname; local serves them all
     * from one host with the estate in the path. See routes/tenant.php.
     */
    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
