<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\Guard;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\MoneyFormatter;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide figures for the Gemini Console dashboard.
 *
 * Reads ONLY from gs_platform. No method here may open an estate database:
 * a cross-tenant figure that fans out across estates would break both the
 * isolation model and the rule that every cross-tenant report is an aggregate
 * derived from central data. If a number cannot be produced centrally, it is
 * not a cross-tenant figure and does not belong on this dashboard.
 *
 * The shape is the approved board's, not a shape of my own: four KPI cards,
 * MRR split by subscription tier, and a recent-activity list. Screen
 * super-admin-02, and the full activity feed behind it, screen super-admin-03.
 */
class PlatformOverview
{
    /**
     * Rows the activity feed shows — board screen super-admin-03 draws eight.
     *
     * A feed length, not a page size, and the board draws no pager to make it
     * one. The eight most recent platform events are what this screen is: the
     * standing ledger of who did what is the Access & audit log, which is its
     * own module with its own board.
     */
    public const FEED_ROWS = 8;

    /**
     * How recent an event has to be for the feed to name the day.
     *
     * The board writes "Sep 4" for the last few weeks and "May 2026" for
     * anything older, because a day number stops meaning anything once it is
     * months back and the month is the fact worth reading.
     */
    private const FEED_DAY_PRECISION = 60;

    /**
     * The four KPI cards, in the order the board draws them.
     *
     * @return list<array<string, mixed>>
     */
    public function kpis(): array
    {
        $activeClients = Tenant::query()->where('status', 'active')->count();
        $units = (int) Subscription::query()->where('status', 'active')->sum('unit_count');
        $guards = Guard::query()->where('status', 'active')->count();

        return [
            [
                'key' => 'clients',
                'icon' => 'clients',
                'value' => (string) $activeClients,
                'label' => 'Active clients',
                'trend' => null,
            ],
            [
                'key' => 'units',
                'icon' => 'units',
                'value' => number_format($units),
                'label' => 'Units under management',
                'trend' => null,
            ],
            [
                'key' => 'mrr',
                'icon' => 'billing',
                'value' => $this->formatWhole($this->monthlyRecurringMinor()),
                'label' => 'Platform MRR',
                'trend' => null,
            ],
            [
                'key' => 'guards',
                'icon' => 'guards',
                'value' => (string) $guards,
                'label' => 'Guards deployed',
                'trend' => null,
            ],
        ];
    }

