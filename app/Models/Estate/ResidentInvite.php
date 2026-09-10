<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The self-verification invite board 34 sends. Estate database only.
 *
 * A RECORD, NOT A SIDE EFFECT. "Simone will get an SMS and email with a link to
 * download the Resident App and claim Lot 112" describes something the estate
 * has to be able to chase: a resident who never claimed their unit is the exact
 * case a property manager is asked about a fortnight later, and an estate that
 * only sent an email has nothing to answer with.
 *
 * NOTHING HERE SENDS ANYTHING. There is no mail driver and no SMS gateway on
 * this path yet, and an invite row that claimed a message had gone would be a
 * lie the moment somebody read `sent_at`. The row is written with `sent_at`
 * null; the delivery adapter stamps it when one exists. That is why the column
 * is nullable rather than defaulting to now.
 *
 * @property int $id
 * @property int $resident_id
 * @property int $unit_id
 * @property string $token
 * @property string $channels
 * @property Carbon|null $sent_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Resident|null $resident
 * @property-read Unit|null $unit
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResidentInvite newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResidentInvite newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ResidentInvite query()
 *
 * @mixin \Eloquent
 */
class ResidentInvite extends Model
{
    protected $fillable = [
        'resident_id',
        'unit_id',
        'token',
        'channels',
        'sent_at',
        'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Resident, $this> */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function isClaimed(): bool
    {
        return $this->claimed_at !== null;
    }
}
