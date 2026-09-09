<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A household occupying a unit. Estate database only.
 *
 * `access_restricted` is the ONLY thing about a household's standing that a
 * guard is ever told — a boolean, never an amount, never an ageing bucket,
 * never a payment history (invariant 2).
 */
class Household extends Model
{
    use HasFactory;

    protected $fillable = ['unit_id', 'name', 'access_restricted'];

    protected function casts(): array
    {
        return ['access_restricted' => 'boolean'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class);
    }

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
     */
    public function guardVisibleStanding(): array
    {
        return ['access_restricted' => $this->access_restricted];
    }
}
