<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property-read Phase|null $phase
 * @property-read Collection<int, Charge> $charges
 * @property-read Collection<int, Payment> $payments
 * @property-read Collection<int, UnitClaim> $claims
 * @property-read int|null $claims_count
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

    /**
     * The phase this unit stands in.
     *
     * Related on the NAME, because `block` has held "Phase 2" as a string since
     * Phase 1 and re-keying 450 units under a ledger that cannot be unposted
     * buys nothing a unique phase name does not already give. See `Phase`.
     *
     * @return BelongsTo<Phase, $this>
     */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(Phase::class, 'block', 'name');
    }

    /** @return HasMany<UnitClaim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(UnitClaim::class);
    }

    /**
     * The URL segment board 38 addresses this unit by — "lot-47".
     *
     * THE ROUTE KEYS ON THE LOT AND NOT ON THE PERSON, which is the board's own
     * URL and is the correct choice on its own terms: the unit outlives every
     * household in it, so a link a committee member bookmarks still resolves
     * after the family at Lot 47 has moved out.
     */
    public function slug(): string
    {
        return strtolower(str_replace(' ', '-', trim((string) $this->reference)));
    }

    /** "Phase 2 · Lot 47", as every estate screen addresses a unit. */
    public function addressLabel(): string
    {
        return implode(' · ', array_filter([$this->block, $this->reference]));
    }
}
