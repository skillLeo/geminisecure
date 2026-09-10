<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\AuditEntry;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\MoneyFormatter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Putting a client on a tier, and changing the one they are on — board 06.
 *
 * THIS SCREEN PRICES A CLIENT, which is why nothing about it is casual. Every
 * figure it shows is computed from the same arithmetic the invoice uses, and
 * the write it performs is audited with the before and after in the entry.
 *
 * ONE SCREEN, TWO ACTS. An estate mid-onboarding is being ACTIVATED — it has
 * never billed, so the projection is what it will cost once it goes live. A
 * live estate is being RE-PRICED — it is billing today, so the projection is
 * the difference between what it pays now and what it would pay after. The
 * board draws the first; the second is the same screen with a different verb,
 * and conflating them would tell a live client's account manager that their
 * bill is starting from zero.
 *
 * THE PROJECTION IS NOT A PROMISE ABOUT WHEN. A change takes effect at the
 * first full billing cycle, not immediately, because a mid-cycle re-price
 * would need proration nobody has specified. The screen says so in words
 * rather than showing a date it cannot honour.
 */
class ClientPlan
{
    /**
     * Everything screen 06 draws for one client.
     *
     * @return array<string, mixed>
     */
    public function forEstate(Tenant $estate): array
    {
        $subscription = Subscription::query()->where('tenant_id', $estate->getTenantKey())->first();
        $plans = Plan::query()->where('is_active', true)->orderBy('sort')->get();
        $onboarding = $estate->status === ClientDirectory::ONBOARDING;

        $currency = $plans->isEmpty()
            ? MoneyFormatter::DEFAULT_CURRENCY
            : (string) $plans->first()->currency;

        return [
            'estate' => [
                'id' => (string) $estate->getTenantKey(),
                'name' => (string) $estate->name,
                'onboarding' => $onboarding,
            ],
            'plans' => $plans->map(fn (Plan $plan): array => [
                'id' => (int) $plan->id,
                'key' => (string) $plan->key,
                'name' => (string) $plan->name,
                'price' => MoneyFormatter::whole((int) $plan->price_per_unit_minor, (string) $plan->currency).'/unit',
                'price_minor' => (int) $plan->price_per_unit_minor,
                'min_units' => (int) $plan->min_units,
            ])->all(),
            'current' => [
                'plan_id' => $subscription?->plan_id,
                'units' => $subscription?->unit_count,
                'guards' => $subscription?->contracted_guards,
                'term_months' => $subscription?->term_months,
            ],
            'guard_rate_minor' => $this->guardRateMinor(),
            'currency' => $currency,

            /*
             * The platform's current MRR, so the note beneath the projection
             * can say what this change does to the headline. Read from the
             * same place the dashboard reads it rather than recomputed, so
             * the two cannot disagree.
             */
            'platform_mrr' => MoneyFormatter::whole($this->platformMrrMinor(), $currency),
            'action' => $onboarding ? 'Activate subscription' : 'Change plan',
            'effective' => $onboarding
                ? 'First full billing cycle after go-live'
                : 'First full billing cycle after the change',
        ];
    }

