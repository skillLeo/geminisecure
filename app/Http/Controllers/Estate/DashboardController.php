<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Meeting;
use App\Models\Estate\Payment;
use App\Models\Estate\Resident;
use App\Models\Estate\UnitClaim;
use App\Models\SecurityIncident;
use App\Services\Estate\Dues;
use App\Services\Estate\Maintenance;
use App\Services\Estate\Residents;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
 * THE ACTIVITY FEED READS FIVE TABLES AND NEVER A SIXTH COPY OF ITS OWN,
 * the same discipline D-035's cross-tenant feed keeps: a payment, an
 * incident, a resident verification, a closed ticket and a booking, each
 * read from the table that already owns that fact and merged here by their
 * own timestamps — never copied into an activity table of its own. A
 * category with nothing to show is simply absent from the merge; five real
 * rows or fewer is the honest feed, never five invented ones.
 */
class DashboardController extends Controller
{
    private const NO_NOTICE_YET = 'Not built yet — a notice reaches every resident\'s phone and is kept as a record, so it needs a channel and a retention rule before it needs a button.';

    private const NO_SEE_ALL_YET = 'Not built yet — a full activity log needs its own paginated read of the tables this panel already merges, so it is not one query away.';

    private const NO_ADD_RESIDENT_ACCESS = 'Adding a resident puts a person on the estate\'s register and needs Residents create access. You are able to read this dashboard.';

    private const NO_CHARGE_ACCESS = 'Posting a charge adds to what a household owes and needs Dues & ledger create access. Reading this dashboard does not carry it.';

    private const NO_LEDGER_ACCESS = 'The arrears panel is Dues & ledger\'s own screen and needs Dues & ledger view access, which this role may not hold.';

    private const NO_NOTIFICATIONS_YET = 'Not built yet — a notification centre needs a read/unread model of its own. The badge counts the same pending unit claims board 4\'s banner shows.';

    public function __invoke(Request $request, Dues $dues, Maintenance $maintenance, Residents $residents): Response
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
                'rows' => $this->activityFeed(),
                'seeAllReason' => self::NO_SEE_ALL_YET,
            ],

            'quickActions' => [
                'notice' => ['reason' => self::NO_NOTICE_YET],
                'addResident' => [
                    'href' => $user->can('estate.residents.create') ? $this->path('/residents/new') : null,
                    'reason' => self::NO_ADD_RESIDENT_ACCESS,
                ],
                'newCharge' => [
                    'href' => $user->can('estate.dues_ledger.create') ? $this->path('/finance/charges/new') : null,
                    'reason' => self::NO_CHARGE_ACCESS,
                ],
            ],

            'notifications' => [
                'count' => UnitClaim::query()->where('status', UnitClaim::PENDING)->count(),
                'reason' => self::NO_NOTIFICATIONS_YET,
            ],
        ]);
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

    /* ------------------------------------------------------------------ */
    /* the activity feed */
    /* ------------------------------------------------------------------ */

    /**
     * Five real events, each read from the table that owns it — never a sixth
     * copy of any of them. Fewer than five where a category has nothing to
     * show; never an invented row to fill the gap.
     *
     * @return list<array{icon: string, amber: bool, title: string, subtitle: string}>
     */
    private function activityFeed(): array
    {
        $rows = [];

        $payment = Payment::query()->with('unit')->orderByDesc('received_at')->first();

        if ($payment !== null) {
            $rows[] = [
                'at' => $payment->received_at,
                'icon' => 'payment',
                'amber' => false,
                'title' => 'Payment received — '.($payment->unit->reference ?? 'unit'),
                'subtitle' => $this->money($payment->amount_minor).' · '.$this->relative($payment->received_at),
            ];
        }

        // Central table, filtered to this estate — the same reach board 26's
        // cross-tenant feed has in the other direction. See D-035.
        $incident = SecurityIncident::query()
            ->where('tenant_id', (string) tenant()->getTenantKey())
            ->orderByDesc('occurred_at')
            ->first();

        if ($incident !== null) {
            $rows[] = [
                'at' => $incident->occurred_at,
                'icon' => 'incident',
                'amber' => true,
                'title' => 'New incident report',
                'subtitle' => $incident->kind.' · '.$this->relative($incident->occurred_at),
            ];
        }

        $resident = Resident::query()
            ->with('household.unit')
            ->whereNotNull('verified_at')
            ->orderByDesc('verified_at')
            ->first();

        if ($resident !== null) {
            $unit = $resident->household?->unit;

            $rows[] = [
                'at' => $resident->verified_at,
                'icon' => 'residents',
                'amber' => false,
                'title' => 'New resident verified — '.($unit->reference ?? 'unit'),
                'subtitle' => $this->relative($resident->verified_at),
            ];
        }

        $ticket = MaintenanceTicket::query()
            ->with('unit')
            ->whereNotNull('closed_at')
            ->orderByDesc('closed_at')
            ->first();

        if ($ticket !== null) {
            $rows[] = [
                'at' => $ticket->closed_at,
                'icon' => 'maintenance',
                'amber' => false,
                'title' => 'Maintenance ticket closed — '.($ticket->unit->reference ?? 'unit'),
                'subtitle' => trim(($ticket->resolution ?? 'Resolved').' · '.$this->relative($ticket->closed_at)),
            ];
        }

        $booking = AmenityBooking::query()
            ->with(['amenity', 'unit'])
            ->orderByDesc('created_at')
            ->first();

        if ($booking !== null) {
            $at = $booking->created_at ?? $booking->starts_at;

            $rows[] = [
                'at' => $at,
                'icon' => 'facility',
                'amber' => false,
                'title' => ($booking->amenity->name ?? 'Amenity').' booked — '.$booking->starts_at->format('D, M j'),
                'subtitle' => ($booking->unit->reference ?? 'unit').' · '.$this->relative($at),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        return array_map(
            static fn (array $row): array => [
                'icon' => $row['icon'],
                'amber' => $row['amber'],
                'title' => $row['title'],
                'subtitle' => $row['subtitle'],
            ],
            array_slice($rows, 0, 5),
        );
    }

    /**
     * The board's own relative-time style — "2 min ago", "1 hour ago" — never
     * Carbon's spelled-out default, so a dashboard reads in the same words the
     * board draws even though the underlying moments are the seed's own
     * rather than literally minutes old.
     */
    private function relative(Carbon $at): string
    {
        $minutes = (int) $at->diffInMinutes(now());
        $hours = intdiv($minutes, 60);
        $days = intdiv($minutes, 1440);

        return match (true) {
            $minutes < 1 => 'just now',
            $minutes < 60 => $minutes.' min ago',
            $minutes < 1440 => $hours.' hour'.($hours === 1 ? '' : 's').' ago',
            $minutes < 10_080 => $days.' day'.($days === 1 ? '' : 's').' ago',
            default => $at->format('M j, Y'),
        };
    }

    /** Minor units as the activity feed prints them — "$6,200", no decimals. */
    private function money(int $minor): string
    {
        return '$'.number_format(intdiv($minor, 100));
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