    /**
     * MRR split by subscription tier, widest bar first.
     *
     * The bar width is each tier's share of total MRR, computed here rather
     * than in the template: a percentage worked out in Vue would be a second
     * place the money maths lives, and the two would drift.
     *
     * @return list<array{key: string, label: string, amount: string, percent: float}>
     */
    public function mrrByTier(): array
    {
        $total = $this->monthlyRecurringMinor();

        /*
         * The query builder, not Eloquent.
         *
         * This is an aggregate over two tables and hydrates no Subscription:
         * the columns it selects (plan_key, plan_name, minor) belong to no
         * model, and asking Eloquent for them produces Subscription objects
         * carrying attributes the class does not have.
         */
        $rows = DB::connection('mysql')
            ->table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.status', 'active')
            ->groupBy('plans.id', 'plans.key', 'plans.name', 'plans.sort')
            ->orderBy('plans.sort')
            ->selectRaw('plans.key as plan_key, plans.name as plan_name, SUM(subscriptions.unit_count * plans.price_per_unit_minor) as minor')
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'key' => (string) $row->plan_key,
                'label' => (string) $row->plan_name,
                'amount' => $this->formatWhole((int) $row->minor),
                'percent' => $total > 0 ? round(((int) $row->minor / $total) * 100, 1) : 0.0,
            ])
            ->sortByDesc('percent')
            ->values()
            ->all();
    }

    /**
     * Recently provisioned estates, for the activity list.
     *
     * @return list<array<string, mixed>>
     */
    public function recentEstates(int $limit = 8): array
    {
        $recent = [];

        // A block body, not an arrow function: the callable is declared to
        // return void, and an arrow function would return the Builder.
        $recentlyProvisioned = Tenant::estates(function ($query) use ($limit): void {
            $query->orderByDesc('provisioned_at')->limit($limit);
        });

        foreach ($recentlyProvisioned as $tenant) {
            $recent[] = $tenant->toSummary();
        }

        return $recent;
    }

    /**
     * The dashboard's activity panel — board screen super-admin-02.
     *
     * Three rows, and no column of its own for the time, so each row ends with
     * when it happened: "320 units · 2 days ago". The feed screen has a time
     * column and spends that space on a second fact instead.
     *
     * @return list<array{icon: string, title: string, meta: string}>
     */
    public function recentActivity(int $limit = 3): array
    {
        return array_map(
            fn (array $event): array => [
                'icon' => $event['icon'],
                'title' => $event['title'],
                'meta' => $this->join([$event['context'], $event['stamp']]),
            ],
            $this->platformEvents($limit)
        );
    }

    /**
     * The full activity feed — board screen super-admin-03.
     *
     * The same events as the panel above, from the same builder. Two readings
     * of "what has been happening on this platform" that could disagree with
     * each other, on a screen reached by a "See all" link from the other, is
     * exactly the drift one service exists to prevent.
     *
     * @return list<array{key: string, icon: string, title: string, meta: string, when: string}>
     */
    public function activityFeed(int $limit = self::FEED_ROWS): array
    {
        return array_map(
            fn (array $event): array => [
                'key' => $event['key'],
                'icon' => $event['icon'],
                'title' => $event['title'],
                'meta' => $this->join([$event['context'], $event['extra']]),
                'when' => $this->feedTime($event['at']),
            ],
            $this->platformEvents($limit)
        );
    }

    /**
     * Everything both activity screens are built from, newest first.
     *
     * Four kinds of event, all of them real central records with a real date
     * of their own:
     *
     *   an estate onboarded          tenants.provisioned_at
     *   a platform invoice paid      invoices.paid_on
     *   a guard deployed             guards.hired_on
     *   a guard stood down           guards.psra_expires_on
     *
     * They are merged and re-sorted on those dates rather than shown in fixed
     * blocks, because a feed that always lists one of each is a layout, not a
     * feed. Each source is asked for at most $limit rows: the merged list can
     * never need more than that from any single one.
     *
     * Every row is central. Nothing here opens an estate database — a feed
     * that fanned out across estates to find events would be a tenant-isolation
     * breach wearing a report's clothes.
     *
     * `context` is the fact that rides beside the title on both screens;
     * `extra` is the second fact only the feed has room for; `stamp` is the
     * time as the dashboard panel writes it, inline.
     *
     * @return list<array{key: string, at: Carbon, icon: string, title: string, context: string, extra: string|null, stamp: string}>
     */
    private function platformEvents(int $limit): array
    {
        $events = [
            ...$this->onboardings($limit),
            ...$this->invoicesPaid($limit),
            ...$this->guardsDeployed($limit),
            ...$this->licencesLapsed($limit),
        ];

        // DateTimeInterface compares chronologically under <=>, so the newest
        // event sorts first without reducing either side to a string.
        usort($events, fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        return array_slice($events, 0, $limit);
    }

    /**
     * Estates that have been provisioned.
     *
     * @return list<array{key: string, at: Carbon, icon: string, title: string, context: string, extra: string|null, stamp: string}>
     */
    private function onboardings(int $limit): array
    {
        // A block body, not an arrow function: the callable is declared to
        // return void, and an arrow function would return the Builder.
        $provisioned = Tenant::estates(function ($query) use ($limit): void {
            $query->whereNotNull('provisioned_at')->orderByDesc('provisioned_at')->limit($limit);
        });

        $plans = $this->contractedPlans();
        $events = [];

        foreach ($provisioned as $tenant) {
            $at = $tenant->provisioned_at;

            if ($at === null) {
                continue;
            }

            $id = (string) $tenant->getTenantKey();
            $contract = $plans[$id] ?? null;

            $events[] = [
                'key' => 'estate:'.$id,
                'at' => $at,
                'icon' => 'clients',
                'title' => $tenant->name.' — onboarding started',
                'context' => $contract === null ? '' : number_format($contract['units']).' units',
                'extra' => $contract === null ? null : $contract['plan'].' plan',
                'stamp' => $at->diffForHumans(short: false),
            ];
        }

        return $events;
    }

    /**
     * Platform invoices that have been settled.
     *
     * This is Gemini Security billing its clients. An estate's own ledger lives
     * in that estate's database and never appears on a Gemini Console screen.
     *
     * @return list<array{key: string, at: Carbon, icon: string, title: string, context: string, extra: string|null, stamp: string}>
     */
    private function invoicesPaid(int $limit): array
    {
        $rows = DB::connection('mysql')
            ->table('invoices')
            ->join('tenants', 'tenants.id', '=', 'invoices.tenant_id')
            ->where('invoices.status', 'paid')
            ->whereNotNull('invoices.paid_on')
            ->orderByDesc('invoices.paid_on')
            ->limit($limit)
            ->select(
                'invoices.id',
                'invoices.reference',
                'invoices.total_minor',
                'invoices.currency',
                'invoices.paid_on',
                'tenants.name'
            )
            ->get();

        return $rows
            ->map(function (object $invoice): array {
                $at = Carbon::parse((string) $invoice->paid_on);

                return [
                    'key' => 'invoice:'.$invoice->id,
                    'at' => $at,
                    'icon' => 'billing',
                    'title' => $invoice->name.' — invoice paid',
                    /*
                     * The invoice's OWN currency, not the plan's.
                     *
                     * Both are JMD today, and the day they are not is the day a
                     * settled invoice would be reprinted in a currency nobody
                     * was billed in.
                     */
                    'context' => MoneyFormatter::whole(
                        (int) $invoice->total_minor,
                        $this->currency($invoice->currency)
                    ),
                    'extra' => 'Invoice '.$invoice->reference,
                    'stamp' => $at->format('M j'),
                ];
            })
            ->all();
    }

    /**
     * Guards taken on.
     *
     * @return list<array{key: string, at: Carbon, icon: string, title: string, context: string, extra: string|null, stamp: string}>
     */
    private function guardsDeployed(int $limit): array
    {
        /*
         * Left joins, not the estate relation.
         *
         * A guard's tenant_id is nullable — an unassigned guard is a real
         * state, not a broken row — so the estate name has to survive being
         * absent, and so does the post. A left join says that in the query;
         * eager loading the relation would hand back a null the relation's own
         * type says cannot happen.
         */
        $rows = DB::connection('mysql')
            ->table('guards')
            ->leftJoin('tenants', 'tenants.id', '=', 'guards.tenant_id')
            ->leftJoin('posts', 'posts.id', '=', 'guards.post_id')
            ->whereNotNull('guards.hired_on')
            ->orderByDesc('guards.hired_on')
            ->limit($limit)
            ->select(
                'guards.id',
                'guards.full_name',
                'guards.employment_type',
                'guards.hired_on',
                'tenants.name as estate_name',
                'posts.name as post_name'
            )
            ->get();

        return $rows
            ->map(function (object $guard): array {
                $at = Carbon::parse((string) $guard->hired_on);

                return [
                    'key' => 'hire:'.$guard->id,
                    'at' => $at,
                    'icon' => 'guards',
                    'title' => $guard->full_name.' deployed — '
                        .str_replace('_', ' ', (string) $guard->employment_type),
                    'context' => (string) ($guard->estate_name ?? 'Unassigned'),
                    'extra' => $guard->post_name === null ? null : (string) $guard->post_name,
                    'stamp' => $at->format('M j'),
                ];
            })
            ->all();
    }

    /**
     * Guards standing down because their PSRA licence has run out.
     *
     * The date is the licence's own expiry, which is the day the guard stopped
     * being deployable — there is no separate "stood down on" column and this
     * screen is not a reason to invent one, because the licence date already
     * says exactly when it happened.
     *
     * Only a guard whose status records the lapse appears, and only once the
     * date has actually passed: a licence expiring next month is a compliance
     * warning for the workforce screen, not something that has happened.
     *
     * @return list<array{key: string, at: Carbon, icon: string, title: string, context: string, extra: string|null, stamp: string}>
     */
    private function licencesLapsed(int $limit): array
    {
        $rows = DB::connection('mysql')
            ->table('guards')
            ->leftJoin('tenants', 'tenants.id', '=', 'guards.tenant_id')
            ->where('guards.status', 'licence_expired')
            ->whereNotNull('guards.psra_expires_on')
            ->whereDate('guards.psra_expires_on', '<=', Carbon::today())
            ->orderByDesc('guards.psra_expires_on')
            ->limit($limit)
            ->select(
                'guards.id',
                'guards.full_name',
                'guards.psra_number',
                'guards.psra_expires_on',
                'tenants.name as estate_name'
            )
            ->get();

        return $rows
            ->map(function (object $guard): array {
                $at = Carbon::parse((string) $guard->psra_expires_on);

                return [
                    'key' => 'licence:'.$guard->id,
                    'at' => $at,
                    'icon' => 'shield',
                    'title' => $guard->full_name.' stood down — PSRA licence expired',
                    'context' => (string) ($guard->estate_name ?? 'Unassigned'),
                    'extra' => (string) $guard->psra_number,
                    'stamp' => $at->format('M j'),
                ];
            })
            ->all();
    }

    /**
     * Each estate's contracted units and plan name, in one query.
     *
     * Asked for once rather than per estate: eight rows on the feed is eight
     * round trips the moment this is done inside the loop, and the answer is
     * the same query every time.
     *
     * @return array<string, array{units: int, plan: string}>
     */
    private function contractedPlans(): array
    {
        return DB::connection('mysql')
            ->table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->select('subscriptions.tenant_id', 'subscriptions.unit_count', 'plans.name as plan_name')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (string) $row->tenant_id => [
                    'units' => (int) $row->unit_count,
                    'plan' => (string) $row->plan_name,
                ],
            ])
            ->all();
    }

    /**
     * "Sep 4" for something recent, "May 2026" for something older.
     *
     * The board writes both, and the difference is not decoration: a day
     * number months back tells the reader nothing they can place, whereas the
     * month does.
     */
    private function feedTime(Carbon $at): string
    {
        return $at->greaterThanOrEqualTo(Carbon::now()->subDays(self::FEED_DAY_PRECISION))
            ? $at->format('M j')
            : $at->format('M Y');
    }

    /**
     * The board's separator, with the empty parts dropped.
     *
     * An estate provisioned before it has a plan has no unit count to show, and
     * the row must read as one fact rather than as a dangling middot.
     *
     * @param  list<string|null>  $parts
     */
    private function join(array $parts): string
    {
        return implode(' · ', array_filter(
            $parts,
            static fn (?string $part): bool => $part !== null && $part !== ''
        ));
    }

    private function currency(mixed $currency): string
    {
        return is_string($currency) && $currency !== ''
            ? $currency
            : MoneyFormatter::DEFAULT_CURRENCY;
    }

    /** Total active MRR in minor units. */
    private function monthlyRecurringMinor(): int
    {
        return (int) Subscription::query()
            ->where('subscriptions.status', 'active')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->sum(DB::raw('subscriptions.unit_count * plans.price_per_unit_minor'));
    }

    /**
     * "$243,900" — the board's format for headline money.
     *
     * Delegates to MoneyFormatter. This used to hand-pick the symbol — "J$"
     * for anything non-USD — while the client directory took the locale's,
     * so the dashboard wrote "J$243,900" and the directory wrote "$88,800"
     * for the same currency on adjacent screens. Two formatters is how a
     * system ends up disagreeing with itself about money.
     */
    private function formatWhole(int $minor): string
    {
        return MoneyFormatter::whole(
            $minor,
            Plan::query()->value('currency') ?? MoneyFormatter::DEFAULT_CURRENCY
        );
    }
}
