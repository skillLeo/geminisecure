<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessLevel;
use App\Enums\AccessScope;
use App\Enums\Console;
use App\Support\MoneyFormatter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Platform settings — board screens super-admin-42, 43 and 44.
 *
 * Three screens about one thing: what GeminiSecure charges, and for what.
 *
 *   42  the rate card — every price the platform quotes, and who holds a
 *       platform account
 *   43  the package template — which tier includes which feature, for every
 *       current and future client on it
 *   44  one client's overrides — what has been added above their tier or
 *       removed from it
 *
 * Everything is read from gs_platform. That is not a preference on this module:
 * a plan prices households, and an estate's own household records live in the
 * estate database. Nothing here needs them, and nothing here opens one.
 *
 * NOTHING HERE WRITES. Every one of these three screens configures money that
 * applies to clients other than the one in front of you — a tier price
 * re-prices every estate on it, a package row changes what every current and
 * future client on that tier receives. Those are privileged, audited writes,
 * and they do not get built as a side effect of the screen that displays them.
 * The controls the boards draw are rendered inert, saying so.
 */
class PlatformSettings
{
    /**
     * Board 42's left panel: every rate the platform quotes.
     *
     * Two sources, deliberately, and in this order. A tier price is a property
     * of a plan and lives on `plans`; a per-guard charge applies on top of ANY
     * tier and therefore cannot be a column on a plan without asserting that a
     * guard costs a different amount depending on the estate's subscription.
     *
     * @return list<array{name: string, detail: string, amount: string}>
     */
    public function rateCard(): array
    {
        /*
         * How many clients each tier prices.
         *
         * Every subscription counts, not only the active ones: the question
         * this line answers is "how much of the book moves if I change this
         * number", and an estate in dunning is still billed against its tier.
         */
        $clients = DB::connection('mysql')
            ->table('subscriptions')
            ->select('plan_id', DB::raw('COUNT(*) as clients'))
            ->groupBy('plan_id')
            ->pluck('clients', 'plan_id');

        $rows = DB::connection('mysql')
            ->table('plans')
            ->where('is_active', true)
            ->orderBy('sort')
            ->get()
            ->map(fn (object $plan): array => [
                'name' => (string) $plan->name,
                'detail' => $this->clientCount((int) ($clients[$plan->id] ?? 0)),
                'amount' => MoneyFormatter::whole(
                    (int) $plan->price_per_unit_minor,
                    $this->currency($plan->currency)
                ).' /unit',
            ])
            ->all();

        foreach ($this->platformRates() as $rate) {
            $rows[] = [
                'name' => (string) $rate->label,
                'detail' => (string) $rate->applies_to,
                'amount' => MoneyFormatter::whole(
                    (int) $rate->amount_minor,
                    $this->currency($rate->currency)
                ).' /'.$rate->basis,
            ];
        }

        return $rows;
    }

    /**
     * Is anything priced but retired?
     *
     * The difference between a rate card that is empty because it was emptied
     * and one that is empty because it was never filled. Those are two
     * different screens — one says retired plans are still on file and how to
     * bring one back, the other says nothing has ever been priced — and
     * neither can be told from a row count alone.
     *
     * @param  bool  $includeRates  whether retired platform rates count too;
     *                              false on the package builder, which draws
     *                              plan columns and no rate rows
     */
    public function hasRetiredPricing(bool $includeRates = true): bool
    {
        $plans = DB::connection('mysql')->table('plans')->where('is_active', false)->exists();

        if ($plans || ! $includeRates) {
            return $plans;
        }

        return DB::connection('mysql')->table('platform_rates')->where('is_active', false)->exists();
    }

