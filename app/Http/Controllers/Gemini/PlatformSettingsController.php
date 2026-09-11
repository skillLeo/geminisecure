<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Enums\AccessLevel;
use App\Enums\AccessScope;
use App\Enums\Console;
use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Services\Gemini\PlatformSettings;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Platform settings — board screens super-admin-42 to 45.
 *
 * Four screens behind one tab strip:
 *
 *   42  Pricing & rates       the rate card, and who holds a platform account
 *   43  Package builder       which tier includes which feature
 *   44  Client line items     one client's additions and removals
 *   45  Role access matrix    which role can reach which module
 *
 * `gemini.platform_settings` is screen 42, the module's own landing screen. It
 * used to serve /settings/roles, which put the sidebar's Platform settings link
 * on the role matrix and left the module without a front door — and, because
 * the fidelity harness resolves boards by route name, made every measurement of
 * board 42 a measurement of a different screen.
 *
 * EVERY SCREEN IN THIS MODULE IS READ ONLY, and each for the same reason. A
 * tier price re-prices every estate on it. A package row changes what every
 * current and future client on that tier receives. A permission cell changes
 * who can reach what across the whole platform. Those are privileged, audited
 * writes; they do not get built as a side effect of the screens that display
 * them. The controls the boards draw are rendered visibly inert, each carrying
 * the reason, rather than being omitted or — worse — left live and silent.
 */
class PlatformSettingsController extends Controller
{
    /**
     * Why the write controls on these screens are inert.
     *
     * Stated per screen rather than shared, because "not built yet" is not the
     * whole reason and a reader hovering a disabled Save deserves to know which
     * of the two it is.
     */
    /**
     * Why a reader without the verb is refused, on all three screens.
     *
     * These are privileged, audited writes — a tier price re-prices every
     * client on it, a package row changes what every current and future client
     * on that tier receives, and an override changes one client's invoice. The
     * matrix gives `configure` to the roles that may make them; everybody else
     * reads. This is one sentence because it is one refusal.
     */
    private const NO_PLATFORM_WRITE = 'Changing what the platform charges or what a tier includes re-prices or '
        .'re-provisions real clients, so it needs Platform settings configure access. You are able to read these screens.';

    public function index(Request $request, PlatformSettings $settings): Response
    {
        /*
         * A DATED CHANGE THAT HAS COME DUE IS APPLIED ON READ, and deliberately
         * here: a pending change that silently never applied would be a price
         * the platform believes it charges and does not, and reading the rate
         * card is the moment somebody would notice.
         */
        $settings->applyDuePriceChanges();

        return inertia('Gemini/Settings/Index', [
            'tabs' => $this->tabs('gemini.platform_settings'),
            'rates' => $settings->rateCard(),
            'plans' => $settings->editablePlans(),
            'pending' => $settings->pendingPriceChanges(),
            'admins' => $settings->administrators(),
            // Tells an empty rate card WHY it is empty: everything retired, or
            // nothing ever priced. Two different screens, and a row count of
            // nought cannot tell them apart.
            'hasRetired' => $settings->hasRetiredPricing(),
            'canWrite' => $request->user()->can('gemini.platform_settings.configure'),
            'saveDisabledReason' => self::NO_PLATFORM_WRITE,
        ]);
    }

    /** Change a tier price, from a date, with a reason (12 §2, Wave 4). */
    public function changePrice(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'effective_from' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $result = $settings->changePlanPrice(
                (int) $data['plan_id'],
                (string) $data['amount'],
                (string) $data['effective_from'],
                (string) $data['reason'],
                $request->user(),
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['amount' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to('/settings')
            ->with('success', $result['applied']
                ? 'Price changed. '.$result['clients'].' client(s) on that tier are re-priced from today.'
                : 'Price change recorded. It takes effect on its date and re-prices '.$result['clients'].' client(s) then; nothing has changed yet.');
    }

    public function packages(Request $request, PlatformSettings $settings): Response
    {
        return inertia('Gemini/Settings/Packages', [
            'tabs' => $this->tabs('gemini.platform_settings.packages'),
            ...$settings->packageTemplate(),
            'canWrite' => $request->user()->can('gemini.platform_settings.configure'),
            'saveDisabledReason' => self::NO_PLATFORM_WRITE,
        ]);
    }

    /**
     * Turn a package feature on or off for a tier.
     *
     * IT TAKES EFFECT AT ONCE and the screen says so. Unlike a price, a
     * capability is not something a client is invoiced for on a date, and
     * giving it an effective date would be a date that meant nothing.
     */
    public function savePackages(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'changes' => ['required', 'array', 'max:200'],
            'changes.*.plan_id' => ['required', 'integer'],
            'changes.*.feature_id' => ['required', 'integer'],
            'changes.*.included' => ['required', 'boolean'],
        ]);

        try {
            foreach ($data['changes'] as $change) {
                $settings->setPackageFeature(
                    (int) $change['plan_id'],
                    (int) $change['feature_id'],
                    (bool) $change['included'],
                    $request->user(),
                );
            }
        } catch (DomainException $refused) {
            return back()->withErrors(['changes' => $refused->getMessage()]);
        }

        return redirect()
            ->to('/settings/packages')
            ->with('success', count($data['changes']).' change(s) saved. Every current and future client on those tiers is affected from now.');
    }

