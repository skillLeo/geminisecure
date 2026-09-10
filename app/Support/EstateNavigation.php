<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * The Estate Console sidebar, exactly as every board draws it.
 *
 * TEN ITEMS IN FOUR GROUPS, and the boards are the contract: Dashboard on its
 * own, then Estate, Finance, Community and System. That list is not the same as
 * the permission module list and was never meant to be — D-010 split
 * `accounting` into `accounting_posting` and `vendor_costs`, and `dues_ledger`
 * away from `maintenance_budget`, precisely so a Property Manager could be
 * refused the money and still see what they commission. Those splits are about
 * WHO MAY DO WHAT. This is about what the navigation says, and one nav item can
 * sit on more than one of them.
 *
 * A ROLE THAT HOLDS NONE OF AN ITEM'S MODULES DOES NOT SEE IT. Not disabled,
 * not greyed — absent. The Build Spec is explicit: "A role without a module
 * permission does not see that module at all." A Property Manager opening this
 * console finds no Dues & ledger and no Accounting, which is the separation
 * working rather than a fault to explain.
 */
class EstateNavigation
{
    /**
     * The sidebar, in the board's own order.
     *
     * `modules` is an ANY-OF: an item appears when the viewer holds view on at
     * least one of them. "Accounting" covers posting and vendor costs because
     * the board draws one item over both, and a role with only vendor costs —
     * the Property Manager — still needs somewhere to reach them.
     *
     * EVERY ITEM NOW HAS A DESTINATION, AND THAT IS AN INVARIANT RATHER THAN A
     * COINCIDENCE. This list carried a nullable href through the build — a
     * module with no screens yet drew greyed and inert, so a role could still
     * check what it would reach — and the one thing that had to be kept honest
     * was flipping the href in the commit that landed the module's first screen.
     * It was missed twice, for Accounting and for Facilities, and the console
     * told a manager a module was missing while nine of its pages sat one click
     * away.
     *
     * Reports was the tenth and last, so the nullable case is now unreachable
     * and is gone rather than kept as dead code somebody would have to reason
     * about. A new module arrives with its first screen or it does not arrive:
     * `EstateNavigationTest` asserts every item a role can see has somewhere to
     * go, which is a live assertion where `pending` had become a vestige.
     * D-070.
     *
     * @var list<array{key: string, label: string, icon: string, section: string|null, href: string, modules: list<string>}>
     */
    private const ITEMS = [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard', 'section' => null, 'href' => '/', 'modules' => ['dashboard']],

        ['key' => 'estate_structure', 'label' => 'Estate structure', 'icon' => 'estate', 'section' => 'Estate', 'href' => '/estate', 'modules' => ['estate_structure']],
        ['key' => 'residents', 'label' => 'Residents', 'icon' => 'residents', 'section' => 'Estate', 'href' => '/residents', 'modules' => ['residents']],

        ['key' => 'dues_ledger', 'label' => 'Dues & ledger', 'icon' => 'dues', 'section' => 'Finance', 'href' => '/finance/arrears', 'modules' => ['dues_ledger', 'payments']],
        ['key' => 'accounting', 'label' => 'Accounting', 'icon' => 'accounting', 'section' => 'Finance', 'href' => '/accounting/chart-of-accounts', 'modules' => ['accounting_posting', 'vendor_costs']],
        ['key' => 'payroll', 'label' => 'Payroll & HR', 'icon' => 'payroll', 'section' => 'Finance', 'href' => '/payroll', 'modules' => ['payroll']],

        ['key' => 'facilities', 'label' => 'Facilities', 'icon' => 'facilities', 'section' => 'Community', 'href' => '/facilities/maintenance', 'modules' => ['facilities', 'maintenance_budget']],
        /*
         * THE MEETING REGISTER, NOT THE ELECTION, and the boards themselves are
         * why. Board 9's caption reads "Sidebar → Governance → Elections", but an
         * election is addressed by YEAR — /governance/elections/2026 — and a
         * constant here would still be pointing at 2026 in 2028, when there is no
         * such ballot to draw. The register is the one governance screen with a
         * static address, it is never empty for an estate that has ever met, and
         * its own sub-navigation reaches Elections in one more click.
         */
        ['key' => 'governance', 'label' => 'Governance', 'icon' => 'governance', 'section' => 'Community', 'href' => '/governance/meetings', 'modules' => ['governance']],
        ['key' => 'reports', 'label' => 'Reports', 'icon' => 'reports', 'section' => 'Community', 'href' => '/reports', 'modules' => ['reports']],

        ['key' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'section' => 'System', 'href' => '/settings/profile', 'modules' => ['settings']],
    ];

    /**
     * What this viewer's sidebar holds.
     *
     * @param  string  $active  the key of the item the current screen sits under
     * @return list<array{key: string, label: string, icon: string, section: string|null, href: string, active: bool}>
     */
    public function forViewer(User $viewer, string $active = '', string $tenantKey = ''): array
    {
        $items = [];

        foreach (self::ITEMS as $item) {
            if (! $this->mayReach($viewer, $item['modules'])) {
                continue;
            }

            $items[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'icon' => $item['icon'],
                'section' => $item['section'],
                'href' => $this->path($item['href'], $tenantKey),
                'active' => $item['key'] === $active,
            ];
        }

        return $items;
    }

    /**
     * The estate path, in whichever shape this environment serves.
     *
     * Production gives each estate its own hostname, so the path is bare.
     * Local serves them all from one host with the estate in the path, because
     * *.localhost does not resolve on Windows — see routes/tenant.php.
     */
    private function path(string $href, string $tenantKey): string
    {
        if (! app()->isLocal() || $tenantKey === '') {
            return $href;
        }

        return rtrim('/estate/'.$tenantKey.rtrim($href, '/'), '/') ?: '/estate/'.$tenantKey;
    }

    /**
     * @param  list<string>  $modules
     */
    private function mayReach(User $viewer, array $modules): bool
    {
        foreach ($modules as $module) {
            if ($viewer->can('estate.'.$module.'.view')) {
                return true;
            }
        }

        return false;
    }
}
