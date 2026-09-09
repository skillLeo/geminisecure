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
use Inertia\Response;

/**
 * Platform settings — screen super-admin-45, the Role Access Matrix.
 *
 * The grid is not a report about permissions. It is the permission tables
 * printed: roles, modules and role_module_access, the same three the
 * navigation is generated from and the same three the `can:` middleware
 * resolves against. Nothing here recomputes an access decision, so the screen
 * cannot say one thing while the application does another.
 *
 * The board draws the Gemini Console's six roles. The Estate Console's matrix
 * is its own board on its own console and is not reproduced here.
 */
class PlatformSettingsController extends Controller
{
    /**
     * Why the other six tabs are inert.
     *
     * Screens 41 to 44, 46 and 47 have no controller and no route. The tab is
     * still drawn — the board draws it — but it carries this on hover instead
     * of swallowing a click.
     */
    private const PENDING_TAB = 'Not built yet — Role access matrix is the only Platform settings screen in this phase.';

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
            'tabs' => $this->tabs(),
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
     * @return list<array{label: string, href: string|null, active: bool, reason: string|null}>
     */
    private function tabs(): array
    {
        return [
            ['label' => 'Pricing & rates', 'href' => null, 'active' => false, 'reason' => self::PENDING_TAB],
            ['label' => 'Package builder', 'href' => null, 'active' => false, 'reason' => self::PENDING_TAB],
            ['label' => 'Client line items', 'href' => null, 'active' => false, 'reason' => self::PENDING_TAB],
            ['label' => 'Platform admins', 'href' => null, 'active' => false, 'reason' => self::PENDING_TAB],
            [
                'label' => 'Role access matrix',
                'href' => route('gemini.platform_settings', absolute: false),
                'active' => true,
                'reason' => null,
            ],
            ['label' => 'Statutory rates', 'href' => null, 'active' => false, 'reason' => self::PENDING_TAB],
            ['label' => 'Notifications', 'href' => null, 'active' => false, 'reason' => self::PENDING_TAB],
        ];
    }
}
