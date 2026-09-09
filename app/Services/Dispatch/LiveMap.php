<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Enums\AccessScope;
use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The dispatch live map — board screen super-admin-12.
 *
 * THE MAP IS A SCHEMATIC, NOT A SURVEY, and the board draws it that way: fixed
 * dashed boxes for parishes, a labelled card per site, a pin per guard. It is
 * read the way an airport departure board is read — what is where, in which
 * state — and every position on it comes from a fixed slot in the canvas rather
 * than from a coordinate.
 *
 * THAT IS DELIBERATE, AND IT IS THE POINT.
 *
 * A pin drawn at a projected latitude would be read as where the guard is
 * standing. This platform does not know that, has no column for it, and is not
 * going to acquire one to make a picture look convincing: a guard's live
 * position is the single most sensitive thing a security company could hold on
 * its own staff, and the safest place to keep it is nowhere. What a pin here
 * says is exactly what its label says — this guard is standing this post — and
 * that is a fact the roster can prove.
 *
 * The consequence is that nothing on this screen can be broadcast as a
 * position, because no position exists to broadcast.
 *
 * Everything else is real: the parishes are the estates' own, the guard pins
 * are guards on a shift that is actually underway, the alert pins are alerts
 * that are actually open, and a site with nobody on duty draws the board's
 * offline pin rather than being quietly left off.
 */
class LiveMap
{
    /**
     * The canvas positions, lifted from the board.
     *
     * The board's map is hand-drawn: every element carries an inline left/top
     * because its stylesheet defines no class for them. Those numbers are the
     * design, so they are copied verbatim and estates are placed INTO them —
     * rather than a layout of my own invention being substituted for one the
     * designer drew.
     *
     * Each site slot carries two guard positions and one alert position, which
     * is the density the board draws. A site with more guards than that gets
     * positions stepped off its cluster; a site with none draws the board's
     * offline pin instead.
     *
     * @var list<array{cluster: array{left: int, top: int}, guards: list<array{left: int, top: int}>, alert: array{left: int, top: int}}>
     */
    private const SITE_SLOTS = [
        [
            'cluster' => ['left' => 70, 'top' => 60],
            'guards' => [['left' => 130, 'top' => 170], ['left' => 230, 'top' => 150]],
            'alert' => ['left' => 180, 'top' => 230],
        ],
        [
            'cluster' => ['left' => 130, 'top' => 320],
            'guards' => [['left' => 180, 'top' => 420], ['left' => 280, 'top' => 400]],
            'alert' => ['left' => 230, 'top' => 480],
        ],
        [
            'cluster' => ['left' => 470, 'top' => 250],
            'guards' => [['left' => 540, 'top' => 340], ['left' => 640, 'top' => 320]],
            'alert' => ['left' => 590, 'top' => 400],
        ],
        [
            'cluster' => ['left' => 660, 'top' => 400],
            'guards' => [['left' => 700, 'top' => 490], ['left' => 800, 'top' => 470]],
            'alert' => ['left' => 750, 'top' => 550],
        ],
    ];

    /**
     * The parish boxes, also lifted verbatim.
     *
     * @var list<array{box: array{left: int, top: int, width: int, height: int}, label: array{left: int, top: int}}>
     */
    private const REGION_SLOTS = [
        [
            'box' => ['left' => 40, 'top' => 40, 'width' => 340, 'height' => 280],
            'label' => ['left' => 48, 'top' => 18],
        ],
        [
            'box' => ['left' => 420, 'top' => 200, 'width' => 340, 'height' => 300],
            'label' => ['left' => 428, 'top' => 178],
        ],
    ];

    /** How far back the acknowledgement-time figure looks. */
    private const ACKNOWLEDGEMENT_WINDOW_DAYS = 30;

    /**
     * Everything the live map draws.
     *
     * @return array<string, mixed>
     */
    public function forViewer(User $viewer): array
    {
        $estates = $this->estates($viewer);
        $onDuty = $this->onDutyShifts($estates->pluck('id')->all());
        $openAlerts = $this->openAlerts($estates->pluck('id')->all());

        return [
            'banner' => $this->banner($openAlerts),
            'kpis' => $this->kpis($estates, $onDuty, $openAlerts),
            'regions' => $this->regions($estates),
            'sites' => $this->sites($estates, $onDuty, $openAlerts),
            'legend' => $this->legend(),
            'onDuty' => $this->onDutyPanel($onDuty),
        ];
    }

    /* -------------------------------------------------------------- banner */

