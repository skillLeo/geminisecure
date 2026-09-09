<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * The audit log: the only sanctioned way to write it, and the console's way to
 * read it back.
 *
 * Reading and writing live in one class deliberately. The write side decides
 * what an action is called; the read side is the only thing that has to turn
 * those strings back into words a reviewer understands. Split across two files
 * that vocabulary drifts, and the log starts describing an action the logger
 * cannot produce — the exact failure an audit log exists to rule out.
 *
 * The actor's name and role are denormalised onto the row rather than joined
 * at read time, because the log has to read correctly years later, after the
 * account has been renamed, re-roled or deleted. A join that returns null tells
 * a reviewer nothing about who did the thing.
 *
 * Nothing here updates or deletes. It cannot: audit_log is append-only at a
 * withheld grant, at a database trigger and in App\Models\AuditEntry.
 */
class AuditLogger
{
    /** How an entry with no signed-in actor is shown, and filtered by. */
    public const SYSTEM_ACTOR = 'System';

    /** How the board draws a platform-level entry that concerns no estate. */
    public const NO_ESTATE = '—';

    public const DEFAULT_SORT = 'timestamp';

    public const DEFAULT_DIRECTION = 'desc';

    /** Rows per page. The board draws five; a real log needs a real page. */
    public const PER_PAGE = 25;

    /**
     * The five columns the board draws, and what each one sorts by.
     *
     * The header text is the board's. The sort columns are here rather than in
     * the page component so the screen cannot offer a sort the query does not
     * implement: the component renders whatever this returns and nothing else.
     *
     * `client` is special-cased in the query — it sorts by the estate name the
     * column shows, which needs a join, not a column. `details` sorts by the
     * same fields the details sentence is built from, and in the same order,
     * so the visible order and the sort order are the same fact.
     *
     * `first` is the direction a column takes the first time it is clicked.
     * Time runs backwards by default in a log — the newest entry is the one
     * being looked for — while a name is looked up from the top.
     *
     * @var array<string, array{label: string, order: list<string>, first: string}>
     */
    private const COLUMNS = [
        'timestamp' => ['label' => 'Timestamp', 'order' => ['created_at'], 'first' => 'desc'],
        'admin' => ['label' => 'Admin', 'order' => ['actor_name'], 'first' => 'asc'],
        'action' => ['label' => 'Action', 'order' => ['action'], 'first' => 'asc'],
        'client' => ['label' => 'Client affected', 'order' => [], 'first' => 'asc'],
        'details' => ['label' => 'Details', 'order' => ['action', 'entity_type', 'entity_id'], 'first' => 'asc'],
    ];

    /**
     * Which part of the platform an action belongs to, keyed by the domain the
     * action names before the dot.
     *
     * Keyed by domain rather than by every individual action, so recording a
     * new `invoice.*` action does not also require an entry here before the
     * screen can label it. `tag` is the board's pill colour: the stylesheet
     * defines exactly three (billing, compliance, access) and no more may be
     * invented, so several categories share the neutral one.
     *
     * @var array<string, array{category: string, tag: string}>
     */
    private const DOMAINS = [
        'tenant' => ['category' => 'Clients', 'tag' => 'access'],
        'subscription' => ['category' => 'Billing', 'tag' => 'billing'],
        'invoice' => ['category' => 'Billing', 'tag' => 'billing'],
        'plan' => ['category' => 'Billing', 'tag' => 'billing'],
        'payroll' => ['category' => 'Billing', 'tag' => 'billing'],
        'payslip' => ['category' => 'Billing', 'tag' => 'billing'],
        'guard' => ['category' => 'Workforce', 'tag' => 'access'],
        'post' => ['category' => 'Workforce', 'tag' => 'access'],
        'shift' => ['category' => 'Workforce', 'tag' => 'access'],
        'user' => ['category' => 'Access', 'tag' => 'access'],
        'role' => ['category' => 'Access', 'tag' => 'access'],
        'permission' => ['category' => 'Access', 'tag' => 'access'],
        'session' => ['category' => 'Access', 'tag' => 'access'],
        'alert' => ['category' => 'Dispatch', 'tag' => 'compliance'],
        'duress' => ['category' => 'Dispatch', 'tag' => 'compliance'],
    ];

