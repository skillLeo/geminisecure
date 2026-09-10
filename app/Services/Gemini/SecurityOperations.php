<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The cross-client operational picture — board screens super-admin-24 to 27.
 *
 * Four questions a security company asks about every client at once: who is
 * standing which post this week, which written orders are in force at each
 * post, what the posts are reporting, and what has gone wrong.
 *
 * EVERY QUERY HERE READS gs_platform AND NOTHING ELSE. Guards, posts, shifts
 * and standing orders belong to Gemini Security Limited, not to an estate, so
 * they are central by construction. An estate database is never opened, and
 * where a screen wants a fact that only an estate holds, the screen says so
 * rather than reaching for it — see gateActivity() below, which is the whole
 * of that argument.
 *
 * SCOPE IS APPLIED IN SQL, NEVER IN THE RENDER. A Head of Security is scoped
 * to assigned sites, and both cross-client screens must therefore narrow the
 * QUERY: filtering rows out of a rendered list would leave every other estate
 * reachable by paging, sorting or deep-linking. ClientDirectory sets the
 * pattern and this follows it.
 */
class SecurityOperations
{
    /**
     * The rota's columns, Monday first.
     *
     * A guard week runs Monday to Sunday here rather than following the
     * viewer's locale: a rota published on Monday for the week ahead is the
     * thing being drawn, and a grid whose first column moved with the reader's
     * browser would put a different day under the same heading for two people
     * looking at the same roster.
     *
     * @var list<string>
     */
    private const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /**
     * The cross-client roster — board screen super-admin-24.
     *
     * WHAT A ROW IS, AND WHY.
     *
     * A row is a standing assignment: a guard, and the post they are posted to.
     * That is what `guards.post_id` records, and it is the fact a rota is built
     * out of — "Marcus Whyte stands Phoenix Park's Main Gate". A post nobody is
     * posted to is not a line on the rota; a post whose guard cannot stand it
     * is, and every cell on it reads Open, because an uncovered post is the one
     * thing this screen exists to make impossible to miss.
     *
     * This is deliberately NOT built from the `shifts` table. A shift is one
     * instance — rostered start, actual start, geofence distance — and the
     * dispatch coverage board reads those to answer "is this post manned right
     * now". The rota answers a different question, about the standing pattern
     * for the week, and reading tonight's shift rows to answer it would show a
     * post as uncovered on Thursday merely because Thursday has not been
     * dispatched yet.
     *
     * @return array<string, mixed>
     */
    public function roster(User $viewer): array
    {
        $lines = $this->rosterQuery($viewer)->get();

        $groups = [];

        foreach ($lines as $line) {
            $estate = (string) $line->tenant_id;

            $groups[$estate] ??= [
                'id' => $estate,
                'name' => (string) $line->estate_name,
                'lines' => [],
            ];

            $groups[$estate]['lines'][] = [
                'key' => $estate.'-'.$line->post_id.'-'.$line->guard_id,
                'post' => (string) $line->post_name,
                'cells' => $this->week($line),
            ];
        }

        $groups = array_values($groups);

        return [
            'days' => self::DAYS,
            'week' => $this->weekLabel(),
            'groups' => $groups,
            'kpis' => $this->rosterKpis($viewer, $lines->all()),
            'scoped' => $this->isScoped($viewer),
        ];
    }

    /**
     * One rota line's seven cells.
     *
     * Every day carries the same answer today, and that is honest rather than
     * lazy: nothing in the central schema records a per-day exception to a
     * standing assignment. Approved leave is held as a date range on the
     * requests inbox and an expired licence as a status, and both of those
     * change the whole week at once. When a per-day rota is published — the
     * board's own "copy last week" and "publish the roster" actions — this is
     * the method that reads it, and only this method changes.
     *
     * @return list<array{label: string, tone: string, title: string}>
     */
    private function week(object $line): array
    {
        $blocked = $this->blockedReason($line);

        $cell = $blocked === null
            ? [
                'label' => $this->shortName((string) $line->guard_name),
                // A patrol is a night line and a gate a day line: that is what
                // `posts.type` distinguishes, and it is the only window this
                // schema records. A post whose manning window is contracted
                // differently would need `post.coverage requirement` from the
                // spec's entity list — see the note on rosterKpis().
                'tone' => $line->post_type === 'patrol' ? 'night' : 'day',
                'title' => $line->guard_name.' — '.$line->post_name.', '.$line->estate_name,
            ]
            : [
                'label' => 'Open',
                'tone' => 'open',
                'title' => $blocked,
            ];

        return array_fill(0, count(self::DAYS), $cell);
    }

