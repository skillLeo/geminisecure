<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Gemini\ClientHealth;
use App\Services\Gemini\CrossTenantReports;
use App\Services\Gemini\RevenueReports;
use Illuminate\Http\Request;
use Inertia\Response;

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
     * Why every Export control on these reports is inert.
     *
     * A cross-tenant export leaves the platform as a file: it is the one act
     * on these screens that puts every client's commercial position into an
     * attachment somebody can forward. That wants a recorded reason, an
     * audit entry and a retention rule before it wants a button.
     */
    private const NO_EXPORT = 'Not built yet — a cross-tenant export puts every client\'s figures in a file that leaves the platform, and needs an audit trail before it needs a button.';

    public function index(Request $request, CrossTenantReports $reports): Response
    {
        return inertia('Gemini/Reports/Index', [
            'groups' => $reports->catalogue($request->user()),
        ]);
    }

    /** MRR over time, and what moved it — board screen 37. */
    public function mrr(RevenueReports $reports): Response
    {
        return inertia('Gemini/Reports/Mrr', [
            ...$reports->mrrTrend(),
            'exportDisabledReason' => self::NO_EXPORT,
        ]);
    }

    /** Where the money comes from — board screen 38. */
    public function revenue(RevenueReports $reports): Response
    {
        return inertia('Gemini/Reports/Revenue', [
            ...$reports->revenueByTier(),
            'exportDisabledReason' => self::NO_EXPORT,
        ]);
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
