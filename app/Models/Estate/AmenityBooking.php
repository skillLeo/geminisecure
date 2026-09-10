<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One reservation of a shared facility — board 19.
 *
 * A BOOKING CARRIES THE TERMS IT WAS MADE UNDER. `fee_minor`, `deposit_minor`
 * and `cancellation_hours` are copied off the amenity when the booking is
 * created and are never read back through the relation. The Build Spec states
 * the rule for board 20 — "Changing a rule does not alter bookings already
 * confirmed under the previous rule" — and board 19's accounting note states the
 * consequence: "bookings copy amenity.deposit_amount verbatim, so a change to
 * this rate card must not retro-alter deposits already held or the deposit
 * control account will no longer agree to the sum of open bookings."
 *
 * A booking that read the amenity live would restate every held deposit in the
 * estate the moment a manager typed a new figure into board 20, and the estate
 * would owe residents an amount its own books no longer showed.
 *
 * THE DEPOSIT STATE IS A STATE, NOT A POSTING. "held" means the estate has the
 * money and carries it as a refundable liability on 2200; "awaiting" means it
 * has nothing at all and must not touch that account, or the control balance
 * overstates by the quoted amount; "refunded" means the liability was reversed.
 * Moving cash is a treasury act with its own gate, and a facilities route does
 * not hold it — see `Amenities` and QUESTIONS.md Q-009.
 *
 * `fee_charge_id` IS A POINTER AND THE AMOUNT IS NOT BESIDE IT. An amenity fee
 * that is charged is a charge on a unit like any other: it posts through
 * `Dues::charge`, which raises the entry, and the charge is the money. The column
 * is unique, so the same charge cannot back two bookings and — with the service's
 * own refusal — the same booking cannot be charged twice.
 *
 * @property int $id
 * @property string $reference
 * @property int $amenity_id
 * @property int $unit_id
 * @property string $resident_name
 * @property int|null $resident_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int|null $guests
 * @property string $status
 * @property int $fee_minor
 * @property int $deposit_minor
 * @property string $currency
 * @property int|null $cancellation_hours
 * @property string $deposit_state
 * @property Carbon|null $deposit_refunded_on
 * @property string|null $deposit_journal_ref
 * @property string|null $deposit_forfeit_reason
 * @property int|null $fee_charge_id
 * @property int|null $approved_by
 * @property string|null $approved_by_name
 * @property Carbon|null $approved_at
 * @property string|null $declined_reason
 * @property Carbon|null $declined_at
 * @property Carbon|null $cancelled_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Amenity $amenity
 * @property-read Unit $unit
 * @property-read Charge|null $feeCharge
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AmenityBooking newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AmenityBooking newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AmenityBooking query()
 *
 * @mixin \Eloquent
 */
class AmenityBooking extends Model
{
    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    public const COMPLETED = 'completed';

    /** No deposit was ever due — a nil-deposit amenity. */
    public const DEPOSIT_NONE = 'none';

    /** Quoted and not received. It must never reach the deposits-held account. */
    public const DEPOSIT_AWAITING = 'awaiting';

    /** Received and carried as a refundable liability. */
    public const DEPOSIT_HELD = 'held';

    /** Returned, and no longer part of the held balance. */
    public const DEPOSIT_REFUNDED = 'refunded';

    /**
     * Kept by the estate — damage, or the amenity was not handed back.
     *
     * The only deposit state that ever becomes income (Dr 2200, Cr 4100). It
     * carries a reason for the same argument the state itself makes: keeping a
     * resident's money is the one deposit act somebody will be asked to justify.
     */
    public const DEPOSIT_FORFEITED = 'forfeited';

    /** What board 19's status badge prints. */
    public const STATUS_LABELS = [
        self::PENDING => 'Pending',
        self::CONFIRMED => 'Confirmed',
        self::DECLINED => 'Declined',
        self::CANCELLED => 'Cancelled',
        self::COMPLETED => 'Completed',
    ];

