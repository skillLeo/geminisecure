<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A panic, duress, medical, fire or intrusion alert.
 *
 * Raised on a mobile device and consumed by the Gemini Console. Central,
 * because one dispatcher watches every estate at once.
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> active()
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $kind
 * @property int|null $guard_id
 * @property string|null $raised_by_name
 * @property string|null $unit_reference
 * @property string $status
 * @property numeric|null $latitude
 * @property numeric|null $longitude
 * @property Carbon|null $device_time
 * @property Carbon $server_time
 * @property bool $clock_skewed
 * @property bool $captured_offline
 * @property string|null $idempotency_key
 * @property int|null $acknowledged_by
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $resolved_at
 * @property string|null $resolution_note
 * @property bool $is_simulated
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $estate
 * @property-read Guard|null $raisedByGuard
 *
 * @method static Builder<static>|DuressAlert active()
 * @method static Builder<static>|DuressAlert newModelQuery()
 * @method static Builder<static>|DuressAlert newQuery()
 * @method static Builder<static>|DuressAlert query()
 * @method static Builder<static>|DuressAlert whereAcknowledgedAt($value)
 * @method static Builder<static>|DuressAlert whereAcknowledgedBy($value)
 * @method static Builder<static>|DuressAlert whereCapturedOffline($value)
 * @method static Builder<static>|DuressAlert whereClockSkewed($value)
 * @method static Builder<static>|DuressAlert whereCreatedAt($value)
 * @method static Builder<static>|DuressAlert whereDeviceTime($value)
 * @method static Builder<static>|DuressAlert whereGuardId($value)
 * @method static Builder<static>|DuressAlert whereId($value)
 * @method static Builder<static>|DuressAlert whereIdempotencyKey($value)
 * @method static Builder<static>|DuressAlert whereIsSimulated($value)
 * @method static Builder<static>|DuressAlert whereKind($value)
 * @method static Builder<static>|DuressAlert whereLatitude($value)
 * @method static Builder<static>|DuressAlert whereLongitude($value)
 * @method static Builder<static>|DuressAlert whereRaisedByName($value)
 * @method static Builder<static>|DuressAlert whereResolutionNote($value)
 * @method static Builder<static>|DuressAlert whereResolvedAt($value)
 * @method static Builder<static>|DuressAlert whereServerTime($value)
 * @method static Builder<static>|DuressAlert whereStatus($value)
 * @method static Builder<static>|DuressAlert whereTenantId($value)
 * @method static Builder<static>|DuressAlert whereUnitReference($value)
 * @method static Builder<static>|DuressAlert whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class DuressAlert extends Model
{
    use CentralConnection;

    protected $fillable = [
        'tenant_id',
        'kind',
        'guard_id',
        'raised_by_name',
        'unit_reference',
        'status',
        'latitude',
        'longitude',
        'device_time',
        'server_time',
        'clock_skewed',
        'captured_offline',
        'idempotency_key',
        'acknowledged_by',
        'acknowledged_at',
        'resolved_at',
        'resolution_note',
        'is_simulated',
    ];

    protected function casts(): array
    {
        return [
            'device_time' => 'datetime',
            'server_time' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'clock_skewed' => 'boolean',
            'captured_offline' => 'boolean',
            'is_simulated' => 'boolean',
        ];
    }

    /**
     * Deliberately NOT named guard().
     *
     * Eloquent already defines Model::guard(array $guarded) for mass-assignment,
     * so a relation of that name is a fatal signature clash rather than an
     * override. Same reason the foreign key stays `guard_id`: the column is
     * conventional, only the accessor has to move.
     */
    /** @return BelongsTo<Guard, $this> */
    public function raisedByGuard(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * Anything not yet resolved, newest first.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['resolved', 'false_alarm'])
            ->orderByDesc('server_time');
    }

    /**
     * How urgent this is, for ordering the queue.
     *
     * Panic and duress outrank everything: a person is asking for help. This
     * is deliberately not alphabetical and not by timestamp alone.
     */
    public function priority(): int
    {
        return match ($this->kind) {
            'panic', 'duress' => 1,
            'medical' => 2,
            'fire' => 3,
            default => 4,
        };
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'panic' => 'Panic button',
            'duress' => 'Guard duress',
            'medical' => 'Medical',
            'fire' => 'Fire',
            'intrusion' => 'Intrusion',
            default => ucfirst($this->kind),
        };
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            'open' => 'overdue',
            'acknowledged', 'responding' => 'pending',
            default => 'active',
        };
    }
}
