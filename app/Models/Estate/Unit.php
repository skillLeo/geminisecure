<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A physical address within one estate. Estate database only.
 *
 * THE UNIT IS WHAT OWES THE DUES. Households come and go; the property and its
 * arrears stay, which is why the receivable sub-ledger, `charges` and `payments`
 * are all keyed here rather than on the household. Nine of Phoenix Park's 450
 * units are vacant and every one of them still has a ledger.
 *
 * @property int $id
 * @property string $reference
 * @property string|null $block
 * @property string|null $street
 * @property string $type
 * @property string $status
 * @property-read Collection<int, Household> $households
 * @property-read int|null $households_count
 * @property-read Household|null $household
 * @property-read Collection<int, Charge> $charges
 * @property-read Collection<int, Payment> $payments
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

    /**
     * The household living here now, if any.
     *
     * A unit has a history of households and at most one current occupant, so
     * `households` is the record and this is the one the screens ask for. Null
     * on a vacant unit, which is a real and common state: the dues still fall
     * due and there is simply nobody to call about them.
     *
     * @return HasOne<Household, $this>
     */
    public function household(): HasOne
    {
        return $this->hasOne(Household::class)->latestOfMany();
    }

    /** @return HasMany<Charge, $this> */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
