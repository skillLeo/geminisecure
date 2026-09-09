<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A panic, duress, medical, fire or intrusion alert.
 *
 * Raised on a mobile device and consumed by the Gemini Console. Central,
 * because one dispatcher watches every estate at once.
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
    public function raisedByGuard(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }

    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** Anything not yet resolved, newest first. */
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
