<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\Guard;
use App\Models\Subscription;
use App\Support\MoneyFormatter;
use Illuminate\Support\Collection;

/**
 * Cross-tenant reporting.
 *
 * TWO RULES GOVERN EVERY METHOD HERE, and both are structural rather than
 * conventions to remember:
 *
 * 1. Reads ONLY from gs_platform. No method may open an estate database. If a
 *    figure cannot be produced from central data it is not a cross-tenant
 *    report, and the honest answer is to say so rather than to fan out across
 *    every estate and call the result an aggregate.
 *
 * 2. Every figure is an AGGREGATE. None can be drilled down to an individual
 *    resident. A per-estate contribution to MRR is a property of the
 *    subscription, not of anybody living there, which is precisely why this
 *    class can compute it without touching a resident row.
 *
 * Note what is therefore absent and cannot be added here: arrears totals,
 * collection rates, anything derived from resident charges. Those live in
 * estate databases and reaching them would break both rules at once.
 */
class CrossTenantReports
{
    /** @return array<string, mixed> */
    public function catalogue(): array
    {
        return [
            'mrr' => $this->mrr(),
            'revenueByTier' => $this->revenueByTier(),
            'guardUtilisation' => $this->guardUtilisation(),
        ];
    }

    /** @return array<string, mixed> */
    public function mrr(): array
    {
        $active = $this->activeSubscriptions();
        $total = $active->sum(fn (Subscription $s) => $s->mrrMinor());

        return [
            'total_minor' => $total,
            'total' => MoneyFormatter::fromMinor($total),
            'subscription_count' => $active->count(),
            'unit_count' => $active->sum('unit_count'),
        ];
    }

    /**
     * Revenue split by plan tier.
     *
     * @return array<int, array<string, mixed>>
     */
    public function revenueByTier(): array
    {
        $active = $this->activeSubscriptions();
        $total = max(1, $active->sum(fn (Subscription $s) => $s->mrrMinor()));

        return $active
            ->map(fn (Subscription $s) => [
                'estate' => $s->estate?->name ?? 'Unknown',
                'tier' => $s->plan?->name ?? 'Unassigned',
                'units' => $s->unit_count,
                'contribution_minor' => $s->mrrMinor(),
                'contribution' => MoneyFormatter::fromMinor($s->mrrMinor()),
                // One decimal place: this is a proportion for reading, not a
                // figure anything reconciles against.
                'share' => round($s->mrrMinor() / $total * 100, 1),
            ])
            ->sortByDesc('contribution_minor')
            ->values()
            ->all();
    }

    /**
     * Guards per estate, and how many are unpostable.
     *
     * @return array<int, array<string, mixed>>
     */
    public function guardUtilisation(): array
    {
        return Guard::with('estate')
            ->get()
            ->groupBy('tenant_id')
            ->map(fn (Collection $guards, $tenantId) => [
                'estate' => $guards->first()->estate?->name ?? 'Unassigned',
                'guards' => $guards->count(),
                'active' => $guards->where('status', 'active')->count(),
                'on_leave' => $guards->where('status', 'on_leave')->count(),
                // A guard whose licence has lapsed cannot lawfully stand a
                // post, so utilisation that counted them would overstate cover.
                'unpostable' => $guards->filter(
                    fn (Guard $g) => $g->licenceState() === 'expired' || $g->status === 'suspended'
                )->count(),
            ])
            ->sortByDesc('guards')
            ->values()
            ->all();
    }

    private function activeSubscriptions(): Collection
    {
        return Subscription::with(['plan', 'estate'])
            ->where('status', 'active')
            ->get();
    }
}
