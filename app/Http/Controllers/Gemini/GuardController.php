<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Enums\AccessScope;
use App\Http\Controllers\Controller;
use App\Models\Guard;
use App\Services\Gemini\GuardWorkforce;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Guard workforce — Super Admin screens 18, 19 and 20.
 *
 * Every read is scoped by the service, not by the view, so a role restricted
 * to assigned sites cannot reach an unassigned estate's guards by searching,
 * sorting, paginating or deep-linking past the list.
 *
 * The directory's whole state — search, client, status, employment, licence,
 * sort, direction, page — lives in the query string. That is what makes the
 * list linkable, back-button correct and reproducible in a bug report, and it
 * is why nothing on screen 18 is filtered in the browser.
 */
class GuardController extends Controller
{
    public function index(Request $request, GuardWorkforce $workforce): Response
    {
        $filters = $workforce->normaliseFilters([
            'q' => $request->query('q'),
            'client' => $request->query('client'),
            'status' => $request->query('status'),
            'employment' => $request->query('employment'),
            'licence' => $request->query('licence'),
            'sort' => $request->query('sort'),
            'dir' => $request->query('dir'),
        ]);

        $roster = $workforce->roster($request->user(), $filters);

        return inertia('Gemini/Guards/Index', [
            'guards' => $roster['rows'],
            'pagination' => $roster['pagination'],
            'summary' => $workforce->summary($request->user()),
            'clients' => $workforce->clients($request->user()),
            'filters' => $filters,
            'scoped' => $request->user()->widestScope() === AccessScope::AssignedSites,
        ]);
    }

    /**
     * The PSRA licence register.
     *
     * No search here, because the board draws no search field on this topbar
     * and the register is read down rather than looked up: it is short, it is
     * ordered by urgency, and the directory next door is where a named guard is
     * found. A field the design does not have would be one more control to
     * explain rather than one fewer.
     */
    public function compliance(Request $request, GuardWorkforce $workforce): Response
    {
        return inertia('Gemini/Guards/Compliance', [
            'guards' => $workforce->compliance($request->user()),
        ]);
    }

    public function show(Request $request, Guard $guard, GuardWorkforce $workforce): Response
    {
        /*
         * 404 rather than 403. A 403 confirms this guard exists and is posted
         * at an estate the viewer cannot see, which is more than a
         * site-restricted role should be able to learn.
         */
        abort_unless(
            $guard->tenant_id === null || $request->user()->canAccessEstate($guard->tenant_id),
            404,
        );

        $guard->load(['post', 'estate']);

        return inertia('Gemini/Guards/Show', [
            'guard' => $workforce->profile($guard),
            'history' => $workforce->deploymentHistory($guard),
        ]);
    }
}
