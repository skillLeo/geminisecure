<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\Tenant;
use App\Models\User;

/**
 * Platform-wide figures for the Gemini Console.
 *
 * Reads ONLY from gs_platform. No method here may open an estate database:
 * a cross-tenant figure that fans out across estates would break both the
 * isolation model and the rule that every cross-tenant report is an aggregate
 * derived from central data. If a number cannot be produced centrally, it is
 * not a cross-tenant figure and does not belong on this dashboard.
 */
class PlatformOverview
{
    /** @return array<int, array<string, mixed>> */
    public function kpis(): array
    {
        $byStatus = Tenant::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $active = (int) ($byStatus['active'] ?? 0);
        $onboarding = (int) ($byStatus['onboarding'] ?? 0);
        $dunning = (int) ($byStatus['dunning'] ?? 0);

        return [
            [
                'key' => 'estates',
                'icon' => 'clients',
                'value' => (string) $active,
                'label' => 'Active estates',
                'trend' => null,
            ],
            [
                'key' => 'onboarding',
                'icon' => 'clients',
                'value' => (string) $onboarding,
                'label' => 'Onboarding',
                'trend' => null,
            ],
            [
                'key' => 'users',
                'icon' => 'guard_workforce',
                'value' => (string) User::where('status', 'active')->count(),
                'label' => 'Active accounts',
                'trend' => null,
            ],
            [
                /*
                 * Access is never withheld over a billing dispute, so this
                 * counts estates in dunning without implying any of them are
                 * restricted. It is a collections signal, not an access one.
                 */
                'key' => 'dunning',
                'icon' => 'billing_subscriptions',
                'value' => (string) $dunning,
                'label' => 'In dunning',
                'alert' => $dunning > 0,
                'trend' => null,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function recentEstates(int $limit = 8): array
    {
        return Tenant::query()
            ->orderByDesc('provisioned_at')
            ->limit($limit)
            ->get()
            ->map(fn (Tenant $tenant) => [
                'id' => $tenant->getTenantKey(),
                'name' => $tenant->name,
                'status' => $tenant->status,
                'provisioned_at' => $tenant->provisioned_at?->toDateString(),
            ])
            ->all();
    }
}
