<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\AuditEntry;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Tenant;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads over the guard workforce, scoped to what the viewer may see.
 *
 * Scoping lives here rather than in the controller so the Inertia console and
 * the /api/v1 endpoints the mobile apps will call cannot drift into different
 * answers about who may see which guard.
 *
 * Central only. A guard is a Gemini Security employee posted AT an estate, so
 * every column this class reads lives in gs_platform and no method here opens
 * an estate database.
 *
 * On money: a guard's pay is Gemini's own payroll, and the invariant that a
 * guard never sees an amount owed governs what the GUARD APP shows a guard
 * about residents. It is not a rule against Gemini staff seeing their own
 * employee's payslip in the Gemini Console. The one figure shown here is read
 * from payslips in minor units and formatted by MoneyFormatter; nothing is
 * divided by 100 anywhere.
 */
class GuardWorkforce
{
    /** Rows per page on the directory. */
    public const PER_PAGE = 25;

    /**
     * Sortable columns: the key that appears in the URL => the column it means.
     *
     * A whitelist rather than a column name taken from the query string, which
     * is the difference between a sortable table and an injection point. The
     * keys are short because they are visible to the reader in the address bar.
     */
    private const SORTS = [
        'name' => 'guards.full_name',
        'psra' => 'guards.psra_number',
        'client' => 'tenants.name',
        'post' => 'posts.name',
        'status' => 'guards.status',
    ];

    /** @var list<string> */
    private const STATUSES = ['active', 'on_leave', 'licence_expired', 'suspended', 'inactive'];

    /** @var list<string> */
    private const EMPLOYMENT_TYPES = ['full_time', 'part_time'];

    /**
     * Everything the directory URL is allowed to say, and nothing else.
     *
     * The screen reads its own state back out of this array, so an unknown
     * status or a made-up sort key does not merely fail to filter: it is gone
     * by the time either the query or the page sees it.
     *
     * @param  array<string, mixed>  $input
     * @return array{q: string|null, client: string|null, status: string|null, employment: string|null, licence: string|null, sort: string|null, dir: string}
     */
    public function normaliseFilters(array $input): array
    {
        /** @var list<string> $sortKeys */
        $sortKeys = array_keys(self::SORTS);

        $sort = $this->oneOf($input['sort'] ?? null, $sortKeys);

        return [
            'q' => $this->searchTerm($input['q'] ?? null),
            'client' => $this->text($input['client'] ?? null),
            'status' => $this->oneOf($input['status'] ?? null, self::STATUSES),
            'employment' => $this->oneOf($input['employment'] ?? null, self::EMPLOYMENT_TYPES),
            'licence' => $this->oneOf($input['licence'] ?? null, ['expired', 'expiring']),
            'sort' => $sort,

            // A direction without a column sorts nothing, so it is dropped
            // rather than kept: it would otherwise sit in the URL claiming a
            // state the table is not in.
            'dir' => $sort !== null && $this->text($input['dir'] ?? null) === 'desc' ? 'desc' : 'asc',
        ];
    }

    /**
     * One page of the directory, filtered, sorted and presented.
     *
     * @param  array{q: string|null, client: string|null, status: string|null, employment: string|null, licence: string|null, sort: string|null, dir: string}  $filters
     * @return array{rows: array<int, array<string, mixed>>, pagination: array{current: int, last: int, from: int|null, to: int|null, total: int}}
     */
    public function roster(User $viewer, array $filters): array
    {
        $page = $this->filtered($viewer, $filters)
            ->with(['post', 'estate'])
            ->paginate(self::PER_PAGE);

        return [
            'rows' => $page->getCollection()
                ->map(fn (Guard $guard): array => $this->present($guard))
                ->all(),
            'pagination' => [
                'current' => $page->currentPage(),
                'last' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'total' => $page->total(),
            ],
        ];
    }

    /**
     * The four figures the board draws above the directory.
     *
     * Deliberately NOT narrowed by the current filter. They are the counts the
     * filters drill into, so a set that shrank as you filtered would leave
     * nothing to come back to.
     *
     * @return array{total: int, active: int, on_leave: int, licence_expired: int}
     */
    public function summary(User $viewer): array
    {
        return [
            'total' => $this->scoped($viewer)->count(),
            'active' => $this->scoped($viewer)->where('guards.status', 'active')->count(),
            'on_leave' => $this->scoped($viewer)->where('guards.status', 'on_leave')->count(),
            'licence_expired' => $this->expiredLicence($this->scoped($viewer))->count(),
        ];
    }

