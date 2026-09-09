<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
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
