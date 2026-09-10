<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccessLevel;
use App\Enums\AccessScope;
use App\Enums\Console;
use App\Enums\PermissionVerb;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the role access matrix, parsed from the approved wireframes.
 *
 *   Gemini  — Super Admin "09 Platform Settings.html", screen 45, lines 1066-1152
 *   Estate  — Community Admin "06 Settings ... and Roles.html", screen 24, lines 581-685
 *
 * Cell notation matches the wireframe pills exactly:
 *   F   Full                 A   Full · Approver
 *   S   Scoped               V   View
 *   E   Entry                -   no access
 *
 * Idempotent: re-running updates cells in place rather than duplicating them.
 */
class RbacMatrixSeeder extends Seeder
{
    /** Wireframe pill -> [level, can_approve, scope] */
    private const CELLS = [
        'F' => [AccessLevel::Full, false, AccessScope::All],
        'A' => [AccessLevel::Full, true, AccessScope::All],
        'S' => [AccessLevel::Full, false, AccessScope::AssignedSites],
        'V' => [AccessLevel::View, false, AccessScope::All],
        'E' => [AccessLevel::Entry, false, AccessScope::All],
        '-' => [AccessLevel::None, false, AccessScope::All],
    ];

    /**
     * [key, label, section, sort, is_locked_financial]
     *
     * Order and grouping are the sidebar's, verbatim from the approved
     * wireframe: Dashboard sits above both headings, then Platform, then
     * System.
     */
    private const GEMINI_MODULES = [
        ['dashboard', 'Dashboard', null, 10, false],
        ['clients', 'Clients', 'Platform', 20, false],
        ['dispatch', 'Dispatch', 'Platform', 25, false],
        ['guard_workforce', 'Guard workforce', 'Platform', 30, false],
        ['payroll_accounting', 'Payroll & Accounting', 'Platform', 40, false],
        ['billing_subscriptions', 'Billing & subscriptions', 'Platform', 50, false],
        ['cross_tenant_reports', 'Cross-tenant reports', 'Platform', 60, false],
        ['access_audit_log', 'Access & audit log', 'System', 70, false],
        ['platform_settings', 'Platform settings', 'System', 80, false],
    ];

    /**
     * Estate modules, after the Ruling 1 split (D-010).
     *
     * `accounting` became accounting_posting + vendor_costs, and
     * maintenance_budget was split away from dues_ledger, so that the
     * Property Manager can see costs on work they commissioned without ever
     * seeing a resident's financial position.
     */
    private const ESTATE_MODULES = [
        ['dashboard', 'Dashboard', null, 10, false],
        ['estate_structure', 'Estate structure', 'Community', 20, false],
        ['residents', 'Residents', 'Community', 30, false],
        ['dues_ledger', 'Dues & ledger', 'Money', 40, true],
        ['payments', 'Payments', 'Money', 50, true],
        ['accounting_posting', 'Accounting', 'Money', 60, true],
        ['vendor_costs', 'Vendor costs', 'Money', 70, false],
        ['maintenance_budget', 'Maintenance budget', 'Money', 80, false],
        ['payroll', 'Payroll & HR', 'Money', 90, true],
        ['facilities', 'Facilities', 'Community', 100, false],
        ['governance', 'Governance', 'Community', 110, false],
        ['reports', 'Reports', 'System', 120, false],
        ['settings', 'Settings', 'System', 130, false],
    ];

    /** [name, label, sort, scope_default] */
    private const GEMINI_ROLES = [
        [Role::DIRECTOR, 'Director', 10, AccessScope::All],
        [Role::OPERATIONS_MANAGER, 'Operations Manager', 20, AccessScope::All],
        [Role::HEAD_OF_SECURITY, 'Head of Security', 30, AccessScope::AssignedSites],
        [Role::DISPATCHER, 'Dispatcher', 40, AccessScope::All],
        [Role::ADMIN_ASSISTANT, 'Admin Assistant', 50, AccessScope::All],
        [Role::ACCOUNTANT, 'Accountant', 60, AccessScope::All],
    ];

    private const ESTATE_ROLES = [
        [Role::COMMUNITY_SUPER_ADMIN, 'Community Super Admin', 5, AccessScope::All],
        [Role::PRESIDENT, 'President', 10, AccessScope::All],
        [Role::VICE_PRESIDENT, 'Vice President', 20, AccessScope::All],
        [Role::SECRETARY, 'Secretary', 30, AccessScope::All],
        [Role::PROPERTY_MANAGER, 'Property Manager', 40, AccessScope::All],
        [Role::TREASURER, 'Treasurer', 50, AccessScope::All],
        [Role::ESTATE_ADMIN_ASSISTANT, 'Admin Assistant', 60, AccessScope::All],
    ];