    /**
     * Why this post is not being stood, or null when it is.
     *
     * `licence_expired` is a HARD BLOCK rather than a warning: the spec sets an
     * expired PSRA licence to un-rosterable, and a rota that quietly showed
     * that guard covering their post all week would be the platform asserting
     * coverage that legally does not exist. On leave and suspended are the same
     * answer for different reasons — the post is not being stood.
     *
     * The reason travels with the cell rather than being reduced to a red chip,
     * because "nobody is posted here" and "the guard posted here has a lapsed
     * licence" are the same colour on the grid and completely different
     * problems to fix.
     */
    private function blockedReason(object $line): ?string
    {
        if ($line->guard_id === null) {
            return 'No guard is posted to '.$line->post_name.'.';
        }

        return match ((string) $line->guard_status) {
            'licence_expired' => $line->guard_name.' cannot be rostered: their PSRA licence has expired.',
            'on_leave' => $line->guard_name.' is on leave, so this post is unstaffed.',
            'suspended' => $line->guard_name.' is suspended from active duty.',
            'inactive' => $line->guard_name.' is no longer an active employee.',
            default => null,
        };
    }

    /**
     * The four cards above the grid.
     *
     * "Posts, platform-wide" counts every active post, not only the ones with a
     * line on the rota. The difference between the two figures is the point:
     * a post with nobody posted to it is a post the platform is not staffing.
     *
     * @param  list<object>  $lines
     * @return list<array{icon: string, stroke: float, value: string, label: string, warn: bool, title: string}>
     */
    private function rosterKpis(User $viewer, array $lines): array
    {
        $onDuty = 0;
        $uncovered = 0;

        foreach ($lines as $line) {
            if ($this->blockedReason($line) === null) {
                $onDuty++;
            } else {
                $uncovered++;
            }
        }

        $posts = $this->scoped($viewer, DB::connection('mysql')->table('posts'), 'posts.tenant_id')
            ->where('posts.is_active', true)
            ->count();

        $clients = count(array_unique(array_map(
            static fn (object $line): string => (string) $line->tenant_id,
            $lines
        )));

        return [
            [
                'icon' => 'guards',
                'stroke' => 1.7,
                'value' => (string) $onDuty,
                'label' => 'On duty now, platform-wide',
                'warn' => false,
                'title' => 'Guards posted to a client and fit to stand it right now.',
            ],
            [
                'icon' => 'alert',
                'stroke' => 1.6,
                'value' => (string) $uncovered,
                'label' => $uncovered === 1 ? 'Uncovered post' : 'Uncovered posts',
                'warn' => true,
                'title' => 'Posts whose assigned guard cannot stand them this week.',
            ],
            [
                'icon' => 'check-circle',
                'stroke' => 1.6,
                'value' => (string) $clients,
                'label' => 'Clients covered',
                'warn' => false,
                'title' => 'Clients with at least one post on the rota.',
            ],
            [
                'icon' => 'clock',
                'stroke' => 1.7,
                'value' => (string) $posts,
                'label' => 'Posts, platform-wide',
                'warn' => false,
                'title' => 'Every active guard post, whether or not anyone is posted to it.',
            ],
        ];
    }

