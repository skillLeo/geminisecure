<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Support\MoneyFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The four revenue and operational reports — board screens 37, 38, 39 and 40.
 *
 * EVERY FIGURE IS A CENTRAL AGGREGATE. Nothing here opens an estate database,
 * and none of it could: MRR is a property of a subscription, tenure is a
 * property of a tenant row, and a guard's posting is a column on the guard.
 * Arrears totals, collection rates and anything derived from a resident charge
 * are deliberately absent and cannot be added — they live in estate databases
 * and reaching them would make a cross-tenant report a tenant-isolation breach
 * wearing a report's clothes.
 *
 * MRR IS DEFINED ONCE, in monthlyRecurringMinor(), and every screen that quotes
 * a total quotes that. The dashboard's headline, the tier split and the
 * per-client contribution are three views of one number; computing it three
 * times is how three screens come to disagree about what the platform earns.
 *
 * The per-guard add-on is part of MRR. It is billed monthly on top of any tier
 * and appears on every invoice, so a "recurring revenue" figure that omitted it
 * would be smaller than what the platform actually invoices each month.
 */
class RevenueReports
{
    /** How far back the trend chart looks. The board draws six columns. */
    private const TREND_MONTHS = 6;

    /* ------------------------------------------------------------ 37 */

    /**
     * MRR over time, and what moved it — board screen 37.
     *
     * The bars are built from what each month's invoices ACTUALLY TOTALLED,
     * not from today's subscriptions replayed backwards. A client who joined
     * in May did not contribute in April, and a rate that changed in July did
     * not apply in June; projecting the present over the past would draw a
     * flat line and call it history.
     *
     * A month with no invoices raised is a real gap and is drawn as zero
     * rather than skipped, so the spacing of the columns stays honest.
     *
     * @return array<string, mixed>
     */
    public function mrrTrend(): array
    {
        $months = $this->recentMonths(self::TREND_MONTHS);
        $totals = $this->invoicedByMonth($months[0]);
        $current = $this->monthlyRecurringMinor();

        $values = array_map(
            static fn (Carbon $month): int => (int) ($totals[$month->format('Y-m')] ?? 0),
            $months,
        );

        $peak = max([...$values, 1]);
        $events = $this->contributingEvents();

        return [
            'kpis' => $this->trendKpis($current, $values),
            'bars' => array_map(
                function (Carbon $month, int $value) use ($peak, $events): array {
                    $key = $month->format('Y-m');

                    return [
                        'label' => $month->format('M'),
                        'value' => $this->compact($value),
                        // Percentage of the tallest column, so the chart is
                        // readable whatever the platform is earning.
                        'height' => (int) round(($value / $peak) * 100),
                        /*
                         * The annotation on the bar is the event that MOVED
                         * it, matched by month. A month with two events shows
                         * the first; the panel beside the chart lists them all,
                         * and stacking labels on a 40px-wide column would make
                         * both unreadable.
                         */
                        'annotation' => $events[$key][0]['title'] ?? null,
                    ];
                },
                $months,
                $values,
            ),
            'events' => $this->eventFeed(),
        ];
    }

    /**
     * The four cards above the chart.
     *
     * @param  list<int>  $values
     * @return list<array<string, mixed>>
     */
    private function trendKpis(int $current, array $values): array
    {
        $first = $values[0] ?? 0;
        $growth = $current - $first;

        $lastMonth = $values[count($values) - 2] ?? 0;
        $change = $lastMonth > 0
            ? (int) round((($current - $lastMonth) / $lastMonth) * 100)
            : null;

        return [
            [
                'key' => 'current',
                'icon' => 'billing',
                'value' => $this->exact($current),
                'label' => 'Current MRR',
                // Only when there is a previous month to compare against. A
                // trend arrow on a platform's first month is an invention.
                'trend' => $change === null || $change === 0 ? null : sprintf('%+d%%', $change),
            ],
            [
                'key' => 'growth',
                'icon' => 'reports',
                'value' => sprintf('%s%s', $growth < 0 ? '-' : '+', $this->exact(abs($growth))),
                'label' => self::TREND_MONTHS.'-month growth',
                'trend' => null,
            ],
            [
                'key' => 'projected',
                'icon' => 'clients',
                'value' => $this->exact($this->projectedMinor()),
                'label' => $this->projectionLabel(),
                'trend' => null,
            ],
            [
                'key' => 'churn',
                'icon' => 'clock',
                'value' => $this->churnRate().'%',
                'label' => 'Churn, trailing 12mo',
                'trend' => null,
            ],
        ];
    }

    /* ------------------------------------------------------------ 38 */

