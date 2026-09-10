<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\AmenitySlot;
use App\Models\Estate\Charge;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Unit;
use App\Models\User;
use Brick\Money\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The booking diary and the rate card behind it — boards 19 and 20.
 *
 * A BOOKING CARRIES THE TERMS IT WAS MADE UNDER. The fee, the deposit and the
 * cancellation rule are copied off the amenity at the moment the booking is
 * made and never read back through the relation. The Build Spec states the rule
 * — "Changing a rule does not alter bookings already confirmed under the
 * previous rule" — and board 19's accounting note states what breaks without it:
 * a rate card edited on Tuesday would restate every deposit held since March,
 * and the deposits-held control account would stop agreeing with the sum of open
 * bookings.
 *
 * A HOUSEHOLD IN ARREARS MAY BE BLOCKED, AND THE ESTATE DECIDES. "A household in
 * arrears may be blocked from booking, and that is a configurable estate rule
 * rather than a platform default." It is off unless an estate switches it on,
 * and `mayBook()` returns a BOOLEAN and never a figure — the person reading
 * board 19 is the Property Manager, who holds `facilities` in full and is locked
 * out of `dues_ledger`, `payments` and `accounting_posting` by platform
 * invariant (D-010). "This household cannot book" is a facilities fact; "this
 * household owes J$44,900" is not theirs to see, and a refusal message that
 * named the amount would hand it to them through the back door.
 *
 * THE CHECK HAPPENS WHEN THE BOOKING IS MADE AND NOT AGAIN. A household that
 * booked the Club House while clear and fell behind afterwards keeps its
 * booking, for the same reason a confirmed booking keeps its fee: the terms were
 * settled when the estate accepted it. Re-testing at approval would make the
 * rule bite on a date nobody agreed to.
 *
 * AN AMENITY FEE IS A CHARGE ON A UNIT LIKE ANY OTHER. `recordFee()` posts it
 * through `Dues::charge` — Dr 1200 Dues Receivable, Cr 4100 Amenity Booking Fees
 * — and stores only the charge's id on the booking. It is not recorded a second
 * way and no amount is kept beside the pointer, so the fee on a resident's
 * statement and the fee on their booking cannot drift apart: they are one row
 * read two ways. The unique key on `fee_charge_id` and the refusal below mean it
 * can happen exactly once.
 *
 * A DEPOSIT IS A STATE HERE AND A POSTING SOMEWHERE ELSE. Board 19 draws three
 * of them — held, awaiting payment, refunded — and its accounting note is exact
 * about what each means in the books: only "held" belongs on the deposits-held
 * liability, and posting "awaiting" would overstate that account by money the
 * estate does not have. Moving cash is a treasury act behind `payments` and
 * `accounting_posting`, neither of which a facilities route holds, so this class
 * records what the estate has agreed and raises no entry for it. See
 * QUESTIONS.md Q-009.
 */
class Amenities
{
    /** The income account an amenity fee credits — board 25's chart. */
    public const FEE_ACCOUNT = '4100';

    /** Board 35's charge type for one of these. */
    public const CHARGE_TYPE = 'amenity';

    public function __construct(private readonly Dues $dues) {}

    /* ------------------------------------------------------------------ */
    /* the estate's own rule */
    /* ------------------------------------------------------------------ */

    /**
     * Whether this unit may book anything at all.
     *
     * A BOOLEAN, AND DELIBERATELY NOTHING ELSE. See the class docblock: the
     * caller is a facilities screen, and a facilities screen may not learn a
     * household's financial position — not the balance, not the bucket, not the
     * number of days. D-010 is a platform invariant rather than an estate
     * setting, and this signature is where it is kept.
     *
     * Returns true when the estate has not switched the rule on, which is the
     * default and the safe direction.
     */
    public function mayBook(Unit $unit, ?Carbon $asAt = null): bool
    {
        $settings = EstateSetting::current();

        if (! $settings->amenity_arrears_block_enabled) {
            return true;
        }

        if ($this->dues->balanceOf($unit)->getMinorAmount()->toInt() <= 0) {
            return true;
        }

        $today = $asAt?->copy() ?? Carbon::today();
        $oldest = $this->dues->oldestOpenChargeDate($unit, $today);

        /*
         * A balance with no open charge behind it is a unit that owes something
         * not yet due — a charge raised for next month. Owing money the estate
         * has not asked for yet is not arrears, and blocking on it would turn a
         * bill into a punishment before its own due date.
         */
        if ($oldest === null) {
            return true;
        }

        return (int) $oldest->diffInDays($today, absolute: false) < $settings->amenity_arrears_block_days;
    }