    /**
     * The one alert a dispatcher is being asked to act on right now.
     *
     * One, not a list: the banner is an interruption, and an interruption that
     * scrolls has stopped interrupting. The rest of the queue is one click
     * away and the count is in the KPI beside it.
     *
     * @param  Collection<int, DuressAlert>  $openAlerts
     * @return array<string, string>|null
     */
    private function banner(Collection $openAlerts): ?array
    {
        $alert = $openAlerts
            ->sortBy([
                fn (DuressAlert $a): int => $a->priority(),
                fn (DuressAlert $a): string => $a->server_time->format('Y-m-d H:i:s'),
            ])
            ->first();

        if ($alert === null) {
            return null;
        }

        $who = $alert->raisedByGuard->full_name ?? $alert->raised_by_name ?? 'Unknown';

        return [
            'title' => $this->headline($alert).' — '.($alert->estate->name ?? 'Unknown estate'),
            'meta' => $this->join([
                $who,
                $alert->unit_reference,
                $alert->kindLabel(),
                'fired '.$alert->server_time->diffForHumans(),
                $alert->acknowledged_at === null ? 'unacknowledged' : 'acknowledged',
            ]),
            'href' => '/dispatch/alerts/'.$alert->id,
        ];
    }

    /* ---------------------------------------------------------------- kpis */

    /**
     * @param  Collection<int, Tenant>  $estates
     * @param  Collection<int, Shift>  $onDuty
     * @param  Collection<int, DuressAlert>  $openAlerts
     * @return list<array{icon: string, stroke: float, tone: string, value: string, label: string}>
     */
    private function kpis(Collection $estates, Collection $onDuty, Collection $openAlerts): array
    {
        return [
            [
                'icon' => 'guards',
                'stroke' => 1.7,
                'tone' => '',
                'value' => (string) $onDuty->pluck('guard_id')->unique()->count(),
                'label' => 'Guards on duty, platform-wide',
            ],
            [
                'icon' => 'warning',
                'stroke' => 1.7,
                'tone' => 'alert',
                'value' => (string) $openAlerts->count(),
                'label' => 'Active alerts',
            ],
            [
                'icon' => 'clients',
                'stroke' => 1.7,
                'tone' => '',
                'value' => (string) $estates->count(),
                'label' => 'Sites monitored',
            ],
            [
                'icon' => 'clock',
                'stroke' => 1.6,
                'tone' => '',
                'value' => $this->averageAcknowledgement($estates->pluck('id')->all()),
                'label' => 'Avg. acknowledgement time, 30d',
            ],
        ];
    }

    /**
     * How long dispatch takes to acknowledge an alert, over thirty days.
     *
     * An em dash when nothing has been acknowledged in the window, never a
     * zero. Zero seconds would read as instant response, which is the opposite
     * of "no alert has been answered this month".
     *
     * @param  list<string>  $estateIds
     */
    private function averageAcknowledgement(array $estateIds): string
    {
        $seconds = DuressAlert::query()
            ->whereIn('tenant_id', $estateIds)
            ->whereNotNull('acknowledged_at')
            ->where('server_time', '>=', now()->subDays(self::ACKNOWLEDGEMENT_WINDOW_DAYS))
            ->get()
            ->map(fn (DuressAlert $a): int => (int) $a->server_time->diffInSeconds($a->acknowledged_at));

        if ($seconds->isEmpty()) {
            return '—';
        }

        $average = (int) round($seconds->avg() ?? 0);

        return $average < 60
            ? $average.'s'
            : intdiv($average, 60).'m '.($average % 60).'s';
    }

    /* --------------------------------------------------------------- canvas */

    /**
     * One dashed box per parish the platform actually guards.
     *
     * Ordered by parish name so the layout is stable between requests — a map
     * whose regions swap places on a refresh is not a map anyone can learn.
     *
     * @param  Collection<int, Tenant>  $estates
     * @return list<array{key: string, label: string, box: array{left: int, top: int, width: int, height: int}, labelAt: array{left: int, top: int}}>
     */
    private function regions(Collection $estates): array
    {
        $parishes = $estates
            ->map(fn (Tenant $estate): string => $estate->parish ?? 'Parish not recorded')
            ->unique()
            ->sort()
            ->values();

        $regions = [];

        foreach ($parishes as $index => $parish) {
            $slot = self::REGION_SLOTS[$index] ?? null;

            // More parishes than the board drew boxes for. The sites are still
            // plotted and the side panel still lists every guard, so nobody
            // disappears; only the decorative box is missing.
            if ($slot === null) {
                continue;
            }

            $regions[] = [
                'key' => $parish,
                'label' => $parish,
                'box' => $slot['box'],
                'labelAt' => $slot['label'],
            ];
        }

        return $regions;
    }

