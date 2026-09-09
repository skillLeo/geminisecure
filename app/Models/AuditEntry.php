<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * One entry in the append-only audit log.
 *
 * The database enforces immutability with a withheld grant and a trigger; the
 * overrides below only fail earlier and more legibly than a raw SQLSTATE 45000
 * arriving from three layers down.
 */
class AuditEntry extends Model
{
    use CentralConnection;

    protected $table = 'audit_log';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'actor_id',
        'actor_name',
        'actor_role',
        'action',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException(
                'audit_log is append-only and is not editable by anyone, including platform staff. '.
                'This is also enforced by a withheld grant and a database trigger.'
            );
        });

        static::deleting(function () {
            throw new LogicException('audit_log is append-only; entries are never deleted.');
        });
    }
}
