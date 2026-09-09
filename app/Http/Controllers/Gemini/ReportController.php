<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Gemini\CrossTenantReports;
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
    public function index(Request $request, CrossTenantReports $reports): Response
    {
        return inertia('Gemini/Reports/Index', [
            'groups' => $reports->catalogue($request->user()),
        ]);
    }
}
