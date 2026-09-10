<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Gemini\SecurityOperations;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Security operations — board screens super-admin-24 to 27.
 *
 * The operational view across every client: the week's rota, the standing
 * orders in force, what the posts are reporting, and the incident log. All four
 * read gs_platform only.
 *
 * Nothing is decided here. The controller reads the request, hands the viewer
 * to the service and returns what comes back, because who may see which estate
 * is a rule that has to be enforced in SQL — a controller that filtered rows
 * after the fact would leave the rest reachable by paging or deep-linking.
 */
class OperationsController extends Controller
{
    /**
     * Publishing an order set changes what guards are legally instructed to do
     * at a gate. It needs a review and an acknowledgement cycle before it
     * needs a button.
     */
    private const NO_ORDER_WRITE = 'Not built yet — publishing an order set changes what guards are instructed to do at a post, and needs a review and acknowledgement cycle first.';

    /**
     * An incident record is evidence: an insurer, a client and the PSRA all
     * read it. Logging one wants a structured intake with severity criteria,
     * not a free-text box on a list screen.
     */
    private const NO_INCIDENT_WRITE = 'Not built yet — an incident record is evidence for an insurer and the PSRA, and needs a structured intake before it needs a button.';

    public function roster(Request $request, SecurityOperations $operations): Response
    {
        return inertia('Gemini/Operations/Roster', $operations->roster($request->user()));
    }

    /** The written orders in force at every post — board screen 25. */
    public function standingOrders(Request $request, SecurityOperations $operations): Response
    {
        return inertia('Gemini/Operations/StandingOrders', [
            ...$operations->standingOrders($request->user()),
            'writeDisabledReason' => self::NO_ORDER_WRITE,
        ]);
    }

    /** What the gates are reporting, across every client — board screen 26. */
    public function gateActivity(Request $request, SecurityOperations $operations): Response
    {
        return inertia('Gemini/Operations/GateActivity', $operations->gateActivity($request->user()));
    }

    /** What has gone wrong on a Gemini post — board screen 27. */
    public function incidents(Request $request, SecurityOperations $operations): Response
    {
        return inertia('Gemini/Operations/Incidents', [
            ...$operations->incidents($request->user()),
            'writeDisabledReason' => self::NO_INCIDENT_WRITE,
        ]);
    }
}