    /**
     * Apply the change.
     *
     * A single transaction, because a subscription written without its audit
     * entry is a re-price nobody can account for — and this is the act that
     * changes what a client pays.
     *
     * @param  array<string, mixed>  $input
     */
    public function apply(Tenant $estate, User $actor, array $input): void
    {
        $plans = Plan::query()->where('is_active', true)->get()->keyBy('id');

        $data = Validator::make($input, [
            'plan_id' => ['required', 'integer', Rule::in($plans->keys()->all())],

            /*
             * A tier states a minimum, and it is a commercial floor rather
             * than a technical one — the price only works above it. Checked
             * against the CHOSEN plan below, because the minimum differs per
             * tier and a single number here would be wrong for two of them.
             */
            'units' => ['required', 'integer', 'min:1', 'max:100000'],

            'guards' => ['required', 'integer', 'min:0', 'max:500'],

            // Nullable and meaningful: a client with no committed term renews
            // monthly, which is a real arrangement rather than missing data.
            'term_months' => ['nullable', 'integer', 'min:1', 'max:120'],
        ], [
            'plan_id.required' => 'Choose a tier before activating.',
            'plan_id.in' => 'That tier is no longer available.',
            'units.required' => 'A subscription is priced per unit, so it needs a unit count.',
            'guards.required' => 'Enter 0 if this client manages their own security.',
        ])->validate();

        $plan = $plans[$data['plan_id']];

        if ((int) $data['units'] < (int) $plan->min_units) {
            Validator::make([], [])->after(function ($validator) use ($plan): void {
                $validator->errors()->add(
                    'units',
                    sprintf(
                        'The %s tier starts at %s units. Choose a lower tier, or raise the unit count.',
                        $plan->name,
                        number_format((int) $plan->min_units),
                    ),
                );
            })->validate();
        }

        DB::connection('mysql')->transaction(function () use ($estate, $actor, $data, $plan): void {
            $id = (string) $estate->getTenantKey();
            $existing = Subscription::query()->where('tenant_id', $id)->first();

            $before = $existing === null ? null : [
                'plan_id' => $existing->plan_id,
                'unit_count' => $existing->unit_count,
                'contracted_guards' => $existing->contracted_guards,
                'term_months' => $existing->term_months,
                'status' => $existing->status,
            ];

            $after = [
                'plan_id' => (int) $data['plan_id'],
                'unit_count' => (int) $data['units'],
                'contracted_guards' => (int) $data['guards'],
                'term_months' => $data['term_months'] === null ? null : (int) $data['term_months'],
            ];

            /*
             * The subscription does NOT start billing here.
             *
             * An onboarding estate's subscription stays out of `active` until
             * onboarding is marked complete — that is the act that starts
             * charging, and it lives on the client detail screen with its own
             * checklist behind it. Activating a plan sets what they WILL be
             * billed, not that they are.
             */
            $status = $existing === null ? 'onboarding' : (string) $existing->status;

            Subscription::updateOrCreate(
                ['tenant_id' => $id],
                [
                    ...$after,
                    'status' => $status,
                    'started_on' => $existing === null ? now()->toDateString() : $existing->started_on,
                    'renews_on' => now()->addMonth()->startOfMonth()->toDateString(),
                ],
            );

            AuditEntry::create([
                'tenant_id' => $id,
                'actor_id' => $actor->getKey(),
                'actor_name' => $actor->name,
                'actor_role' => $actor->roles->isEmpty() ? 'No role assigned' : $actor->roles->first()->label,
                'action' => $before === null ? 'client.plan_activated' : 'client.plan_changed',
                'entity_type' => 'subscription',
                'entity_id' => $id,
                'before' => $before,
                'after' => [...$after, 'plan' => $plan->name],
            ]);
        });
    }

    /**
     * The blank form for a client that does not exist yet — board screen 08.
     *
     * The same tiers and the same per-guard rate the activate screen uses, so
     * the projection an account manager sees at sign-up is the one the client
     * will actually be invoiced.
     *
     * @return array<string, mixed>
     */
    public function newClientForm(): array
    {
        $plans = Plan::query()->where('is_active', true)->orderBy('sort')->get();

        return [
            'plans' => $plans->map(fn (Plan $plan): array => [
                'id' => (int) $plan->id,
                'key' => (string) $plan->key,
                'name' => (string) $plan->name,
                'price' => MoneyFormatter::whole((int) $plan->price_per_unit_minor, (string) $plan->currency).'/unit',
                'price_minor' => (int) $plan->price_per_unit_minor,
                'min_units' => (int) $plan->min_units,
            ])->all(),
            'guard_rate_minor' => $this->guardRateMinor(),
            'currency' => $plans->isEmpty()
                ? MoneyFormatter::DEFAULT_CURRENCY
                : (string) $plans->first()->currency,

            /*
             * Who is mid-onboarding right now. The board's note ends "same as
             * Ocean View Gardens is now", which is only true while an estate
             * is in that state — so it names whoever actually is, and says
             * nothing when nobody is.
             */
            'onboarding_example' => Tenant::query()
                ->where('status', ClientDirectory::ONBOARDING)
                ->orderBy('name')
                ->value('name'),
        ];
    }

    /** The per-guard charge that sits on top of any tier. */
    private function guardRateMinor(): int
    {
        return (int) DB::connection('mysql')
            ->table('platform_rates')
            ->where('key', 'security_provider_guard')
            ->where('is_active', true)
            ->value('amount_minor');
    }

    /** What the platform bills today, tiers plus add-on. */
    private function platformMrrMinor(): int
    {
        $subscriptions = (int) DB::connection('mysql')
            ->table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.status', 'active')
            ->sum(DB::raw('subscriptions.unit_count * plans.price_per_unit_minor'));

        $guards = (int) DB::connection('mysql')
            ->table('subscriptions')
            ->where('status', 'active')
            ->sum('contracted_guards');

        return $subscriptions + ($guards * $this->guardRateMinor());
    }
}