    /**
     * Board 42's right panel: who holds an account on this console.
     *
     * "Platform administrator" is not a separate list to maintain — it is
     * anyone holding a Gemini Console role, which is the same fact the sidebar
     * and the `can:` middleware are generated from. A second list would be a
     * second thing to keep in step, and the one that drifted would be this one.
     *
     * @return list<array{initials: string, name: string, detail: string}>
     */
    public function administrators(): array
    {
        $roles = DB::connection('mysql')
            ->table('roles')
            ->where('console', Console::Gemini->value)
            ->orderBy('sort')
            ->get()
            ->keyBy('id');

        $moduleCount = DB::connection('mysql')
            ->table('modules')
            ->where('console', Console::Gemini->value)
            ->count();

        /** @var array<int, string> $summaries */
        $summaries = [];

        foreach ($roles as $roleId => $role) {
            $summaries[(int) $roleId] = $this->accessSummary((int) $roleId, $moduleCount);
        }

        return DB::connection('mysql')
            ->table('users')
            ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.console', Console::Gemini->value)
            ->orderBy('roles.sort')
            ->orderBy('users.name')
            ->select('users.name', 'users.status', 'roles.id as role_id', 'roles.label', 'roles.name as role_name')
            ->get()
            ->map(fn (object $row): array => [
                'initials' => $this->initials((string) $row->name),
                'name' => (string) $row->name,
                /*
                 * Role, then reach. The board writes "Head of Platform Ops ·
                 * Full access", and the second half is the half that matters:
                 * a title is what someone is called, and what they can open is
                 * what a reader of this panel is checking.
                 *
                 * A suspended account is called out. Someone reading a list of
                 * who holds platform access needs to see that a row is dormant
                 * without opening it.
                 */
                'detail' => implode(' · ', array_filter([
                    (string) ($row->label ?? $row->role_name),
                    $summaries[(int) $row->role_id] ?? null,
                    $row->status === 'active' ? null : 'account '.$row->status,
                ])),
            ])
            ->all();
    }

    /**
     * Board 43: the package template, one column per active plan.
     *
     * The grid is not a report about packages — it is `plan_features` printed,
     * which is the same table an estate's available feature set is resolved
     * from. Nothing here recomputes what a tier includes, so this screen cannot
     * say one thing while a provisioned estate gets another.
     *
     * @return array{plans: list<array{id: int, name: string, price: string, emphasised: bool}>, features: list<array{key: string, label: string, sub: string|null, kind: string, cells: list<array{variant: string, value: string|null, emphasised: bool}>}>}
     */
    public function packageTemplate(): array
    {
        $plans = DB::connection('mysql')
            ->table('plans')
            ->where('is_active', true)
            ->orderBy('sort')
            ->get();

        /*
         * The top tier carries the board's emphasis — a tinted column heading
         * and a tinted branding tag. Derived from the ordering rather than
         * matched on the name "Premium", so renaming the top tier or adding one
         * above it moves the emphasis with it instead of leaving it on a plan
         * that is no longer the flagship.
         */
        $topPlanId = $plans->isEmpty() ? null : (int) $plans->last()->id;

        $features = DB::connection('mysql')
            ->table('package_features')
            ->orderBy('sort')
            ->get();

        $matrix = DB::connection('mysql')
            ->table('plan_features')
            ->whereIn('plan_id', $plans->pluck('id'))
            ->get()
            ->keyBy(fn (object $row): string => $row->plan_id.':'.$row->package_feature_id);

        return [
            'plans' => $plans->map(fn (object $plan): array => [
                'id' => (int) $plan->id,
                'name' => (string) $plan->name,
                'price' => MoneyFormatter::whole(
                    (int) $plan->price_per_unit_minor,
                    $this->currency($plan->currency)
                ).'/unit',
                'emphasised' => (int) $plan->id === $topPlanId,
            ])->all(),
            'features' => $features->map(fn (object $feature): array => [
                'key' => (string) $feature->key,
                'label' => (string) $feature->label,
                'sub' => $feature->sub_label === null ? null : (string) $feature->sub_label,
                'kind' => (string) $feature->kind,
                'cells' => $plans->map(fn (object $plan): array => $this->cell(
                    $feature,
                    $matrix->get($plan->id.':'.$feature->id),
                    (int) $plan->id === $topPlanId,
                ))->all(),
            ])->all(),
        ];
    }

