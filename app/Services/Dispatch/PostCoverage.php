<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Enums\AccessScope;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The post coverage board — board screen super-admin-13.
 *
 * ONE QUESTION, ASKED TWICE A DAY: is there a guard on this post between 7 AM
 * and 7 PM, and is there one between 7 PM and 7 AM?
 *
 * It is answered from the ROSTER, not from `guards.post_id`. A standing
 * assignment says which post a guard belongs to and carries no time at all,
 * so answering both windows from it would print one fact under two headings
 * and let a dispatcher read it as knowledge of tonight. On the screen whose
 * entire purpose is showing which posts stand empty, that is the one mistake
 * that costs something.
 *
 * A shift nobody started is a gap, not coverage — but only once it should have
 * begun. A night shift rostered for later this evening is a plan that has not
 * come due, and reporting it as a hole would make every afternoon look like a
 * crisis. So a window is covered when it has a rostered shift AND, if that
 * window is already underway, someone actually clocked in.
 *
 * An expired licence is a hard block rather than a warning: an unlicensed guard
 * is un-rosterable, so the post they used to stand reads as uncovered and names
 * why.
 */
class PostCoverage
{
    /** The two windows the board draws, and the hours they run. */
    private const WINDOWS = [
        'day' => ['label' => '7 AM – 7 PM', 'from' => 7, 'to' => 19],
        'night' => ['label' => '7 PM – 7 AM', 'from' => 19, 'to' => 31],
    ];

    /**
     * Post types in the order the board lists them: gates, then patrols, then
     * relief. A dispatcher reads the perimeter before the rounds.
     *
     * @var array<string, int>
     */
    private const TYPE_ORDER = ['gate' => 0, 'patrol' => 1, 'relief' => 2];

    /**
     * @return array<string, mixed>
     */
    public function forViewer(User $viewer): array
    {
        $estates = $this->estates($viewer);
        $posts = $this->posts($estates->pluck('id')->all());
        $shifts = $this->shiftsToday($estates->pluck('id')->all());

        $rows = $this->rows($estates, $posts, $shifts);

        return [
            'day' => Carbon::today()->format('D, M j'),
            'kpis' => $this->kpis($rows, $shifts, $posts),
            'windows' => array_column(self::WINDOWS, 'label'),
            'rows' => $rows,
        ];
    }

    /* ---------------------------------------------------------------- rows */

    /**
     * One row per post, and one row for an estate that has no posts at all.
     *
     * The postless estate keeps its row deliberately. A client absent from a
     * coverage board reads as a client with nothing to report; a client
     * present and saying "not a Gemini post" reads as a decision somebody made.
     *
     * @param  Collection<int, Tenant>  $estates
     * @param  Collection<int, Post>  $posts
     * @param  Collection<int, Shift>  $shifts
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $estates, Collection $posts, Collection $shifts): array
    {
        $rows = [];

        foreach ($estates as $estate) {
            $key = (string) $estate->getTenantKey();
            $here = $posts->filter(fn (Post $post): bool => $post->tenant_id === $key)->values();

            if ($here->isEmpty()) {
                $rows[] = $this->postlessRow($estate);

                continue;
            }

            foreach ($here as $post) {
                $rows[] = $this->postRow(
                    $estate,
                    $post,
                    $shifts->filter(fn (Shift $shift): bool => $shift->post_id === $post->id),
                );
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, Shift>  $shifts
     * @return array<string, mixed>
     */
    private function postRow(Tenant $estate, Post $post, Collection $shifts): array
    {
        $cells = [];
        $covered = 0;

        foreach (self::WINDOWS as $window => $hours) {
            $cell = $this->cell($shifts, $hours);
            $cells[] = $cell;

            if ($cell['class'] === 'covered') {
                $covered++;
            }
        }

        $names = $shifts
            ->map(fn (Shift $shift): string => $shift->officer->full_name ?? '')
            ->filter()
            ->unique()
            ->values();

        return [
            'key' => 'post-'.$post->id,
            'site' => $estate->name,
            'post' => $post->name,
            'cells' => $cells,
            'assigned' => $names->isNotEmpty()
                ? $names->implode(', ')
                : $this->whyUnassigned($post),
            'fullyCovered' => $covered === count(self::WINDOWS),
        ];
    }

    /**
     * The state of one window, as one pill.
     *
     * @param  Collection<int, Shift>  $shifts
     * @param  array{label: string, from: int, to: int}  $hours
     * @return array{label: string, class: string}
     */
    private function cell(Collection $shifts, array $hours): array
    {
        $start = Carbon::today()->addHours($hours['from']);
        $end = Carbon::today()->addHours($hours['to']);

        $rostered = $shifts->first(
            fn (Shift $shift): bool => $shift->rostered_start->lessThan($end)
                && $shift->rostered_end->greaterThan($start)
        );

        if ($rostered === null) {
            return ['label' => 'Uncovered', 'class' => 'uncovered'];
        }

        /*
         * Due, but nobody clocked in.
         *
         * Only checked once the window has actually begun. A shift rostered for
         * this evening has not failed to start; it has not started yet, and
         * colouring it red would make every afternoon read as a crisis.
         */
        if ($start->lessThanOrEqualTo(now()) && $rostered->actual_start === null) {
            return ['label' => 'Not started', 'class' => 'uncovered'];
        }

        return ['label' => 'Covered', 'class' => 'covered'];
    }

