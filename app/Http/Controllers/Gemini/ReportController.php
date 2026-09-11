<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Exports\Exporter;
use App\Services\Gemini\ClientHealth;
use App\Services\Gemini\CrossTenantReports;
use App\Services\Gemini\RevenueReports;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cross-tenant reports, Super Admin screen 36.
 *
 * The landing screen is a catalogue: it launches reports rather than rendering
 * figures of its own. The viewer is passed to the service because which reports
 * can be opened depends on the role — a report kept in a module this role
 * cannot reach is offered as unavailable, with the reason, instead of linking
 * into a 403.
 */
class ReportController extends Controller
{
    /**
     * Why an Export on these reports can be refused.
     *
     * A cross-tenant export puts every client's commercial position into a file
     * somebody can forward. That is what the export verb is for, and what the
     * audit entry is for — the entry names the clients in the file (12 §1). A
     * role without the verb is told that, and not told a feature is missing.
     */
    private const NO_EXPORT_ACCESS = 'A cross-tenant export puts every client\'s figures into a file that leaves the platform, so it needs Reports export access. You are able to read this report.';

    public function index(Request $request, CrossTenantReports $reports): Response
    {
        return inertia('Gemini/Reports/Index', [
            'groups' => $reports->catalogue($request->user()),
        ]);
    }

    /** MRR over time, and what moved it — board screen 37. */
    public function mrr(Request $request, RevenueReports $reports): Response
    {
        return inertia('Gemini/Reports/Mrr', [
            ...$reports->mrrTrend(),
            'exportHref' => $request->user()->can('gemini.reports.export') ? '/reports/mrr/export' : null,
            'exportDisabledReason' => self::NO_EXPORT_ACCESS,
        ]);
    }

    /** Where the money comes from — board screen 38. */
    public function revenue(Request $request, RevenueReports $reports): Response
    {
        return inertia('Gemini/Reports/Revenue', [
            ...$reports->revenueByTier(),
            'exportHref' => $request->user()->can('gemini.reports.export') ? '/reports/revenue/export' : null,
            'exportDisabledReason' => self::NO_EXPORT_ACCESS,
        ]);
    }

    /**
     * The MRR trend as a file — board 37's Export (12 §1).
     *
     * THE CHART, NOT A SECOND ARITHMETIC. The rows are the same `mrrTrend()`
     * the screen draws: a report and its export that computed their figures
     * separately would disagree the first time either changed, and the reader
     * would have two numbers and no way to tell which is the platform's.
     */
    public function exportMrr(RevenueReports $reports, Exporter $exporter): StreamedResponse
    {
        $trend = $reports->mrrTrend();

        $rows = array_map(static fn (array $bar): array => [
            $bar['label'],
            $bar['value'],
            $bar['annotation'] ?? '',
        ], $trend['bars']);

        return $exporter->csv(
            scope: 'MRR trend, '.count($rows).' months across every client on the platform',
            headers: ['Month', 'Invoiced', 'What moved it'],
            rows: $rows,
            filename: 'mrr-trend-'.now()->format('Y-m-d').'.csv',

            // Platform-wide: the clients are the platform's whole book, and the
            // per-client file below is where each one is named.
            tenants: ['(every client on the platform)'],
        );
    }

    /** Revenue by tier, and every client's contribution — board 38's Export. */
    public function exportRevenue(RevenueReports $reports, Exporter $exporter): StreamedResponse
    {
        $revenue = $reports->revenueByTier();

        $rows = [];

        /*
         * THE TIERS AND THE CLIENTS IN ONE FILE, labelled by a Row column. The
         * screen draws a donut over a table and both are one dataset shown
         * twice; splitting them into two downloads would let somebody
         * reconcile a tier total against a client list taken a week apart.
         */
        foreach ($revenue['tiers'] as $tier) {
            $rows[] = ['Tier', $tier['name'], '', '', $tier['amount'], $tier['percent'].'%'];
        }

        foreach ($revenue['clients'] as $client) {
            $rows[] = [
                'Client',
                $client['client'],
                $client['tier'],
                $client['units'],
                $client['contribution'],
                $client['share'],
            ];
        }

        $tenants = array_column($revenue['clients'], 'client');
        sort($tenants);

        return $exporter->csv(
            scope: 'Revenue by tier, with every client\'s contribution — total '.$revenue['total'],
            headers: ['Row', 'Name', 'Tier', 'Units', 'Monthly', 'Share'],
            rows: $rows,
            filename: 'revenue-by-tier-'.now()->format('Y-m-d').'.csv',
            tenants: $tenants,
        );
    }

    /** Who stayed — board screen 39. */
    public function churn(RevenueReports $reports): Response
    {
        return inertia('Gemini/Reports/Churn', $reports->churn());
    }

    /** Are the posts we are paid for actually staffed — board screen 40. */
    public function utilisation(RevenueReports $reports): Response
    {
        return inertia('Gemini/Reports/Utilisation', $reports->utilisation());
    }

    /**
     * Is each client using what they bought — board screen 07.
     *
     * Reads the central adoption roll-up and nothing else. Adoption of an
     * estate's OWN modules is recorded in that estate's database, and a console
     * that opened each one in turn to build a cross-client report would be a
     * tenant-isolation breach wearing a report's clothes — so the owner of each
     * fact rolls it up centrally and this reads the roll-up.
     */
    public function clientHealth(Request $request, ClientHealth $health): Response
    {
        return inertia('Gemini/Reports/ClientHealth', $health->forViewer($request->user()));
    }
}
