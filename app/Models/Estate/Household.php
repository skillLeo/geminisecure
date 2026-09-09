<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A household occupying a unit. Estate database only.
 *
 * `access_restricted` is the ONLY thing about a household's standing that a
 * guard is ever told — a boolean, never an amount, never an ageing bucket,
 * never a payment history (invariant 2).
 *
 * @property-read Collection<int, Charge> $charges
 * @property-read int|null $charges_count
 * @property-read Collection<int, Resident> $residents
 * @property-read int|null $residents_count
 * @property-read Unit|null $unit
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Household newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Household newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Household query()
 *
 * @mixin \Eloquent
 */
class Household extends Model
{
    protected $fillable = ['unit_id', 'name', 'access_restricted'];

    protected function casts(): array
    {
        return ['access_restricted' => 'boolean'];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasMany<Resident, $this> */
    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class);
    }

    /** @return HasMany<Charge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /**
     * Everything a guard may know about this household's standing.
     *
     * Deliberately a whole-object method rather than a serializer concern, so
     * that there is exactly one place to audit and no route by which a balance
     * can reach a guard payload by accident.
     *
     * ASSUMPTION Q-005: nothing sets access_restricted automatically yet. The
     * arrears threshold that would drive it is unresolved, and restriction
     * must never follow a failed payment or a gateway outage.
     *
     * @return array{access_restricted: bool}
     */
    public function guardVisibleStanding(): array
    {
        return ['access_restricted' => $this->access_restricted];
    }
}