    /**
     * The rota lines, ordered as the grid draws them.
     *
     * A live client comes before one still being onboarded, because an
     * onboarding estate's rota is provisional and the live sites are what a
     * supervisor is reading this screen for. Within a client, gates come before
     * patrols and then it is alphabetical, which is the order the board draws
     * and the order a post list is read in.
     */
    private function rosterQuery(User $viewer): Builder
    {
        $query = DB::connection('mysql')
            ->table('posts')
            ->join('tenants', 'tenants.id', '=', 'posts.tenant_id')
            /*
             * INNER JOIN, and that is the row definition rather than an
             * optimisation: a post with no guard posted to it has no standing
             * assignment and so is not a line on the rota. It is still counted
             * by the "Posts, platform-wide" card, which is where the gap shows.
             */
            ->join('guards', 'guards.post_id', '=', 'posts.id')
            ->where('posts.is_active', true)
            ->select([
                'posts.id as post_id',
                'posts.name as post_name',
                'posts.type as post_type',
                'posts.tenant_id',
                'tenants.name as estate_name',
                'tenants.status as estate_status',
                'guards.id as guard_id',
                'guards.full_name as guard_name',
                'guards.status as guard_status',
            ])
            ->orderByRaw("CASE WHEN tenants.status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('tenants.name')
            ->orderByRaw("CASE WHEN posts.type = 'patrol' THEN 1 ELSE 0 END")
            ->orderBy('posts.name')
            ->orderBy('guards.full_name');

        return $this->scoped($viewer, $query, 'posts.tenant_id');
    }

    /* ------------------------------------------------------------------ */
    /* scope */
    /* ------------------------------------------------------------------ */

    /**
     * The written orders in force at every post — board screen 25.
     *
     * Two kinds of set, and the difference is the whole screen. A MASTER
     * TEMPLATE applies to every post at every client and is acknowledged by
     * nobody in particular; a POST-SPECIFIC set belongs to one gate and has to
     * be acknowledged by the guard standing it. An unacknowledged post-specific
     * set is a guard working to orders they have not read, which is why the
     * board gives it a red badge and why that state is computed rather than
     * stored.
     *
     * @return array<string, mixed>
     */
    public function standingOrders(User $viewer): array
    {
        $query = DB::connection('mysql')
            ->table('standing_order_sets')
            ->leftJoin('tenants', 'tenants.id', '=', 'standing_order_sets.tenant_id')
            ->leftJoin('posts', 'posts.id', '=', 'standing_order_sets.post_id')
            ->orderByRaw('standing_order_sets.tenant_id IS NOT NULL')
            ->orderBy('tenants.name')
            ->orderBy('posts.name')
            ->select([
                'standing_order_sets.id',
                'standing_order_sets.title',
                'standing_order_sets.category',
                'standing_order_sets.summary',
                'standing_order_sets.version',
                'standing_order_sets.reviewed_on',
                'standing_order_sets.tenant_id',
                'standing_order_sets.post_id',
                'tenants.name as estate',
                'posts.name as post',
            ]);

        /*
         * A master template has no tenant_id and belongs to everyone, so a
         * scoped viewer keeps it. Scoping it away would hide the company's own
         * general orders from the person enforcing them.
         */
        if ($this->isScoped($viewer)) {
            $ids = $viewer->accessibleEstateIds();
            $query->where(function (Builder $q) use ($ids): void {
                $q->whereNull('standing_order_sets.tenant_id')
                    ->orWhereIn('standing_order_sets.tenant_id', $ids);
            });
        }

        $sets = $query->get();

        $acknowledgements = DB::connection('mysql')
            ->table('standing_order_acknowledgements')
            ->join('guards', 'guards.id', '=', 'standing_order_acknowledgements.guard_id')
            ->whereIn('standing_order_set_id', $sets->pluck('id'))
            ->orderByDesc('acknowledged_at')
            ->select([
                'standing_order_acknowledgements.standing_order_set_id as set_id',
                'standing_order_acknowledgements.version',
                'standing_order_acknowledgements.acknowledged_at',
                'guards.full_name',
            ])
            ->get()
            ->groupBy('set_id');

        /*
         * Which posts have somebody who can LEGALLY stand them, so an order set
         * nobody has acknowledged can be told apart from one at a post nobody
         * can be assigned to.
         *
         * An expired PSRA licence is a hard block, not a warning: Devon Palmer
         * is posted at Phoenix Park's Service Gate and cannot be rostered
         * there, which is why the board's badge for that set reads "Unassigned
         * post" rather than "not yet acknowledged". Counting him as staffed
         * would turn a gate nobody can stand into a guard who has not got round
         * to reading the orders.
         */
        $staffed = DB::connection('mysql')
            ->table('guards')
            ->whereNotNull('post_id')
            ->where('status', 'active')
            ->where(function (Builder $licence): void {
                $licence->whereNull('psra_expires_on')
                    ->orWhereDate('psra_expires_on', '>=', today());
            })
            ->pluck('post_id')
            ->flip();

        return [
            'sets' => $sets->map(function (object $set) use ($acknowledgements, $staffed): array {
                $master = $set->tenant_id === null;
                $ack = $acknowledgements->get($set->id, collect())
                    ->firstWhere('version', $set->version);

                return [
                    'id' => (int) $set->id,
                    /*
                     * The board draws three glyphs and means three things by
                     * them: a ledger for the company's own general orders, a
                     * speaker for the emergency protocols that get broadcast,
                     * and a shield for a set that belongs to one gate.
                     */
                    'icon' => $master ? ($set->category === 'emergency' ? 'broadcast' : 'ledger') : 'shield',
                    'name' => $master
                        ? (string) $set->title
                        : sprintf('%s — %s', $set->estate, $set->post),
                    'meta' => $this->orderMeta($set, $master, $ack, $staffed),
                    'badge' => $master ? 'Master template' : ($ack === null && ! $staffed->has($set->post_id) ? 'Unassigned post' : 'Active'),
                    'variant' => $master
                        ? 'template'
                        : ($ack === null && ! $staffed->has($set->post_id) ? 'unassigned' : 'active'),
                ];
            })->all(),
        ];
    }

    /**
     * The line under a set's name.
     *
     * Three different sentences for three different facts: a template says
     * where it applies, an acknowledged set says who read it and when, and an
     * unacknowledged one says why nobody has.
     *
     * @param  Collection<int, int>  $staffed
     */
    private function orderMeta(object $set, bool $master, ?object $ack, $staffed): string
    {
        if ($master) {
            return sprintf(
                '%s · Version %d · Reviewed %s',
                $set->summary ?? 'Applied to every post at every client',
                (int) $set->version,
                $set->reviewed_on === null ? 'not yet' : Carbon::parse((string) $set->reviewed_on)->format('M j, Y'),
            );
        }

        if ($ack !== null) {
            return sprintf(
                'Post-specific orders · Version %d · Acknowledged by %s, %s',
                (int) $set->version,
                $ack->full_name,
                Carbon::parse((string) $ack->acknowledged_at)->format('M j'),
            );
        }

        return sprintf(
            'Post-specific orders · Version %d · %s',
            (int) $set->version,
            $staffed->has($set->post_id)
                ? 'Not yet acknowledged by the guard on post'
                : 'No guard currently assigned to acknowledge',
        );
    }

    /**
     * How many rows the feed carries.
     *
     * The board's own figure. It is a live surface a dispatcher glances at, not
     * a log they read — seven rows is what fits above the fold, and a feed that
     * scrolled would put the most recent event out of sight the moment the next
     * one landed.
     */
    private const FEED_ROWS = 7;

    /**
     * What the gates are reporting, across every client — board screen 26.
     *
     * READ CENTRALLY, which is the only way this screen can exist. A
     * cross-client feed built by opening each estate's own database in turn
     * would be a tenant-isolation breach wearing a report's clothes.
     *
     * THREE SOURCES, MERGED, and deliberately not one. A gate DECISION has no
     * central home, so `gate_events` holds it. A checkpoint scan and a shift
     * start already have one — `checkpoint_scans` and `shifts`, written by the
     * screens that own them — and copying those into a fourth table so this
     * feed could be a single query would create two records of one event that
     * are free to disagree. Each fact is read from the table that owns it.
     *
     * Every branch is indexed and capped at FEED_ROWS before the merge, so the
     * five-second poll costs three small reads rather than three table scans.
     *
     * @return array<string, mixed>
     */
    public function gateActivity(User $viewer): array
    {
        $feed = array_merge(
            $this->gateDecisions($viewer),
            $this->checkpointScans($viewer),
            $this->shiftStarts($viewer),
        );

        usort($feed, static fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        $today = $this->scoped(
            $viewer,
            DB::connection('mysql')->table('gate_events'),
            'tenant_id',
        )
            ->whereDate('occurred_at', today())
            ->selectRaw("SUM(verdict = 'admit') as admits, SUM(verdict = 'deny') as denies")
            ->first();

        /*
         * ON POST means standing one right now, not assigned to one on paper.
         *
         * Counting `guards.post_id` would include Devon Palmer, whose licence
         * has lapsed and who is therefore not rostered anywhere — and the
         * coverage board two screens away says his gate is uncovered. Two
         * screens disagreeing about whether a post is manned is worse than
         * either number on its own.
         */
        $onPost = $this->scoped(
            $viewer,
            DB::connection('mysql')->table('shifts'),
            'tenant_id',
        )
            ->where('status', 'active')
            ->whereNotNull('actual_start')
            ->distinct()
            ->count('guard_id');

        $duress = $this->scoped(
            $viewer,
            DB::connection('mysql')->table('duress_alerts'),
            'tenant_id',
        )
            ->whereNull('resolved_at')
            ->count();

        return [
            'kpis' => [
                ['key' => 'on_post', 'icon' => 'guards', 'value' => (string) $onPost, 'label' => 'Guards on post, platform-wide'],
                ['key' => 'admits', 'icon' => 'check', 'value' => (string) (int) ($today->admits ?? 0), 'label' => 'Admits today, all clients'],
                ['key' => 'denies', 'icon' => 'close', 'value' => (string) (int) ($today->denies ?? 0), 'label' => 'Denied today, all clients'],
                ['key' => 'duress', 'icon' => 'shield', 'value' => (string) $duress, 'label' => 'Active duress alerts'],
            ],
            'feed' => array_map(
                static fn (array $row): array => Arr::except($row, 'at'),
                array_slice($feed, 0, self::FEED_ROWS),
            ),
        ];
    }

    /**
     * Admits and denials — the only branch `gate_events` owns.
     *
     * @return list<array<string, mixed>>
     */
    private function gateDecisions(User $viewer): array
    {
        return $this->scoped(
            $viewer,
            DB::connection('mysql')
                ->table('gate_events')
                ->join('tenants', 'tenants.id', '=', 'gate_events.tenant_id'),
            'gate_events.tenant_id',
        )
            ->orderByDesc('gate_events.occurred_at')
            ->limit(self::FEED_ROWS)
            ->select([
                'gate_events.verdict',
                'gate_events.subject',
                'gate_events.basis',
                'gate_events.guard_name',
                'gate_events.post_name',
                'gate_events.occurred_at',
                'tenants.name as estate',
            ])
            ->get()
            ->map(fn (object $row): array => $this->feedRow(
                // An override is a guard admitting somebody against standing
                // orders. It is still an admission, and the board has no fourth
                // colour, so it is drawn as one — and its basis says what it was.
                $row->verdict === 'deny' ? 'deny' : 'admit',
                $row->basis === null
                    ? (string) $row->subject
                    : sprintf('%s — %s', $row->subject, $row->basis),
                $this->joinDetail([$row->post_name, $row->guard_name]),
                (string) $row->estate,
                Carbon::parse((string) $row->occurred_at),
            ))
            ->all();
    }

    /**
     * Patrol checkpoints reached.
     *
     * Joined to the estate through the checkpoint, because `checkpoint_scans`
     * holds no tenant of its own — a scan belongs to a checkpoint, and the
     * checkpoint belongs to a post at an estate.
     *
     * @return list<array<string, mixed>>
     */
    private function checkpointScans(User $viewer): array
    {
        return $this->scoped(
            $viewer,
            DB::connection('mysql')
                ->table('checkpoint_scans')
                ->join('patrol_checkpoints', 'patrol_checkpoints.id', '=', 'checkpoint_scans.patrol_checkpoint_id')
                ->join('guards', 'guards.id', '=', 'checkpoint_scans.guard_id')
                ->join('tenants', 'tenants.id', '=', 'patrol_checkpoints.tenant_id')
                ->leftJoin('posts', 'posts.id', '=', 'patrol_checkpoints.post_id'),
            'patrol_checkpoints.tenant_id',
        )
            ->orderByDesc('checkpoint_scans.server_time')
            ->limit(self::FEED_ROWS)
            ->select([
                'patrol_checkpoints.label',
                'guards.full_name',
                'posts.name as post',
                'checkpoint_scans.server_time',
                'tenants.name as estate',
            ])
            ->get()
            ->map(fn (object $row): array => $this->feedRow(
                'patrol',
                sprintf('Checkpoint scanned — %s', $row->label),
                $this->joinDetail([$row->post, $row->full_name]),
                (string) $row->estate,
                Carbon::parse((string) $row->server_time),
            ))
            ->all();
    }

    /**
     * Shifts that actually started.
     *
     * `actual_start`, never `rostered_start`. A roster is an intention; this
     * feed reports what happened, and a post that stood empty for two hours is
     * exactly the thing a rostered time would hide.
     *
     * The geofence line comes from `shifts` rather than from a copy of it,
     * which is the whole reason this branch exists instead of a fourth column
     * on `gate_events`.
     *
     * @return list<array<string, mixed>>
     */
    private function shiftStarts(User $viewer): array
    {
        return $this->scoped(
            $viewer,
            DB::connection('mysql')
                ->table('shifts')
                ->join('guards', 'guards.id', '=', 'shifts.guard_id')
                ->join('posts', 'posts.id', '=', 'shifts.post_id')
                ->join('tenants', 'tenants.id', '=', 'shifts.tenant_id'),
            'shifts.tenant_id',
        )
            ->whereNotNull('shifts.actual_start')
            ->orderByDesc('shifts.actual_start')
            ->limit(self::FEED_ROWS)
            ->select([
                'shifts.actual_start',
                'shifts.rostered_start',
                'shifts.geofence_distance_m',
                'shifts.mock_location_flag',
                'guards.full_name',
                'posts.name as post',
                'tenants.name as estate',
            ])
            ->get()
            ->map(fn (object $row): array => $this->feedRow(
                'patrol',
                sprintf(
                    'Shift clocked in — %s, %s',
                    $row->post,
                    Carbon::parse((string) $row->rostered_start)->hour < 12 ? 'Day Shift' : 'Night Shift',
                ),
                $this->joinDetail([$row->full_name, $this->geofenceNote($row)]),
                (string) $row->estate,
                Carbon::parse((string) $row->actual_start),
            ))
            ->all();
    }

    /**
     * What the clock-in proved about where it happened.
     *
     * A DISTANCE, NOT A COORDINATE. `geofence_distance_m` is how far outside the
     * post's fence the handset was; it cannot be turned back into a position,
     * which is why the platform stores it and not the fix it came from. A
     * reported mock location outranks the distance — a spoofed handset can
     * report nought metres from anywhere on earth.
     */
    private function geofenceNote(object $shift): string
    {
        if ($shift->mock_location_flag) {
            return 'mock location reported';
        }

        return $shift->geofence_distance_m === null
            ? 'geofence not verified'
            : 'geofence verified';
    }

    /**
     * One row of the feed.
     *
     * `at` is the sort key and is dropped before the response leaves. It is a
     * Carbon rather than a formatted string because "9:40 AM" sorts before
     * "2:14 PM", and a live feed sorted alphabetically would put the afternoon
     * under the morning.
     *
     * @return array<string, mixed>
     */
    private function feedRow(string $verdict, string $headline, string $detail, string $estate, Carbon $at): array
    {
        return [
            'verdict' => $verdict,
            'headline' => $headline,
            'detail' => $detail,
            'estate' => $estate,
            'time' => $at->format('g:i A'),
            'at' => $at,
        ];
    }

    /**
     * The board's separator, skipping anything absent.
     *
     * @param  array<int, string|null>  $parts
     */
    private function joinDetail(array $parts): string
    {
        return implode(' · ', array_filter(
            $parts,
            static fn (?string $part): bool => $part !== null && $part !== '',
        ));
    }

    /**
     * What has gone wrong on a Gemini post — board screen 27.
     *
     * @return array<string, mixed>
     */
    public function incidents(User $viewer): array
    {
        $rows = $this->scoped(
            $viewer,
            DB::connection('mysql')
                ->table('security_incidents')
                ->join('tenants', 'tenants.id', '=', 'security_incidents.tenant_id'),
            'security_incidents.tenant_id',
        )
            ->orderByDesc('security_incidents.occurred_at')
            ->select([
                'security_incidents.id',
                'security_incidents.kind',
                'security_incidents.severity',
                'security_incidents.status',
                'security_incidents.occurred_at',
                'security_incidents.guard_name',
                'tenants.name as estate',
            ])
            ->get();

        /*
         * Duress is counted, not listed. It is a life-safety path with its own
         * table and its own screen; the board draws the count here as the
         * reassurance it is, and folding those alerts into an incident table
         * would put a panic button behind case management.
         */
        $duress = $this->scoped(
            $viewer,
            DB::connection('mysql')->table('duress_alerts'),
            'tenant_id',
        )->count();

        return [
            'incidents' => $rows->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'date' => Carbon::parse((string) $row->occurred_at)->format('M j, Y'),
                'estate' => (string) $row->estate,
                'kind' => (string) $row->kind,
                'guard' => $row->guard_name ?? 'Not recorded',
                'severity' => (string) $row->severity,
                'severity_label' => match ((string) $row->severity) {
                    'med' => 'Medium',
                    'high' => 'High',
                    default => 'Low',
                },
                'status' => $row->status === 'resolved' ? 'closed' : 'open',
                'status_label' => $row->status === 'resolved' ? 'Resolved' : 'Open',
            ])->all(),
            'duress_count' => $duress,
        ];
    }

