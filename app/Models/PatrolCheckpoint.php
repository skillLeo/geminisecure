<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A point on a patrol route that has to be physically reached and scanned.
 *
 * The sequence is the tour order, and it is what makes "checkpoint 4 of 6"
 * answerable: a tour is not a set of tags, it is an ordered walk, and a guard
 * who scanned the last one first has not walked it.
 *
 * @property int $id
 * @property string $tenant_id
 * @property int|null $post_id
 * @property string $label
 * @property string $code
 * @property int $sequence
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Post|null $post
 * @property-read Collection<int, CheckpointScan> $scans
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatrolCheckpoint newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatrolCheckpoint newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PatrolCheckpoint query()
 *
 * @mixin \Eloquent
 */
class PatrolCheckpoint extends Model
{
    use CentralConnection;

    /**
     * How long a checkpoint may go unscanned before dispatch is told.
     *
     * The Build Spec calls a fifteen-minute cadence typical on night posts;
     * this is that cadence plus a grace period, so a guard held up by a
     * legitimate call-through is not reported as missing.
     */
    public const CADENCE_MINUTES = 15;

    public const GRACE_MINUTES = 10;

    protected $fillable = [
        'tenant_id',
        'post_id',
        'label',
        'code',
        'sequence',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return HasMany<CheckpointScan, $this> */
    public function scans(): HasMany
    {
        return $this->hasMany(CheckpointScan::class);
    }
}
