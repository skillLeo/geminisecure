<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Console;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A navigable module in one of the two consoles.
 *
 * Modules live centrally with the role matrix, never per estate, so that two
 * estates cannot drift into different definitions of the same module.
 */
class Module extends Model
{
    use CentralConnection;
    use HasFactory;

    protected $fillable = [
        'key',
        'label',
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
