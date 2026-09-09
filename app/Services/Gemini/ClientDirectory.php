<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\User;
use App\Support\MoneyFormatter;
use Brick\Money\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The client directory and one client's detail — board screens super-admin-04
 * and super-admin-05.
 *
 * A "client" is an estate that subscribes to GeminiSecure. Everything here is
 * read from gs_platform and nothing else: an estate database holds residents,
 * households and their money, and none of that belongs on a Gemini Console
 * screen. A figure that cannot be produced centrally is not a cross-tenant
 * figure and is not shown.
 *
 * Search, filtering, sorting and paging all happen in SQL rather than in the
 * page, so a viewer scoped to assigned sites cannot reach an estate outside
 * that scope by paging or sorting past what the first page happened to show.
 */
class ClientDirectory
{
    /** Rows per page. Twelve fills the 900px board viewport without scrolling. */
    public const PER_PAGE = 12;

    public const DEFAULT_SORT = 'name';

    public const DEFAULT_DIRECTION = 'asc';

    /**
     * Sortable columns, mapped to what they actually order by.
     *
     * `tier` orders by the plan's own sort column rather than its name, so
     * Essential / Standard / Premium come out in tier order instead of
     * alphabetically. `guards` and `mrr` are select aliases, computed below.
     *
     * @var array<string, string>
     */
    private const SORTS = [
        'name' => 'tenants.name',
        'units' => 'subscriptions.unit_count',
        'tier' => 'plans.sort',
        'guards' => 'guards_deployed',
        'mrr' => 'mrr_minor',
        'status' => 'tenants.status',
    ];

    /**
     * The board's three status chips, and the tenant statuses each covers.
     *
     * "At risk" is a commercial state, not an access one: `dunning` and
     * `suspended` gate billing features and never entry or a safety function.
     *
     * @var array<string, list<string>>
     */
    private const STATUS_FILTERS = [
        'active' => ['active'],
        'onboarding' => ['onboarding'],
        'at-risk' => ['dunning', 'suspended'],
    ];

    /**
     * The only column names `sort` may carry.
     *
     * Public because the controller narrows the query string against this list
     * before the value reaches a query as a column name, and a second copy of
     * the list living in the controller is a second thing to keep in step.
     *
     * @return list<string>
     */
    public static function sortKeys(): array
    {
        return array_keys(self::SORTS);
    }

    /**
     * The only values `status` may carry.
     *
     * @return list<string>
     */
    public static function statusKeys(): array
    {
        return array_keys(self::STATUS_FILTERS);
    }

