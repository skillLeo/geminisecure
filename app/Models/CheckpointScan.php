<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Proof that a guard physically reached a checkpoint.
 *
 * Device time is kept beside server time and flagged when they disagree, never
 * rewritten to look tidy — a scan recorded from a handset with a wrong clock is
 * still evidence, and rewriting it destroys the only trace that it happened.
 *
 * @property int $id
 * @property int $patrol_checkpoint_id
 * @property int $guard_id
 * @property Carbon|null $device_time
 * @property Carbon $server_time
 * @property bool $clock_skewed
 * @property bool $captured_offline
 * @property string|null $idempotency_key
 * @property bool $is_simulated
 * @property-read PatrolCheckpoint|null $checkpoint
 * @property-read Guard|null $officer
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckpointScan newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckpointScan newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CheckpointScan query()
 *
 * @mixin \Eloquent
 */
class CheckpointScan extends Model
{
    use CentralConnection;

    /** The table carries an event time of its own; created_at would duplicate it. */
    public $timestamps = false;

    protected $fillable = [
        'patrol_checkpoint_id',
        'guard_id',
        'device_time',
        'server_time',
        'clock_skewed',
        'captured_offline',
        'idempotency_key',
        'is_simulated',
    ];

    protected function casts(): array
    {
        return [
            'device_time' => 'datetime',
            'server_time' => 'datetime',
            'clock_skewed' => 'boolean',
            'captured_offline' => 'boolean',
            'is_simulated' => 'boolean',
        ];
    }

    /** @return BelongsTo<PatrolCheckpoint, $this> */
    public function checkpoint(): BelongsTo
    {
        return $this->belongsTo(PatrolCheckpoint::class, 'patrol_checkpoint_id');
    }

    /**
     * `officer`, not `guard`: Eloquent's own `Model::guard(array $guarded)`
     * already holds that name, and a relation there is a fatal clash.
     *
     * @return BelongsTo<Guard, $this>
     */
    public function officer(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }
}
