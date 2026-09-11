<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Enums\AccessScope;
use App\Http\Controllers\Controller;
use App\Models\Guard;
use App\Services\Exports\Exporter;
use App\Services\Gemini\GuardWorkforce;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Guard workforce — Super Admin screens 18 to 23.
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
            // Board 22 is built, so "Add guard" is a link for whoever the form's
            // own route admits — the same gate, asked once, here.
            'canCreate' => $request->user()->can('gemini.guard_workforce.create'),
            'clients' => $workforce->clients($request->user()),
            'filters' => $filters,
            'scoped' => $request->user()->widestScope() === AccessScope::AssignedSites,
        ]);
    }

    /**
     * The Add Guard form — board screen super-admin-22.
     *
     * Everything this hands the page is a real choice the operator can make:
     * the estates they are allowed to post a guard to, that estate's own posts,
     * and the two employment types the directory can filter by. Nothing is a
     * list of labels invented for a picker.
     */
    public function create(Request $request, GuardWorkforce $workforce): Response
    {
        return inertia('Gemini/Guards/Create', [
            'clients' => $workforce->postings($request->user()),
            'employment_types' => $workforce->employmentTypes(),
        ]);
    }

    /**
     * Create the employee record.
     *
     * The whole of what may be entered, what it must satisfy and what gets
     * written to the audit log lives in the service. This method does the two
     * things a service cannot: hand it the viewer whose scope decides which
     * clients are on offer, and turn the outcome back into a redirect.
     *
     * Back to the form rather than on to the new record, and deliberately. The
     * profile screen draws no confirmation, so a redirect there would state the
     * outcome nowhere; coming back with the allocated employee number in hand
     * both confirms the write with a fact the operator could not have known and
     * leaves them where the next hire is entered.
     */
    public function store(Request $request, GuardWorkforce $workforce): RedirectResponse
    {
        $guard = $workforce->add($request->user(), $request->all());

        return back()->with('success', sprintf(
            '%s is on the books as %s. They will not appear on a roster until a device is bound to them.',
            $guard->full_name,
            $guard->employee_number,
        ));
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
            'canExport' => $request->user()->can('gemini.guard_workforce.export'),
            'exportBlockedReason' => 'A licence register leaves the platform as a file naming officers and their PSRA numbers, so it needs Guard workforce export access. You are able to read this register.',
        ]);
    }

    /**
     * The licence register as a file — board 20's Export (12 §1).
     *
     * CROSS-TENANT: a Director's register spans every estate on the platform,
     * so the audit entry names the estates in the file, per the ruling. A
     * site-scoped role exports its own — the scope is in `compliance()`'s query
     * and never applied to its result.
     */
    public function exportCompliance(Request $request, GuardWorkforce $workforce, Exporter $exporter): StreamedResponse
    {
        $guards = $workforce->compliance($request->user());

        $rows = array_map(static fn (array $row): array => [
            $row['name'],
            $row['psra_number'],
            $row['estate'],
            $row['expires_on'],
            $row['licence_label'],
        ], $guards);

        $tenants = array_values(array_unique(array_column($guards, 'estate')));
        sort($tenants);

        return $exporter->csv(
            scope: 'PSRA licence register, every officer this role can see',
            headers: ['Officer', 'PSRA number', 'Estate', 'Licence expires', 'Standing'],
            rows: $rows,
            filename: 'licence-register-'.now()->format('Y-m-d').'.csv',
            tenants: $tenants,
        );
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

    /**
     * The compliance action screen — board 21, pinned to one open matter.
     *
     * A READ behind `view`, not behind `update`, and deliberately. The register
     * next door offers "Take action" to everyone who may see it, and sending
     * half of them to a 403 for following their own screen's link would be the
     * silent dead end this console is built to avoid. So the page opens, the
     * case is legible, and the ability to ACT arrives as `can_act` — the write
     * itself is gated at the route, twice over.
     */
    public function complianceAction(Request $request, Guard $guard, GuardWorkforce $workforce): Response
    {
        abort_unless(
            $guard->tenant_id === null || $request->user()->canAccessEstate($guard->tenant_id),
            404,
        );

        $guard->load(['post', 'estate']);

        return inertia('Gemini/Guards/ComplianceAction', [
            'action' => $workforce->complianceAction($guard),
            'can_act' => Gate::allows('gemini.guard_workforce.update'),
        ]);
    }

    /**
     * Suspend a guard from active duty over an open compliance matter.
     *
     * Everything that decides whether this may happen, what changes and what is
     * written to the audit log lives in the service. The controller's whole job
     * is the one thing a service cannot do: refuse a guard this viewer's role
     * cannot see, and turn the outcome back into a redirect.
     */
    public function suspend(Request $request, Guard $guard, GuardWorkforce $workforce): RedirectResponse
    {
        abort_unless(
            $guard->tenant_id === null || $request->user()->canAccessEstate($guard->tenant_id),
            404,
        );

        $guard->load(['post', 'estate']);

        return back()->with('success', $workforce->suspendFromDuty($guard));
    }
}