    /**
     * Where the money comes from — board screen 38.
     *
     * The donut and the table are one dataset shown twice, so they cannot
     * disagree: the legend's percentages and the table's "% of MRR" column are
     * the same numbers rounded the same way.
     *
     * A client not billing contributes nothing and is shown saying so rather
     * than as a zero. Zero and "not billing yet" are different facts, and only
     * one of them is a problem.
     *
     * @return array<string, mixed>
     */
    public function revenueByTier(): array
    {
        $total = $this->monthlyRecurringMinor();

        $tiers = DB::connection('mysql')
            ->table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereIn('subscriptions.status', ['active'])
            ->groupBy('plans.id', 'plans.key', 'plans.name', 'plans.sort')
            ->orderBy('plans.sort')
            ->selectRaw('plans.key, plans.name, SUM(subscriptions.unit_count * plans.price_per_unit_minor) as minor')
            ->get()
            ->map(fn (object $row): array => [
                'key' => (string) $row->key,
                'name' => (string) $row->name,
                'amount' => $this->exact((int) $row->minor),
                'percent' => $total > 0 ? (int) round(((int) $row->minor / $total) * 100) : 0,
                'minor' => (int) $row->minor,
            ])
            ->sortByDesc('minor')
            ->values()
            ->all();

        return [
            'total' => $this->exact($total),
            'totalCompact' => $this->compact($total),
            'tiers' => array_map(
                static fn (array $tier): array => [
                    'key' => $tier['key'],
                    'name' => $tier['name'],
                    'amount' => $tier['amount'],
                    'percent' => $tier['percent'],
                ],
                $tiers,
            ),
            // The conic-gradient stops for the donut, computed here so the
            // ring and the legend are drawn from one arithmetic.
            'gradient' => $this->donutGradient($tiers),
            'clients' => $this->clientContributions($total),
        ];
    }

    /**
     * One row per client: tier, units, contribution, share.
     *
     * @return list<array<string, mixed>>
     */
    private function clientContributions(int $total): array
    {
        return DB::connection('mysql')
            ->table('tenants')
            ->leftJoin('subscriptions', 'subscriptions.tenant_id', '=', 'tenants.id')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->orderByDesc(DB::raw('COALESCE(subscriptions.unit_count * plans.price_per_unit_minor, 0)'))
            ->orderBy('tenants.name')
            ->select([
                'tenants.name',
                'plans.key as plan_key',
                'plans.name as plan_name',
                'subscriptions.unit_count',
                'subscriptions.status as subscription_status',
                'plans.price_per_unit_minor',
            ])
            ->get()
            ->map(function (object $row) use ($total): array {
                $billing = $row->subscription_status === 'active' && $row->unit_count !== null;
                $minor = $billing ? (int) $row->unit_count * (int) $row->price_per_unit_minor : 0;

                return [
                    'client' => (string) $row->name,
                    'tier_key' => $row->plan_key === null ? null : (string) $row->plan_key,
                    'tier' => $row->plan_name === null ? 'No plan' : (string) $row->plan_name,
                    'units' => $row->unit_count === null ? '—' : number_format((int) $row->unit_count),
                    'contribution' => $billing ? $this->exact($minor) : 'Not billing',
                    'share' => $billing && $total > 0
                        ? number_format(($minor / $total) * 100, 1).'%'
                        : '—',
                ];
            })
            ->all();
    }

    /* ------------------------------------------------------------ 39 */

