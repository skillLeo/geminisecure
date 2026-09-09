<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Gemini\ClientDirectory;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Response;

/**
 * Clients (estates) — board screens super-admin-04 and super-admin-05.
 *
 * A "client" here is an estate that subscribes to GeminiSecure. Listing and
 * detail both read gs_platform only; neither opens an estate database.
 *
 * Search, filter, sort and page are read from the query string and answered in
 * SQL, so every one of them is a real, shareable, bookmarkable URL rather than
 * a piece of state that lives only in the page.
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
        $estate = Tenant::findOrFail($tenant);

        // 404 rather than 403: a 403 confirms the estate exists.
        abort_unless($request->user()->canAccessEstate($estate->getTenantKey()), 404);

        return inertia('Gemini/Clients/Show', [
            'estate' => $directory->detail((string) $estate->getTenantKey()),

            /*
             * The board's primary action opens this client's billing history.
             * Billing is its own gated module, and an Operations Manager who
             * cannot open it should be told so on the button rather than sent
             * to a 403 by a link that looked live.
             */
            'canViewBilling' => $request->user()->can('gemini.billing_subscriptions.view'),
        ]);
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