    /**
     * Everything the directory screen renders.
     *
     * @param  array{q: string, status: list<string>, sort: string, direction: string, page: int}  $criteria
     * @return array<string, mixed>
     */
    public function directory(User $viewer, array $criteria): array
    {
        $query = $this->baseQuery($viewer, $criteria);

        /*
         * Counted before the ordering is applied.
         *
         * COUNT(*) replaces the select list, so an ORDER BY that names a select
         * alias — `mrr_minor`, `guards_deployed` — would reference a column the
         * count query no longer has, and MySQL would reject it outright.
         */
        $total = (clone $query)->count();

        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $criteria['page']), $lastPage);

        $rows = $query
            ->orderBy(self::SORTS[$criteria['sort']], $criteria['direction'])
            // A tiebreaker, so two estates on the same tier keep a stable order
            // across pages instead of swapping between requests.
            ->orderBy('tenants.id')
            ->forPage($page, self::PER_PAGE)
            ->get();

        return [
            'clients' => $rows->map(fn (object $row): array => $this->row($row))->all(),
            'filters' => $this->chips($criteria),
            'columns' => $this->columns($criteria),
            'pages' => $this->pageLinks($criteria, $page, $lastPage),
            'total' => $total,
            'isFiltered' => $criteria['q'] !== '' || $criteria['status'] !== [],
            'search' => $criteria['q'],
        ];
    }

    /**
     * One client's detail — board screen super-admin-05.
     *
     * @return array<string, mixed>
     */
    public function detail(string $tenantId): array
    {
        $estate = DB::connection('mysql')
            ->table('tenants')
            ->leftJoin('subscriptions', 'subscriptions.tenant_id', '=', 'tenants.id')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('tenants.id', $tenantId)
            ->select([
                'tenants.id',
                'tenants.name',
                'tenants.status',
                'tenants.provisioned_at',
                'subscriptions.unit_count',
                'subscriptions.status as subscription_status',
                'subscriptions.started_on',
                'subscriptions.renews_on',
                'plans.name as plan_name',
                'plans.min_units',
                'plans.price_per_unit_minor',
                'plans.currency',
            ])
            ->first();

        if ($estate === null) {
            return [];
        }

        $currency = $this->currency($estate->currency ?? null);
        $guards = $this->guards($tenantId);
        $posts = DB::connection('mysql')->table('posts')->where('tenant_id', $tenantId)->count();

        $mrrMinor = $estate->subscription_status === 'active' && $estate->unit_count !== null
            ? (int) $estate->unit_count * (int) $estate->price_per_unit_minor
            : null;

        return [
            'id' => (string) $estate->id,
            'name' => (string) $estate->name,
            'subtitle' => implode(' · ', array_filter([
                (string) $estate->id,
                $posts === 1 ? '1 guard post' : $posts.' guard posts',
                $this->clientSince($estate),
            ])),
            'tierLabel' => $estate->plan_name === null
                ? 'No plan yet'
                : $estate->plan_name.' tier',
            'stats' => [
                [
                    'value' => $estate->unit_count === null ? '—' : number_format((int) $estate->unit_count),
                    'label' => 'Units',
                ],
                [
                    'value' => (string) count($guards),
                    'label' => 'Guards deployed',
                ],
                [
                    'value' => $mrrMinor === null ? '—' : $this->money($mrrMinor, $currency),
                    'label' => 'MRR',
                ],
                [
                    'value' => $this->billingStatus($tenantId),
                    'label' => 'Billing status',
                ],
            ],
            'subscription' => $this->subscriptionRows($estate, $currency),
            'invoices' => $this->invoiceRows($tenantId),
            'contacts' => $this->contacts($tenantId),
            'guards' => $guards,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* the directory query */
    /* ------------------------------------------------------------------ */

    /**
     * The filtered, unordered directory query.
     *
     * The query builder rather than Eloquent: every column below belongs to a
     * join or is computed, so there is no Tenant to hydrate — asking Eloquent
     * for these would hand back Tenant objects carrying attributes the class
     * does not have.
     *
     * @param  array{q: string, status: list<string>, sort: string, direction: string, page: int}  $criteria
     */
    private function baseQuery(User $viewer, array $criteria): Builder
    {
        $deployed = DB::connection('mysql')
            ->table('guards')
            ->whereNotNull('tenant_id')
            // The same definition of "deployed" the detail screen's panel
            // uses. Two answers to one question on two screens about the same
            // estate is how a directory ends up contradicting a record.
            ->whereNotIn('status', ['on_leave', 'suspended'])
            ->groupBy('tenant_id')
            ->select('tenant_id', DB::raw('COUNT(*) as deployed'));

        $query = DB::connection('mysql')
            ->table('tenants')
            ->leftJoin('subscriptions', 'subscriptions.tenant_id', '=', 'tenants.id')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->leftJoinSub($deployed, 'deployed', 'deployed.tenant_id', '=', 'tenants.id')
            ->select([
                'tenants.id',
                'tenants.name',
                'tenants.status',
                'tenants.provisioned_at',
                'subscriptions.unit_count',
                'subscriptions.status as subscription_status',
                'subscriptions.started_on',
                'plans.key as plan_key',
                'plans.name as plan_name',
                'plans.currency',
                DB::raw('COALESCE(deployed.deployed, 0) as guards_deployed'),
                /*
                 * MRR counts an ACTIVE subscription only, which is the same rule
                 * the platform dashboard's MRR uses. Were this screen to count
                 * dunning or suspended estates too, the directory would add up to
                 * more than the headline figure on the dashboard and one of the
                 * two would be wrong. A client without active recurring revenue
                 * shows an em dash, exactly as the board draws an onboarding one.
                 */
                DB::raw("CASE WHEN subscriptions.status = 'active'
                    THEN subscriptions.unit_count * plans.price_per_unit_minor
                    ELSE NULL END as mrr_minor"),
            ]);

        /*
         * A role scoped to assigned sites sees only those, enforced in the
         * query. Filtering the rendered list instead would leave every other
         * estate reachable by paging, sorting or deep-linking.
         */
        if ($viewer->widestScope() === AccessScope::AssignedSites) {
            $query->whereIn('tenants.id', $viewer->accessibleEstateIds());
        }

        if ($criteria['q'] !== '') {
            // The wildcards are escaped: a search for "50%" must look for that
            // text, not for everything.
            $term = '%'.addcslashes($criteria['q'], '%_\\').'%';

            $query->where(function (Builder $scoped) use ($term): void {
                $scoped->where('tenants.name', 'like', $term)
                    ->orWhere('tenants.id', 'like', $term);
            });
        }

        $statuses = [];

        foreach ($criteria['status'] as $key) {
            $statuses = array_merge($statuses, self::STATUS_FILTERS[$key] ?? []);
        }

        if ($statuses !== []) {
            $query->whereIn('tenants.status', array_values(array_unique($statuses)));
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function row(object $row): array
    {
        $status = (string) $row->status;
        $guards = (int) $row->guards_deployed;

        return [
            'id' => (string) $row->id,
            'href' => '/clients/'.$row->id,
            'name' => (string) $row->name,
            'initials' => $this->initials((string) $row->name),
            'sub' => implode(' · ', array_filter([
                (string) $row->id,
                $this->clientSince($row),
            ])),
            'units' => $row->unit_count === null ? '—' : number_format((int) $row->unit_count),
            'tierKey' => $row->plan_key === null ? null : (string) $row->plan_key,
            'tierLabel' => $row->plan_name === null ? '—' : (string) $row->plan_name,
            'guards' => $this->guardCell($guards, $status),
            'mrr' => $row->mrr_minor === null
                ? '—'
                : $this->money((int) $row->mrr_minor, $this->currency($row->currency ?? null)),
            'statusBadge' => $this->statusBadge($status),
            'statusLabel' => $this->statusLabel($status),
        ];
    }

    /**
     * The guards column, in the board's own three states.
     *
     * Nought guards is two different facts: an onboarding estate has not been
     * staffed yet, whereas a live estate with no guards runs its own security
     * and buys the software alone. Printing a bare "0" for both loses the
     * difference, which is why the board draws them as different sentences.
     */
    private function guardCell(int $guards, string $status): string
    {
        if ($guards > 0) {
            return (string) $guards;
        }

        return $status === 'onboarding' ? 'Not yet assigned' : '0 · self-managed security';
    }

    /* ------------------------------------------------------------------ */
    /* links: chips, sortable headings, pages */
    /* ------------------------------------------------------------------ */

    /**
     * The four filter chips, each carrying the URL that toggles it.
     *
     * Built here rather than in the page because they are the same query
     * string the controller reads back: a second implementation in JavaScript
     * would be a second place for the rules to drift.
     *
     * @param  array{q: string, status: list<string>, sort: string, direction: string, page: int}  $criteria
     * @return array<string, array{href: string, active: bool}>
     */
    private function chips(array $criteria): array
    {
        $selected = $criteria['status'];

        // "All clients" clears the status filter; it does not clear the search,
        // which is a different question the viewer has already asked.
        $chips = [
            'all' => [
                'href' => $this->href($criteria, ['status' => null, 'page' => null]),
                'active' => $selected === [],
            ],
        ];

        foreach (array_keys(self::STATUS_FILTERS) as $key) {
            $isOn = in_array($key, $selected, true);

            // Chips combine: toggling one adds or removes it, leaving the rest.
            $next = $isOn
                ? array_values(array_diff($selected, [$key]))
                : [...$selected, $key];

            $chips[$key] = [
                'href' => $this->href($criteria, ['status' => $next === [] ? null : $next, 'page' => null]),
                'active' => $isOn,
            ];
        }

        return $chips;
    }

    /**
     * Each sortable heading's URL and its current aria-sort.
     *
     * A fresh column starts ascending; the column already sorted flips. The
     * direction is announced with aria-sort rather than drawn, because the
     * board's heading has no room for an arrow and inventing one would mean
     * inventing the CSS to place it.
     *
     * @param  array{q: string, status: list<string>, sort: string, direction: string, page: int}  $criteria
     * @return array<string, array{href: string, aria: string}>
     */
    private function columns(array $criteria): array
    {
        $columns = [];

        foreach (array_keys(self::SORTS) as $key) {
            $isSorted = $criteria['sort'] === $key;
            $flipped = $criteria['direction'] === 'asc' ? 'desc' : 'asc';

            $columns[$key] = [
                'href' => $this->href($criteria, [
                    'sort' => $key,
                    'direction' => $isSorted ? $flipped : 'asc',
                    'page' => null,
                ]),
                'aria' => $isSorted
                    ? ($criteria['direction'] === 'asc' ? 'ascending' : 'descending')
                    : 'none',
            ];
        }

        return $columns;
    }

    /**
     * Page links, or none at all when everything fits on one page.
     *
     * A pager drawn over a single page is a control that cannot do anything,
     * and the board draws no pager, so on one page there is nothing to render.
     *
     * @param  array{q: string, status: list<string>, sort: string, direction: string, page: int}  $criteria
     * @return list<array{label: string, href: string, active: bool}>
     */
    private function pageLinks(array $criteria, int $page, int $lastPage): array
    {
        if ($lastPage <= 1) {
            return [];
        }

        $links = [];

        if ($page > 1) {
            $links[] = [
                'label' => 'Previous',
                'href' => $this->href($criteria, ['page' => $page - 1]),
                'active' => false,
            ];
        }

        // A window rather than every page, so a platform with two hundred
        // clients does not draw a hundred chips. Previous and Next still reach
        // everything outside the window.
        $first = max(1, min($page - 3, $lastPage - 6));
        $last = min($lastPage, max($page + 3, 7));

        for ($number = $first; $number <= $last; $number++) {
            $links[] = [
                'label' => (string) $number,
                'href' => $this->href($criteria, ['page' => $number]),
                'active' => $number === $page,
            ];
        }

        if ($page < $lastPage) {
            $links[] = [
                'label' => 'Next',
                'href' => $this->href($criteria, ['page' => $page + 1]),
                'active' => false,
            ];
        }

        return $links;
    }

    /**
     * The directory URL carrying the current criteria, with overrides applied.
     *
     * Defaults are omitted so an unfiltered directory is plain "/clients"
     * rather than a URL restating everything it is not filtering by.
     *
     * @param  array{q: string, status: list<string>, sort: string, direction: string, page: int}  $criteria
     * @param  array<string, mixed>  $overrides
     */
    private function href(array $criteria, array $overrides = []): string
    {
        $params = array_merge([
            'q' => $criteria['q'] !== '' ? $criteria['q'] : null,
            'status' => $criteria['status'] !== [] ? $criteria['status'] : null,
            'sort' => $criteria['sort'] !== self::DEFAULT_SORT ? $criteria['sort'] : null,
            'direction' => $criteria['direction'] !== self::DEFAULT_DIRECTION ? $criteria['direction'] : null,
            'page' => $criteria['page'] > 1 ? $criteria['page'] : null,
        ], $overrides);

        $params = array_filter(
            $params,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []
        );

        return $params === [] ? '/clients' : '/clients?'.Arr::query($params);
    }

    /* ------------------------------------------------------------------ */
    /* detail panels */
    /* ------------------------------------------------------------------ */

    /**
     * The subscription panel.
     *
     * The board's second row is a security add-on priced per guard. There is no
     * guard add-on rate in the central schema, so the row shows the contracted
     * unit count instead rather than a number nobody stores.
     *
     * @return list<array{label: string, value: string}>
     */
    private function subscriptionRows(object $estate, string $currency): array
    {
        if ($estate->subscription_status === null) {
            return [];
        }

        $units = (int) $estate->unit_count;

        return [
            [
                'label' => 'Plan',
                'value' => $estate->plan_name.' — '
                    .$this->money((int) $estate->price_per_unit_minor, $currency).'/unit/mo',
            ],
            [
                'label' => 'Units contracted',
                'value' => number_format($units).' of a '.number_format((int) $estate->min_units).' minimum',
            ],
            [
                'label' => 'Billing cycle',
                'value' => 'Monthly, since '.$this->month($estate->started_on),
            ],
            [
                'label' => 'Contract renewal',
                'value' => $estate->renews_on === null ? 'Not set' : $this->month($estate->renews_on),
            ],
        ];
    }

    /**
     * Recent platform invoices for this client.
     *
     * This is Gemini Security billing its client. It is not the estate's own
     * ledger, which lives in the estate database and never appears here.
     *
     * @return list<array{label: string, value: string}>
     */
    private function invoiceRows(string $tenantId): array
    {
        return DB::connection('mysql')
            ->table('invoices')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('period_start')
            ->limit(5)
            ->select('period', 'total_minor', 'currency', 'status')
            ->get()
            ->map(fn (object $invoice): array => [
                'label' => (string) $invoice->period,
                'value' => $this->money(
                    (int) $invoice->total_minor,
                    $this->currency($invoice->currency ?? null)
                ).' · '.ucfirst((string) $invoice->status),
            ])
            ->all();
    }

    /**
     * The estate's own committee, from the central assignment table.
     *
     * Gemini staff assigned to this estate are deliberately excluded: they are
     * colleagues, not the client's contacts.
     *
     * @return list<array{initials: string, name: string, detail: string}>
     */
    private function contacts(string $tenantId): array
    {
        return DB::connection('mysql')
            ->table('estate_assignments')
            ->join('users', 'users.id', '=', 'estate_assignments.user_id')
            ->join('roles', 'roles.id', '=', 'estate_assignments.role_id')
            ->where('estate_assignments.tenant_id', $tenantId)
            ->where('estate_assignments.is_active', true)
            ->where('roles.console', 'estate')
            ->orderBy('roles.sort')
            ->select('users.name', 'users.status', 'roles.label')
            ->get()
            ->map(fn (object $contact): array => [
                'initials' => $this->initials((string) $contact->name),
                'name' => (string) $contact->name,
                'detail' => $contact->status === 'active'
                    ? (string) $contact->label
                    : $contact->label.' · account '.$contact->status,
            ])
            ->all();
    }

    /**
     * Guards posted to this estate.
     *
     * @return list<array{initials: string, name: string, detail: string}>
     */
    private function guards(string $tenantId): array
    {
        return DB::connection('mysql')
            ->table('guards')
            ->leftJoin('posts', 'posts.id', '=', 'guards.post_id')
            ->where('guards.tenant_id', $tenantId)
            /*
             * Deployed means standing at a post here, not compliant.
             *
             * This required status = 'active', which hid a licence-expired
             * guard from the client whose gate they are on. That is the wrong
             * way round: an expired licence is exactly the thing a client
             * should be able to see about someone posted at their estate, and
             * the board lists that guard. On leave and suspended are excluded
             * because those people are genuinely not at the post.
             */
            ->whereNotIn('guards.status', ['on_leave', 'suspended'])
            ->orderBy('guards.full_name')
            ->select('guards.full_name', 'guards.psra_number', 'posts.name as post_name')
            ->get()
            ->map(fn (object $guard): array => [
                'initials' => $this->initials((string) $guard->full_name),
                'name' => (string) $guard->full_name,
                'detail' => ($guard->post_name ?? 'Unposted').' · '.$guard->psra_number,
            ])
            ->all();
    }

    /**
     * Where this client stands on its platform invoices.
     *
     * Derived on read rather than stored: a stored flag would still say
     * "Current" the morning after an invoice fell due.
     */
    private function billingStatus(string $tenantId): string
    {
        $unpaid = DB::connection('mysql')
            ->table('invoices')
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['paid', 'void']);

        if ((clone $unpaid)->whereDate('due_on', '<', Carbon::today())->exists()) {
            return 'Overdue';
        }

        return $unpaid->exists() ? 'Due' : 'Current';
    }

    /* ------------------------------------------------------------------ */
    /* small formatters */
    /* ------------------------------------------------------------------ */

    private function statusBadge(string $status): string
    {
        return match ($status) {
            'active' => 'active',
            'onboarding' => 'onboarding',
            'dunning' => 'due',
            'suspended' => 'overdue',
            default => 'ok',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Active',
            'onboarding' => 'Onboarding',
            'dunning' => 'At risk',
            'suspended' => 'Suspended',
            default => ucfirst($status),
        };
    }

    /** "client since Feb 2026", from the subscription if there is one. */
    private function clientSince(object $row): string
    {
        $since = $row->started_on ?? $row->provisioned_at ?? null;

        return $since === null ? '' : 'client since '.$this->month($since);
    }

    private function month(mixed $value): string
    {
        return $value === null ? '—' : Carbon::parse((string) $value)->format('M Y');
    }

    private function currency(mixed $currency): string
    {
        return is_string($currency) && $currency !== ''
            ? $currency
            : MoneyFormatter::DEFAULT_CURRENCY;
    }

    /**
     * "$171,000" — every amount on both of these screens.
     *
     * The decimals are dropped only when there are none to drop. A per-unit
     * platform figure never has a fraction, so the board's clean headline is
     * what appears; an amount that does have one is printed in full, because a
     * rounded invoice total is a different number wearing the same symbol.
     *
     * The library decides all of that from the currency. Nothing here divides
     * minor units by a hundred and nothing here becomes a float: the number of
     * decimal places belongs to the currency, and a hand-rolled divide is
     * silently wrong for any currency that does not have two.
     *
     * The symbol is the locale's, via the same formatter and the same locale
     * MoneyFormatter uses, rather than picked by hand from the currency code.
     */
    private function money(int $minor, string $currency): string
    {
        return MoneyFormatter::whole($minor, $currency);
    }

    /** Two characters, uppercase, as the board draws an avatar. */
    private function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => strtoupper($part[0]))
            ->implode('');
    }
}