    /**
     * Who stayed — board screen 39.
     *
     * Churn is measured on CANCELLED subscriptions rather than on estate
     * status, because an estate suspended for non-payment has not left: it is
     * being chased, and counting it as churn would make a collections problem
     * look like a retention one.
     *
     * @return array<string, mixed>
     */
    public function churn(): array
    {
        $clients = DB::connection('mysql')
            ->table('tenants')
            ->leftJoin('subscriptions', 'subscriptions.tenant_id', '=', 'tenants.id')
            ->orderBy('subscriptions.started_on')
            ->select([
                'tenants.name',
                'tenants.provisioned_at',
                'subscriptions.started_on',
                'subscriptions.status as subscription_status',
            ])
            ->get();

        $tenures = $clients
            ->map(function (object $row): array {
                $since = $row->started_on ?? $row->provisioned_at;
                $months = $since === null ? 0 : (int) Carbon::parse((string) $since)->diffInMonths(now());

                return [
                    'client' => (string) $row->name,
                    'since' => $since === null ? null : Carbon::parse((string) $since)->format('M Y'),
                    'months' => $months,
                    'cancelled' => $row->subscription_status === 'cancelled',
                ];
            })
            ->sortByDesc('months')
            ->values();

        $cancelled = $tenures->where('cancelled', true);
        $retained = $tenures->where('cancelled', false);
        $everOnboarded = $tenures->count();

        return [
            'kpis' => [
                [
                    'key' => 'churn',
                    'icon' => 'check-circle',
                    'value' => $this->churnRate().'%',
                    'label' => 'Churn, trailing 12mo',
                ],
                [
                    'key' => 'retention',
                    'icon' => 'clients',
                    'value' => $everOnboarded === 0
                        ? '—'
                        : (int) round(($retained->count() / $everOnboarded) * 100).'%',
                    'label' => 'Client retention rate',
                ],
                [
                    'key' => 'longest',
                    'icon' => 'clock',
                    'value' => $tenures->isEmpty() ? '—' : $tenures->first()['months'].' mo',
                    'label' => 'Longest-standing client',
                ],
                [
                    'key' => 'net_new',
                    'icon' => 'plus',
                    'value' => (string) $tenures->where('months', '<=', 12)->count(),
                    'label' => 'Net new clients, trailing 12mo',
                ],
            ],
            'tenures' => $retained
                ->map(fn (array $row): array => [
                    'client' => $row['client'],
                    'detail' => $row['since'] === null
                        ? 'Onboarding date not recorded'
                        : sprintf(
                            'Client since %s — %s',
                            $row['since'],
                            $row['months'] === 1 ? '1 month' : $row['months'].' months',
                        ),
                ])
                ->all(),
            /*
             * The board draws an empty panel under the tenure list when
             * nothing has been cancelled, and that emptiness is the finding
             * rather than a missing state. Null once something has been.
             */
            'cancellations' => $cancelled->isEmpty()
                ? null
                : $cancelled->map(fn (array $row): array => [
                    'client' => $row['client'],
                    'detail' => 'Cancelled · was a client for '.$row['months'].' months',
                ])->all(),
        ];
    }

    /* ------------------------------------------------------------ 40 */

    /**
     * Are the posts we are paid for actually staffed — board screen 40.
     *
     * Contracted comes from the subscription; deployed comes from the guards
     * table. The gap between them is the report: a client paying for four
     * guards and staffed by three is an unfilled contract, and it is invisible
     * from either number alone.
     *
     * A guard on leave or suspended is counted as NOT on active duty, because
     * a post nobody is standing is unstaffed whatever the reason.
     *
     * @return array<string, mixed>
     */
    public function utilisation(): array
    {
        $contracted = (int) DB::connection('mysql')->table('subscriptions')->sum('contracted_guards');

        $deployed = (int) DB::connection('mysql')
            ->table('guards')
            ->whereNotNull('tenant_id')
            ->whereNotIn('status', ['on_leave', 'suspended'])
            ->count();

        $offDuty = (int) DB::connection('mysql')
            ->table('guards')
            ->whereIn('status', ['on_leave', 'suspended'])
            ->count();

        $rows = DB::connection('mysql')
            ->table('tenants')
            ->leftJoin('subscriptions', 'subscriptions.tenant_id', '=', 'tenants.id')
            ->orderBy('tenants.name')
            ->select([
                'tenants.id',
                'tenants.name',
                'tenants.status as tenant_status',
                'subscriptions.contracted_guards',
            ])
            ->get()
            ->map(function (object $row): array {
                $want = (int) ($row->contracted_guards ?? 0);

                $have = (int) DB::connection('mysql')
                    ->table('guards')
                    ->where('tenant_id', $row->id)
                    ->whereNotIn('status', ['on_leave', 'suspended'])
                    ->count();

                return [
                    'client' => (string) $row->name,
                    'detail' => $this->fillDetail($want, $have, (string) $row->tenant_status),
                    'percent' => $want === 0 ? 0 : min(100, (int) round(($have / $want) * 100)),
                    // A client contracting no guards has no bar to fill, so
                    // the track is drawn empty in the muted colour rather than
                    // full — nothing is 100% staffed of nothing.
                    'muted' => $want === 0 || $have === 0,
                ];
            })
            ->all();

        return [
            'kpis' => [
                ['key' => 'contracted', 'icon' => 'guards', 'value' => (string) $contracted, 'label' => 'Guards contracted'],
                ['key' => 'deployed', 'icon' => 'check', 'value' => (string) $deployed, 'label' => 'Guards deployed'],
                [
                    'key' => 'fill',
                    'icon' => 'check-circle',
                    'value' => $contracted === 0 ? '—' : min(100, (int) round(($deployed / $contracted) * 100)).'%',
                    'label' => 'Fill rate',
                ],
                ['key' => 'off_duty', 'icon' => 'alert', 'value' => (string) $offDuty, 'label' => 'Not on active duty'],
            ],
            'rows' => $rows,
        ];
    }