    /**
     * The estates that actually have a guard posted, for the filter chips.
     *
     * The query builder rather than Eloquent: the columns wanted belong to
     * `tenants`, and asking Eloquent for them hands back Guard models carrying
     * attributes the class does not have.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function clients(User $viewer): array
    {
        $query = DB::connection('mysql')
            ->table('guards')
            ->join('tenants', 'tenants.id', '=', 'guards.tenant_id')
            ->distinct()
            // Onboarding order, which is the order the board lists them in and
            // the order the people who run this platform think in.
            ->orderBy('tenants.created_at')
            ->orderBy('tenants.name')
            ->select('tenants.id as id', 'tenants.name as name', 'tenants.created_at as created_at');

        $restricted = $this->restrictedTo($viewer);

        if ($restricted !== null) {
            $query->whereIn('guards.tenant_id', $restricted);
        }

        return $query->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
            ])
            ->all();
    }

    /**
     * The PSRA licence register: every guard, soonest expiry first.
     *
     * Not only the guards inside `needingCompliance()`. This screen is the
     * register a compliance officer reads down, and a licence renewing in four
     * months is a fact they plan around. A table holding only today's failures
     * would answer "is anything wrong now" rather than "when does the next
     * thing go wrong". `needingCompliance()` still decides which rows are
     * actionable, so the flag and the badge cannot disagree.
     *
     * @return array<int, array<string, mixed>>
     */
    public function compliance(User $viewer): array
    {
        /** @var list<int> $flagged */
        $flagged = $this->scoped($viewer)->needingCompliance()->pluck('guards.id')->all();

        return $this->joined($this->scoped($viewer))
            ->with(['post', 'estate'])
            // Undated licences last: an unrecorded expiry is a gap in the
            // record, not the most urgent thing on the register, and MySQL
            // would otherwise sort NULL to the very top.
            ->orderByRaw('guards.psra_expires_on is null, guards.psra_expires_on')
            ->orderBy('guards.full_name')
            ->get()
            ->map(fn (Guard $guard): array => [
                'id' => $guard->id,
                'name' => $guard->full_name,
                'initials' => $this->initials($guard->full_name),
                'psra_number' => $guard->psra_number,
                'estate' => $guard->estate->name ?? 'Unassigned',
                'expires_on' => $this->longDate($guard->psra_expires_on),
                'licence_badge' => $this->licenceBadge($guard),
                'licence_label' => $this->licenceCountdown($guard),
                'action' => in_array($guard->id, $flagged, true) ? 'Take action' : 'View',
            ])
            ->all();
    }