    /**
     * Narrow a query to the estates this viewer may see.
     *
     * In the QUERY. A Head of Security scoped to assigned sites must not be
     * able to reach an estate outside that scope by any route the screen
     * offers, and the only way to guarantee that is for the rows never to be
     * selected. An empty assignment list selects nothing, which is the correct
     * answer for a scoped account with no sites yet — not everything.
     */
    private function scoped(User $viewer, Builder $query, string $column): Builder
    {
        if ($this->isScoped($viewer)) {
            $query->whereIn($column, $viewer->accessibleEstateIds());
        }

        return $query;
    }

    private function isScoped(User $viewer): bool
    {
        return $viewer->widestScope() === AccessScope::AssignedSites;
    }

    /* ------------------------------------------------------------------ */
    /* small formatters */
    /* ------------------------------------------------------------------ */

    /** "Sep 7 – Sep 13, 2026", the week the grid covers. */
    private function weekLabel(): string
    {
        $start = Carbon::today()->startOfWeek(Carbon::MONDAY);

        return $start->format('M j').' – '.$start->copy()->addDays(6)->format('M j, Y');
    }

    /** "Marcus Whyte" -> "M. Whyte", which is what fits a rota cell. */
    private function shortName(string $name): string
    {
        $parts = array_values(array_filter(explode(' ', $name)));

        if (count($parts) < 2) {
            return $name;
        }

        return mb_substr((string) $parts[0], 0, 1).'. '.$parts[count($parts) - 1];
    }
}
