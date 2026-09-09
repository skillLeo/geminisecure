<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A security officer employed by Gemini Security Limited.
 *
 * Central, never per estate: a guard is posted at an estate and can be
 * reassigned, and the cross-client roster has to see all of them at once.
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> needingCompliance()
 *
 * @property int $id
 * @property string $full_name
 * @property string $employee_number
 * @property string $psra_number
 * @property Carbon|null $psra_expires_on
 * @property string $employment_type
 * @property string $status
 * @property string|null $phone
 * @property string|null $email
 * @property Carbon|null $hired_on
 * @property string|null $tenant_id
 * @property int|null $post_id
 * @property int|null $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $estate
 * @property-read Post|null $post
 * @property-read User|null $user
 *
 * @method static Builder<static>|Guard needingCompliance()
 * @method static Builder<static>|Guard newModelQuery()
 * @method static Builder<static>|Guard newQuery()
 * @method static Builder<static>|Guard query()
 * @method static Builder<static>|Guard whereCreatedAt($value)
 * @method static Builder<static>|Guard whereEmail($value)
 * @method static Builder<static>|Guard whereEmployeeNumber($value)
 * @method static Builder<static>|Guard whereEmploymentType($value)
 * @method static Builder<static>|Guard whereFullName($value)
 * @method static Builder<static>|Guard whereHiredOn($value)
 * @method static Builder<static>|Guard whereId($value)
 * @method static Builder<static>|Guard wherePhone($value)
 * @method static Builder<static>|Guard wherePostId($value)
 * @method static Builder<static>|Guard wherePsraExpiresOn($value)
 * @method static Builder<static>|Guard wherePsraNumber($value)
 * @method static Builder<static>|Guard whereStatus($value)
 * @method static Builder<static>|Guard whereTenantId($value)
 * @method static Builder<static>|Guard whereUpdatedAt($value)
 * @method static Builder<static>|Guard whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Guard extends Model
{
    use CentralConnection;

    /** Days before expiry at which a licence is flagged as expiring soon. */
    public const LICENCE_WARNING_DAYS = 30;

    protected $fillable = [
        'full_name',
        'employee_number',
        'psra_number',
        'psra_expires_on',
        'employment_type',
        'status',
        'phone',
        'email',
        'hired_on',
        'tenant_id',
        'post_id',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'psra_expires_on' => 'date',
            'hired_on' => 'date',
        ];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Licence state, derived rather than stored.
     *
     * Storing it would mean a guard whose licence lapsed overnight still read
     * as valid until something happened to rewrite the row. Deriving it from
     * the date means the answer is correct the moment it is asked.
     */
    public function licenceState(): string
    {
        if ($this->psra_expires_on === null) {
            return 'unknown';
        }

        if ($this->psra_expires_on->isPast()) {
            return 'expired';
        }

        return $this->psra_expires_on->diffInDays(now()) <= self::LICENCE_WARNING_DAYS
            ? 'expiring'
            : 'valid';
    }

    /** The status pill as drawn in the wireframe. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'active' => 'Active',
            'on_leave' => 'On leave',
            'licence_expired' => 'Licence expired',
            'suspended' => 'Suspended',
            default => 'Inactive',
        };
    }

    /** Maps status onto the badge classes the wireframe defines. */
    public function statusBadge(): string
    {
        return match ($this->status) {
            'active' => 'active',
            'on_leave' => 'leave',
            'licence_expired', 'suspended' => 'expired',
            default => 'ok',
        };
    }

    /**
     * Guards whose licence needs attention, for the compliance screen.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNeedingCompliance(Builder $query): Builder
    {
        return $query->whereNotNull('psra_expires_on')
            ->where('psra_expires_on', '<=', now()->addDays(self::LICENCE_WARNING_DAYS))
            ->orderBy('psra_expires_on');
    }
}
