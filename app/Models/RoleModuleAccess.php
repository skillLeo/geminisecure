<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessLevel;
use App\Enums\AccessScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One cell of a role access matrix.
 *
 * Three orthogonal facts, not one enum (D-007): what may be done (level),
 * whether the irreversible act may be committed (can_approve), and which
 * records are in reach (scope).
 *
 * @property int $id
 * @property int $role_id
 * @property int $module_id
 * @property AccessLevel $level
 * @property bool $can_approve
 * @property AccessScope $scope
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Module $module
 * @property-read Role $role
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereCanApprove($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereModuleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereRoleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereScope($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoleModuleAccess whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class RoleModuleAccess extends Model
{
    use CentralConnection;

    protected $table = 'role_module_access';

    protected $fillable = [
        'role_id',
        'module_id',
        'level',
        'can_approve',
        'scope',
    ];

    protected function casts(): array
    {
        return [
            'level' => AccessLevel::class,
            'scope' => AccessScope::class,
            'can_approve' => 'boolean',
        ];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<Module, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    /**
     * The permission strings this cell grants, as `module.verb`.
     *
     * `approve` is appended from can_approve rather than from the level,
     * because Full without the Approver tag must not grant it (D-008).
     *
     * @return list<string>
     */
    public function permissionNames(): array
    {
        $prefix = $this->module->permissionPrefix();

        $names = array_map(
            fn ($verb) => "{$prefix}.{$verb->value}",
            $this->level->verbs()
        );

        if ($this->can_approve) {
            $names[] = "{$prefix}.approve";
        }

        return $names;
    }

    /** The pill text as drawn, including the Approver tag. */
    public function pillLabel(): string
    {
        return $this->can_approve
            ? $this->level->label().' · Approver'
            : $this->level->label();
    }
}
