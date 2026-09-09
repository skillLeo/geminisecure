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
 * THE BOARDS ARE THE SPECIFICATION FOR DEMO DATA.
 *
 * A reviewer comparing a screen against the design they signed off cannot tell
 * a layout fault from a seeding accident. The approved boards state what each
 * client is — Phoenix Park Village 1 is the flagship Premium account at 450
 * units and $171,000 a month, Ocean View Gardens is mid-onboarding at 320 —
 * so the seed says the same, and every screen that shows a client agrees with
 * every other.
 *
 * This was previously seeded by loop index: estate 0 active, the rest dunning,
 * units 48 + 22i. That made Phoenix Park a dunning Standard client with no
 * MRR, which is the opposite of what the boards draw, and the Client Detail
 * screen's own hero card sizes to its content — so shorter data made a
 * narrower card and shifted the entire action column against the design.
 *
 * Prices come from the Platform Dashboard board's MRR-by-tier panel, which is
 * the only place the design states them: Premium $171,000 on Phoenix Park's
 * 450 units is $380 per unit per month, and the tier totals there add up to
 * the $243,900 headline.
 *
 * One estate stays in `dunning` deliberately. The billing screen claims that
 * dunning gates billing features only and never restricts access, and that
 * claim is worth nothing if no estate is ever in dunning to demonstrate it.
 */
class BillingSeeder extends Seeder
{
    /** [key, name, price per unit per month in minor units, min units] */
    private const PLANS = [
        ['essential', 'Essential', 1_200_00, 25],
        ['standard', 'Standard', 1_850_00, 50],
        ['premium', 'Premium', 380_00, 100],
    ];

    /**
     * What each client is, taken from the boards that draw it.
     *
     * Keyed by subdomain. An estate not listed here falls back to the generic
     * profile below, so provisioning a new estate still produces something
     * sensible without anyone editing this file.
     *
     * @var array<string, array{plan: string, units: int, status: string, months: int}>
     */
    private const CLIENTS = [
        // Board super-admin-05: "450 units · 4 guards deployed · $171,000 MRR
        // · Current" and "Premium tier", client since Mar 2024.
        'phoenixpark' => ['plan' => 'premium', 'units' => 450, 'status' => 'active', 'months' => 30],

        // Board super-admin-09: Ocean View Gardens, 320 units, still onboarding.
        // Left in dunning so the billing screen has a live example of the state
        // it makes a claim about.
        'oceanview' => ['plan' => 'standard', 'units' => 320, 'status' => 'dunning', 'months' => 7],
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

        $plans = Plan::query()->get()->keyBy('key');

        foreach (Tenant::estates() as $i => $estate) {
            $subdomain = (string) $estate->getTenantKey();

            $profile = self::CLIENTS[$subdomain] ?? [
                // A newly provisioned estate nobody drew a board for. Standard
                // tier at its minimum, active, just signed.
                'plan' => 'standard',
                'units' => 50 + ($i * 22),
                'status' => 'active',
                'months' => 1,
            ];

            $plan = $plans[$profile['plan']];

            $subscription = Subscription::updateOrCreate(
                ['tenant_id' => $subdomain],
                [
                    'plan_id' => $plan->id,
                    'unit_count' => $profile['units'],
                    'status' => $profile['status'],
                    'started_on' => now()->subMonths($profile['months'])->toDateString(),
                    'renews_on' => now()->addMonth()->startOfMonth()->toDateString(),
                ],
            );

            foreach (range(2, 0) as $monthsAgo) {
                $start = now()->subMonths($monthsAgo)->startOfMonth();
                $total = $profile['units'] * $plan->price_per_unit_minor;

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
