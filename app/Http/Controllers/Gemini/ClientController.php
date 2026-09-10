<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Gemini\ClientDirectory;
use App\Services\Gemini\ClientGuardAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Response;
use RuntimeException;

/**
 * Clients (estates) — board screens super-admin-04, 05, 09 and 10.
 *
 * A "client" here is an estate that subscribes to GeminiSecure. Every screen in
 * this module reads gs_platform only; none of them opens an estate database.
 *
 * Search, filter, sort and page are read from the query string and answered in
 * SQL, so every one of them is a real, shareable, bookmarkable URL rather than
 * a piece of state that lives only in the page.
 *
 * The rules live in the services. This class resolves the estate, checks the
 * viewer may reach it, and hands over — so that the answer to "may this officer
 * be put on this gate" is the same one whether it is the picker asking or a
 * request made by hand.
 */
class ClientController extends Controller
{
    public function index(Request $request, ClientDirectory $directory): Response
    {
        $user = $request->user();

        return inertia('Gemini/Clients/Index', $directory->directory($user, $this->criteria($request)));
    }

    public function show(Request $request, string $tenant, ClientDirectory $directory): Response
    {
        $estate = $this->reachable($request, $tenant);

        return inertia('Gemini/Clients/Show', [
            'estate' => $directory->detail((string) $estate->getTenantKey()),

            /*
             * The board's primary action opens this client's billing history.
             * Billing is its own gated module, and an Operations Manager who
             * cannot open it should be told so on the button rather than sent
             * to a 403 by a link that looked live.
             */
            'canViewBilling' => $request->user()->can('gemini.billing_subscriptions.view'),

            /*
             * Whether this role may CHANGE the client rather than only read it,
             * which is what the whole action stack turns on.
             *
             * The page has always declared this prop and it was never passed,
             * so it arrived undefined and every management action on the screen
             * read as refused — including "Mark onboarding complete", the one
             * act board 09 exists for. Undefined is falsy, so nothing looked
             * broken; the button simply said the work was not the reader's,
             * to a Director who holds every permission there is.
             */
            'canManage' => $request->user()->can('gemini.clients.update'),
        ]);
    }

    /**
     * Manage guard assignment — board screen super-admin-10.
     */
    public function guards(Request $request, string $tenant, ClientGuardAssignment $assignment): Response
    {
        $estate = $this->reachable($request, $tenant);

        return inertia('Gemini/Clients/Guards', [
            'roster' => $assignment->roster($estate),

            /*
             * The pencil on each row opens that officer's own record, which is
             * where an employment fact is changed. It is a different module, so
             * a role holding Clients and not Guard workforce is told on the
             * control rather than sent to a 403 by it.
             */
            'canOpenGuardRecords' => $request->user()->can('gemini.guard_workforce.view'),
        ]);
    }

    /**
     * Commit a roster.
     *
     * The whole roster, in one request, because that is what the screen edits.
     * See ClientGuardAssignment for why it is not saved a row at a time.
     */
    public function saveGuards(Request $request, string $tenant, ClientGuardAssignment $assignment): RedirectResponse
    {
        $estate = $this->reachable($request, $tenant);

        /** @var array{roster: list<array{guard_id: int, post_id: int|null}>} $validated */
        $validated = $request->validate([
            'roster' => ['present', 'array'],
            'roster.*.guard_id' => ['required', 'integer', 'exists:mysql.guards,id'],
            'roster.*.post_id' => ['nullable', 'integer', 'exists:mysql.posts,id'],
        ]);

        try {
            $assignment->save($estate, $validated['roster']);
        } catch (RuntimeException $refused) {
            /*
             * Back to the screen with the reason on the roster field, not a 500.
             * Every one of these is a rule a reader can act on — a lapsed
             * licence, a post belonging to another client — and each names what
             * to do next.
             */
            return back()->withErrors(['roster' => $refused->getMessage()]);
        }

        return back()->with('success', "The roster for {$estate->name} has been saved.");
    }

    /**
     * The estate this URL names, or a 404.
     *
     * 404 rather than 403 throughout: a 403 confirms the estate exists, which
     * is a fact a role scoped to its own assigned sites should not be able to
     * harvest by walking subdomains.
     */
    private function reachable(Request $request, string $tenant): Tenant
    {
        $estate = Tenant::findOrFail($tenant);

        abort_unless($request->user()->canAccessEstate($estate->getTenantKey()), 404);

        return $estate;
    }

    /**
     * The directory's query string, narrowed to what it may contain.
     *
     * Narrowed rather than validated, deliberately. These parameters shape a
     * view; they are not a submission. A hand-edited or stale `?sort=` should
     * show the directory sorted the default way, not bounce the reader back
     * where they came from with a validation error over a column name.
     *
     * The narrowing is also the safety: `sort` reaches the query as a column
     * name, so it may only ever be one of a known set, and anything else is
     * replaced rather than passed on.
     *
     * @return array{q: string, status: list<string>, sort: string, direction: string, page: int}
     */
    private function criteria(Request $request): array
    {
        // `q` is the parameter the console's top-bar search posts.
        $q = $request->query('q');
        $sort = $request->query('sort');
        $direction = $request->query('direction');

        $requested = array_values(
            array_filter(Arr::wrap($request->query('status', [])), 'is_string')
        );

        $page = $request->query('page');

        return [
            'q' => is_string($q) ? trim(mb_substr($q, 0, 120)) : '',
            'status' => array_values(array_intersect($requested, ClientDirectory::statusKeys())),
            'sort' => is_string($sort) && in_array($sort, ClientDirectory::sortKeys(), true)
                ? $sort
                : ClientDirectory::DEFAULT_SORT,
            'direction' => $direction === 'desc' ? 'desc' : ClientDirectory::DEFAULT_DIRECTION,
            'page' => is_numeric($page) ? max(1, (int) $page) : 1,
        ];
    }
}
