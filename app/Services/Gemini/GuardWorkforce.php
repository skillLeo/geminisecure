<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\AuditEntry;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\MoneyFormatter;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
     * The audit log is a constructor dependency rather than a facade call.
     *
     * Every write in this class has to leave a trace, and a dependency the
     * container hands over is one a test can assert against. A static call
     * inside the method would be invisible from the outside, which is the one
     * property an audit trail cannot afford.
     */
    public function __construct(private readonly AuditLogger $audit) {}

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

                /*
                 * "Take action" goes to the action screen, "View" to the
                 * record. The two words on this board are different promises
                 * and were reaching the same page, which made one of them a
                 * lie: a reader who clicked "Take action" arrived somewhere
                 * with nothing to act on.
                 */
                'href' => in_array($guard->id, $flagged, true)
                    ? '/guards/'.$guard->id.'/compliance'
                    : '/guards/'.$guard->id,
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

            /*
             * Why the one live control on this board cannot be pressed, or
             * null when it can. Decided here rather than in the page, so the
             * button the reader sees and the rule `suspendFromDuty()` enforces
             * are the same sentence — a screen that offered a suspension the
             * service then refused would be worse than one that never offered.
             */
            'suspend_blocked_reason' => $this->suspensionBlockedReason($guard),
        ];
    }

    /**
     * Suspend a guard from active duty. The one write board 21 performs.
     *
     * Two things happen and both are the point: the guard's status changes,
     * and the audit log gains a row that cannot later be edited or removed. A
     * suspension is a decision taken about a person's livelihood, so the record
     * of who took it, when, and what the record looked like beforehand is not
     * optional book-keeping — it is the reason the control exists at all.
     *
     * WHAT DOES NOT HAPPEN IS AS IMPORTANT (D-034). The guard keeps their
     * tenant_id and their post_id. Deployed means POSTED AT THE ESTATE, not
     * compliant, and Phoenix Park Village 1 must go on seeing that the officer
     * assigned to their Service Gate is the one who cannot legally stand it.
     * Quietly unposting him here would clear the client's screen of the problem
     * while the problem was still standing at their gate.
     *
     * @return string what was recorded, for the reader who just did it
     *
     * @throws ValidationException when there is nothing to suspend for
     */
    public function suspendFromDuty(Guard $guard): string
    {
        $refusal = $this->suspensionBlockedReason($guard);

        if ($refusal !== null) {
            /*
             * The same sentence the disabled button carries. A refusal that
             * reached this far arrived from a stale page or a hand-made POST,
             * and either way the caller deserves the reason rather than a 500.
             */
            throw ValidationException::withMessages(['guard' => $refusal]);
        }

        $before = ['status' => $guard->status, 'post_id' => $guard->post_id, 'tenant_id' => $guard->tenant_id];

        DB::connection('mysql')->transaction(function () use ($guard, $before): void {
            $guard->forceFill(['status' => 'suspended'])->save();

            $this->audit->record(
                action: 'guard.suspended',
                entityType: 'Guard',
                entityId: $guard->psra_number,
                before: $before,
                after: [
                    'status' => 'suspended',
                    'post_id' => $guard->post_id,
                    'tenant_id' => $guard->tenant_id,
                    'reason' => $this->complianceCase($guard)['headline'] ?? 'PSRA licence cannot be shown to be current',
                ],
                tenantId: $guard->tenant_id,
            );
        });

        $where = $guard->post === null
            ? ' They hold no post.'
            : ' '.($guard->estate->name ?? 'The client').' still sees them assigned to '.$guard->post->name.'.';

        return $guard->full_name.' is suspended from active duty, and the suspension is on the audit log.'.$where;
    }

    /**
     * The estates a new guard can be posted to, with their posts.
     *
     * Scoped like every other read here: a Head of Security confined to their
     * assigned sites cannot post a new employee to an estate they cannot see.
     *
     * Each estate also carries what one more guard COSTS that client. Board 22
     * states the consequence in words — "this adds a 3rd guard to Emerald
     * Heights, taking their Security Provider add-on from $9,000/mo to
     * $13,500/mo" — and it is a real figure rather than a flourish: the
     * per-guard add-on is a row in `platform_rates`, so the two amounts are read
     * from the rate card and the client's own guard count, never assembled in
     * the browser. A client with no rate on file carries nulls and the screen
     * says nothing about billing rather than guessing at it.
     *
     * @return array<int, array{id: string, name: string, posts: array<int, array{id: int, name: string}>, guards_now: int, next_ordinal: string, addon_now: string|null, addon_next: string|null}>
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
        $counts = $this->guardCountsByEstate();
        $rate = $this->addOnPerGuard();

        return $estates
            ->map(function (Tenant $estate) use ($posts, $counts, $rate): array {
                $id = (string) $estate->getTenantKey();
                $now = $counts[$id] ?? 0;

                return [
                    'id' => $id,
                    'name' => $estate->name,
                    'posts' => $posts
                        ->where('tenant_id', $id)
                        ->map(fn (Post $post): array => ['id' => $post->id, 'name' => $post->name])
                        ->values()
                        ->all(),
                    'guards_now' => $now,
                    'next_ordinal' => $this->ordinal($now + 1),
                    'addon_now' => $rate === null
                        ? null
                        : MoneyFormatter::whole($now * $rate['minor'], $rate['currency']),
                    'addon_next' => $rate === null
                        ? null
                        : MoneyFormatter::whole(($now + 1) * $rate['minor'], $rate['currency']),
                ];
            })
            ->all();
    }

    /**
     * The two employment types, as the board writes them.
     *
     * Read off the same constant the directory filters by, so the picker on the
     * Add Guard form can never offer a type the roster cannot then filter for.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function employmentTypes(): array
    {
        return array_map(
            fn (string $type): array => ['value' => $type, 'label' => $this->employmentLabel($type)],
            self::EMPLOYMENT_TYPES,
        );
    }

    /**
     * Create a guard employee record — board screen super-admin-22.
     *
     * WHAT THIS METHOD REFUSES IS THE POINT OF IT.
     *
     * A PSRA number and its expiry are compliance data, not two more strings on
     * a form. An expired licence sets un-rosterable — the Build Spec's words,
     * and a hard block rather than a warning — so a guard saved with a lapsed
     * date would be created into a state they can never work from: on the
     * payroll, on the compliance register, and refused by every roster and
     * assignment picker on the platform from the moment they exist. Accepting
     * that silently and letting the compliance screen discover it tomorrow is
     * the failure this refusal exists to prevent. The message says so in words
     * rather than reporting "invalid date".
     *
     * WHAT IS NOT ASKED FOR IS ALSO DELIBERATE. The board draws no employee
     * number field, and the number is not the operator's to invent: it is
     * Gemini Security's own sequence, allocated here, so two people onboarding
     * two guards on the same afternoon cannot both type GS-1072.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException when the record would be created into a state
     *                             it could not legally be worked from
     */
    public function add(User $viewer, array $input): Guard
    {
        /** @var array<string, mixed> $data */
        $data = $this->validateNewGuard($viewer, $input)->validate();

        $rateMinor = Money::of((string) $data['standard_rate'], MoneyFormatter::DEFAULT_CURRENCY)
            ->getMinorAmount()
            ->toInt();

        return DB::connection('mysql')->transaction(function () use ($data, $rateMinor): Guard {
            $guard = Guard::create([
                'full_name' => (string) $data['full_name'],
                'employee_number' => $this->nextEmployeeNumber(),
                'psra_number' => (string) $data['psra_number'],
                'psra_expires_on' => (string) $data['psra_expires_on'],
                'employment_type' => (string) $data['employment_type'],
                'standard_rate_minor' => $rateMinor,
                'standard_rate_currency' => MoneyFormatter::DEFAULT_CURRENCY,

                /*
                 * Active, and un-enrolled. The spec's rule is that "a guard
                 * cannot be rostered until the licence is recorded and the
                 * device is bound", and the second half of that is already
                 * modelled: `device_id` is null until a handset is bound to
                 * them, which is what `deviceIsBound()` reads. Inventing a
                 * sixth status to mean the same thing would give the platform
                 * two answers to one question.
                 */
                'status' => 'active',

                'phone' => (string) $data['phone'],

                // The day the record is made is the day the employment starts,
                // unless somebody says otherwise — and this board draws no
                // field where they could. A null hire date would read as "not
                // recorded" on a profile created ten seconds ago.
                'hired_on' => Carbon::today()->toDateString(),

                'tenant_id' => $this->nullableText($data['tenant_id'] ?? null),
                'post_id' => $this->nullableId($data['post_id'] ?? null),
            ]);

            /*
             * Creating an employee is an audited write, and the entry is
             * written inside the same transaction as the row it describes.
             * Outside it, a failure between the two would leave either a guard
             * nobody can account for or a log entry for a guard who does not
             * exist, and an audit trail that can be wrong in either direction
             * is not one.
             */
            $this->audit->record(
                action: 'guard.created',
                entityType: 'Guard',
                entityId: $guard->psra_number,
                before: null,
                after: [
                    'employee_number' => $guard->employee_number,
                    'full_name' => $guard->full_name,
                    'psra_number' => $guard->psra_number,
                    'psra_expires_on' => $guard->psra_expires_on?->toDateString(),
                    'employment_type' => $guard->employment_type,
                    'standard_rate_minor' => $guard->standard_rate_minor,
                    'standard_rate_currency' => $guard->standard_rate_currency,
                    'tenant_id' => $guard->tenant_id,
                    'post_id' => $guard->post_id,
                ],
                tenantId: $guard->tenant_id,
            );

            return $guard;
        });
    }

    /**
     * The next employee number in Gemini Security's own sequence.
     *
     * Read from the numbers already issued rather than from a counter, so it
     * stays correct after a restore, a reseed or a manually inserted record.
     * Only `GS-<digits>` is considered: the test suite creates guards with
     * numbers of its own shape, and one of those must never be able to push the
     * company's sequence somewhere it cannot come back from.
     *
     * The unique index on `employee_number` is the real guarantee. This is
     * called inside `add()`'s transaction, so two simultaneous onboardings
     * cannot both commit the same number — one of them fails on the index
     * rather than quietly issuing a duplicate.
     */
    public function nextEmployeeNumber(): string
    {
        $highest = DB::connection('mysql')
            ->table('guards')
            ->whereRaw("employee_number regexp '^GS-[0-9]+$'")
            ->selectRaw('max(cast(substring(employee_number, 4) as unsigned)) as highest')
            ->value('highest');

        return 'GS-'.max((int) $highest + 1, 1001);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Everything the Add Guard form must be true before a record is made.
     *
     * A validator rather than a FormRequest, and here rather than in the
     * controller, for the reason every other rule in this class lives here: the
     * console and the /api/v1 endpoints the mobile apps will call must not be
     * able to disagree about what a valid guard is. A rule enforced in an HTTP
     * request class is enforced on one route.
     *
     * @param  array<string, mixed>  $input
     * @return \Illuminate\Validation\Validator
     */
    private function validateNewGuard(User $viewer, array $input): \Illuminate\Contracts\Validation\Validator
    {
        $estates = $this->postings($viewer);

        /** @var array<int, string> $estateIds */
        $estateIds = array_column($estates, 'id');

        /** @var array<int, string> $postEstate  post id => the estate it stands at */
        $postEstate = [];

        foreach ($estates as $estate) {
            foreach ($estate['posts'] as $post) {
                $postEstate[$post['id']] = $estate['id'];
            }
        }

        $validator = Validator::make($input, [
            'full_name' => ['required', 'string', 'max:160'],

            'psra_number' => ['required', 'string', 'max:32', Rule::unique('mysql.guards', 'psra_number')],

            /*
             * `date` first, then `after_or_equal:today`. The two failures are
             * different facts about the world — "that is not a date" and "that
             * date has passed" — and the second one is the one this screen
             * exists to catch.
             */
            'psra_expires_on' => ['required', 'date', 'after_or_equal:today'],

            // Required, because the enrolment invite goes out by SMS. A guard
            // record with no way to reach the guard cannot start the sequence
            // the panel beside the form promises.
            'phone' => ['required', 'string', 'max:40'],

            'employment_type' => ['required', Rule::in(self::EMPLOYMENT_TYPES)],

            // Nullable, and that is a domain fact rather than leniency: a guard
            // hired between postings is a real state the roster already draws.
            'tenant_id' => ['nullable', Rule::in($estateIds)],
            'post_id' => ['nullable', 'integer', Rule::in(array_keys($postEstate))],

            // Digits and at most two decimal places, so the string that reaches
            // Money::of() is one that currency can represent exactly. A float
            // never touches it.
            'standard_rate' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
        ], [
            'full_name.required' => 'A guard needs a name on their record.',
            'psra_number.required' => 'A PSRA licence number is what makes this officer licensable. It cannot be added later.',
            'psra_number.unique' => 'That PSRA licence number already belongs to another guard on the platform.',
            'psra_expires_on.required' => 'A licence with no expiry on file cannot be shown to be current, which is the same to a regulator as one that has lapsed.',
            'psra_expires_on.date' => 'That is not a real date.',
            'psra_expires_on.after_or_equal' => 'That licence has already expired. An expired PSRA licence is a hard block on rostering, so this guard would be un-rosterable from the moment the record exists — record the renewed licence and its new expiry instead.',
            'phone.required' => 'A phone number is how the Guard App enrolment invite reaches them.',
            'employment_type.required' => 'Choose whether this is a full-time or part-time position.',
            'tenant_id.in' => 'That client is not one you may post a guard to.',
            'post_id.in' => 'That post is not one you may post a guard to.',
            'standard_rate.required' => 'A standard hourly rate is an employment term, agreed at hire.',
            'standard_rate.regex' => 'Enter the hourly rate as an amount — 425 or 425.00, without a currency symbol.',
        ]);

        $validator->after(function ($validator) use ($input, $postEstate): void {
            $postId = $this->nullableId($input['post_id'] ?? null);

            if ($postId === null || ! array_key_exists($postId, $postEstate)) {
                return;
            }

            $tenantId = $this->nullableText($input['tenant_id'] ?? null);

            /*
             * A post belongs to exactly one estate, so a post chosen against
             * the wrong client is not a preference to reconcile — it would
             * create a guard standing a gate at an estate their record says
             * they do not work at, and the cross-client roster would draw them
             * under both.
             */
            if ($tenantId === null) {
                $validator->errors()->add('post_id', 'Choose the client first — a post belongs to one client.');

                return;
            }

            if ($postEstate[$postId] !== $tenantId) {
                $validator->errors()->add('post_id', 'That post is not at the client you chose.');
            }
        });

        return $validator;
    }

    /**
     * How many guards each estate has on its books right now.
     *
     * @return array<string, int>
     */
    private function guardCountsByEstate(): array
    {
        /** @var array<string, int> $counts */
        $counts = DB::connection('mysql')
            ->table('guards')
            ->whereNotNull('tenant_id')
            ->groupBy('tenant_id')
            ->selectRaw('tenant_id, count(*) as total')
            ->pluck('total', 'tenant_id')
            ->map(fn ($total): int => (int) $total)
            ->all();

        return $counts;
    }

    /**
     * The per-guard Security Provider add-on from the platform rate card.
     *
     * Null when no such rate is on file, and the screen then says nothing about
     * billing at all. A default of any kind here would put a made-up monthly
     * figure in front of somebody about to hire.
     *
     * @return array{minor: int, currency: string}|null
     */
    private function addOnPerGuard(): ?array
    {
        $row = DB::connection('mysql')
            ->table('platform_rates')
            ->where('key', 'security_provider_guard')
            ->where('is_active', true)
            ->select('amount_minor', 'currency')
            ->first();

        return $row === null
            ? null
            : ['minor' => (int) $row->amount_minor, 'currency' => (string) $row->currency];
    }

    /** "1st", "2nd", "3rd", "11th" — the board's own phrasing for the next hire. */
    private function ordinal(int $number): string
    {
        $suffix = match (true) {
            $number % 100 >= 11 && $number % 100 <= 13 => 'th',
            $number % 10 === 1 => 'st',
            $number % 10 === 2 => 'nd',
            $number % 10 === 3 => 'rd',
            default => 'th',
        };

        return $number.$suffix;
    }

    /** A submitted value that means "nothing chosen", turned into a real null. */
    private function nullableText(mixed $value): ?string
    {
        return $this->text($value);
    }

    /** The same, for an id: an empty select posts '', which is not a post. */
    private function nullableId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        $text = $this->text($value);

        return $text === null || ! ctype_digit($text) ? null : (int) $text;
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
     * Why this guard cannot be suspended right now, or null when they can.
     *
     * Two refusals, and neither is a technicality. Suspending a guard whose
     * licence is in order would be a disciplinary act dressed as a compliance
     * one, and this screen is not where that is decided. Suspending a guard who
     * is already suspended would write a second audit row saying nothing
     * changed, which is exactly the kind of noise that makes a log unreadable.
     */
    private function suspensionBlockedReason(Guard $guard): ?string
    {
        if ($guard->status === 'suspended') {
            return $guard->full_name.' is already suspended from active duty.';
        }

        if ($this->complianceCase($guard) === null) {
            return 'There is no open compliance matter against '.$guard->full_name
                .'. A licence in good standing is not grounds for a suspension.';
        }

        return null;
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
