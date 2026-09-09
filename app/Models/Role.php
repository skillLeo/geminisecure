<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessScope;
use App\Enums\Console;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role as SpatieRole;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Roles live in gs_platform, never per estate.
 *
 * spatie/laravel-permission has no configuration key for the connection, so
 * its stock models resolve against Laravel's default connection. Under
 * DatabaseTenancyBootstrapper that default is swapped to the tenant database
 * for the duration of a tenant request, which would silently give every estate
 * its own private copy of the role table.
 *
 * The consequences are not cosmetic: the role-permission matrix drives runtime
 * navigation generation, so per-estate role tables would let two estates drift
 * into different definitions of what a "Treasurer" may see. Pinning to the
 * central connection keeps one authoritative matrix.
 *
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property Console $console
 * @property string|null $label
 * @property int $sort
 * @property AccessScope $scope_default
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, RoleModuleAccess> $moduleAccess
 * @property-read int|null $module_access_count
 * @property-read Collection<int, Module> $modules
 * @property-read int|null $modules_count
 * @property-read Collection<int, Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read Collection<int, User> $users
 * @property-read int|null $users_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role permission($permissions, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereConsole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereGuardName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereScopeDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereSort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Role withoutPermission($permissions)
 *
 * @mixin \Eloquent
 */
class Role extends SpatieRole
{
    use CentralConnection;

    // --- Gemini Console (6) ------------------------------------------------
    public const DIRECTOR = 'gemini.director';

    public const OPERATIONS_MANAGER = 'gemini.operations_manager';

    public const HEAD_OF_SECURITY = 'gemini.head_of_security';

    public const DISPATCHER = 'gemini.dispatcher';

    public const ADMIN_ASSISTANT = 'gemini.admin_assistant';

    public const ACCOUNTANT = 'gemini.accountant';

    // --- Estate Console (7) ------------------------------------------------
    /**
     * Not shown in the matrix screen. From the audit note in wireframe 06:
     * full access within its OWN estate only, including user and role
     * management. Explicitly not the platform Director, and with no visibility
     * into any other estate or into Gemini's own payroll and HR (D-009).
     */
    public const COMMUNITY_SUPER_ADMIN = 'estate.community_super_admin';

    public const PRESIDENT = 'estate.president';

    public const VICE_PRESIDENT = 'estate.vice_president';

    public const SECRETARY = 'estate.secretary';

    public const PROPERTY_MANAGER = 'estate.property_manager';

    public const TREASURER = 'estate.treasurer';

    public const ESTATE_ADMIN_ASSISTANT = 'estate.admin_assistant';

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'console' => Console::class,
            'scope_default' => AccessScope::class,
            'sort' => 'integer',
        ]);
    }

    /**
     * Typed lookup by machine name.
     *
     * spatie's findByName() is declared as returning its own Contracts\Role
     * interface, which knows nothing about `label`, `console` or
     * `scope_default`. Every caller here wants this class, so narrowing it
     * once is better than casting at each call site.
     */
    public static function named(string $name, string $guard = 'web'): self
    {
        /** @var self $role */
        $role = static::findByName($name, $guard);

        return $role;
    }

    /** @return BelongsToMany<Module, $this> */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'role_module_access')
            ->withPivot(['level', 'can_approve', 'scope'])
            ->withTimestamps();
    }

    /** @return HasMany<RoleModuleAccess, $this> */
    public function moduleAccess(): HasMany
    {
        return $this->hasMany(RoleModuleAccess::class);
    }

    /**
     * The navigation this role sees, generated from the matrix at runtime.
     *
     * Cross-cutting rule 9: a module a role cannot use is ABSENT from the
     * navigation, not disabled or greyed. That is why this filters rather than
     * returning every module with a flag — there is no code path that can
     * render a module at level `none`.
     *
     * @return Collection<int, Module>
     */
    public function navigableModules(): Collection
    {
        return $this->moduleAccess()
            ->with('module')
            ->get()
            ->filter(fn (RoleModuleAccess $a) => $a->level->isVisible())
            ->sortBy(fn (RoleModuleAccess $a) => $a->module->sort)
            ->map(fn (RoleModuleAccess $a) => $a->module)
            ->values();
    }
}
