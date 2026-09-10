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
 * A HOUSEHOLD IN ARREARS IS BLOCKED, ON THE ESTATE'S ONE THRESHOLD. Not the
 * module's own: Q-008 was ruled with "one arrears threshold across the estate,
 * not two — a household is either in arrears or it is not", so `mayBook()` reads
 * the same `arrears_restriction_*` settings the gate uses and an active payment
 * plan lifts it exactly as it lifts the gate.
 *
 * `mayBook()` returns a BOOLEAN and never a figure — the person reading board 19
 * is the Property Manager, who holds `facilities` in full and is locked out of
 * `dues_ledger`, `payments` and `accounting_posting` by platform invariant
 * (D-010). "This household cannot book" is a facilities fact; "this household
 * owes J$44,900" is not theirs to see, and a refusal message that named the
 * amount would hand it to them through the back door.
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
 * A DEPOSIT IS A LIABILITY, AND IT REACHES THE LEDGER. Board 19's accounting
 * note is exact about what each state means in the books: only "held" belongs on
 * the deposits-held liability, and posting "awaiting" would overstate that
 * account by money the estate does not have. This class used to record the state
 * and post nothing at all — the client overruled that on Q-009, because cash had
 * moved and the ledger said nothing.
 *
 *     taken      Dr 1000 Bank            Cr 2200 Deposits Held
 *     refunded   Dr 2200 Deposits Held   Cr 1000 Bank
 *     forfeited  Dr 2200 Deposits Held   Cr 4100 Amenity Booking Fees
 *
 * Only the third is ever income, and NONE of them touches 1200 Dues Receivable.
 * A deposit is the estate holding somebody's money, not somebody owing the
 * estate money — the mirror image of a charge, and the half most easily got
 * wrong. It appears in no dues statement and on no arrears report.
 *
 * // ASSUMPTION Q-008 — one arrears threshold across the estate, not two. Ruled;
 * // `mayBook()` reads the gate's own settings and a payment plan lifts both.
 * // ASSUMPTION Q-009 — the deposit entries above. Ruled: a deposit is a
 * // liability, it posts, and it never reaches receivables. What remains open is
 * // WHO may make the entry, and no route reaches these methods yet.
 */
class Amenities
{
    /** The income account an amenity fee credits — board 25's chart. */
    public const FEE_ACCOUNT = '4100';

    /**
     * Where a deposit sits while the estate is holding it.
     *
     * A LIABILITY, NOT INCOME, AND NOT A RECEIVABLE. The estate is holding
     * somebody else's money and owes it back; it becomes income only if it is
     * forfeited. 2200 Resident Deposits Held has been in the chart since board
     * 25 was transcribed and nothing posted to it until the client ruled on
     * Q-009 — which is why the account read zero while three bookings on board
     * 19 said "held".
     */
    public const DEPOSIT_ACCOUNT = '2200';

    /** Where the cash actually lands and leaves from. */
    public const BANK_ACCOUNT = '1000';

    /** Board 35's charge type for one of these. */
    public const CHARGE_TYPE = 'amenity';

    /** Resolved on first use — see `mayBook()` for why it is not a constructor edge. */
    private ?Collections $collections = null;