    /* ------------------------------------------------------------------ */
    /* writing */
    /* ------------------------------------------------------------------ */

    /**
     * Take a booking request. It starts PENDING and holds the diary.
     *
     * Pending rather than confirmed, because board 19 draws an approve/reject
     * pair against a pending row: somebody decides. It still occupies the slot
     * while it waits, or two households would each be told the Club House was
     * theirs on the same Saturday.
     */
    public function book(
        Amenity $amenity,
        Unit $unit,
        string $residentName,
        Carbon|string $startsAt,
        Carbon|string $endsAt,
        ?int $guests = null,
        ?string $notes = null,
        ?int $residentId = null,
    ): AmenityBooking {
        $from = $this->asMoment($startsAt);
        $to = $this->asMoment($endsAt);

        if ($to->lessThanOrEqualTo($from)) {
            throw new DomainException(
                'A booking has to end after it starts. A slot of no length is not a reservation and '.
                'would sit invisibly across everybody else\'s.'
            );
        }

        if (! $amenity->is_active || ! $amenity->is_bookable) {
            throw new DomainException(sprintf(
                'The %s is not open for booking. An amenity is retired rather than deleted, so what was '.
                'booked while it was open still reads correctly.',
                $amenity->name,
            ));
        }

        if (! $amenity->isWithinHours($from, $to)) {
            throw new DomainException(sprintf(
                'The %s is open %s. A booking outside those hours is one nobody will be there to open '.
                'the gate for.',
                $amenity->name,
                $amenity->hoursLabel(),
            ));
        }

        if ($guests !== null && $guests > $amenity->capacity) {
            throw new DomainException(sprintf(
                'The %s holds %d guests and this booking is for %d. Capacity is a safety limit before '.
                'it is a rule, and an estate that overrides it once has no answer the day it matters.',
                $amenity->name,
                $amenity->capacity,
                $guests,
            ));
        }

        $this->guardAvailability($amenity, $from, $to);

        if (! $this->mayBook($unit)) {
            /*
             * NO FIGURE, NO DATE, NO BUCKET. The refusal reaches a facilities
             * screen and, through it, possibly a resident. It says what the rule
             * is and where to go, and it says nothing about how much or how long
             * — the Property Manager reading it holds no dues access, and a
             * message naming an amount would be the invariant leaking through an
             * error string.
             */
            throw new DomainException(sprintf(
                'Bookings are closed to %s while the household is in arrears. This estate has switched '.
                'that rule on; the dues office can settle it or lift it.',
                $unit->reference,
            ));
        }

        return DB::connection('tenant')->transaction(function () use (
            $amenity, $unit, $residentName, $from, $to, $guests, $notes, $residentId
        ): AmenityBooking {
            $deposit = (int) ($amenity->deposit_minor ?? 0);

            return AmenityBooking::create([
                'reference' => $this->nextReference($from),
                'amenity_id' => $amenity->id,
                'unit_id' => $unit->id,
                'resident_name' => $residentName,
                'resident_id' => $residentId,
                'starts_at' => $from,
                'ends_at' => $to,
                'guests' => $guests,
                'status' => AmenityBooking::PENDING,

                /*
                 * THE SNAPSHOT. Copied here and never re-read from the amenity,
                 * so board 20's rate card can be edited tomorrow without moving
                 * a single figure on board 19.
                 */
                'fee_minor' => (int) ($amenity->booking_fee_minor ?? 0),
                'deposit_minor' => $deposit,
                'currency' => $amenity->currency,
                'cancellation_hours' => $amenity->cancellation_hours,

                // Quoted and not received. It must not reach the deposits-held
                // account until somebody actually pays it.
                'deposit_state' => $deposit > 0
                    ? AmenityBooking::DEPOSIT_AWAITING
                    : AmenityBooking::DEPOSIT_NONE,

                'notes' => $notes,
            ]);
        });
    }

    /** Approve a pending booking — board 19's green tick. */
    public function approve(AmenityBooking $booking, ?User $by = null, Carbon|string|null $on = null): AmenityBooking
    {
        if ($booking->status !== AmenityBooking::PENDING) {
            throw new DomainException(sprintf(
                'Booking %s is %s and is not waiting on a decision. Approving it again would restate a '.
                'date the household has already been told about.',
                $booking->reference,
                $booking->status,
            ));
        }

        $booking->forceFill([
            'status' => AmenityBooking::CONFIRMED,
            'approved_by' => $by?->getKey(),
            'approved_by_name' => $by?->name,
            'approved_at' => $on === null ? now() : $this->asMoment($on),
        ])->save();

        return $booking;
    }