    /**
     * Actions that are compliance events whatever domain they name.
     *
     * A flagged licence is a `guard.*` action but it is not workforce news,
     * and the board colours it red. Checked before the domain table, so this
     * wins.
     *
     * @var list<string>
     */
    private const COMPLIANCE_ACTIONS = [
        '*licence*',
        '*compliance*',
        '*.flagged',
        '*.suspended',
        '*.expired',
        '*.breached',
    ];

    /* -----------------------------------------------------------------
     | Writing
     |------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $tenantId = null,
    ): AuditEntry {
        $actor = Auth::user();

        return AuditEntry::create([
            // Falls back to the current tenant so an action taken inside an
            // estate is attributed to it without every caller remembering.
            'tenant_id' => $tenantId ?? tenant()?->getTenantKey(),
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_role' => $actor?->roles->first()?->label,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip' => Request::ip(),
            'user_agent' => str(Request::userAgent() ?? '')->limit(250)->value(),
        ]);
    }

    /* -----------------------------------------------------------------
     | Reading — Super Admin screen 41
     |------------------------------------------------------------------ */

    /**
     * The columns the screen may render and sort, in the board's order.
     *
     * @return list<array{key: string, label: string, first: string}>
     */
    public static function columns(): array
    {
        $columns = [];

        foreach (self::COLUMNS as $key => $column) {
            $columns[] = ['key' => $key, 'label' => $column['label'], 'first' => $column['first']];
        }

        return $columns;
    }

    /** A requested sort key, or the default if it is not one this screen sorts by. */
    public static function sortKey(?string $requested): string
    {
        return $requested !== null && array_key_exists($requested, self::COLUMNS)
            ? $requested
            : self::DEFAULT_SORT;
    }

    /** A requested direction, or the default. Only two values are ever accepted. */
    public static function direction(?string $requested): string
    {
        return $requested === 'asc' || $requested === 'desc'
            ? $requested
            : self::DEFAULT_DIRECTION;
    }