    /**
     * The site cards and every pin around them.
     *
     * @param  Collection<int, Tenant>  $estates
     * @param  Collection<int, Shift>  $onDuty
     * @param  Collection<int, DuressAlert>  $openAlerts
     * @return list<array<string, mixed>>
     */
    private function sites(Collection $estates, Collection $onDuty, Collection $openAlerts): array
    {
        $tiers = $this->tiers();
        $sites = [];

        foreach ($estates->values() as $index => $estate) {
            $slot = self::SITE_SLOTS[$index] ?? null;

            if ($slot === null) {
                continue;
            }

            $key = (string) $estate->getTenantKey();
            $here = $onDuty->filter(fn (Shift $s): bool => $s->tenant_id === $key)->values();
            $alerts = $openAlerts->filter(fn (DuressAlert $a): bool => $a->tenant_id === $key)->values();

            $sites[] = [
                'key' => $key,
                'name' => $estate->name,
                'tier' => $this->tierLine($estate, $tiers[$key] ?? null, $here->count()),
                'cluster' => $slot['cluster'],
                'pins' => $this->pins($slot, $here, $alerts),
            ];
        }

        return $sites;
    }

    /**
     * "Premium · 4 guards", or what is true instead.
     *
     * An onboarding estate has no tier in force — the plan is prepared, not
     * billed — so it says onboarding, which is what a dispatcher needs to know
     * before anything else about that site.
     */
    private function tierLine(Tenant $estate, ?string $plan, int $onDuty): string
    {
        $left = $plan ?? 'No plan yet';

        $right = match (true) {
            $estate->status === 'onboarding' => 'onboarding',
            $onDuty === 0 => 'self-managed',
            $onDuty === 1 => '1 guard',
            default => $onDuty.' guards',
        };

        return $left.' · '.$right;
    }

    /**
     * Guard pins, then one alert pin, then the offline pin if nobody is there.
     *
     * ONE ALERT PIN PER SITE, not one per alert. The map answers "where", and
     * a site is one place however many alerts are open at it; the label says
     * how many and the queue says what they are. Stacking five pins on one
     * cluster would make the busiest site the hardest to read.
     *
     * @param  array{cluster: array{left: int, top: int}, guards: list<array{left: int, top: int}>, alert: array{left: int, top: int}}  $slot
     * @param  Collection<int, Shift>  $onDuty
     * @param  Collection<int, DuressAlert>  $alerts
     * @return list<array{key: string, left: int, top: int, dot: string, label: string, labelClass: string, labelStyle: string|null}>
     */
    private function pins(array $slot, Collection $onDuty, Collection $alerts): array
    {
        $pins = [];

        foreach ($onDuty as $index => $shift) {
            $at = $slot['guards'][$index] ?? [
                // Beyond the positions the board drew: stepped diagonally off
                // the cluster so pins never land on top of one another.
                'left' => $slot['cluster']['left'] + 40 + ($index * 46),
                'top' => $slot['cluster']['top'] + 150 + ($index * 22),
            ];

            $pins[] = [
                'key' => 'shift-'.$shift->id,
                'left' => $at['left'],
                'top' => $at['top'],
                'dot' => $shift->post?->type === 'patrol' ? 'patrol' : 'post',
                'label' => ($shift->officer->full_name ?? 'Unassigned').' · '.($shift->post->name ?? 'No post'),
                'labelClass' => 'pin-label',
                'labelStyle' => null,
            ];
        }

        if ($alerts->isNotEmpty()) {
            /** @var DuressAlert $first */
            $first = $alerts->first();

            $pins[] = [
                'key' => 'alerts-'.$first->tenant_id,
                'left' => $slot['alert']['left'],
                'top' => $slot['alert']['top'],
                'dot' => 'alert',
                'label' => $alerts->count() === 1
                    ? $this->join([
                        $first->raisedByGuard->full_name ?? $first->raised_by_name ?? 'Unknown',
                        $first->unit_reference,
                    ])
                    : $alerts->count().' active alerts',
                'labelClass' => 'pin-label alert-label',
                'labelStyle' => null,
            ];
        }

        if ($onDuty->isEmpty()) {
            $pins[] = [
                'key' => 'offline-empty',
                'left' => $slot['guards'][0]['left'],
                'top' => $slot['guards'][0]['top'],
                'dot' => 'offline',
                'label' => 'No Gemini guards on site',
                'labelClass' => 'pin-label',

                // The board's own inline style on this one pin, verbatim: its
                // stylesheet has no modifier for a grey label.
                'labelStyle' => 'background:var(--slate-500);',
            ];
        }

        return $pins;
    }

