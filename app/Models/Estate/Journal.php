<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A posted journal entry. APPEND-ONLY.
 *
 * Enforced at the database in two independent layers — a withheld grant and a
 * BEFORE UPDATE/DELETE trigger — so this class does not carry the guarantee.
 * The overrides below exist to fail early and legibly, with an explanation, in
 * place of a raw SQLSTATE 45000 surfacing from three layers down.
 *
 * A correction is a new entry referencing the original, via reverse().
 *
 * @property Money $amount
 * @property-read Journal|null $reverses
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Journal newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Journal newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Journal query()
 *
 * @mixin \Eloquent
 */
class Journal extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'reference',
        'memo',
        'amount_minor',
        'currency',
        'posted_on',
        'reverses_journal_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'posted_on' => 'date',
        ];
    }

    /** @return BelongsTo<self, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_journal_id');
    }

    /**
     * Post the reversing entry that corrects this one.
     *
     * The only way to undo a posted journal. Both entries remain, and the
     * pair is the audit trail.
     */
    public function reverse(string $reference, string $memo): self
    {
        return static::create([
            'reference' => $reference,
            'memo' => $memo,
            'amount_minor' => -$this->amount_minor,
            'currency' => $this->currency,
            'posted_on' => now()->toDateString(),
            'reverses_journal_id' => $this->id,
        ]);
    }

    protected static function booted(): void
    {
        static::updating(function (self $journal) {
            throw new LogicException(
                "Journal [{$journal->reference}] is posted and cannot be edited. ".
                'Post a reversing entry with reverse() instead. This is also enforced by '.
                'a revoked grant and a database trigger; you are seeing the earliest of three layers.'
            );
        });

        static::deleting(function (self $journal) {
            throw new LogicException(
                "Journal [{$journal->reference}] is posted and cannot be deleted."
            );
        });
    }
}
