<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessScope;
use App\Enums\Console;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Traits\HasRoles;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A user account, always central.
 *
 * Accounts are issued, never self-created — there is no public registration
 * endpoint. A user belongs to exactly one console and never both.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Console $console
 * @property string $status
 * @property string|null $phone
 * @property string|null $mfa_secret
 * @property bool $mfa_enabled
 * @property Carbon|null $last_login_at
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, EstateAssignment> $assignments
 * @property-read int|null $assignments_count
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read Collection<int, Role> $roles
 * @property-read int|null $roles_count
 * @property-read Collection<int, Permission> $teams
 * @property-read int|null $teams_count
 * @property-read Collection<int, PersonalAccessToken> $tokens
 * @property-read int|null $tokens_count
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User permission($permissions, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User role($roles, ?string $guard = null, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User team($teams, bool $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereConsole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastLoginAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereMfaEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereMfaSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutPermission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutRole($roles, ?string $guard = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutTeam($teams)
 *
 * @mixin \Eloquent
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

    /** @return HasMany<EstateAssignment, $this> */
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

    /**
     * The estate ids this user may reach, for a scoped listing.
     *
     * @return list<string>
     */
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
