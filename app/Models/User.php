<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessScope;
use App\Enums\Console;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A user account, always central.
 *
 * Accounts are issued, never self-created — there is no public registration
 * endpoint. A user belongs to exactly one console and never both.
 */
class User extends Authenticatable
{
    use CentralConnection;
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'console',
        'status',
        'phone',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'mfa_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'console' => Console::class,
            'mfa_enabled' => 'boolean',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EstateAssignment::class);
    }

    /**
     * May this user reach the given estate?
     *
     * This is the single question every tenant-scoped request must ask, and it
     * is answered from the ROLE's scope plus explicit assignments — never from
     * anything in the request.
     *
     * Note the deliberate asymmetry: for a role scoped to `all`, the absence of
     * assignment rows means unrestricted; for `assigned_sites` it means no
     * access at all. Scope is therefore read from the role and never inferred
     * from whether rows happen to exist, because inferring it would silently
     * promote a Head of Security with no assignments yet into seeing every
     * estate on the platform.
     */
    public function canAccessEstate(string $tenantId): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        $hasAssignment = $this->assignments()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->exists();

        // Estate committee members live inside exactly one estate.
        if ($this->console === Console::Estate) {
            return $hasAssignment;
        }

        // Gemini staff: platform-wide unless their role narrows them.
        return $this->widestScope() === AccessScope::All || $hasAssignment;
    }

    /**
     * The least restrictive scope across this user's roles.
     *
     * A user with two roles gets the wider of the two, which is why this
     * cannot simply read the first role.
     */
    public function widestScope(): AccessScope
    {
        $scopes = $this->roles->pluck('scope_default');

        return $scopes->contains(AccessScope::All)
            ? AccessScope::All
            : ($scopes->first() ?? AccessScope::OwnRecords);
    }

    /** The estate ids this user may reach, for a scoped listing. */
    public function accessibleEstateIds(): array
    {
        return $this->assignments()
            ->where('is_active', true)
            ->pluck('tenant_id')
            ->all();
    }

    public function isGeminiStaff(): bool
    {
        return $this->console === Console::Gemini;
    }
}