    /**
     * One page of the log, filtered, sorted and already in the shape the
     * screen renders.
     *
     * Every narrowing happens in the query, never after it. A scoped role that
     * sorts a column or asks for page nine must not see one row more than it
     * would on page one, and a limit applied to an already-fetched collection
     * gives exactly that.
     *
     * @param  array{q?: string|null, actor?: string|null, category?: string|null, from?: string|null, to?: string|null}  $filters
     * @param  list<string>|null  $estateIds  Null sees every estate; a list sees those, plus platform-level entries.
     * @return array{rows: list<array<string, mixed>>, page: int, pages: int, total: int, previous_url: string|null, next_url: string|null}
     */
    public function search(
        array $filters,
        string $sortKey,
        string $direction,
        ?array $estateIds = null,
        int $perPage = self::PER_PAGE,
    ): array {
        $query = AuditEntry::query()->with('estate');

        if ($estateIds !== null) {
            // A site-scoped role sees its own estates, plus the platform-level
            // entries that belong to no single estate.
            $query->where(fn ($scoped) => $scoped->whereIn('tenant_id', $estateIds)->orWhereNull('tenant_id'));
        }

        $term = $filters['q'] ?? null;
        $category = $filters['category'] ?? null;

        /*
         * Both the search and the category filter have to reach the Action
         * column, whose pill is a category this class derives rather than a
         * column anyone can compare against. Deriving it needs the log's own
         * action strings, so they are fetched once, here, and only when one of
         * those two filters is actually in play.
         */
        $actions = $term !== null || $category !== null ? $this->distinctActions() : [];

        $this->applyTerm($query, $term, $actions);
        $this->applyActor($query, $filters['actor'] ?? null);
        $this->applyCategory($query, $category, $actions);

        if (($filters['from'] ?? null) !== null) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (($filters['to'] ?? null) !== null) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $this->applySort($query, $sortKey, $direction);

        $page = $query->paginate($perPage)->withQueryString();
        $entries = $page->getCollection();

        $estateNames = $this->estateNames($estateIds);
        $userNames = $this->userNames($entries);

        /** @var list<array<string, mixed>> $rows */
        $rows = $entries
            ->map(fn (AuditEntry $entry): array => $this->present($entry, $estateNames, $userNames))
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'page' => $page->currentPage(),
            'pages' => $page->lastPage(),
            'total' => $page->total(),
            'previous_url' => $page->previousPageUrl(),
            'next_url' => $page->nextPageUrl(),
        ];
    }

    /**
     * The category and pill colour an action is shown under.
     *
     * @return array{category: string, tag: string}
     */
    public function categoryOf(string $action): array
    {
        if (Str::is(self::COMPLIANCE_ACTIONS, $action)) {
            return ['category' => 'Compliance', 'tag' => 'compliance'];
        }

        $domain = Str::before($action, '.');

        // An action from a domain nobody has classified still has to render,
        // and render usefully: the domain it names is a better label than
        // "Other", and it keeps working when a new domain starts logging.
        return self::DOMAINS[$domain] ?? ['category' => Str::headline($domain), 'tag' => 'access'];
    }

    /* -----------------------------------------------------------------
     | Reading — internals
     |------------------------------------------------------------------ */

    /**
     * Free-text search across everything the screen actually shows.
     *
     * "What the screen shows" is the whole point of the method. The estate
     * name lives on another table; the details sentence is the action string
     * with its dots and underscores opened out; the action tag is a category
     * derived in PHP. A reader who types a word they can see on the row and
     * gets nothing back does not conclude that the search is narrow — they
     * conclude the entry is gone, which is the one thing an audit log must
     * never suggest.
     *
     * @param  Builder<AuditEntry>  $query
     * @param  list<string>  $actions  Every distinct action in the log.
     */
    private function applyTerm($query, ?string $term, array $actions): void
    {
        if ($term === null) {
            return;
        }

        // The wildcards are the search's, not the reader's: a term containing
        // % or _ must match those characters, not stand in for any run of them.
        $like = '%'.addcslashes($term, '%_\\').'%';

        $categoryActions = array_values(array_filter(
            $actions,
            fn (string $action): bool => Str::contains($this->categoryOf($action)['category'], $term, true),
        ));

        $query->where(function ($matches) use ($like, $categoryActions) {
            $matches->where('actor_name', 'like', $like)
                ->orWhere('actor_role', 'like', $like)
                ->orWhere('action', 'like', $like)
                // The Details column reads "Guard licence flagged", so that is
                // what a reader searches for, not guard.licence_flagged.
                ->orWhereRaw("replace(replace(action, '.', ' '), '_', ' ') like ?", [$like])
                ->orWhere('entity_type', 'like', $like)
                ->orWhere('entity_id', 'like', $like)
                ->orWhereHas('estate', fn ($estate) => $estate->where('name', 'like', $like));

            if ($categoryActions !== []) {
                $matches->orWhereIn('action', $categoryActions);
            }
        });
    }

    /**
     * @param  Builder<AuditEntry>  $query
     */
    private function applyActor($query, ?string $actor): void
    {
        if ($actor === null) {
            return;
        }

        if ($actor === self::SYSTEM_ACTOR) {
            // Entries with no signed-in actor are drawn as "System", so
            // filtering by System has to return exactly the rows that are
            // drawn that way — the unattributed ones included.
            $query->where(fn ($system) => $system->whereNull('actor_name')->orWhere('actor_name', self::SYSTEM_ACTOR));

            return;
        }

        $query->where('actor_name', $actor);
    }

    /**
     * @param  Builder<AuditEntry>  $query
     * @param  list<string>  $actions  Every distinct action in the log.
     */
    private function applyCategory($query, ?string $category, array $actions): void
    {
        if ($category === null) {
            return;
        }

        /*
         * Resolved through categoryOf(), rather than from a second list of
         * which actions count as "Billing". The reader clicked a pill that
         * categoryOf() drew; putting the filter through the same function is
         * what makes it impossible for the two to disagree. A category nothing
         * belongs to narrows to nothing, which is the honest answer.
         */
        $query->whereIn('action', array_values(array_filter(
            $actions,
            fn (string $action): bool => $this->categoryOf($action)['category'] === $category,
        )));
    }

    /**
     * Every action string the log actually holds.
     *
     * @return list<string>
     */
    private function distinctActions(): array
    {
        $actions = AuditEntry::query()
            ->distinct()
            ->pluck('action')
            ->map(fn ($action): string => (string) $action)
            ->values()
            ->all();

        return $actions;
    }

    /**
     * @param  Builder<AuditEntry>  $query
     */
    private function applySort($query, string $sortKey, string $direction): void
    {
        if ($sortKey === 'client') {
            /*
             * Ordered by the estate name the column shows, not by the tenant id
             * behind it. A reader sorting a visible column expects the visible
             * order, and 'oceanview' does not sort where 'Ocean View' does.
             */
            $query->leftJoin('tenants', 'tenants.id', '=', 'audit_log.tenant_id')
                ->select('audit_log.*')
                ->orderBy('tenants.name', $direction);
        } else {
            foreach (self::COLUMNS[$sortKey]['order'] as $column) {
                $query->orderBy($column, $direction);
            }
        }

        // A stable tie-break on a unique column, always. Without one, rows
        // sharing a sort value can drift between pages as the log grows, and a
        // reader paging through an audit trail silently skips entries.
        $query->orderBy('audit_log.id', 'desc');
    }

    /**
     * @param  array<string, string>  $estateNames
     * @param  array<string, string>  $userNames
     * @return array<string, mixed>
     */
    private function present(AuditEntry $entry, array $estateNames, array $userNames): array
    {
        $category = $this->categoryOf($entry->action);
        $actor = $entry->actor_name ?? self::SYSTEM_ACTOR;

        return [
            'id' => $entry->id,
            // The board's format, to the minute. An audit trail is read as a
            // sequence, and a bare date cannot order two entries in one day.
            'timestamp' => $entry->created_at->format('M j, Y g:i A'),
            'date' => $entry->created_at->toDateString(),
            'date_label' => $entry->created_at->format('j F Y'),
            'actor' => $actor,
            'initials' => $this->initials($actor),
            'category' => $category['category'],
            'tag' => $category['tag'],
            'estate' => $entry->estate->name ?? self::NO_ESTATE,
            'details' => $this->details($entry, $estateNames, $userNames),
        ];
    }

    /**
     * The sentence in the Details column.
     *
     * Built from the action string rather than from a table of hand-written
     * sentences: `guard.licence_flagged` reads as "Guard licence flagged"
     * without anyone maintaining a translation, and an action added tomorrow
     * reads correctly the first time it is recorded.
     *
     * @param  array<string, string>  $estateNames
     * @param  array<string, string>  $userNames
     */
    private function details(AuditEntry $entry, array $estateNames, array $userNames): string
    {
        $phrase = Str::ucfirst(str_replace(['.', '_'], ' ', $entry->action));
        $subject = $this->subjectOf($entry, $estateNames, $userNames);

        return $subject === null ? $phrase : $phrase.' — '.$subject;
    }

    /**
     * What the entry was about, named rather than keyed where the platform
     * knows the name. An estate id and a user id say nothing to a reviewer.
     *
     * @param  array<string, string>  $estateNames
     * @param  array<string, string>  $userNames
     */
    private function subjectOf(AuditEntry $entry, array $estateNames, array $userNames): ?string
    {
        $id = $entry->entity_id;

        if ($id === null || $id === '') {
            return null;
        }

        return match ($entry->entity_type) {
            'Tenant' => $estateNames[$id] ?? $id,
            'User' => $userNames[$id] ?? $id,
            default => $id,
        };
    }

    /**
     * Estate names by id, fetched once for the page, and scoped the way the
     * rows are.
     *
     * A site-scoped role can see a platform-level entry that names an estate
     * it does not hold — a provisioning, say, which belongs to no tenant.
     * Turning that id into the client's name would tell it something the
     * client directory deliberately does not, so out of scope the id stays the
     * id. The Client affected column needs no such care: a row is only visible
     * at all if its estate is one this role holds.
     *
     * @param  list<string>|null  $estateIds
     * @return array<string, string>
     */
    private function estateNames(?array $estateIds): array
    {
        $names = [];

        foreach (Tenant::estates() as $estate) {
            $key = (string) $estate->getTenantKey();

            if ($estateIds === null || in_array($key, $estateIds, true)) {
                $names[$key] = $estate->name;
            }
        }

        return $names;
    }

    /**
     * The names behind the user ids on this page, in one query.
     *
     * @param  Collection<int, AuditEntry>  $entries
     * @return array<string, string>
     */
    private function userNames(Collection $entries): array
    {
        $ids = $entries
            ->filter(fn (AuditEntry $entry): bool => $entry->entity_type === 'User' && $entry->entity_id !== null)
            ->map(fn (AuditEntry $entry): string => (string) $entry->entity_id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> $names */
        $names = User::query()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id): array => [(string) $id => (string) $name])
            ->all();

        return $names;
    }

    /**
     * The two characters the board draws in the avatar: the initials of the
     * first two words, or the first two letters of a single-word name.
     */
    private function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) >= 2) {
            return Str::upper(Str::substr($words[0], 0, 1).Str::substr($words[1], 0, 1));
        }

        return Str::upper(Str::substr($words[0] ?? '?', 0, 2));
    }
}