    /**
     * One guard, as the profile screen draws them.
     *
     * The board's hero shows a standard hourly rate and the record carries no
     * such column, so that slot holds the figure that does exist: the gross on
     * the guard's most recent payslip. Inventing a rate to match the drawing
     * would put a number on screen that no table could ever confirm.
     *
     * Boards 19 and 23 are the SAME screen drawn on two guards, and the second
     * one is not a styling variant. Devon Palmer's PSRA licence has lapsed, so
     * his record is in two states a compliant guard's is not — UN-ROSTERABLE,
     * and carrying an OPEN COMPLIANCE CASE — and both are answered here rather
     * than in the page, so the profile, the register and the compliance action
     * screen cannot come to different conclusions about the same guard.
     *
     * @return array<string, mixed>
     */
    public function profile(Guard $guard): array
    {
        $estate = $guard->estate->name ?? 'Unassigned';
        $post = $guard->post?->name;
        $case = $this->complianceCase($guard);
        $rosterable = $this->isRosterable($guard);

        return [
            'id' => $guard->id,
            'name' => $guard->full_name,
            'subtitle' => implode(' · ', array_filter(['Security Officer', $estate, $post])),
            'status_label' => $guard->statusLabel(),
            'status_badge' => $guard->statusBadge(),
            'contact_href' => $this->contactHref($guard),

            /*
             * The un-rosterable state, made explicit rather than left to be
             * inferred from the status pill. The Build Spec is unambiguous:
             * "Expired licence sets un-rosterable — a hard block, not a
             * warning." A hard block is why the profile of such a guard offers
             * no reassignment control at all, rather than one that is offered
             * and then refused.
             */
            'rosterable' => $rosterable,
            'hero_class' => $rosterable ? null : 'suspended',
            'blocked_reason' => $rosterable ? null : $this->blockedReason($guard),

            'compliance_case' => $case,
            'compliance_href' => $case === null ? null : '/guards/'.$guard->id.'/compliance',

            // An open case turns the deployment timeline into the case file:
            // the history and the compliance record are one sequence, and the
            // heading says so rather than filing compliance events under a
            // title that does not admit they are there.
            'history_head' => $case === null ? 'Deployment history' : 'Deployment & compliance history',

            'stats' => [
                ['label' => 'Licence number', 'value' => $guard->psra_number],
                ['label' => $this->licenceStatLabel($guard), 'value' => $this->longDate($guard->psra_expires_on)],
                ['label' => 'Last gross pay', 'value' => $this->latestGross($guard) ?? 'No payslip yet'],
                ['label' => 'Employed since', 'value' => $guard->hired_on?->format('M Y') ?? 'Not recorded'],
            ],
            'employment' => [
                ['label' => 'Employment type', 'value' => $this->employmentLabel($guard->employment_type)],
                ['label' => 'Employee number', 'value' => $guard->employee_number],
                ['label' => 'Email', 'value' => $guard->email ?? 'Not recorded'],
                ['label' => 'Phone', 'value' => $guard->phone ?? 'Not recorded'],
            ],
            'assignment' => [
                ['label' => 'Client', 'value' => $estate],
                ['label' => 'Post', 'value' => $post ?? 'No post assigned'],
                ['label' => 'Post type', 'value' => $this->postType($guard)],
            ],
        ];
    }

    /**
     * The deployment timeline, built only from events that actually happened.
     *
     * Where the guard stands now, whatever the audit log recorded about them,
     * and the two dated facts on the record — the licence and the hire.
     * Nothing here is a placeholder with a date attached.
     *
     * ORDER CARRIES MEANING. On a compliant guard the posting leads, because
     * where they stand is the thing being read. On a guard with an OPEN
     * COMPLIANCE CASE the case leads, because somebody opening this profile has
     * to know the officer cannot legally be on post before they read which post
     * that is. Same rows, same component, one question asked of the record.
     *
     * @return array<int, array{title: string, meta: string, danger: bool}>
     */
    public function deploymentHistory(Guard $guard): array
    {
        $posting = $guard->tenant_id === null
            ? ['title' => 'No current posting', 'meta' => 'Awaiting assignment', 'danger' => false]
            : [
                'title' => 'Assigned to '.($guard->estate->name ?? 'an estate').' — '.($guard->post->name ?? 'no post'),
                'meta' => 'Current posting',
                'danger' => false,
            ];

        $hired = [
            'title' => 'Hired by Gemini Security',
            'meta' => $guard->hired_on === null ? 'Date not recorded' : $this->longDate($guard->hired_on),
            'danger' => false,
        ];

        $compliance = [...$this->auditEvents($guard), $this->licenceEvent($guard)];

        if ($this->complianceCase($guard) !== null) {
            $compliance[] = $this->payrollEvent($guard);
        }

        return $this->complianceCase($guard) === null
            ? [$posting, ...$compliance, $hired]
            : [...$compliance, $posting, $hired];
    }

    /**
     * The compliance action screen — board 21, one guard, one open matter.
     *
     * There is no compliance_cases table and the Build Spec's entity list does
     * not name one, so nothing here is stored: the CASE is the licence state,
     * and the RECORD of what was done about it is the append-only audit log.
     * A status column would be a second home for the same fact, and the two
     * would disagree the first night a licence lapsed.
     *
     * @return array<string, mixed>
     */
    public function complianceAction(Guard $guard): array
    {
        return [
            'id' => $guard->id,
            'name' => $guard->full_name,
            'first_name' => Str::before($guard->full_name, ' '),
            'psra_number' => $guard->psra_number,
            'estate' => $guard->estate->name ?? 'Unassigned',
            'post' => $guard->post?->name,
            'status_label' => $guard->statusLabel(),
            'suspended' => $guard->status === 'suspended',
            'contact_href' => $this->contactHref($guard),
            'case' => $this->complianceCase($guard),
            'impacts' => $this->complianceImpacts($guard),
        ];
    }