    public function __construct(
        private readonly Dues $dues,
        private readonly Ledger $ledger,
    ) {}

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
     * ONE ARREARS THRESHOLD ACROSS THE ESTATE, NOT TWO — the client's ruling on
     * Q-008. This used to read its own `amenity_arrears_block_enabled` and
     * `amenity_arrears_block_days`, defaulting to off, so an estate could have
     * ended up admitting a household's visitors at the gate on Friday and
     * refusing the household itself the Club House on Saturday. A household is
     * either in arrears or it is not. Both figures now come from the same
     * `arrears_restriction_*` settings the gate uses, and moving one moves both.
     *
     * AND A PAYMENT PLAN LIFTS IT, SAME AS THE GATE. A household that has agreed
     * a schedule and is meeting it is not a household to turn away from a
     * birthday party — the arrears were given a schedule, not forgiven, and
     * board 7's whole purpose is that keeping to one restores normal life.
     * `Collections::isProtected()` is the same check `RestrictionPolicy` makes,
     * called rather than reimplemented, so the two answers cannot drift.
     */
    public function mayBook(Unit $unit, ?Carbon $asAt = null): bool
    {
        $settings = EstateSetting::current();

        if (! $settings->arrears_restriction_enabled) {
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

        if ((int) $oldest->diffInDays($today, absolute: false) < $settings->arrears_restriction_days) {
            return true;
        }

        /*
         * Resolved here rather than in the constructor, the way
         * `RestrictionPolicy` does it: collections reads the dues ledger and
         * this class is constructed from a facilities route, and a constructor
         * edge between the two is a cycle waiting for somebody to add one more
         * dependency.
         */
        $this->collections ??= app(Collections::class);

        return $this->collections->isProtected($unit);
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
                'Bookings are closed to %s while the household is in arrears. The dues office can settle '.
                'it, or agree a payment plan — a plan being met lifts this the same way it lifts the gate.',
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
     * The deposit has been paid in — Dr 1000 Bank, Cr 2200 Deposits Held.
     *
     * A POSTING, AND IT DID NOT USED TO BE. This recorded a state and raised no
     * entry, on the reasoning that moving cash belongs to whoever holds
     * `payments` and a facilities route holds neither that nor
     * `accounting_posting`. The client overruled it on Q-009: the money is in
     * the bank, so the ledger has to say so.
     *
     * THE PERMISSION QUESTION IS STILL OPEN, AND IT IS OPEN HARMLESSLY. There is
     * no HTTP route to any of the three deposit actions — board 19 draws the
     * deposit STATE and no control that changes it, and the booking detail
     * screen that would carry one is not on any approved board. So the only
     * callers today are this estate's seeder and its tests, and nobody reaches
     * these methods through a permission boundary at all.
     *
     * WHEN A ROUTE LANDS IT MUST CARRY `estate.accounting_posting.create`
     * ALONGSIDE THE FACILITIES GATE, the way `booking.fee` carries the dues one
     * — otherwise the Property Manager D-010 locks out of the books would be
     * moving cash through a facilities screen. `EstateFacilitiesAmenitiesTest`
     * asserts no such route exists yet, so this stops being a comment the day
     * somebody adds one.
     */
    public function holdDeposit(
        AmenityBooking $booking,
        Carbon|string|null $on = null,
        ?User $by = null,
    ): AmenityBooking {
        if ($booking->deposit_minor <= 0) {
            throw new DomainException(sprintf(
                'Booking %s carries no deposit. Recording one held would put a liability on the estate '.
                'for money nobody was asked for.',
                $booking->reference,
            ));
        }

        if ($booking->deposit_state === AmenityBooking::DEPOSIT_HELD) {
            throw new DomainException(sprintf(
                'Booking %s is already recorded as held. Taking it twice would post the deposit to the '.
                'bank twice and leave the estate owing back money it never received.',
                $booking->reference,
            ));
        }

        $memo = 'Deposit held — '.$booking->reference;

        return DB::connection('tenant')->transaction(function () use ($booking, $memo, $by, $on): AmenityBooking {
            /*
             * CASH MOVED, SO THE LEDGER SAYS SO. This was recorded as a state
             * and nothing else until the client overruled it on Q-009: a
             * deposit received is real money in the operating account and a
             * real liability against it, and a booking row saying "held" over a
             * ledger that had never heard of it is the estate's own books
             * disagreeing with its own diary.
             */
            $entry = $this->ledger->post(
                memo: $memo,
                postings: [
                    Posting::debit(self::BANK_ACCOUNT, $booking->deposit_minor, $memo),
                    Posting::credit(self::DEPOSIT_ACCOUNT, $booking->deposit_minor, $memo),
                ],
                on: $on === null ? Carbon::today() : $this->asMoment($on),
                source: Ledger::SOURCE_DEPOSIT_HELD,
                sourceId: $booking->id,
                by: $by,
            );

            $booking->forceFill([
                'deposit_state' => AmenityBooking::DEPOSIT_HELD,
                'deposit_refunded_on' => null,
                'deposit_journal_ref' => $entry->reference,
            ])->save();

            return $booking;
        });
    }

    /**
     * The deposit has gone back — board 19's "$5,000 refunded Aug 18".
     *
     * Dr 2200, Cr 1000: the liability is discharged and the cash leaves. The
     * mirror of taking it, and it never reaches income — a deposit returned was
     * never the estate's to earn.
     */
    public function refundDeposit(
        AmenityBooking $booking,
        Carbon|string|null $on = null,
        ?User $by = null,
    ): AmenityBooking {
        if ($booking->deposit_state !== AmenityBooking::DEPOSIT_HELD) {
            throw new DomainException(sprintf(
                'Booking %s holds no deposit to refund — it is %s. Refunding money the estate never '.
                'received would take it out of the operating account.',
                $booking->reference,
                $booking->deposit_state,
            ));
        }

        $refundedOn = $on === null ? Carbon::today() : $this->asMoment($on);
        $memo = 'Deposit refunded — '.$booking->reference;

        return DB::connection('tenant')->transaction(function () use ($booking, $memo, $refundedOn, $by): AmenityBooking {
            $entry = $this->ledger->post(
                memo: $memo,
                postings: [
                    Posting::debit(self::DEPOSIT_ACCOUNT, $booking->deposit_minor, $memo),
                    Posting::credit(self::BANK_ACCOUNT, $booking->deposit_minor, $memo),
                ],
                on: $refundedOn,
                source: Ledger::SOURCE_DEPOSIT_REFUND,
                sourceId: $booking->id,
                by: $by,
            );

            $booking->forceFill([
                'deposit_state' => AmenityBooking::DEPOSIT_REFUNDED,
                'deposit_refunded_on' => $refundedOn->toDateString(),
                'deposit_journal_ref' => $entry->reference,
            ])->save();

            return $booking;
        });
    }

    /**
     * The estate is keeping it — damage, or the amenity was not handed back.
     *
     * Dr 2200, Cr 4100: THE ONLY PATH ON WHICH A DEPOSIT EVER BECOMES INCOME.
     * The liability is discharged because the estate no longer owes it back, and
     * the same figure lands in Amenity Booking Fees. No cash moves — it was
     * already in the bank from the day it was taken — which is why the bank
     * account is absent from this entry and present in the other two.
     *
     * A REASON IS REQUIRED. Keeping a resident's J$5,000 is the one deposit act
     * somebody will be asked to justify, and "forfeited" with nothing after it
     * is not an answer a committee can give them.
     */
    public function forfeitDeposit(
        AmenityBooking $booking,
        string $reason,
        Carbon|string|null $on = null,
        ?User $by = null,
    ): AmenityBooking {
        if ($booking->deposit_state !== AmenityBooking::DEPOSIT_HELD) {
            throw new DomainException(sprintf(
                'Booking %s holds no deposit to forfeit — it is %s. The estate cannot keep money it is '.
                'not holding.',
                $booking->reference,
                $booking->deposit_state,
            ));
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(
                'Say why the deposit is being kept. Forfeiting is the one deposit act a resident will '.
                'ask the estate to justify, and a blank reason is not an answer.'
            );
        }

        $forfeitedOn = $on === null ? Carbon::today() : $this->asMoment($on);
        $memo = 'Deposit forfeited — '.$booking->reference.' — '.$reason;

        return DB::connection('tenant')->transaction(function () use ($booking, $memo, $reason, $forfeitedOn, $by): AmenityBooking {
            $entry = $this->ledger->post(
                memo: $memo,
                postings: [
                    Posting::debit(self::DEPOSIT_ACCOUNT, $booking->deposit_minor, $memo),
                    Posting::credit(self::FEE_ACCOUNT, $booking->deposit_minor, $memo),
                ],
                on: $forfeitedOn,
                source: Ledger::SOURCE_DEPOSIT_FORFEIT,
                sourceId: $booking->id,
                by: $by,
            );

            $booking->forceFill([
                'deposit_state' => AmenityBooking::DEPOSIT_FORFEITED,
                'deposit_forfeit_reason' => $reason,
                'deposit_journal_ref' => $entry->reference,
            ])->save();

            return $booking;
        });
    }

    /** The event has happened and the amenity was handed back. */
    public function complete(
        AmenityBooking $booking,
        Carbon|string|null $refundedOn = null,
        ?User $by = null,
    ): AmenityBooking {
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
            $this->refundDeposit($booking, $refundedOn, $by);
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
