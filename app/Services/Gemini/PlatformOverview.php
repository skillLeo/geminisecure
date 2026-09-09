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
 * super-admin-02.
 */
class PlatformOverview
{
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
     * The activity list, drawn from real central records.
     *
     * The board shows three kinds of event — an estate onboarding, an invoice
     * paid, a guard deployed — so those are the three sources. They are merged
     * and re-sorted by their own timestamps rather than shown in fixed blocks,
     * because a feed that always lists one of each is a layout, not a feed.
     *
     * @return list<array{icon: string, title: string, meta: string}>
     */
    public function recentActivity(int $limit = 3): array
    {
        $events = [];

        // A block body, not an arrow function: the callable is declared to
        // return void, and an arrow function would return the Builder.
        $provisioned = Tenant::estates(function ($query) use ($limit): void {
            $query->whereNotNull('provisioned_at')->orderByDesc('provisioned_at')->limit($limit);
        });

        foreach ($provisioned as $tenant) {
            $units = (int) Subscription::query()->where('tenant_id', $tenant->getTenantKey())->value('unit_count');

            $events[] = [
                'at' => $tenant->provisioned_at,
                'icon' => 'clients',
                'title' => $tenant->name.' — onboarding started',
                'meta' => ($units > 0 ? number_format($units).' units · ' : '').$this->ago($tenant->provisioned_at),
            ];
        }

        $paidInvoices = DB::connection('mysql')
            ->table('invoices')
            ->join('tenants', 'tenants.id', '=', 'invoices.tenant_id')
            ->where('invoices.status', 'paid')
            ->whereNotNull('invoices.paid_on')
            ->orderByDesc('invoices.paid_on')
            ->limit($limit)
            ->select('tenants.name', 'invoices.total_minor', 'invoices.currency', 'invoices.paid_on')
            ->get();

        foreach ($paidInvoices as $invoice) {
            $events[] = [
                'at' => $invoice->paid_on,
                'icon' => 'billing',
                'title' => $invoice->name.' — invoice paid',
                'meta' => $this->formatWhole((int) $invoice->total_minor).' · '.$this->shortDate($invoice->paid_on),
            ];
        }

        /*
         * Left join, not the estate relation.
         *
         * A guard's tenant_id is nullable — an unassigned guard is a real
         * state, not a broken row — so the estate name has to survive being
         * absent. A left join says that in the query; eager loading the
         * relation would hand back a null the relation's own type says cannot
         * happen.
         */
        $deployed = DB::connection('mysql')
            ->table('guards')
            ->leftJoin('tenants', 'tenants.id', '=', 'guards.tenant_id')
            ->whereNotNull('guards.hired_on')
            ->orderByDesc('guards.hired_on')
            ->limit($limit)
            ->select('guards.full_name', 'guards.employment_type', 'guards.hired_on', 'tenants.name as estate_name')
            ->get();

        foreach ($deployed as $guard) {
            $events[] = [
                'at' => $guard->hired_on,
                'icon' => 'guards',
                'title' => $guard->full_name.' deployed — '.str_replace('_', ' ', (string) $guard->employment_type),
                'meta' => ($guard->estate_name ?? 'Unassigned').' · '.$this->shortDate($guard->hired_on),
            ];
        }

        usort($events, fn (array $a, array $b): int => strcmp((string) $b['at'], (string) $a['at']));

        return array_map(
            fn (array $e): array => ['icon' => $e['icon'], 'title' => $e['title'], 'meta' => $e['meta']],
            array_slice($events, 0, $limit)
        );
    }

    private function shortDate(mixed $value): string
    {
        return $value === null ? '' : Carbon::parse((string) $value)->format('M j');
    }

    private function ago(mixed $value): string
    {
        return $value === null ? '' : Carbon::parse((string) $value)->diffForHumans(short: false);
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
     * Whole units, no decimals. The board drops the cents on dashboard
     * figures because a platform MRR to the penny is noise at that size, and
     * reproducing the board is the instruction. The exact amount is never
     * derived from this string: it is formatted from minor units, once, here.
     */
    private function formatWhole(int $minor): string
    {
        $currency = Plan::query()->value('currency') ?? MoneyFormatter::DEFAULT_CURRENCY;
        $money = Money::ofMinor($minor, $currency);

        $symbol = $money->getCurrency()->getCurrencyCode() === 'USD' ? '$' : 'J$';

        return $symbol.number_format((float) $money->getAmount()->toFloat(), 0);
    }
}