    /**
     * Decline it — board 19's red cross.
     *
     * A REASON IS REQUIRED. A household told only "declined" has nothing to act
     * on and will ask a guard at a gate about it, which is the one place the
     * answer cannot be given.
     */
    public function decline(AmenityBooking $booking, string $reason, ?User $by = null): AmenityBooking
    {
        if (trim($reason) === '') {
            throw new DomainException(
                'Say why the booking is declined. "Declined" on its own tells the household nothing '.
                'they can do anything about.'
            );
        }

        if ($booking->status !== AmenityBooking::PENDING) {
            throw new DomainException(sprintf(
                'Booking %s is %s. A confirmed booking is cancelled rather than declined, and the two '.
                'are different things to have on a record.',
                $booking->reference,
                $booking->status,
            ));
        }

        $booking->forceFill([
            'status' => AmenityBooking::DECLINED,
            'declined_reason' => trim($reason),
            'declined_at' => now(),
            'approved_by' => $by?->getKey(),
            'approved_by_name' => $by?->name,
        ])->save();

        return $booking;
    }

    /**
     * Take a period out of an amenity's diary.
     *
     * The estate's own decision — resurfacing, a private function, a closure —
     * and it refuses to close over a booking that already exists. A block that
     * silently overlapped a confirmed booking would leave a household turning up
     * to a locked gate with a confirmation in their hand.
     */
    public function blockSlot(
        Amenity $amenity,
        Carbon|string $startsAt,
        Carbon|string $endsAt,
        ?string $reason = null,
        ?User $by = null,
        string $kind = AmenitySlot::BLOCKED,
    ): AmenitySlot {
        $from = $this->asMoment($startsAt);
        $to = $this->asMoment($endsAt);

        if ($to->lessThanOrEqualTo($from)) {
            throw new DomainException('A blocked period has to end after it starts.');
        }

        $clash = $amenity->bookings()
            ->whereIn('status', [AmenityBooking::PENDING, AmenityBooking::CONFIRMED])
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->first();

        if ($clash !== null) {
            throw new DomainException(sprintf(
                'Booking %s already covers part of that period on the %s. Decline or cancel it first, '.
                'so the household is told rather than finding out at the gate.',
                $clash->reference,
                $amenity->name,
            ));
        }

        return AmenitySlot::create([
            'amenity_id' => $amenity->id,
            'kind' => $kind,
            'starts_at' => $from,
            'ends_at' => $to,
            'reason' => $reason,
            'created_by' => $by?->getKey(),
            'created_by_name' => $by?->name,
        ]);
    }

    /**
     * Charge the booking fee to the unit. POSTED THROUGH `Dues::charge`.
     *
     * IT IS A CHARGE ON A UNIT LIKE ANY OTHER, and it is not recorded a second
     * way. The entry is the money — Dr 1200, Cr 4100 — and the booking keeps
     * only the charge's id, so the figure on the resident's statement and the
     * figure behind their booking are the same row. Storing the amount here as
     * well would give the two permission to disagree.
     *
     * IT CAN HAPPEN ONCE. `fee_charge_id` is unique at the database and refused
     * here, because a household billed twice for one Saturday has to be refunded
     * through a credit note that nobody would have raised.
     *
     * A NIL-FEE AMENITY RAISES NOTHING AT ALL. Board 20's accounting note is
     * explicit: a Pool Deck booking must "produce a booking with zero journal
     * lines rather than a zero-amount entry". A zero-value entry is a row in the
     * ledger claiming something happened.
     */
    public function recordFee(
        AmenityBooking $booking,
        ?User $by = null,
        Carbon|string|null $dueOn = null,
    ): Charge {
        if ($booking->isCharged()) {
            throw new DomainException(sprintf(
                'Booking %s was already charged on charge %s. Billing one Saturday twice is a refund '.
                'the estate would have to notice before it could make it.',
                $booking->reference,
                (string) ($booking->feeCharge->reference ?? $booking->fee_charge_id),
            ));
        }

        if ($booking->fee_minor <= 0) {
            throw new DomainException(sprintf(
                'The %s carries no booking fee, so there is nothing to charge. An entry for nothing is '.
                'a line in the ledger saying something happened.',
                $booking->amenity->name,
            ));
        }

        if ($booking->status === AmenityBooking::DECLINED || $booking->status === AmenityBooking::CANCELLED) {
            throw new DomainException(sprintf(
                'Booking %s is %s. Charging a household for a booking the estate refused is a debt they '.
                'never agreed to.',
                $booking->reference,
                $booking->status,
            ));
        }

        return DB::connection('tenant')->transaction(function () use ($booking, $by, $dueOn): Charge {
            $charge = $this->dues->charge(
                unit: $booking->unit,
                amount: Money::ofMinor($booking->fee_minor, $booking->currency),
                description: $booking->amenity->name.' booking — '.$booking->starts_at->format('M j, Y'),
                dueOn: $dueOn ?? Carbon::today(),
                type: self::CHARGE_TYPE,
                account: self::FEE_ACCOUNT,
                by: $by,
            );

            $booking->forceFill(['fee_charge_id' => $charge->id])->save();

            return $charge;
        });
    }

