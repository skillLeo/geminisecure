<?php

declare(strict_types=1);

namespace App\Services\Navigation;

use App\Enums\Console;
use App\Models\Module;
use App\Models\RoleModuleAccess;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Builds a user's navigation from the role access matrix, at runtime.
 *
 * Cross-cutting rule 9: a module a role cannot use is ABSENT from the
 * navigation, not disabled and not greyed. This returns only what the user may
 * reach, so there is no code path by which a forbidden module could render at
 * all — the front end is never handed one to hide.
 *
 * A user with two roles gets the union of their access, taking the highest
 * level where the two disagree. Two roles must never combine to LESS than
 * either grants alone, which is what a naive intersection would produce.
 */
class ConsoleNavigation
{
    /**
     * @return list<array{key: string, label: string, section: string|null, href: string, active: bool}>
     */
    public function for(User $user, Console $console): array
    {
        $roleIds = $user->roles->pluck('id');

        if ($roleIds->isEmpty()) {
            return [];
        }

        $modules = Module::where('console', $console->value)
            ->orderBy('sort')
            ->get()
            ->keyBy('id');

        $access = RoleModuleAccess::whereIn('role_id', $roleIds)
            ->whereIn('module_id', $modules->keys())
            ->get();

        return $access
            ->filter(fn (RoleModuleAccess $a) => $a->level->isVisible())
            ->groupBy('module_id')
            ->map(fn ($cells, $moduleId) => $modules[$moduleId])
            ->sortBy('sort')
            ->map(fn (Module $module) => [
                'key' => $module->key,
                'label' => $module->label,
                'section' => $module->section,
                'href' => $this->hrefFor($module),
                'active' => $this->isActive($module),
            ])
            ->values()
            ->all();
    }

    /**
     * Route name convention: gemini.<module_key> / estate.<module_key>.
     *
     * Falls back to '#' for a module whose screen is not built yet, so the
     * sidebar renders truthfully during a phased build rather than throwing on
     * a missing route.
     */
    private function hrefFor(Module $module): string
    {
        $name = "{$module->console->value}.{$module->key}";

        return Route::has($name) ? route($name) : '#';
    }

    private function isActive(Module $module): bool
    {
        $name = "{$module->console->value}.{$module->key}";

        return request()->routeIs($name) || request()->routeIs("{$name}.*");
    }
}