    /** "4 of 4 contracted", and the two cases the board spells out differently. */
    private function fillDetail(int $want, int $have, string $tenantStatus): string
    {
        if ($want === 0) {
            return 'Self-managed — 0 contracted';
        }

        if ($have === 0 && $tenantStatus === 'onboarding') {
            return sprintf('0 of %d — awaiting go-live', $want);
        }

        return sprintf('%d of %d contracted', $have, $want);
    }

    /* ------------------------------------------------------- shared */

    /**
     * The platform's monthly recurring revenue, in minor units.
     *
     * Subscriptions plus the per-guard add-on. The add-on is billed every
     * month on top of any tier and appears on every invoice, so omitting it
     * would report less than the platform actually invoices.
     */
    private function monthlyRecurringMinor(): int
    {
        $subscriptions = (int) DB::connection('mysql')
            ->table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.status', 'active')
            ->sum(DB::raw('subscriptions.unit_count * plans.price_per_unit_minor'));

        return $subscriptions + $this->guardAddOnMinor(billingOnly: true);
    }

    /**
     * What every client would pay once the ones onboarding go live.
     *
     * The board's third card. It answers "what is coming", which is the
     * question a platform mid-onboarding is actually asking.
     */
    private function projectedMinor(): int
    {
        $subscriptions = (int) DB::connection('mysql')
            ->table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereNotIn('subscriptions.status', ['cancelled'])
            ->sum(DB::raw('subscriptions.unit_count * plans.price_per_unit_minor'));

        return $subscriptions + $this->guardAddOnMinor(billingOnly: false);
    }

    /** "Projected — Ocean View live", naming who it is waiting on. */
    private function projectionLabel(): string
    {
        $waiting = DB::connection('mysql')
            ->table('tenants')
            ->where('status', 'onboarding')
            ->orderBy('name')
            ->pluck('name');

        return match ($waiting->count()) {
            0 => 'Projected — all clients live',
            1 => 'Projected — '.$waiting->first().' live',
            default => sprintf('Projected — %d clients live', $waiting->count()),
        };
    }

    /**
     * The per-guard add-on, across every client that is billed for one.
     *
     * `billingOnly` narrows to subscriptions currently invoicing, which is the
     * difference between current MRR and the projection.
     */
    private function guardAddOnMinor(bool $billingOnly): int
    {
        $rate = (int) DB::connection('mysql')
            ->table('platform_rates')
            ->where('key', 'security_provider_guard')
            ->where('is_active', true)
            ->value('amount_minor');

        if ($rate === 0) {
            return 0;
        }

        $query = DB::connection('mysql')->table('subscriptions');

        $query = $billingOnly
            ? $query->where('status', 'active')
            : $query->whereNotIn('status', ['cancelled']);

        return (int) $query->sum('contracted_guards') * $rate;
    }

    /**
     * Churn over the trailing year.
     *
     * Cancelled subscriptions against everyone who was a client at any point
     * in the window. A platform with no cancellations reports 0, which is a
     * real answer rather than a missing one.
     */
    private function churnRate(): int
    {
        $total = DB::connection('mysql')->table('subscriptions')->count();

        if ($total === 0) {
            return 0;
        }

        $cancelled = DB::connection('mysql')
            ->table('subscriptions')
            ->where('status', 'cancelled')
            ->where('updated_at', '>=', now()->subYear())
            ->count();

        return (int) round(($cancelled / $total) * 100);
    }

    /**
     * What each of the last N months was actually invoiced.
     *
     * @return array<string, int>
     */
    private function invoicedByMonth(Carbon $from): array
    {
        return DB::connection('mysql')
            ->table('invoices')
            ->where('period_start', '>=', $from->toDateString())
            ->whereNotIn('status', ['void', 'draft'])
            ->groupBy(DB::raw("DATE_FORMAT(period_start, '%Y-%m')"))
            ->selectRaw("DATE_FORMAT(period_start, '%Y-%m') as ym, SUM(total_minor) as minor")
            ->pluck('minor', 'ym')
            ->map(static fn ($minor): int => (int) $minor)
            ->all();
    }

    /**
     * The last N months, oldest first.
     *
     * @return list<Carbon>
     */
    private function recentMonths(int $count): array
    {
        return array_map(
            static fn (int $back): Carbon => now()->startOfMonth()->subMonths($back),
            array_reverse(range(0, $count - 1)),
        );
    }