    /**
     * Gemini grid, verbatim from the wireframe.
     * Columns: Director, Ops Manager, Head of Security, Dispatcher, Admin Asst, Accountant
     */
    private const GEMINI_GRID = [
        'dashboard' => ['F', 'F', 'F', 'F', 'F', 'F'],
        'clients' => ['F', 'F', 'S', 'V', 'V', 'V'],
        // DERIVED (D-018). Dispatch appears in the approved sidebar but has no
        // row in the matrix screen. Derived from guard_workforce, its nearest
        // operational analogue, with the Accountant narrowed to no access:
        // where the sources are silent, take the more restrictive reading.
        'dispatch' => ['F', 'F', 'S', 'F', 'V', '-'],
        'guard_workforce' => ['F', 'F', 'S', 'F', 'V', 'V'],
        'payroll_accounting' => ['F', 'V', '-', '-', '-', 'F'],
        'billing_subscriptions' => ['F', 'V', '-', '-', '-', 'F'],
        'cross_tenant_reports' => ['F', 'F', 'S', '-', '-', 'V'],
        'access_audit_log' => ['F', 'V', '-', '-', '-', '-'],
        'platform_settings' => ['F', '-', '-', '-', '-', '-'],
    ];

    /**
     * Estate grid.
     * Columns: Community Super Admin, President, VP, Secretary, Property Manager, Treasurer, Admin Asst
     *
     * The Community Super Admin column is not drawn in the wireframe — the
     * audit note describes it as full access within its own estate (D-009).
     *
     * Rows marked DERIVED did not exist as separate rows in the wireframe and
     * come from the Ruling 1 split; see D-014 for how each was derived.
     */
    private const ESTATE_GRID = [
        'dashboard' => ['F', 'F', 'F', 'F', 'F', 'F', 'V'],
        'estate_structure' => ['F', 'V', 'V', 'V', 'F', '-', '-'],
        /*
         * DERIVED APPROVER CELLS (D-053). Board 24 draws this row with no
         * Approver tag anywhere, because when it was drawn nothing in Residents
         * had an irreversible act in it. Board 31 then gave it one: approving a
         * unit claim binds a person to a household, which decides whose guest
         * passes they may issue and whose gate they may be admitted at, and no
         * edit afterwards unbinds the night somebody was let through.
         *
         * The tag goes to the two roles that already hold Full AND are the
         * screen's own reviewers — the Community Super Admin, who holds
         * everything within its estate (D-009), and the Property Manager, whose
         * name is in board 31's sidebar footer. The SECRETARY KEEPS PLAIN FULL
         * deliberately: they may prepare the decision, refuse a claim and ask
         * for a document, and may not commit the one act that cannot be walked
         * back. That is exactly the separation D-013 and D-008 exist for, and
         * `EstateResidentAccessTest` proves the Secretary is refused.
         */
        'residents' => ['A', 'V', 'V', 'F', 'A', 'V', 'E'],
        'dues_ledger' => ['F', 'V', 'V', '-', '-', 'A', 'E'],  // PM '-' : LOCKED by Ruling 1
        'payments' => ['F', 'V', 'V', '-', '-', 'A', 'E'],      // DERIVED
        'accounting_posting' => ['F', 'V', 'V', '-', '-', 'A', 'E'], // PM '-' : LOCKED
        'vendor_costs' => ['F', 'V', 'V', '-', 'V', 'A', 'E'],  // DERIVED, PM 'V' permitted
        'maintenance_budget' => ['F', 'V', 'V', '-', 'V', 'A', 'E'], // DERIVED, PM 'V' permitted
        /*
         * A DERIVED APPROVER CELL, the same way Residents got two (D-053).
         *
         * Board 24 draws this row with Full for the Community Super Admin and
         * the Treasurer, View for the two officers, and nothing else — and no
         * Approver tag anywhere. Board 15 then requires one in its own banner:
         * "Prepared by Tracey Reid — awaiting your approval. As a second
         * approver, this run cannot be disbursed until you review and approve
         * it." Disbursing is irreversible; the money leaves the bank.
         *
         * Read literally, the drawn row makes that flow unreachable. The
         * Treasurer prepares, and `Payroll::approvalRefusal()` refuses to let a
         * preparer approve their own run — so the only cell that can hold the
         * approval is the Community Super Admin's, and it is promoted from Full
         * to Full · Approver. The Treasurer keeps plain Full and prepares, which
         * is exactly the separation board 15 describes.
         */
        'payroll' => ['A', 'V', 'V', '-', '-', 'F', '-'],
        'facilities' => ['F', 'V', 'V', 'V', 'F', 'V', 'E'],
        'governance' => ['F', 'A', 'A', 'F', '-', 'V', '-'],
        'reports' => ['F', 'F', 'F', 'V', 'V', 'F', '-'],
        'settings' => ['F', 'V', 'V', '-', '-', '-', '-'],
    ];

