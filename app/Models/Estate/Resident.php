<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person in a household. Estate database only.
 *
 * `user_id` points at the central users table and is deliberately not a
 * foreign key: it crosses a database boundary, which MySQL cannot constrain.
 * Integrity is the service layer's job.
 *
 * @property-read Household|null $household
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resident newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resident newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Resident query()
 *
 * @mixin \Eloquent
 */
class Resident extends Model
{
    protected $fillable = [
        'household_id',
        'user_id',
        'full_name',
        'email',
        'phone',
        'relationship',
        'is_primary',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