    /**
     * The legend, which is the board's and is not derived from anything.
     *
     * @return list<array{colour: string, label: string}>
     */
    private function legend(): array
    {
        return [
            ['colour' => 'var(--navy-600)', 'label' => 'On post'],
            ['colour' => 'var(--navy-300)', 'label' => 'On patrol'],
            ['colour' => 'var(--slate-300)', 'label' => 'Offline / not applicable'],
            ['colour' => 'var(--red-700)', 'label' => 'Active alert'],
        ];
    }

    /**
     * Every guard on duty, whether or not a pin could be drawn for them.
     *
     * This list is the safety valve for the canvas. The canvas has four slots
     * because the board drew four; this has no limit, so a fifth site's guards
     * are still on the screen and still reachable.
     *
     * @param  Collection<int, Shift>  $onDuty
     * @return list<array{key: string, colour: string, name: string, where: string, href: string}>
     */
    private function onDutyPanel(Collection $onDuty): array
    {
        return $onDuty->map(fn (Shift $shift): array => [
            'key' => 'duty-'.$shift->id,
            'colour' => $shift->post?->type === 'patrol' ? 'var(--navy-300)' : 'var(--navy-600)',
            'name' => $shift->officer->full_name ?? 'Unassigned',
            'where' => $this->join([
                $shift->estate?->name,
                $shift->post?->name,
            ]),
            'href' => '/guards/'.$shift->guard_id,
        ])->values()->all();
    }

    /* -------------------------------------------------------------- queries */

    /**
     * @return Collection<int, Tenant>
     */
    private function estates(User $viewer): Collection
    {
        $estates = Tenant::estates(function ($query) use ($viewer): void {
            if ($viewer->widestScope() === AccessScope::AssignedSites) {
                $query->whereIn('id', $viewer->accessibleEstateIds());
            }

            // Parish first, then name: the canvas slots are filled in this
            // order, so this is the map's layout as much as its sort.
            $query->orderBy('parish')->orderBy('name');
        });

        return $estates;
    }

    /**
     * Shifts underway right now.
     *
     * Rostered is not the same as present. A shift nobody started is a gap,
     * and drawing a pin for it would put a guard on the map who is not there.
     *
     * @param  list<string>  $estateIds
     * @return Collection<int, Shift>
     */
    private function onDutyShifts(array $estateIds): Collection
    {
        return Shift::query()
            ->with(['officer', 'post', 'estate'])
            ->whereIn('tenant_id', $estateIds)
            ->covering(now())
            ->whereNotNull('actual_start')
            ->whereNull('actual_end')
            ->whereHas('officer', fn ($query) => $query->where('status', 'active'))
            ->get()
            ->sortBy(fn (Shift $shift): string => $shift->officer->full_name ?? '')
            ->values();
    }

    /**
     * @param  list<string>  $estateIds
     * @return Collection<int, DuressAlert>
     */
    private function openAlerts(array $estateIds): Collection
    {
        return DuressAlert::query()
            ->with(['raisedByGuard', 'estate'])
            ->whereIn('tenant_id', $estateIds)
            ->whereNotIn('status', ['resolved', 'false_alarm'])
            ->orderByDesc('server_time')
            ->get();
    }

    /**
     * Each estate's plan name, for the site card.
     *
     * @return array<string, string>
     */
    private function tiers(): array
    {
        /** @var array<string, string> $tiers */
        $tiers = Tenant::query()
            ->join('subscriptions', 'subscriptions.tenant_id', '=', 'tenants.id')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->pluck('plans.name', 'tenants.id')
            ->all();

        return $tiers;
    }

    /* ---------------------------------------------------------------- utils */

    private function headline(DuressAlert $alert): string
    {
        return match ($alert->kind) {
            'panic' => 'Active panic alert',
            'duress' => 'Guard duress',
            'medical' => 'Medical alert',
            'fire' => 'Fire alert',
            'intrusion' => 'Intrusion alert',
            default => $alert->kindLabel(),
        };
    }

    /**
     * The board's meta separator, skipping anything absent.
     *
     * @param  array<int, string|null>  $parts
     */
    private function join(array $parts): string
    {
        return implode(' · ', array_filter($parts, fn (?string $part): bool => $part !== null && $part !== ''));
    }
}