    /**
     * The clients the line-items screen can be pointed at.
     *
     * Every estate, not only the billable ones: an estate mid-onboarding is
     * exactly when a negotiated addition gets recorded, and leaving it out of
     * the picker would mean the override could not be entered until after the
     * conversation that produced it.
     *
     * @return list<array{id: string, name: string}>
     */
    public function clients(): array
    {
        return DB::connection('mysql')
            ->table('tenants')
            ->orderBy('name')
            ->select('id', 'name')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
            ])
            ->all();
    }

    /**
     * Board 44: one client's monthly subscription, base plan and overrides.
     *
     * WHAT THIS IS NOT: an invoice. An invoice is what a client was billed for
     * a period that has closed, and it is already in `invoices` with its own
     * total. This is what they are billed going FORWARD — the thing the next
     * invoice will be generated from — which is why an override carries an
     * effective date and why ending one sets that date rather than deleting the
     * row. Editing history to change a future charge is the mistake the two
     * tables exist separately to prevent.
     *
     * Returns null for a client with no subscription: there is no base plan to
     * put overrides on top of, and a total of nought would be a claim rather
     * than an absence.
     *
     * @return array{client: string, baseHead: string, base: list<array{name: string, detail: string, badge: string, badgeLabel: string, price: string}>, additions: list<array<string, mixed>>, removals: list<array<string, mixed>>, total: string}|null
     */
    public function lineItems(string $tenantId): ?array
    {
        $estate = DB::connection('mysql')
            ->table('tenants')
            ->leftJoin('subscriptions', 'subscriptions.tenant_id', '=', 'tenants.id')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('tenants.id', $tenantId)
            ->select([
                'tenants.id',
                'tenants.name',
                'subscriptions.unit_count',
                'plans.id as plan_id',
                'plans.name as plan_name',
                'plans.price_per_unit_minor',
                'plans.currency',
            ])
            ->first();

        if ($estate === null || $estate->plan_id === null) {
            return null;
        }

        $currency = $this->currency($estate->currency);
        $units = (int) $estate->unit_count;
        $perUnit = (int) $estate->price_per_unit_minor;
        $subscriptionMinor = $units * $perUnit;

        $base = [[
            'name' => $estate->plan_name.' tier subscription',
            'detail' => number_format($units).' units × '.MoneyFormatter::whole($perUnit, $currency)
                .'/unit — includes '.$this->includedIn((int) $estate->plan_id),
            'badge' => 'base',
            'badgeLabel' => 'Base plan',
            'price' => MoneyFormatter::whole($subscriptionMinor, $currency).'/mo',
        ]];

        $totalMinor = $subscriptionMinor;

        /*
         * Guarding, billed per guard on top of the tier.
         *
         * Only drawn where this client actually has guards posted. An estate
         * that runs its own security buys the software alone, and a row reading
         * "0 guards × $4,500" would put a charge on the screen that is not on
         * the bill.
         */
        $guards = DB::connection('mysql')
            ->table('guards')
            ->where('tenant_id', $tenantId)
            // The same definition of "deployed" the client directory uses. Two
            // answers to one question on two screens about the same estate is
            // how a directory ends up contradicting a record.
            ->whereNotIn('status', ['on_leave', 'suspended'])
            ->count();

        foreach ($this->platformRates() as $rate) {
            if ($rate->basis !== 'guard' || $guards === 0) {
                continue;
            }

            $amount = $guards * (int) $rate->amount_minor;
            $totalMinor += $amount;

            $base[] = [
                'name' => (string) $rate->label,
                'detail' => $guards.' guards × '
                    .MoneyFormatter::whole((int) $rate->amount_minor, $this->currency($rate->currency)).'/mo',
                'badge' => 'base',
                'badgeLabel' => 'Base plan',
                'price' => MoneyFormatter::whole($amount, $currency).'/mo',
            ];
        }

        $overrides = DB::connection('mysql')
            ->table('subscription_line_items')
            ->leftJoin('users', 'users.id', '=', 'subscription_line_items.created_by_user_id')
            ->where('subscription_line_items.tenant_id', $tenantId)
            /*
             * In force today. An override that has been end-dated is history —
             * it belongs to the invoices it was billed on, not to what this
             * client pays next month — and one dated ahead of today has not
             * started. Both would inflate a total nobody is being charged.
             */
            ->whereDate('subscription_line_items.effective_from', '<=', Carbon::today())
            ->where(function ($query): void {
                $query->whereNull('subscription_line_items.effective_to')
                    ->orWhereDate('subscription_line_items.effective_to', '>=', Carbon::today());
            })
            ->orderBy('subscription_line_items.effective_from')
            ->select('subscription_line_items.*', 'users.name as author')
            ->get();

        $additions = [];
        $removals = [];

        foreach ($overrides as $override) {
            $amount = (int) $override->amount_minor;

            if ($override->type === 'removal') {
                $totalMinor -= $amount;
                $removals[] = $this->overrideRow($override, $currency, removal: true);

                continue;
            }

            $totalMinor += $amount;
            $additions[] = $this->overrideRow($override, $currency, removal: false);
        }

        return [
            'client' => (string) $estate->name,
            'baseHead' => 'Base plan — '.$estate->plan_name.', '.number_format($units).' units',
            'base' => $base,
            'additions' => $additions,
            'removals' => $removals,
            'total' => MoneyFormatter::whole($totalMinor, $currency).'/mo',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* pieces */
    /* ------------------------------------------------------------------ */

    /**
     * One override row, as the board draws it.
     *
     * @return array{name: string, detail: string, badge: string, badgeLabel: string, price: string}
     */
    private function overrideRow(object $override, string $currency, bool $removal): array
    {
        $amount = MoneyFormatter::whole(abs((int) $override->amount_minor), $currency).'/mo';

        return [
            'name' => (string) $override->description,
            'detail' => $this->overrideDetail($override, $removal),
            'badge' => $removal ? 'removed' : 'added',
            'badgeLabel' => $removal ? '− Removed' : '+ Added',
            // The sign is the board's, and it is doing work: a row under
            // "Custom removals" that read "$0/mo" would look like a charge of
            // nought rather than an amount coming off the bill.
            'price' => ($removal ? '−' : '').$amount,
        ];
    }

    /**
     * The small second line on an override row.
     *
     * Assembled from what is recorded rather than stored as a sentence, so a
     * feature that later moves down a tier stops claiming to be above one.
     */
    private function overrideDetail(object $override, bool $removal): string
    {
        $when = Carbon::parse((string) $override->effective_from);

        if ($removal) {
            return implode(' · ', array_filter([
                trim($this->tierNote($override, removal: true).', but '
                    .lcfirst((string) ($override->reason ?? 'removed'))),
                $when->format('M Y'),
            ]));
        }

        /*
         * "Normally Premium-only · added Sep 2 by Andrea Case ahead of their
         * upgrade conversation" — where the feature normally sits, then who put
         * it here, when, and why. The author is named because a commercial
         * override with no author is not something anyone can review later.
         */
        return implode(' · ', array_filter([
            $this->tierNote($override, removal: false),
            trim('added '.$when->format('M j')
                .($override->author === null ? '' : ' by '.$override->author)
                .' '.lcfirst((string) ($override->reason ?? ''))),
        ]));
    }

    /**
     * Where this feature normally sits, read off the package template.
     *
     * Derived rather than written down, because "Premium-only" stops being true
     * the moment someone adds the feature to Standard, and a stored sentence
     * would go on saying it.
     */
    private function tierNote(object $override, bool $removal): string
    {
        if ($override->package_feature_id === null) {
            return $removal ? 'Included in the base plan' : 'Not part of any package';
        }

        $plans = DB::connection('mysql')
            ->table('plan_features')
            ->join('plans', 'plans.id', '=', 'plan_features.plan_id')
            ->where('plan_features.package_feature_id', $override->package_feature_id)
            ->where('plan_features.included', true)
            ->where('plans.is_active', true)
            ->orderBy('plans.sort')
            ->pluck('plans.name');

        if ($plans->isEmpty()) {
            return $removal ? 'Included in the base plan' : 'Not part of any package';
        }

        if ($removal) {
            return 'Included in '.$plans->first();
        }

        return $plans->count() === 1
            ? 'Normally '.$plans->first().'-only'
            : 'Normally '.$plans->first().' and above';
    }

    /**
     * "Guard App integration, e-Voting / elections, …" — what this tier adds.
     *
     * The core features are left out on purpose. Every package includes them,
     * so naming them here would pad the line with the four things that are true
     * of every client on every tier and bury the four that are not.
     */
    private function includedIn(int $planId): string
    {
        $names = DB::connection('mysql')
            ->table('plan_features')
            ->join('package_features', 'package_features.id', '=', 'plan_features.package_feature_id')
            ->where('plan_features.plan_id', $planId)
            ->where('plan_features.included', true)
            ->where('package_features.is_core', false)
            ->where('package_features.kind', 'toggle')
            ->orderBy('package_features.sort')
            ->pluck('package_features.label');

        return $names->isEmpty() ? 'the core resident features only' : $names->implode(', ');
    }

    /**
     * One cell of the package grid.
     *
     * @return array{variant: string, value: string|null, emphasised: bool}
     */
    private function cell(object $feature, ?object $row, bool $emphasised): array
    {
        if ($feature->kind === 'tier_value') {
            return [
                'variant' => 'value',
                'value' => $row?->tier_value === null ? '—' : (string) $row->tier_value,
                'emphasised' => $emphasised,
            ];
        }

        /*
         * `locked` is not "on, but darker".
         *
         * A core feature is in every package and no package may drop it, so the
         * cell is drawn as a fact rather than as a setting that happens to be
         * switched on. Rendering it as an ordinary tick would invite someone to
         * reach for the switch that must not exist.
         */
        if ($feature->is_core) {
            return ['variant' => 'locked', 'value' => null, 'emphasised' => $emphasised];
        }

        return [
            'variant' => $row !== null && (bool) $row->included ? 'on' : 'off',
            'value' => null,
            'emphasised' => $emphasised,
        ];
    }

    /**
     * What a role can reach, in one short phrase.
     *
     * Read from role_module_access rather than from the role's name, so a
     * matrix change is reflected here without anyone remembering to edit a
     * label. A role holding Full on every module with no narrowed scope is the
     * only one that gets to be called full access.
     */
    private function accessSummary(int $roleId, int $moduleCount): string
    {
        $cells = DB::connection('mysql')
            ->table('role_module_access')
            ->where('role_id', $roleId)
            ->get();

        $visible = $cells->filter(
            fn (object $cell): bool => AccessLevel::from((string) $cell->level)->isVisible()
        );

        $narrowed = $cells->contains(
            fn (object $cell): bool => AccessScope::from((string) $cell->scope) !== AccessScope::All
        );

        $full = $visible->count() === $moduleCount
            && $cells->every(fn (object $cell): bool => $cell->level === AccessLevel::Full->value);

        if ($full && ! $narrowed) {
            return 'Full access';
        }

        return $visible->count().' of '.$moduleCount.' modules'.($narrowed ? ', assigned sites' : '');
    }

    /** @return Collection<int, \stdClass> */
    private function platformRates(): Collection
    {
        return DB::connection('mysql')
            ->table('platform_rates')
            ->where('is_active', true)
            ->orderBy('sort')
            ->get();
    }

    /** "Applies to 2 clients", and the two cases either side of it. */
    private function clientCount(int $clients): string
    {
        return match (true) {
            $clients === 0 => 'Not in use by any client',
            $clients === 1 => 'Applies to 1 client',
            default => 'Applies to '.$clients.' clients',
        };
    }

    private function currency(mixed $currency): string
    {
        return is_string($currency) && $currency !== '' ? $currency : MoneyFormatter::DEFAULT_CURRENCY;
    }

    /** Two characters, uppercase, as the board draws an avatar. */
    private function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => strtoupper($part[0]))
            ->implode('');
    }
}