    /**
     * The deposit has been paid in.
     *
     * A STATE, NOT A POSTING. See the class docblock: the cash leg belongs to
     * whoever holds `payments`, and a facilities route holds neither that nor
     * `accounting_posting`. What this records is the estate's own statement that
     * it now has the money and owes it back.
     */
    public function holdDeposit(AmenityBooking $booking): AmenityBooking
    {
        if ($booking->deposit_minor <= 0) {
            throw new DomainException(sprintf(
                'Booking %s carries no deposit. Recording one held would put a liability on the estate '.
                'for money nobody was asked for.',
                $booking->reference,
            ));
        }

        $booking->forceFill([
            'deposit_state' => AmenityBooking::DEPOSIT_HELD,
            'deposit_refunded_on' => null,
        ])->save();

        return $booking;
    }

    /** The deposit has gone back — board 19's "$5,000 refunded Aug 18". */
    public function refundDeposit(AmenityBooking $booking, Carbon|string|null $on = null): AmenityBooking
    {
        if ($booking->deposit_state !== AmenityBooking::DEPOSIT_HELD) {
            throw new DomainException(sprintf(
                'Booking %s holds no deposit to refund — it is %s. Refunding money the estate never '.
                'received would take it out of the operating account.',
                $booking->reference,
                $booking->deposit_state,
            ));
        }

        $booking->forceFill([
            'deposit_state' => AmenityBooking::DEPOSIT_REFUNDED,
            'deposit_refunded_on' => ($on === null ? Carbon::today() : $this->asMoment($on))->toDateString(),
        ])->save();

        return $booking;
    }

    /** The event has happened and the amenity was handed back. */
    public function complete(AmenityBooking $booking, Carbon|string|null $refundedOn = null): AmenityBooking
    {
        if ($booking->status !== AmenityBooking::CONFIRMED) {
            throw new DomainException(sprintf(
                'Booking %s is %s. Only a confirmed booking can complete — the others describe events '.
                'that never took place.',
                $booking->reference,
                $booking->status,
            ));
        }

        $booking->forceFill(['status' => AmenityBooking::COMPLETED])->save();

        if ($refundedOn !== null && $booking->deposit_state === AmenityBooking::DEPOSIT_HELD) {
            $this->refundDeposit($booking, $refundedOn);
        }

        return $booking;
    }

    /* ------------------------------------------------------------------ */
    /* what the screens read */
    /* ------------------------------------------------------------------ */