    protected $fillable = [
        'reference',
        'amenity_id',
        'unit_id',
        'resident_name',
        'resident_id',
        'starts_at',
        'ends_at',
        'guests',
        'status',
        'fee_minor',
        'deposit_minor',
        'currency',
        'cancellation_hours',
        'deposit_state',
        'deposit_refunded_on',
        'deposit_journal_ref',
        'deposit_forfeit_reason',
        'fee_charge_id',
        'approved_by',
        'approved_by_name',
        'approved_at',
        'declined_reason',
        'declined_at',
        'cancelled_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'guests' => 'integer',
            'fee_minor' => 'integer',
            'deposit_minor' => 'integer',
            'cancellation_hours' => 'integer',
            'deposit_refunded_on' => 'date',
            'approved_at' => 'datetime',
            'declined_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** Whether this booking still occupies the diary. */
    public function holdsTheDiary(): bool
    {
        return in_array($this->status, [self::PENDING, self::CONFIRMED], true);
    }

    /** Whether its fee has already been charged to the unit. */
    public function isCharged(): bool
    {
        return $this->fee_charge_id !== null;
    }

    /**
     * Half-open on the right, so back-to-back bookings are possible.
     *
     * A Club House booked until 7:00 PM and another from 7:00 PM are two
     * bookings, not a clash — which is how a Saturday at a club house actually
     * runs.
     */
    public function overlaps(Carbon $startsAt, Carbon $endsAt): bool
    {
        return $this->starts_at->lessThan($endsAt) && $this->ends_at->greaterThan($startsAt);
    }

    /** "Andrea Fletcher — Lot 47" — board 19's Booked by cell. */
    public function bookedByLabel(): string
    {
        return $this->resident_name.' — '.$this->unit->reference;
    }

    /**
     * Board 19's Date & time cell.
     *
     * A PAST BOOKING COLLAPSES TO THE DATE. The board draws "Aug 15 (past)" with
     * no weekday and no time range: once an event has happened, which two hours
     * of that Saturday it ran for is not what anybody is scanning the list for.
     */
    public function whenLabel(?Carbon $asAt = null): string
    {
        $today = $asAt?->copy() ?? Carbon::today();

        if ($this->starts_at->lessThan($today->copy()->startOfDay())) {
            return $this->starts_at->format('M j').' (past)';
        }

        // An en-dash with NO spaces, unlike the amenity's opening hours. Both
        // forms are the boards' own and they are deliberately different.
        return $this->starts_at->format('D, M j').' · '
            .$this->starts_at->format('g:i A').'–'.$this->ends_at->format('g:i A');
    }

    /**
     * Board 19's Deposit cell, in each of its printed forms.
     *
     * An empty string where no deposit was ever due — a Pool Deck booking has
     * nothing to say in this column, and printing "$0" would suggest a deposit
     * of nothing was taken.
     *
     * "Forfeited" is a fourth form the board does not draw, because the state
     * did not exist when it was drawn. It reads plainly rather than softly: the
     * estate kept a resident's money and the row should say so.
     */
    public function depositLabel(): string
    {
        if ($this->deposit_state === self::DEPOSIT_NONE || $this->deposit_minor <= 0) {
            return '';
        }

        $amount = '$'.number_format(intdiv($this->deposit_minor, 100));

        return match ($this->deposit_state) {
            self::DEPOSIT_HELD => $amount.' held',
            self::DEPOSIT_AWAITING => $amount.' — awaiting payment',
            self::DEPOSIT_REFUNDED => $amount.' refunded '.($this->deposit_refunded_on?->format('M j') ?? ''),
            self::DEPOSIT_FORFEITED => $amount.' forfeited',
            default => $amount,
        };
    }

    /** @return BelongsTo<Amenity, $this> */
    public function amenity(): BelongsTo
    {
        return $this->belongsTo(Amenity::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The charge this booking's fee raised, where it raised one.
     *
     * @return BelongsTo<Charge, $this>
     */
    public function feeCharge(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'fee_charge_id');
    }
}
