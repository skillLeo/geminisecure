<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * A Resident App sign-in (13 D1, D3). See the `create_resident_accounts` migration.
 *
 * Like a guard, a principal that holds no role and can open no console: the
 * abilities on its token are the whole of what it can do, and a pending account's
 * token reaches only its own claim.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $channel
 * @property string $destination
 * @property string|null $full_name
 * @property int|null $resident_id
 * @property int|null $unit_id
 * @property int|null $claim_id
 * @property string $status
 * @property Carbon|null $verified_at
 * @property string|null $device_uid
 * @property string|null $device_platform
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $estate
 */
class ResidentAccount extends Model implements AuthenticatableContract
{
    use AuthenticatableTrait;
    use CentralConnection;
    use HasApiTokens;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function estate(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE && $this->resident_id !== null && $this->unit_id !== null;
    }
}