    /**
     * The estates a new guard can be posted to, with their posts.
     *
     * Scoped like every other read here: a Head of Security confined to their
     * assigned sites cannot post a new employee to an estate they cannot see.
     *
     * @return array<int, array{id: string, name: string, posts: array<int, array{id: int, name: string}>}>
     */
    public function postings(User $viewer): array
    {
        $estates = Tenant::estates();
        $restricted = $this->restrictedTo($viewer);

        if ($restricted !== null) {
            $estates = $estates
                ->filter(fn (Tenant $estate): bool => in_array((string) $estate->getTenantKey(), $restricted, true))
                ->values();
        }

        $posts = Post::query()->where('is_active', true)->orderBy('name')->get();

        return $estates
            ->map(fn (Tenant $estate): array => [
                'id' => (string) $estate->getTenantKey(),
                'name' => $estate->name,
                'posts' => $posts
                    ->where('tenant_id', (string) $estate->getTenantKey())
                    ->map(fn (Post $post): array => ['id' => $post->id, 'name' => $post->name])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /* ------------------------------------------------------------------ */

    /**
     * The open compliance matter against this guard, or null when there is none.
     *
     * Open when the licence cannot be shown to be current — lapsed, or with no
     * expiry on file at all. Both mean the same thing to a regulator asking
     * Gemini to produce a valid licence for the officer at the gate, so both
     * open a case.
     *
     * @return array{headline: string, detail: string}|null
     */
    private function complianceCase(Guard $guard): ?array
    {
        $state = $guard->licenceState();

        if ($state !== 'expired' && $state !== 'unknown') {
            return null;
        }

        $headline = $state === 'expired'
            ? $guard->psra_number.' expired '.$this->longDate($guard->psra_expires_on).' — '.$this->lapsedFor($guard)
            : $guard->psra_number.' has no expiry date on file';

        $where = $guard->post === null
            ? ' They hold no post at present.'
            : ' They are currently posted at '.($guard->estate->name ?? 'an unassigned estate').'’s '.$guard->post->name.'.';

        return [
            'headline' => $headline,
            'detail' => $guard->full_name.' cannot legally be on active duty until this is renewed.'.$where,
        ];
    }

    /**
     * Whether this guard may be put on a post at all.
     *
     * A licence that is merely expiring soon still licenses the officer today,
     * so it does not block: it is a date to plan around, and the register next
     * door is where it is planned around. Suspended and inactive block for a
     * different reason and are the same answer.
     */
    private function isRosterable(Guard $guard): bool
    {
        return in_array($guard->licenceState(), ['valid', 'expiring'], true)
            && ! in_array($guard->status, ['suspended', 'inactive'], true);
    }

    /** Why this guard cannot be rostered, in one sentence, for a control's title. */
    private function blockedReason(Guard $guard): string
    {
        return match (true) {
            $guard->status === 'suspended' => $guard->full_name.' is suspended and cannot be rostered',
            $guard->status === 'inactive' => $guard->full_name.' is no longer an active employee',
            $guard->licenceState() === 'unknown' => $guard->psra_number.' has no expiry date on file, so this guard cannot be shown to be licensed',
            default => $guard->psra_number.' lapsed on '.$this->longDate($guard->psra_expires_on)
                .', so this guard cannot legally be on post',
        };
    }

    /** "Licence expires" reads as a future promise on a licence that already lapsed. */
    private function licenceStatLabel(Guard $guard): string
    {
        return match ($guard->licenceState()) {
            'expired' => 'Licence expired',
            'unknown' => 'Licence expiry',
            default => 'Licence expires',
        };
    }

    /** "14 days ago" — how long a lapsed licence has been lapsed. */
    private function lapsedFor(Guard $guard): string
    {
        $expires = $guard->psra_expires_on;

        if ($expires === null) {
            return 'date not recorded';
        }

        $days = (int) Carbon::today()->diffInDays($expires, absolute: true);

        return $days === 0 ? 'today' : $days.' '.Str::plural('day', $days).' ago';
    }

    /**
     * What the audit log recorded about this guard.
     *
     * The log records a guard by PSRA number, not by row id: it has to stay
     * readable years after the record it describes was renamed or deleted, so
     * it stores the identifier a regulator would recognise.
     *
     * @return array<int, array{title: string, meta: string, danger: bool}>
     */
    private function auditEvents(Guard $guard): array
    {
        return AuditEntry::query()
            ->where('entity_type', 'Guard')
            ->where('entity_id', $guard->psra_number)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (AuditEntry $entry): array => [
                'title' => Str::of($entry->action)->after('.')->replace('_', ' ')->ucfirst()->value(),
                'meta' => ($entry->actor_name ?? 'System').' · '.$this->longDate($entry->created_at),
                'danger' => Str::contains($entry->action, ['flag', 'suspend', 'revoke']),
            ])
            ->all();
    }

    /**
     * What the block costs the guard in pay, taken from the pay run itself.
     *
     * Read rather than asserted. A guard who cannot be rostered has no shifts
     * to be paid for, and the run's own payslips are the proof — so this row
     * says whether this guard is on the latest run, not what the rule is.
     *
     * @return array{title: string, meta: string, danger: bool}
     */
    private function payrollEvent(Guard $guard): array
    {
        $run = DB::connection('mysql')
            ->table('payroll_runs')
            ->orderByDesc('period_end')
            ->select('id', 'period_label')
            ->first();

        if ($run === null) {
            return [
                'title' => 'Not yet reached a pay run',
                'meta' => 'No payroll run has been calculated',
                'danger' => false,
            ];
        }

        $paid = DB::connection('mysql')
            ->table('payslips')
            ->where('payroll_run_id', $run->id)
            ->where('guard_id', $guard->id)
            ->exists();

        return $paid
            ? [
                'title' => 'Still on the '.$run->period_label.' pay run',
                'meta' => 'Calculated before the licence lapsed',
                'danger' => false,
            ]
            : [
                'title' => 'Left off the '.$run->period_label.' pay run',
                'meta' => 'A guard who cannot be rostered has no shifts to be paid for',
                'danger' => false,
            ];
    }

    /**
     * What an open compliance matter actually affects, board 21's middle panel.
     *
     * Three consequences, each read from a table rather than asserted: the post
     * standing uncovered, the pay run, and the client who can see this officer
     * listed under their own deployed guards (D-034).
     *
     * @return array<int, array{icon: string, title: string, meta: string}>
     */
    private function complianceImpacts(Guard $guard): array
    {
        $estate = $guard->estate->name ?? 'Unassigned';
        $payroll = $this->payrollEvent($guard);

        return [
            $guard->post === null
                ? [
                    'icon' => 'shifts',
                    'title' => 'No post held at present',
                    'meta' => 'Nothing at a client site is uncovered by this block',
                ]
                : [
                    'icon' => 'shifts',
                    'title' => $guard->post->name.' at '.$estate,
                    'meta' => 'That post is held by an officer who cannot legally stand it',
                ],
            [
                'icon' => 'billing',
                'title' => $payroll['title'],
                'meta' => $payroll['meta'],
            ],
            [
                'icon' => 'shield',
                'title' => 'Contract compliance risk for '.$estate,
                'meta' => 'The client sees this officer under the guards deployed at their estate',
            ],
        ];
    }

    /** Trims and caps a search term; null when there is nothing to search for. */
    private function searchTerm(mixed $value): ?string
    {
        $text = $this->text($value);

        return $text === null ? null : mb_substr($text, 0, 120);
    }

    /**
     * The directory query: scope, then filters, then order.
     *
     * @param  array{q: string|null, client: string|null, status: string|null, employment: string|null, licence: string|null, sort: string|null, dir: string}  $filters
     * @return Builder<Guard>
     */
    private function filtered(User $viewer, array $filters): Builder
    {
        $query = $this->joined($this->scoped($viewer));

        $this->applySearch($query, $filters['q']);

        if ($filters['client'] !== null) {
            $query->where('guards.tenant_id', $filters['client']);
        }

        if ($filters['status'] !== null) {
            $query->where('guards.status', $filters['status']);
        }

        if ($filters['employment'] !== null) {
            $query->where('guards.employment_type', $filters['employment']);
        }

        if ($filters['licence'] === 'expired') {
            $this->expiredLicence($query);
        }

        if ($filters['licence'] === 'expiring') {
            $query->whereNotNull('guards.psra_expires_on')
                ->where('guards.psra_expires_on', '>=', now())
                ->where('guards.psra_expires_on', '<=', now()->addDays(Guard::LICENCE_WARNING_DAYS));
        }

        if ($filters['sort'] !== null) {
            $query->orderBy(self::SORTS[$filters['sort']], $filters['dir']);
        }

        /*
         * The roster's own order is both the default and the tie-break: the
         * order guards joined the company, which is the order the board lists
         * them in. It also makes paging deterministic, which sorting on a
         * column where every row repeats — every guard is "Full-time" — would
         * not be on its own.
         */
        return $query->orderBy('guards.id');
    }

    /**
     * Joins the two tables the list displays and sorts by.
     *
     * Left joins, because a guard between postings is a real state rather than
     * a broken row, and an inner join would quietly drop them from the roster.
     *
     * @param  Builder<Guard>  $query
     * @return Builder<Guard>
     */
    private function joined(Builder $query): Builder
    {
        return $query
            ->leftJoin('tenants', 'tenants.id', '=', 'guards.tenant_id')
            ->leftJoin('posts', 'posts.id', '=', 'guards.post_id')
            ->select('guards.*');
    }

    /**
     * @param  Builder<Guard>  $query
     */
    private function applySearch(Builder $query, ?string $term): void
    {
        if ($term === null) {
            return;
        }

        // The wildcards belong to the query, not to the person typing: without
        // this, a search for "50%" matches everybody.
        $like = '%'.addcslashes($term, '%_\\').'%';

        // "Full-time" is what the screen shows; full_time is what is stored.
        $employment = '%'.addcslashes(str_replace(['-', ' '], '_', mb_strtolower($term)), '%\\').'%';

        $query->where(function ($inner) use ($like, $employment): void {
            $inner->where('guards.full_name', 'like', $like)
                ->orWhere('guards.psra_number', 'like', $like)
                ->orWhere('guards.employee_number', 'like', $like)
                ->orWhere('guards.employment_type', 'like', $employment)
                ->orWhere('tenants.name', 'like', $like)
                ->orWhere('posts.name', 'like', $like);
        });
    }

    /**
     * A lapsed licence, asked the same way `licenceState()` decides it.
     *
     * One predicate behind both the count and the filter, so the KPI card can
     * never disagree with the list it drills into.
     *
     * @param  Builder<Guard>  $query
     * @return Builder<Guard>
     */
    private function expiredLicence(Builder $query): Builder
    {
        return $query->whereNotNull('guards.psra_expires_on')
            ->where('guards.psra_expires_on', '<', now());
    }

    /**
     * Narrows the query to the estates this viewer may see.
     *
     * Head of Security is the one Gemini role scoped to assigned sites. The
     * scope is read from the ROLE, never inferred from whether assignment rows
     * happen to exist: inferring it would silently promote a newly created
     * Head of Security with no assignments yet into seeing every guard on the
     * platform.
     *
     * @return Builder<Guard>
     */
    private function scoped(User $viewer): Builder
    {
        $query = Guard::query();

        $restricted = $this->restrictedTo($viewer);

        if ($restricted !== null) {
            // Qualified, because `posts` also has a tenant_id and the joined
            // query would otherwise be ambiguous.
            $query->whereIn('guards.tenant_id', $restricted);
        }

        return $query;
    }

    /**
     * The estate ids this viewer is confined to, or null for the whole platform.
     *
     * @return list<string>|null
     */
    private function restrictedTo(User $viewer): ?array
    {
        return $viewer->widestScope() === AccessScope::AssignedSites
            ? $viewer->accessibleEstateIds()
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Guard $guard): array
    {
        return [
            'id' => $guard->id,
            'name' => $guard->full_name,
            'initials' => $this->initials($guard->full_name),
            'employment_type' => $this->employmentLabel($guard->employment_type),
            'employment_key' => $guard->employment_type,
            'psra_number' => $guard->psra_number,
            'estate' => $guard->estate->name ?? 'Unassigned',
            'post' => $guard->post->name ?? '—',
            'status' => $guard->status,
            'status_label' => $guard->statusLabel(),
            'status_badge' => $guard->statusBadge(),

            // The board says "Review" on the row that needs one and "View" on
            // the rest, which is a real distinction rather than decoration.
            'action' => $guard->licenceState() === 'expired' ? 'Review' : 'View',
        ];
    }

    /** The badge class the board defines for each licence state. */
    private function licenceBadge(Guard $guard): string
    {
        return match ($guard->licenceState()) {
            'expired' => 'expired',
            'expiring' => 'soon',
            default => 'ok',
        };
    }

    /** "Expired 14 days ago", "Renews in 87 days" — the board's phrasing. */
    private function licenceCountdown(Guard $guard): string
    {
        $expires = $guard->psra_expires_on;

        if ($expires === null) {
            return 'No expiry recorded';
        }

        $days = (int) Carbon::today()->diffInDays($expires, absolute: true);

        // `isPast()` rather than a comparison of my own: it is the question
        // `licenceState()` asks, and the badge and the words beside it must
        // never disagree about whether a licence has lapsed.
        if ($expires->isPast()) {
            return $days === 0 ? 'Expired today' : 'Expired '.$days.' '.Str::plural('day', $days).' ago';
        }

        return $days === 0 ? 'Expires today' : 'Renews in '.$days.' '.Str::plural('day', $days);
    }

    /**
     * @return array{title: string, meta: string, danger: bool}
     */
    private function licenceEvent(Guard $guard): array
    {
        $number = $guard->psra_number;
        $expires = $this->longDate($guard->psra_expires_on);

        return match ($guard->licenceState()) {
            'expired' => [
                'title' => 'PSRA licence expired',
                'meta' => $number.' · lapsed '.$expires,
                'danger' => true,
            ],
            'expiring' => [
                'title' => 'PSRA licence expires within '.Guard::LICENCE_WARNING_DAYS.' days',
                'meta' => $number.' · '.$expires,
                'danger' => true,
            ],
            'valid' => [
                'title' => 'PSRA licence verified',
                'meta' => $number.' · valid to '.$expires,
                'danger' => false,
            ],
            default => [
                'title' => 'PSRA licence expiry not recorded',
                'meta' => $number.' · compliance cannot be confirmed',
                'danger' => true,
            ],
        };
    }

    /**
     * The gross on this guard's most recent payslip.
     *
     * Minor units in, a formatted string out, and nothing divided by hand on
     * the way. Ordered by the run's period rather than by row id, so a run
     * entered late does not read as the latest one.
     */
    private function latestGross(Guard $guard): ?string
    {
        $row = DB::connection('mysql')
            ->table('payslips')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->where('payslips.guard_id', $guard->id)
            ->orderByDesc('payroll_runs.period_end')
            ->select('payslips.gross_minor', 'payslips.currency')
            ->first();

        return $row === null
            ? null
            : MoneyFormatter::fromMinor((int) $row->gross_minor, (string) $row->currency);
    }

    /** A tel: link where there is a number, a mailto: where there is only mail. */
    private function contactHref(Guard $guard): ?string
    {
        if ($guard->phone !== null) {
            $dialable = preg_replace('/[^0-9+]/', '', $guard->phone) ?? '';

            if ($dialable !== '') {
                return 'tel:'.$dialable;
            }
        }

        return $guard->email === null ? null : 'mailto:'.$guard->email;
    }

    private function postType(Guard $guard): string
    {
        return $guard->post === null
            ? '—'
            : Str::of($guard->post->type)->replace('_', ' ')->ucfirst()->value();
    }

    private function employmentLabel(string $type): string
    {
        return Str::of($type)->replace('_', '-')->ucfirst()->value();
    }

    private function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }

    /** "Nov 30, 2026" — the board's date format. */
    private function longDate(?Carbon $date): string
    {
        return $date?->format('M j, Y') ?? 'Not recorded';
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function oneOf(mixed $value, array $allowed): ?string
    {
        $text = $this->text($value);

        return $text !== null && in_array($text, $allowed, true) ? $text : null;
    }
}
