<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Enums\AccessScope;
use App\Http\Controllers\Controller;
use App\Models\Guard;
use App\Models\Post;
use App\Services\Devices\DeviceEnrolment;
use App\Services\Exports\Exporter;
use App\Services\Gemini\GuardWorkforce;
use App\Services\Gemini\Roster;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

            /*
             * Where this officer could be moved (12 §2, Wave 5), narrowed to
             * what this viewer may reach. A site-scoped role offering a client
             * they cannot see would be a picker that leaks the client list.
             */
            'clientOptions' => $this->clientOptions($request),
            'postOptions' => $this->postOptions($request),
            'canAct' => Gate::allows('gemini.guard_workforce.update'),
            'actBlockedReason' => 'Recording a renewal or moving an officer changes where they may stand, so it needs Guard workforce update access. You are able to read this record.',

            // A handset waiting to replace the bound one (13 D1).
            'rebindRequests' => DB::connection('mysql')->table('device_rebind_requests')
                ->where('guard_id', $guard->id)
                ->where('status', 'pending')
                ->orderBy('id')
                ->get()
                ->map(static fn (object $r): array => [
                    'id' => (int) $r->id,
                    'label' => $r->label,
                    'platform' => $r->platform === 'ios' ? 'iPhone' : 'Android handset',
                    'requested_at' => Carbon::parse((string) $r->created_at)->format('M j, g:i A'),
                ])
                ->all(),
        ]);
    }

    /**
     * Approve or refuse a handset rebind (13 D1). The decision is recorded against
     * the officer's current or next shift; see `DeviceEnrolment::decideRebind()`.
     */
    public function decideRebind(Request $request, Guard $guard, int $rebind, string $decision, DeviceEnrolment $enrolment): RedirectResponse
    {
        abort_unless($guard->tenant_id === null || $request->user()->canAccessEstate($guard->tenant_id), 404);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:190']]);

        abort_unless(DB::connection('mysql')->table('device_rebind_requests')->where('id', $rebind)->where('guard_id', $guard->id)->exists(), 404);

        if ($decision === 'deny' && trim((string) ($data['note'] ?? '')) === '') {
            return back()->withErrors(['note' => 'Say why the handset is refused. The officer is shown it.']);
        }

        try {
            $enrolment->decideRebind($rebind, $decision === 'approve', $request->user(), $data['note'] ?? null);
        } catch (DomainException $refused) {
            return back()->withErrors(['note' => $refused->getMessage()]);
        }

        return back()->with('success', $decision === 'approve'
            ? 'Handset approved for '.$guard->full_name.'. It is bound when the officer\'s app collects its token, and the old handset stops working then.'
            : 'Handset refused. The officer\'s app is told why.');
    }

    /**
     * The clients this viewer may post an officer to.
     *
     * @return list<array{id: string, name: string}>
     */
    private function clientOptions(Request $request): array
    {
        $user = $request->user();

        /*
         * `Tenant` is stancl's model with a string key and a JSON data column,
         * so its collection is not generically typed the way the application's
         * own models are. Read as plain rows: the two fields this picker needs
         * are columns, not virtual attributes.
         */
        return DB::connection('mysql')
            ->table('tenants')
            ->when(
                $user->widestScope() === AccessScope::AssignedSites,
                static fn ($query) => $query->whereIn('id', $user->accessibleEstateIds()),
            )
            ->orderBy('name')
            ->select('id', 'name')
            ->get()
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
            ])
            ->all();
    }

    /**
     * The posts those clients have, each carrying its client so the screen can
     * narrow one picker by the other without a second request.
     *
     * @return list<array{id: int, name: string, tenant_id: string}>
     */
    private function postOptions(Request $request): array
    {
        $user = $request->user();

        return Post::query()
            ->where('is_active', true)
            ->when(
                $user->widestScope() === AccessScope::AssignedSites,
                static fn ($query) => $query->whereIn('tenant_id', $user->accessibleEstateIds()),
            )
            ->orderBy('name')
            ->get()
            ->map(static fn (Post $post): array => [
                'id' => $post->id,
                'name' => (string) $post->name,
                'tenant_id' => (string) $post->tenant_id,
            ])
            ->all();
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

            // How many shifts releasing would actually open (12 §2, Wave 5).
            // A control that says "post open shifts" for an officer holding
            // none would be offering to do nothing.
            'future_shifts' => app(Roster::class)->futureShiftCount($guard),
        ]);
    }

    /**
     * Record a renewed PSRA licence — boards 20 and 21 (12 §2, Wave 5).
     *
     * A RENEWAL IS A NEW EXPIRY DATE READ OFF THE CERTIFICATE, which is what
     * the old reason asked for. Everything that decides whether it may be
     * recorded is in the service; this refuses a guard the viewer cannot see.
     */
    public function renewLicence(Request $request, Guard $guard, GuardWorkforce $workforce): RedirectResponse
    {
        abort_unless(
            $guard->tenant_id === null || $request->user()->canAccessEstate($guard->tenant_id),
            404,
        );

        $data = $request->validate([
            'psra_expires_on' => ['required', 'date'],
            'psra_number' => ['required', 'string', 'max:40'],
        ]);

        try {
            $renewed = $workforce->markLicenceRenewed($guard, $data['psra_expires_on'], $data['psra_number'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['psra_expires_on' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', sprintf(
            '%s\'s licence is recorded to %s.%s',
            $renewed->full_name,
            $renewed->psra_expires_on->format('M j, Y'),
            $renewed->status === 'suspended'
                ? ' They remain suspended — that was a decision somebody took, and lifting it is its own.'
                : '',
        ));
    }

    /**
     * Release an officer's future shifts back to open — boards 20 and 21.
     *
     * FUTURE ONLY. A shift already worked is evidence that a post was covered,
     * and clearing its officer would erase who covered it.
     */
    public function releaseShifts(Request $request, Guard $guard, Roster $roster): RedirectResponse
    {
        abort_unless(
            $guard->tenant_id === null || $request->user()->canAccessEstate($guard->tenant_id),
            404,
        );

        $data = $request->validate(['reason' => ['required', 'string', 'max:190']]);

        try {
            $count = $roster->releaseFutureShifts($guard, $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()]);
        }

        return back()->with('success', $count === 0
            ? $guard->full_name.' holds no future shifts, so there was nothing to open.'
            : $count.' shift(s) are now open on the rota, with the reason on each so a dispatcher knows why.');
    }

    /** Move an officer to another client — board 20's "Reassign client". */
    public function reassign(Request $request, Guard $guard, GuardWorkforce $workforce, Roster $roster): RedirectResponse
    {
        abort_unless(
            $guard->tenant_id === null || $request->user()->canAccessEstate($guard->tenant_id),
            404,
        );

        $data = $request->validate([
            'tenant_id' => ['nullable', 'string', 'max:64'],
            'post_id' => ['nullable', 'integer'],
        ]);

        $tenantId = ($data['tenant_id'] ?? '') === '' ? null : $data['tenant_id'];

        if ($tenantId !== null && ! $request->user()->canAccessEstate($tenantId)) {
            return back()->withErrors(['tenant_id' => 'That client is not one this role can post an officer to.']);
        }

        try {
            $moved = $workforce->reassign($guard, $tenantId, $data['post_id'] ?? null, $request->user(), $roster);
        } catch (DomainException $refused) {
            return back()->withErrors(['tenant_id' => $refused->getMessage()]);
        }

        return back()->with('success', $moved->full_name.' is now posted at '.($moved->estate->name ?? 'no client').'. Any future shifts at the previous client are open on the rota.');
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
