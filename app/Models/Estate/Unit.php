<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical address within one estate. Estate database only.
 *
 * @property-read Collection<int, Household> $households
 * @property-read int|null $households_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Unit query()
 *
 * @mixin \Eloquent
 */
class Unit extends Model
{
    protected $fillable = ['reference', 'block', 'street', 'type', 'status'];

    /** @return HasMany<Household, $this> */
    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }
}
