<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Enums\Console;
use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use Inertia\Response;

/**
 * Platform settings, Super Admin screens 41 to 45.
 *
 * Screen 45 is the Role Access Matrix, and it is the source of truth the
 * navigation is generated from. Rendering it from the same tables the gate
 * reads means the screen cannot drift from the behaviour: if a cell here shows
 * View, that role has exactly `view` and nothing more.
 */
class PlatformSettingsController extends Controller
{
    public function roleMatrix(): Response
    {
        return inertia('Gemini/Settings/RoleMatrix', [
            'consoles' => collect(Console::cases())
                ->map(fn (Console $console) => $this->gridFor($console))
                ->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function gridFor(Console $console): array
    {
        $roles = Role::where('console', $console->value)->orderBy('sort')->get();
        $modules = Module::where('console', $console->value)->orderBy('sort')->get();

        $cells = RoleModuleAccess::whereIn('role_id', $roles->pluck('id'))
            ->get()
            ->keyBy(fn (RoleModuleAccess $a) => $a->role_id.':'.$a->module_id);

        return [
            'key' => $console->value,
            'label' => $console->label(),
            'roles' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'label' => $role->label,
                'scope' => $role->scope_default->label(),
                // Only shown where it actually narrows something, matching the
                // wireframe, which prints a scope line under every Gemini role.
                'scope_narrows' => $role->scope_default->value !== 'all',
            ])->all(),
            'modules' => $modules->map(fn (Module $module) => [
                'id' => $module->id,
                'key' => $module->key,
                'label' => $module->label,
                'locked_financial' => $module->is_locked_financial,
                'cells' => $roles->map(function (Role $role) use ($cells, $module) {
                    $cell = $cells->get($role->id.':'.$module->id);

                    return [
                        'level' => $cell?->level->value ?? 'none',
                        'label' => $cell?->level->label() ?? '—',
                        'can_approve' => (bool) $cell?->can_approve,
                    ];
                })->all(),
            ])->all(),
        ];
    }
}