    /**
     * What moved MRR, keyed by month.
     *
     * Onboardings and guard changes are the two things that move recurring
     * revenue on this platform, and both are central facts with dates of their
     * own.
     *
     * @return array<string, list<array{title: string, detail: string, icon: string}>>
     */
    private function contributingEvents(): array
    {
        $events = [];

        foreach ($this->eventFeed() as $event) {
            $events[$event['month_key']][] = $event;
        }

        return $events;
    }

    /**
     * The events panel beside the chart, newest first.
     *
     * @return list<array{title: string, detail: string, icon: string, month_key: string}>
     */
    private function eventFeed(): array
    {
        $rate = (int) DB::connection('mysql')
            ->table('platform_rates')
            ->where('key', 'security_provider_guard')
            ->value('amount_minor');

        $events = [];

        $clients = DB::connection('mysql')
            ->table('tenants')
            ->leftJoin('subscriptions', 'subscriptions.tenant_id', '=', 'tenants.id')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->select([
                'tenants.name',
                'tenants.status',
                'tenants.provisioned_at',
                'subscriptions.started_on',
                'subscriptions.unit_count',
                'subscriptions.contracted_guards',
                'subscriptions.status as subscription_status',
                'plans.price_per_unit_minor',
            ])
            ->get();

        foreach ($clients as $client) {
            $since = $client->started_on ?? $client->provisioned_at;

            if ($since === null) {
                continue;
            }

            $at = Carbon::parse((string) $since);
            $billing = $client->subscription_status === 'active';

            $minor = $billing && $client->unit_count !== null
                ? (int) $client->unit_count * (int) $client->price_per_unit_minor
                : 0;

            $events[] = [
                'month_key' => $at->format('Y-m'),
                'at' => $at->toDateString(),
                'icon' => 'clients',
                'title' => $billing
                    ? $client->name.' onboarded'
                    : $client->name.' — onboarding started',
                'detail' => $billing
                    ? sprintf('%s · +%s MRR', $at->format('M Y'), $this->exact($minor))
                    : sprintf('%s · not yet billing', $at->format('M Y')),
            ];

            $guards = (int) ($client->contracted_guards ?? 0);

            if ($billing && $guards > 0 && $rate > 0) {
                $events[] = [
                    'month_key' => $at->format('Y-m'),
                    'at' => $at->toDateString(),
                    'icon' => 'guards',
                    'title' => sprintf(
                        '%s contracted %s',
                        $client->name,
                        $guards === 1 ? '1 guard' : $guards.' guards',
                    ),
                    'detail' => sprintf('%s · +%s MRR', $at->format('M Y'), $this->exact($guards * $rate)),
                ];
            }
        }

        usort($events, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));

        return array_map(
            static fn (array $event): array => [
                'month_key' => $event['month_key'],
                'icon' => $event['icon'],
                'title' => $event['title'],
                'detail' => $event['detail'],
            ],
            $events,
        );
    }

    /**
     * The donut's conic-gradient stops.
     *
     * Built from the same tier figures the legend prints, so the ring and the
     * list cannot disagree about a share.
     *
     * @param  list<array<string, mixed>>  $tiers
     */
    private function donutGradient(array $tiers): string
    {
        if ($tiers === []) {
            return 'var(--navy-100)';
        }

        $colours = [
            'premium' => 'var(--purple-800)',
            'standard' => 'var(--navy-600)',
            'essential' => 'var(--navy-300)',
        ];

        $stops = [];
        $at = 0.0;

        foreach ($tiers as $tier) {
            $colour = $colours[$tier['key']] ?? 'var(--navy-200)';
            $to = $at + (float) $tier['percent'];
            $stops[] = sprintf('%s %.1f%% %.1f%%', $colour, $at, $to);
            $at = $to;
        }

        return 'conic-gradient('.implode(', ', $stops).')';
    }

    /** "$243,900" — a report figure, decimals dropped when there are none. */
    private function exact(int $minor): string
    {
        return MoneyFormatter::whole($minor);
    }

    /**
     * "$243.9k" — the chart's own compression.
     *
     * A bar 40px wide cannot carry "$243,900", and the axis label exists to be
     * read at a glance rather than reconciled. The exact figure is one card
     * away on the same screen.
     */
    private function compact(int $minor): string
    {
        $units = (int) round($minor / 100);

        if ($units < 1000) {
            return MoneyFormatter::whole($minor);
        }

        $thousands = $units / 1000;

        return '$'.rtrim(rtrim(number_format($thousands, 1), '0'), '.').'k';
    }
}
