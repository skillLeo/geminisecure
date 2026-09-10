<?php

declare(strict_types=1);

namespace App\Models\Estate;

use App\Casts\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money received against a unit.
 *
 * The RECEIPT, not the accounting. The entry this raised is the money; this row
 * carries what bookkeeping has no place for — the number on the paper the
 * resident holds, how it arrived, and the two timestamps.
 *
 * TWO TIMESTAMPS, AND NEITHER DEFAULTS TO THE OTHER. A guard takes cash at the
 * gate on Friday night and the treasurer keys it on Monday. The resident's
 * receipt is dated Friday; the bank sees it Monday. An arrears report that used
 * one for the other would show them in default over a weekend they had already
 * paid for.
 *
 * @property int $id
 * @property int $unit_id
 * @property string $receipt_no
 * @property int $amount_minor
 * @property string $currency
 * @property string $method
 * @property Carbon $received_at
 * @property Carbon $entered_at
 * @property int|null $received_by
 * @property string|null $received_by_name
 * @property string|null $gateway_ref
 * @property int|null $gateway_fee_minor
 * @property string $status
 * @property string|null $journal_ref
 * @property Money $amount
 * @property-read Unit $unit
 * @property-read Journal|null $entry
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment query()
 *
 * @mixin \Eloquent
 */
class Payment extends Model
{
    public const CARD = 'card';

    public const BANK = 'bank';

    public const CASH = 'cash';

    public const CHEQUE = 'cheque';

    protected $fillable = [
        'unit_id',
        'receipt_no',
        'amount_minor',
        'currency',
        'method',
        'received_at',
        'entered_at',
        'received_by',
        'received_by_name',
        'gateway_ref',
        'gateway_fee_minor',
        'status',
        'journal_ref',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'received_at' => 'datetime',
            'entered_at' => 'datetime',
            'amount_minor' => 'integer',
            'gateway_fee_minor' => 'integer',
        ];
    }

    /**
     * Whether the money was recorded on a different day from the one it
     * arrived.
     *
     * Not a defect — cash genuinely works this way — but a fact a treasurer
     * reconciling a week's takings needs to be able to see.
     */
    public function enteredLate(): bool
    {
        return ! $this->received_at->isSameDay($this->entered_at);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Journal, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_ref', 'reference');
    }
}