    /**
     * Board 19 — the chips and the booking list.
     *
     * NOT ONE FIGURE HERE IS A RESIDENT'S BALANCE. The Deposit column is the
     * amount snapshotted onto the booking, which is a fact about the booking and
     * not about the household's account.
     *
     * @param  string  $amenity  an amenity name, or '' for every one of them
     * @return array<string, mixed>
     */
    public function bookingsBoard(string $amenity = '', ?Carbon $asAt = null): array
    {
        $today = ($asAt?->copy() ?? Carbon::today())->startOfDay();

        /*
         * Upcoming first, ascending, then the past ones last — which is board
         * 19's own order and not a plain date sort. A diary is read forwards:
         * what is coming is what somebody has to do something about.
         *
         * ORDERED BY WHEN EACH BOOKING STARTS, and the reference is only the
         * tiebreak. A reference is sequential within the MONTH the booking
         * falls in, so ordering by it orders same-month bookings by the order
         * they were TAKEN in — and a Saturday booked this morning would be
         * drawn above the Wednesday before it. The tiebreak is still there so
         * that two bookings beginning at the same moment have one order rather
         * than whichever the database happened to return.
         */
        $bookings = AmenityBooking::query()
            ->with(['amenity', 'unit'])
            ->orderByRaw('CASE WHEN starts_at < ? THEN 1 ELSE 0 END', [$today->toDateTimeString()])
            ->orderBy('starts_at')
            ->orderBy('reference')
            ->get();

        $rows = [];
        $pending = 0;

        foreach ($bookings as $booking) {
            if ($booking->status === AmenityBooking::PENDING) {
                $pending++;
            }

            if ($amenity !== '' && $booking->amenity->name !== $amenity) {
                continue;
            }

            $rows[] = [
                'id' => $booking->id,
                'reference' => $booking->reference,
                'amenity' => $booking->amenity->name,
                'icon' => $booking->amenity->icon_key,
                'booked_by' => $booking->bookedByLabel(),
                'unit' => $booking->unit->reference,
                'when' => $booking->whenLabel($today),
                'is_past' => $booking->starts_at->lessThan($today),
                'deposit' => $booking->depositLabel(),
                'deposit_minor' => $booking->deposit_minor,
                'deposit_state' => $booking->deposit_state,
                'fee_minor' => $booking->fee_minor,
                'is_charged' => $booking->isCharged(),
                'status' => $booking->status,
                'status_label' => AmenityBooking::STATUS_LABELS[$booking->status],

                // Board 19 derives the row's controls from the status: a pending
                // booking gets the approve/reject pair and every other status
                // gets a single link.
                'decidable' => $booking->status === AmenityBooking::PENDING,
            ];
        }

        return [
            // Generated from the bookable amenities, which is what board 19's
            // chip row is: the estate's own list rather than a fixed three.
            'amenities' => Amenity::query()
                ->where('is_active', true)
                ->where('is_bookable', true)
                ->orderBy('sort_order')
                ->pluck('name')
                ->all(),
            'filter' => $amenity,
            'pending' => $pending,
            'rows' => $rows,
        ];
    }

    /**
     * Board 20 — the rate card.
     *
     * @return array<string, mixed>
     */
    public function settingsBoard(): array
    {
        $amenities = Amenity::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'rows' => $amenities->map(static fn (Amenity $amenity): array => [
                'id' => $amenity->id,
                'name' => $amenity->name,
                'icon' => $amenity->icon_key,
                'capacity' => $amenity->capacity,
                'capacity_label' => $amenity->capacity.' guests',

                /*
                 * The amounts AND the labels. The minor units are the fact and
                 * the label is what the board draws — "Free for residents",
                 * "None" — which are statements about the amenity rather than
                 * numbers, and which a page formatting a null would have to
                 * invent.
                 */
                'fee_minor' => $amenity->booking_fee_minor,
                'fee_label' => $amenity->feeLabel(),
                'deposit_minor' => $amenity->deposit_minor,
                'deposit_label' => $amenity->depositLabel(),
                'hours_label' => $amenity->hoursLabel(),
                'cancellation_hours' => $amenity->cancellation_hours,
                'booking_window_days' => $amenity->booking_window_days,
                'is_bookable' => $amenity->is_bookable,
            ])->all(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * Refuse a period something else already has.
     *
     * Two separate refusals with two different sentences, because "the Gazebo is
     * closed that afternoon" and "somebody else has it" send a household to
     * different places.
     */
    private function guardAvailability(Amenity $amenity, Carbon $from, Carbon $to): void
    {
        $blocked = $amenity->slots()
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->first();

        if ($blocked !== null) {
            throw new DomainException(sprintf(
                'The %s is unavailable then — %s. The estate closed that period; it is not a slot '.
                'somebody else has taken.',
                $amenity->name,
                $blocked->label(),
            ));
        }

        $taken = $amenity->bookings()
            ->whereIn('status', [AmenityBooking::PENDING, AmenityBooking::CONFIRMED])
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->first();

        if ($taken !== null) {
            throw new DomainException(sprintf(
                'The %s is already booked for part of that period (%s). Two households told the same '.
                'Saturday is theirs is the one failure a booking diary exists to prevent.',
                $amenity->name,
                $taken->reference,
            ));
        }
    }

    /**
     * "BKG-2026-09-0001" — sequential within the month the booking falls in.
     *
     * The same shape as a charge, a receipt and a payment plan, because a
     * resident reads it back over the phone and a manager looks it up beside the
     * others.
     */
    private function nextReference(Carbon $on): string
    {
        $stem = 'BKG-'.$on->format('Y-m').'-';

        $last = AmenityBooking::query()
            ->where('reference', 'like', $stem.'%')
            ->lockForUpdate()
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last === null ? 1 : ((int) substr((string) $last, strlen($stem))) + 1;

        return $stem.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function asMoment(Carbon|string $moment): Carbon
    {
        return $moment instanceof Carbon ? $moment->copy() : Carbon::parse($moment);
    }
}