    /**
     * Why nobody is standing this post, named rather than left blank.
     *
     * "Unassigned" tells a dispatcher nothing they can act on. Naming the guard
     * and what happened to them is the difference between a gap and a gap with
     * a cause — and it is the sentence a supervisor needs when they ask why the
     * Service Gate is empty.
     */
    private function whyUnassigned(Post $post): string
    {
        $guard = Guard::query()->where('post_id', $post->id)->orderBy('full_name')->first();

        if ($guard === null) {
            return 'Unassigned — no guard posted here';
        }

        $because = match (true) {
            $guard->licenceState() === 'expired' => "'s licence expired",
            $guard->status === 'on_leave' => ' went on leave',
            $guard->status === 'suspended' => "'s suspension",
            $guard->status === 'inactive' => ' left the roster',
            default => ' was last rostered',
        };

        return 'Unassigned since '.$guard->full_name.$because;
    }

    /**
     * An estate with no Gemini posts at all.
     *
     * Two different sentences, because they are two different situations: a
     * client that guards itself has made a commercial choice, and a client
     * still onboarding has posts that nobody has built yet. Both read as "no
     * coverage" if collapsed, and only one of them is anybody's problem.
     *
     * @return array<string, mixed>
     */
    private function postlessRow(Tenant $estate): array
    {
        $onboarding = $estate->status === 'onboarding';

        $cell = [
            'label' => $onboarding ? 'Not yet live' : 'Self-managed',
            'class' => 'na',
        ];

        return [
            'key' => 'estate-'.$estate->getTenantKey(),
            'site' => $estate->name,
            'post' => '—',
            'cells' => [$cell, $cell],
            'assigned' => $onboarding ? 'Onboarding — posts not yet built' : 'Not a Gemini post',
            'fullyCovered' => false,
        ];
    }

    /* ---------------------------------------------------------------- kpis */

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, Shift>  $shifts
     * @param  Collection<int, Post>  $posts
     * @return list<array{icon: string, stroke: float, tone: string, value: string, label: string}>
     */
    private function kpis(array $rows, Collection $shifts, Collection $posts): array
    {
        $postRows = array_filter($rows, static fn (array $row): bool => $row['post'] !== '—');

        $fullyCovered = count(array_filter($postRows, static fn (array $row): bool => $row['fullyCovered'] === true));

        return [
            [
                'icon' => 'check-ring',
                'stroke' => 1.6,
                'tone' => '',
                'value' => (string) $fullyCovered,
                'label' => 'Posts fully covered',
            ],
            [
                'icon' => 'alert',
                'stroke' => 1.6,
                'tone' => 'alert',
                'value' => (string) (count($postRows) - $fullyCovered),
                'label' => 'Posts uncovered',
            ],
            [
                'icon' => 'guards',
                'stroke' => 1.7,
                'tone' => '',
                'value' => (string) $shifts->pluck('guard_id')->unique()->count(),
                'label' => 'Guards assigned, today',
            ],
            [
                'icon' => 'clients',
                'stroke' => 1.7,
                'tone' => '',
                'value' => (string) $posts->pluck('tenant_id')->unique()->count(),
                'label' => 'Client sites with Gemini posts',
            ],
        ];
    }

    /* ------------------------------------------------------------- queries */

    /**
     * @return Collection<int, Tenant>
     */
    private function estates(User $viewer): Collection
    {
        return Tenant::estates(function ($query) use ($viewer): void {
            if ($viewer->widestScope() === AccessScope::AssignedSites) {
                $query->whereIn('id', $viewer->accessibleEstateIds());
            }

            // Parish then name, which is the order the live map lays the same
            // sites out in. Two screens in one module that disagree about the
            // order of the same list make a dispatcher re-read both.
            $query->orderBy('parish')->orderBy('name');
        });
    }

    /**
     * @param  list<string>  $estateIds
     * @return Collection<int, Post>
     */
    private function posts(array $estateIds): Collection
    {
        return Post::query()
            ->whereIn('tenant_id', $estateIds)
            ->where('is_active', true)
            ->get()
            ->sortBy([
                fn (Post $post): int => self::TYPE_ORDER[$post->type] ?? 9,
                fn (Post $post): string => $post->name,
            ])
            ->values();
    }

    /**
     * Every shift touching today's two windows.
     *
     * Reaches back to yesterday evening because the night window crosses
     * midnight: a shift that began at 7 PM yesterday is the one covering this
     * morning's small hours, and a query bounded by today's date would report
     * every gate on the platform as unmanned overnight.
     *
     * @param  list<string>  $estateIds
     * @return Collection<int, Shift>
     */
    private function shiftsToday(array $estateIds): Collection
    {
        return Shift::query()
            ->with(['officer', 'post'])
            ->whereIn('tenant_id', $estateIds)
            ->where('rostered_end', '>', Carbon::today()->addHours(self::WINDOWS['day']['from']))
            ->where('rostered_start', '<', Carbon::today()->addHours(self::WINDOWS['night']['to']))
            ->get();
    }
}