    public function run(): void
    {
        DB::connection('mysql')->transaction(function () {
            $this->seedPermissions();

            $gemini = $this->seedModules(Console::Gemini, self::GEMINI_MODULES);
            $estate = $this->seedModules(Console::Estate, self::ESTATE_MODULES);

            $geminiRoles = $this->seedRoles(Console::Gemini, self::GEMINI_ROLES);
            $estateRoles = $this->seedRoles(Console::Estate, self::ESTATE_ROLES);

            $this->seedGrid(self::GEMINI_GRID, $gemini, $geminiRoles);
            $this->seedGrid(self::ESTATE_GRID, $estate, $estateRoles);

            $this->syncSpatiePermissions();
        });
    }

    /**
     * One permission per console.module.verb.
     *
     * The console segment matters: both consoles have a Dashboard, and without
     * it granting a Gemini role sight of its dashboard would silently grant
     * every estate role sight of theirs.
     */
    private function seedPermissions(): void
    {
        $sets = [
            Console::Gemini->value => array_column(self::GEMINI_MODULES, 0),
            Console::Estate->value => array_column(self::ESTATE_MODULES, 0),
        ];

        foreach ($sets as $console => $keys) {
            foreach ($keys as $moduleKey) {
                foreach (PermissionVerb::cases() as $verb) {
                    Permission::findOrCreate("{$console}.{$moduleKey}.{$verb->value}", 'web');
                }
            }
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string|null, 3: int, 4: bool}>  $definitions
     * @return array<string, Module> keyed by module key
     */
    private function seedModules(Console $console, array $definitions): array
    {
        $out = [];

        foreach ($definitions as [$key, $label, $section, $sort, $locked]) {
            $out[$key] = Module::updateOrCreate(
                ['key' => $key, 'console' => $console->value],
                [
                    'label' => $label,
                    'section' => $section,
                    'sort' => $sort,
                    'is_locked_financial' => $locked,
                ],
            );
        }

        return $out;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: AccessScope}>  $definitions
     * @return list<Role> in column order
     */
    private function seedRoles(Console $console, array $definitions): array
    {
        $out = [];

        foreach ($definitions as [$name, $label, $sort, $scope]) {
            // findOrCreate returns spatie's Contracts\Role, which knows
            // nothing about console, label or scope_default. named() narrows
            // it back to this application's Role.
            Role::findOrCreate($name, 'web');
            $role = Role::named($name);

            $role->forceFill([
                'console' => $console->value,
                'label' => $label,
                'sort' => $sort,
                'scope_default' => $scope->value,
            ])->save();

            $out[] = $role;
        }

        return $out;
    }

    /**
     * @param  array<string, array<int, string>>  $grid
     * @param  array<string, Module>  $modules
     * @param  array<int, Role>  $roles
     */
    private function seedGrid(array $grid, array $modules, array $roles): void
    {
        foreach ($grid as $moduleKey => $row) {
            $module = $modules[$moduleKey];

            foreach ($row as $i => $pill) {
                [$level, $canApprove, $scope] = self::CELLS[$pill];
                $role = $roles[$i];

                /*
                 * Ruling 1 is enforced here, not merely represented in data.
                 * If a future edit to the grid above granted the Property
                 * Manager a locked financial module, this would refuse it
                 * rather than seeding a breach.
                 */
                if ($module->isLockedFor($role) && $level !== AccessLevel::None) {
                    throw new \LogicException(
                        "Refusing to grant {$role->name} '{$level->value}' on locked financial module ".
                        "'{$module->key}'. See DECISIONS.md D-010."
                    );
                }

                // Head of Security carries its scope on every cell it holds,
                // not only the ones the wireframe drew as Scoped.
                if ($role->scope_default === AccessScope::AssignedSites && $level !== AccessLevel::None) {
                    $scope = AccessScope::AssignedSites;
                }

                RoleModuleAccess::updateOrCreate(
                    ['role_id' => $role->id, 'module_id' => $module->id],
                    ['level' => $level->value, 'can_approve' => $canApprove, 'scope' => $scope->value],
                );
            }
        }
    }

    /** Project the matrix onto spatie permissions so can() works. */
    private function syncSpatiePermissions(): void
    {
        foreach (Role::with('moduleAccess.module')->get() as $role) {
            $names = $role->moduleAccess
                ->flatMap(fn (RoleModuleAccess $a) => $a->permissionNames())
                ->unique()
                ->values()
                ->all();

            $role->syncPermissions($names);
        }
    }
}
