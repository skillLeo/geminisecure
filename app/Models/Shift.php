<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One guard, one post, one window of time.
 *
 * The Build Spec's `shift` entity. Central alongside guards and posts, because
 * rostering is a Gemini operation spanning every client rather than something
 * each estate does for itself.
 *
 * ROSTERED AND ACTUAL ARE KEPT APART. The roster is a plan; the device reports
 * what happened. A coverage board that showed only the plan would report a post
 * as manned all night when nobody ever arrived, and that is the single failure
 * the board exists to catch.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int $guard_id
 * @property int $post_id
 * @property Carbon $rostered_start
 * @property Carbon $rostered_end
 * @property Carbon|null $actual_start
 * @property Carbon|null $actual_end
 * @property string|null $start_method
 * @property int|null $geofence_distance_m
 * @property bool $mock_location_flag
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Guard|null $officer
 * @property-read Post|null $post
 * @property-read Tenant|null $estate
 *
 * @method static Builder<static>|Shift covering(Carbon $moment)
 * @method static Builder<static>|Shift newModelQuery()
 * @method static Builder<static>|Shift newQuery()
 * @method static Builder<static>|Shift query()
 *
 * @mixin \Eloquent
 */
class Shift extends Model
{
    use CentralConnection;

    /** A shift nobody turned up for, or abandoned. */
    public const SETTLED = ['completed', 'missed'];

    protected $fillable = [
        'tenant_id',
        'guard_id',
        'post_id',
        'rostered_start',
        'rostered_end',
        'actual_start',
        'actual_end',
        'start_method',
        'geofence_distance_m',
        'mock_location_flag',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'rostered_start' => 'datetime',
            'rostered_end' => 'datetime',
            'actual_start' => 'datetime',
            'actual_end' => 'datetime',
            'mock_location_flag' => 'boolean',
        ];
    }

    /**
     * The guard standing this shift.
     *
     * Named `officer` rather than `guard` because Eloquent already has a
     * `guard()` — the mass-assignment one, `Model::guard(array $guarded)` —
     * and a relation of that name is a fatal signature clash rather than an
     * override. The foreign key is still guard_id.
     *
     * @return BelongsTo<Guard, $this>
     */
    public function officer(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
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

    /**
     * Shifts whose rostered window contains a moment.
     *
     * Half open on purpose: 7 PM belongs to the night shift and not to the day
     * shift that ends at it, so a post is never reported as doubly manned at
     * the handover minute.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCovering(Builder $query, Carbon $moment): Builder
    {
        return $query->where('rostered_start', '<=', $moment)
            ->where('rostered_end', '>', $moment);
    }

    /**
     * Is the guard actually on this shift right now?
     *
     * Rostered is not present. A shift that was never started reads as a gap,
     * which is what it is.
     */
    public function isUnderway(): bool
    {
        return $this->actual_start !== null && $this->actual_end === null;
    }

    /**
     * Which half of the day this shift is rostered for.
     *
     * Derived from the start rather than stored, so a roster change cannot
     * leave a column disagreeing with the hours beside it. Day is 7 AM to
     * 7 PM, the two windows the coverage board draws.
     */
    public function window(): string
    {
        return $this->rostered_start->hour >= 7 && $this->rostered_start->hour < 19
            ? 'day'
            : 'night';
    }
}
