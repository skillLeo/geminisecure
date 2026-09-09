<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Plans, subscriptions and a short invoice history.
 *
 * Seeds one estate into `dunning` on purpose. The billing screen states that
 * dunning gates billing features only and never restricts access; that claim
 * is worth nothing if no estate is ever in dunning to demonstrate it.
 */
class BillingSeeder extends Seeder
{
    /** [key, name, price per unit per month in minor units, min units] */
    private const PLANS = [
        ['essential', 'Essential', 1_200_00, 25],
        ['standard', 'Standard', 1_850_00, 50],
        ['premium', 'Premium', 2_400_00, 100],
    ];

    public function run(): void
    {
        foreach (self::PLANS as $i => [$key, $name, $price, $minUnits]) {
            Plan::updateOrCreate(
                ['key' => $key],
                [
                    'name' => $name,
                    'description' => "Per unit, per month. Minimum {$minUnits} units.",
                    'price_per_unit_minor' => $price,
                    'currency' => 'JMD',
                    'min_units' => $minUnits,
                    'is_active' => true,
                    'sort' => ($i + 1) * 10,
                ],
            );
        }

        $estates = Tenant::estates();
        $standard = Plan::where('key', 'standard')->first();

        foreach ($estates as $i => $estate) {
            // One estate in dunning, so the screen's claim about access can be
            // seen to hold rather than merely asserted.
            $status = $i === 0 ? 'active' : 'dunning';
            $units = 48 + ($i * 22);

            $subscription = Subscription::updateOrCreate(
                ['tenant_id' => $estate->getTenantKey()],
                [
                    'plan_id' => $standard->id,
                    'unit_count' => $units,
                    'status' => $status,
                    'started_on' => now()->subMonths(7)->toDateString(),
                    'renews_on' => now()->addMonth()->startOfMonth()->toDateString(),
                ],
            );

            foreach (range(2, 0) as $monthsAgo) {
                $start = now()->subMonths($monthsAgo)->startOfMonth();
                $total = $units * $standard->price_per_unit_minor;

                // The oldest two are settled; the current one is outstanding.
                $paid = $monthsAgo > 0;

                Invoice::updateOrCreate(
                    ['reference' => strtoupper(substr($estate->getTenantKey(), 0, 2))
                        .'-INV-'.$start->format('Ym')],
                    [
                        'tenant_id' => $estate->getTenantKey(),
                        'subscription_id' => $subscription->id,
                        'period' => $start->format('M Y'),
                        'period_start' => $start->toDateString(),
                        'period_end' => $start->copy()->endOfMonth()->toDateString(),
                        'total_minor' => $total,
                        'currency' => 'JMD',
                        'due_on' => $start->copy()->addDays(14)->toDateString(),
                        'status' => $paid ? 'paid' : 'issued',
                        'paid_on' => $paid ? $start->copy()->addDays(9)->toDateString() : null,
                    ],
                );
            }
        }
    }
}
