<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Console;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A navigable module in one of the two consoles.
 *
 * Modules live centrally with the role matrix, never per estate, so that two
 * estates cannot drift into different definitions of the same module.
 *
 * @property int $id
 * @property string $key
 * @property Console $console
 * @property string $label
 * @property string|null $section
 * @property int $sort
 * @property bool $is_locked_financial
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Role> $roles
 * @property-read int|null $roles_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereConsole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereIsLockedFinancial($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereSection($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereSort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Module whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Module extends Model
{
    use CentralConnection;

    protected $fillable = [
        'key',
        'label',
        'section',
        'console',
        'sort',
        'is_locked_financial',
    ];

    protected function casts(): array
    {
        return [
            'console' => Console::class,
            'sort' => 'integer',
            'is_locked_financial' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_module_access')
            ->withPivot(['level', 'can_approve', 'scope'])
            ->withTimestamps();
    }

    /**
     * Permission names are console-scoped: `gemini.dashboard.view`.
     *
     * Without the console segment the two consoles' Dashboard modules would
     * share one permission, so granting a Gemini role sight of its dashboard
     * would silently grant every estate role sight of theirs.
     */
    public function permissionPrefix(): string
    {
        return "{$this->console->value}.{$this->key}";
    }

    /**
     * Modules the Property Manager may never hold any level on.
     *
     * Client Ruling 1 (D-010): the person who commissions work must never be
     * able to pay for it, and must never see a resident's financial position.
     * `vendor_costs` and `maintenance_budget` are deliberately NOT here — the
     * job requires them, at view only.
     */
    public function isLockedFor(Role $role): bool
    {
        return $this->is_locked_financial && $role->name === Role::PROPERTY_MANAGER;
    }
}