    /** Add a dated override to one client's subscription. */
    public function addLineItem(Request $request, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'client' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:300'],
            'type' => ['required', 'string', 'in:addition,removal'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'effective_from' => ['required', 'date'],
        ]);

        try {
            $settings->addLineItem($data['client'], $data, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['name' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to('/settings/line-items?client='.$data['client'])
            ->with('success', $data['name'].' added from '.$data['effective_from'].'. It is on the next invoice raised on or after that date.');
    }

    /** End an override, from a date. Ended, never deleted. */
    public function endLineItem(Request $request, int $item, PlatformSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'client' => ['required', 'string', 'max:64'],
            'effective_to' => ['required', 'date'],
        ]);

        try {
            $settings->endLineItem($item, (string) $data['effective_to'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['effective_to' => $refused->getMessage()]);
        }

        return redirect()
            ->to('/settings/line-items?client='.$data['client'])
            ->with('success', 'Override ended. It stays on the record, because the invoices it was billed on would otherwise be unexplainable.');
    }

    public function lineItems(Request $request, PlatformSettings $settings): Response
    {
        $clients = $settings->clients();

        /*
         * Which client is being priced.
         *
         * Narrowed against the clients that exist rather than validated: this
         * parameter selects a view, and a stale or hand-edited `?client=`
         * should land on the first client rather than bounce the reader back
         * with an error over an estate id. The narrowing is also the safety —
         * the value reaches a WHERE clause, so it may only ever be one of a
         * known set.
         */
        $requested = $request->query('client');
        $ids = array_column($clients, 'id');

        $selected = is_string($requested) && in_array($requested, $ids, true)
            ? $requested
            : ($ids[0] ?? null);

        return inertia('Gemini/Settings/LineItems', [
            'tabs' => $this->tabs('gemini.platform_settings.line_items'),
            'clients' => array_map(
                fn (array $client): array => [
                    ...$client,
                    'href' => route('gemini.platform_settings.line_items', ['client' => $client['id']], absolute: false),
                    'active' => $client['id'] === $selected,
                ],
                $clients,
            ),
            'selected' => $selected,
            'billing' => $selected === null ? null : $settings->lineItems($selected),
            'canWrite' => $request->user()->can('gemini.platform_settings.configure'),
            'writeDisabledReason' => self::NO_PLATFORM_WRITE,
        ]);
    }

    /**
     * Screen super-admin-45, the Role Access Matrix.
     *
     * The grid is not a report about permissions. It is the permission tables
     * printed: roles, modules and role_module_access, the same three the
     * navigation is generated from and the same three the `can:` middleware
     * resolves against. Nothing here recomputes an access decision, so the
     * screen cannot say one thing while the application does another.
     *
     * The board draws the Gemini Console's six roles. The Estate Console's
     * matrix is its own board on its own console and is not reproduced here.
     */
    public function roleMatrix(): Response
    {
        $roles = Role::where('console', Console::Gemini->value)->orderBy('sort')->get();
        $modules = Module::where('console', Console::Gemini->value)->orderBy('sort')->get();

        /*
         * One query for every cell, keyed on the pair.
         *
         * The alternative — a relation load per module — is 9 queries that ask
         * the same table the same question, and the grid has to be walked in
         * role order anyway to line the columns up with the header.
         */
        $cells = RoleModuleAccess::whereIn('role_id', $roles->pluck('id'))
            ->get()
            ->keyBy(fn (RoleModuleAccess $access): string => $access->role_id.':'.$access->module_id);

        return inertia('Gemini/Settings/RoleMatrix', [
            'tabs' => $this->tabs('gemini.platform_settings.roles'),
            'roles' => $roles->map(fn (Role $role): array => [
                'id' => $role->id,
                'label' => $role->label ?? $role->name,
                'scope' => $role->scope_default->label(),
            ])->all(),
            'modules' => $modules->map(fn (Module $module): array => [
                'id' => $module->id,
                'label' => $module->label,
                'cells' => $roles
                    ->map(fn (Role $role): array => $this->cell($cells->get($role->id.':'.$module->id)))
                    ->all(),
            ])->all(),
        ]);
    }

    /**
     * One pill, read straight off the matrix row.
     *
     * @return array{variant: string, label: string}
     */
    private function cell(?RoleModuleAccess $access): array
    {
        /*
         * A missing row is not "no access".
         *
         * The matrix is seeded complete, so a gap means the seed is wrong.
         * Printing an em dash there would hide a broken seed behind a cell
         * that reads as a deliberate denial; `php artisan rbac:show` prints
         * the same question mark for the same reason.
         */
        if ($access === null) {
            return ['variant' => 'none', 'label' => '?'];
        }

        /*
         * "Scoped" is not a fourth level.
         *
         * AccessScope defines it as Full narrowed by reach (D-007 keeps the
         * two axes orthogonal), and that definition is the only rule applied
         * here. Head of Security is the only Gemini role whose rows carry a
         * narrowed scope, which is why it is the only column that shows it.
         */
        $narrowed = $access->level === AccessLevel::Full && $access->scope !== AccessScope::All;

        $label = $narrowed ? 'Scoped' : $access->level->label();

        // From can_approve, never from the level: Full without the Approver tag
        // must not read as permission to commit the irreversible act (D-008).
        if ($access->can_approve) {
            $label .= ' · Approver';
        }

        /*
         * The board defines four pill variants — full, view, scoped, none.
         *
         * Entry, which only the Estate matrix uses, has no variant on this
         * board, so it shares View's plain navy pill and the word in the pill
         * carries the difference. Inventing a fifth variant would mean
         * authoring CSS the design does not have.
         */
        $variant = match (true) {
            $access->level === AccessLevel::None => 'none',
            $narrowed => 'scoped',
            $access->level === AccessLevel::Full => 'full',
            default => 'view',
        };

        return ['variant' => $variant, 'label' => $label];
    }

    /**
     * The Platform settings tab strip, in the order the board draws it.
     *
     * The four built screens carry a route; the three that are not carry the
     * reason they cannot be opened. A tab is never dropped — the board draws
     * seven, and a strip that silently loses one tells the reader the module is
     * smaller than it is.
     *
     * @return list<array{label: string, href: string|null, active: bool, reason: string|null}>
     */
    private function tabs(string $active): array
    {
        $tabs = [
            ['label' => 'Pricing & rates', 'route' => 'gemini.platform_settings', 'reason' => null],
            ['label' => 'Package builder', 'route' => 'gemini.platform_settings.packages', 'reason' => null],
            ['label' => 'Client line items', 'route' => 'gemini.platform_settings.line_items', 'reason' => null],
            [
                'label' => 'Platform admins',
                'route' => null,
                'reason' => 'Not built yet. Who holds a platform account is listed on Pricing & rates; '
                    .'issuing and withdrawing one is a privileged write with no screen of its own yet.',
            ],
            ['label' => 'Role access matrix', 'route' => 'gemini.platform_settings.roles', 'reason' => null],
            [
                'label' => 'Statutory rates',
                'route' => null,
                // Deliberately not a link to /payroll/rates. That screen belongs
                // to Payroll & Accounting, a different module with a different
                // permission, and a role holding Platform settings need not hold
                // it — a live tab here would send some of them to a 403.
                'reason' => 'Statutory rates are held under Payroll & Accounting, which is a separate module '
                    ."with a separate permission. They follow TAJ's published tables, as ruled on Q-002.",
            ],
            [
                'label' => 'Notifications',
                'route' => null,
                'reason' => 'Not built yet. No notification defaults are recorded centrally.',
            ],
        ];

        return array_map(
            fn (array $tab): array => [
                'label' => $tab['label'],
                'href' => $tab['route'] === null ? null : route($tab['route'], absolute: false),
                'active' => $tab['route'] === $active,
                'reason' => $tab['reason'],
            ],
            $tabs,
        );
    }
}
