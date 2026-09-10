<?php

declare(strict_types=1);

use App\Models\Estate\Amenity;
use App\Models\Estate\AmenityBooking;
use App\Models\Estate\AmenitySlot;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Journal;
use App\Models\Role;
use App\Services\Estate\Amenities;
use App\Services\Estate\Dues;
use App\Services\Estate\Ledger;
use App\Services\Estate\Posting;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The booking diary and the rate card behind it — boards 19 and 20
|--------------------------------------------------------------------------
|
| TWO GUARANTEES ARE UNDER TEST HERE AND EVERYTHING ELSE IS SUPPORT.
|
| The first is that A BOOKING CARRIES THE TERMS IT WAS MADE UNDER. The fee, the
| deposit and the cancellation rule are copied off the amenity when the booking
| is made and never read back through the relation. A booking that read the
| amenity live would restate every held deposit in the estate the moment a
| manager typed a new figure into board 20 — and the estate would then owe
| residents an amount its own books no longer showed. So the rate card is edited
| below, under a deposit the estate is already holding, and the deposit must not
| move by one cent.
|
| The second is that AN AMENITY FEE IS MONEY, and money is proven by lines. Not
| "the number renders": the J$3,000 on board 19 equals the debit posted to 1200
| Dues Receivable, equals the credit posted to 4100 Amenity Booking Fees, equals
| the movement in that unit's own sub-ledger. Each of those is summed here in raw
| SQL written out longhand, so the expectation and the application reach the same
| figure by two different routes — and the entry behind them is then attacked
| live, inside rolled-back transactions, the way `gate:ledger` attacks the real
| estate. An audit proves nothing was broken yesterday; a probe proves it cannot
| be broken today.
|
| AND THE PERSONA THE BOARD IS DRAWN FOR MAY NOT DO IT. Board 19 names "record a
| fee" among the Property Manager's actions; recording it posts a charge against
| a unit, which is `estate.dues_ledger.create`, which D-010 locks that role out
| of entirely. The invariant outranks the board, and the assertion below is an
| HTTP 403 rather than a greyed button.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();

    // Committed, and before the transaction below: the permission matrix and
    // the estate itself are the ground these tests stand on, not part of what
    // any one of them writes.
    FacilitiesFixture::platform();

    /*
     * The users each test issues live in the PLATFORM database and are rolled
     * back with it. The estate's own database is not transacted — it is built
     * once per process and these tests read the queue and the diary the seeder
     * left there, exactly as `CollectionsFixture` does.
     */
    DB::connection('mysql')->beginTransaction();

    $this->amenities = app(Amenities::class);
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

/**
 * One account's balance in the signed form the ledger stores it: debits less
 * credits, summed independently in raw SQL.
 *
 * A receivable is an asset and rises with a debit; income sits on the other
 * side, so 4100 moves DOWN in this form when the estate earns something. The
 * sign is kept rather than normalised because what these tests check is which
 * side each line landed on.
 */
function facilitiesNetDebits(string $code): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS net
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ?
    ', [$code])->net;
}

/** What one unit owes, from that unit's own lines on the control account. */
function facilitiesUnitReceivable(int $unitId): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS net
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.unit_id = ?
    ', ['1200', $unitId])->net;
}

/** Every deposit the estate is actually holding, from the bookings themselves. */
function depositsHeld(): int
{
    return (int) AmenityBooking::query()
        ->where('deposit_state', AmenityBooking::DEPOSIT_HELD)
        ->sum('deposit_minor');
}

/* ------------------------------------------------------------------ */
/* board 20 — the rate card */
/* ------------------------------------------------------------------ */

it('draws board 20 four cards with the amounts and with the words', function () {
    $rows = collect($this->amenities->settingsBoard()['rows'])->keyBy('name');

    // The board's own order, which is neither alphabetical nor by capacity nor
    // by fee — so it is somebody's choice and it is stored.
    expect(array_column($this->amenities->settingsBoard()['rows'], 'name'))
        ->toBe(['Gazebo', 'Club House', 'Community Centre', 'Pool Deck']);

    expect($rows['Gazebo']['fee_minor'])->toBe(3_000_00)
        ->and($rows['Gazebo']['fee_label'])->toBe('$3,000')
        ->and($rows['Gazebo']['deposit_minor'])->toBe(5_000_00)
        ->and($rows['Gazebo']['deposit_label'])->toBe('$5,000')
        ->and($rows['Club House']['capacity_label'])->toBe('60 guests');

    /*
     * NULL IS NOT ZERO ON EITHER AMOUNT. "Free for residents" and "None" are
     * statements about the amenity rather than amounts, and the board's own
     * accounting note is explicit that a nil amenity must produce a booking
     * with zero journal lines rather than a zero-amount entry.
     */
    expect($rows['Pool Deck']['fee_minor'])->toBeNull()
        ->and($rows['Pool Deck']['fee_label'])->toBe(Amenity::FREE_LABEL)
        ->and($rows['Pool Deck']['deposit_minor'])->toBeNull()
        ->and($rows['Pool Deck']['deposit_label'])->toBe(Amenity::NO_DEPOSIT_LABEL);
});

it('reads the twenty-fourth hour as midnight rather than as closed all day', function () {
    $centre = FacilitiesFixture::amenity('Community Centre');

    // Stored as 24:00:00, which MySQL's TIME type carries and Carbon cannot
    // parse — which is why nothing casts this column.
    expect($centre->closes_at)->toBe('24:00:00')
        ->and($centre->hoursLabel())->toBe('9:00 AM – Midnight');

    $day = Carbon::today()->addDays(40);

    // An evening booking, and one that runs right up to midnight. Both are
    // inside the hours of an amenity that closes at the end of the day.
    expect($centre->isWithinHours($day->copy()->addHours(18), $day->copy()->addHours(22)))->toBeTrue()
        ->and($centre->isWithinHours($day->copy()->addHours(18), $day->copy()->addDay()))->toBeTrue();

    /*
     * AND THIS IS WHAT 00:00:00 WOULD HAVE MEANT. The same evening against the
     * same opening time, closing at the twenty-fourth hour written the other
     * way: shut before it opened, and every booking on the board refused.
     */
    $midnightAsZero = new Amenity(['opens_at' => '09:00:00', 'closes_at' => '00:00:00', 'currency' => 'JMD']);

    expect($midnightAsZero->isWithinHours($day->copy()->addHours(18), $day->copy()->addHours(22)))->toBeFalse();

    // The diary agrees with the card: a booking to midnight is accepted.
    $booking = $this->amenities->book(
        amenity: $centre,
        unit: FacilitiesFixture::unit('Lot 88'),
        residentName: 'Sonia Campbell',
        startsAt: $day->copy()->addHours(18),
        endsAt: $day->copy()->addDay(),
    );

    expect($booking->status)->toBe(AmenityBooking::PENDING);

    // A booking past a real closing time is still refused, so the assertion
    // above is about midnight and not about the guard being off.
    expect(fn () => $this->amenities->book(
        amenity: FacilitiesFixture::amenity('Pool Deck'),
        unit: FacilitiesFixture::unit('Lot 88'),
        residentName: 'Sonia Campbell',
        startsAt: $day->copy()->addHours(19),
        endsAt: $day->copy()->addHours(22),
    ))->toThrow(DomainException::class, 'is open');
});

/* ------------------------------------------------------------------ */
/* the snapshot — the assertion this module exists for */
/* ------------------------------------------------------------------ */

it('records a deposit as a state and posts no journal line for it — ASSUMPTION Q-009', function () {
    /*
     * BOARD 19 DRAWS THREE DEPOSIT STATES AND THIS CLASS POSTS NONE OF THEM.
     *
     * Its accounting note is exact: only "held" belongs on the deposits-held
     * liability, and posting "awaiting payment" would overstate that account by
     * money the estate does not physically have. What the note does not say is
     * WHO moves the cash — and moving cash sits behind `payments` and
     * `accounting_posting`, neither of which a facilities route holds (D-010).
     *
     * So the honest position, until the client rules: the booking carries the
     * state and the books carry nothing. This asserts that, because "we did not
     * get to it" and "we deliberately post nothing here" look identical in a
     * ledger and only one of them is a decision. QUESTIONS.md Q-009.
     */
    $gazebo = FacilitiesFixture::amenity('Gazebo');
    $unit = FacilitiesFixture::unit('Lot 9');

    $journalsBefore = Journal::query()->count();
    $unitBefore = facilitiesUnitReceivable($unit->id);

    $booking = $this->amenities->book(
        amenity: $gazebo,
        unit: $unit,
        residentName: 'Ricardo Hall',
        startsAt: Carbon::today()->addDays(52)->addHours(11),
        endsAt: Carbon::today()->addDays(52)->addHours(13),
        guests: 20,
    );

    $this->amenities->holdDeposit($booking);

    expect($booking->refresh()->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and($booking->deposit_minor)->toBe(5_000_00);

    // The deposit is real, on the booking, and invisible to the ledger. Not one
    // journal, and not a cent against the unit — a deposit is not a charge.
    expect(Journal::query()->count())->toBe($journalsBefore)
        ->and(facilitiesUnitReceivable($unit->id))->toBe($unitBefore);

    // And refunding it posts nothing either, because there was nothing to
    // reverse. If a ruling gives facilities a posting path, this is the test
    // that changes.
    $this->amenities->refundDeposit($booking);

    expect($booking->refresh()->deposit_state)->toBe(AmenityBooking::DEPOSIT_REFUNDED)
        ->and(Journal::query()->count())->toBe($journalsBefore);
});

it('does not move a deposit the estate is holding when the rate card is edited', function () {
    $gazebo = FacilitiesFixture::amenity('Gazebo');

    // The estate sets its cancellation rule on the edit screen; the seeder
    // leaves it unset rather than inventing one board 20 does not draw.
    $gazebo->forceFill(['cancellation_hours' => 48])->save();

    $booking = $this->amenities->book(
        amenity: $gazebo,
        unit: FacilitiesFixture::unit('Lot 9'),
        residentName: 'Ricardo Hall',
        startsAt: Carbon::today()->addDays(45)->addHours(11),
        endsAt: Carbon::today()->addDays(45)->addHours(13),
        guests: 20,
    );

    // Quoted and not received: it must not reach the deposits-held account
    // until somebody actually pays it.
    expect($booking->deposit_state)->toBe(AmenityBooking::DEPOSIT_AWAITING);

    $this->amenities->holdDeposit($booking);

    expect($booking->refresh()->deposit_state)->toBe(AmenityBooking::DEPOSIT_HELD)
        ->and($booking->depositLabel())->toBe('$5,000 held');

    $heldBefore = depositsHeld();

    /*
     * THE EDIT. A manager raises the Gazebo's fee, triples its deposit and
     * doubles its cancellation window — on Tuesday, over a booking the estate
     * accepted on Monday and a deposit it is already holding.
     */
    $gazebo->forceFill([
        'booking_fee_minor' => 9_000_00,
        'deposit_minor' => 25_000_00,
        'cancellation_hours' => 96,
    ])->save();

    $reloaded = AmenityBooking::findOrFail($booking->id);

    /*
     * THE ONE ASSERTION THE SNAPSHOT COLUMNS EXIST FOR. Every figure is the one
     * the household agreed to. Read back through the relation instead, board
     * 19's Deposit column would now say $25,000 held — for money nobody paid —
     * and the deposits-held control account would stop agreeing with the sum of
     * open bookings.
     */
    expect($reloaded->fee_minor)->toBe(3_000_00)
        ->and($reloaded->deposit_minor)->toBe(5_000_00)
        ->and($reloaded->cancellation_hours)->toBe(48)
        ->and($reloaded->currency)->toBe('JMD')
        ->and($reloaded->depositLabel())->toBe('$5,000 held')

        // And not one deposit anywhere in the estate moved.
        ->and(depositsHeld())->toBe($heldBefore)

        // While the amenity itself really did change — without which the four
        // assertions above would hold over an edit that never happened.
        ->and($reloaded->amenity->deposit_minor)->toBe(25_000_00);

    // A booking made AFTER the edit carries the new terms, which is what makes
    // this a snapshot at creation rather than a figure frozen forever.
    $later = $this->amenities->book(
        amenity: $gazebo,
        unit: FacilitiesFixture::unit('Lot 9'),
        residentName: 'Ricardo Hall',
        startsAt: Carbon::today()->addDays(46)->addHours(11),
        endsAt: Carbon::today()->addDays(46)->addHours(13),
    );

    expect($later->fee_minor)->toBe(9_000_00)
        ->and($later->deposit_minor)->toBe(25_000_00)
        ->and($later->cancellation_hours)->toBe(96);

    // Board 20's own figures back, because these tests share one estate.
    $gazebo->forceFill([
        'booking_fee_minor' => 3_000_00,
        'deposit_minor' => 5_000_00,
        'cancellation_hours' => null,
    ])->save();

    expect(AmenityBooking::findOrFail($later->id)->fee_minor)->toBe(9_000_00);
});

it('refuses to hold or refund a deposit the estate never asked for', function () {
    $booking = $this->amenities->book(
        amenity: FacilitiesFixture::amenity('Pool Deck'),
        unit: FacilitiesFixture::unit('Lot 3'),
        residentName: 'Rachel Bennett',
        startsAt: Carbon::today()->addDays(50)->addHours(9),
        endsAt: Carbon::today()->addDays(50)->addHours(11),
    );

    // Recording one held would put a liability on the estate for money nobody
    // was asked for; refunding one would take cash out of the operating account.
    expect($booking->deposit_state)->toBe(AmenityBooking::DEPOSIT_NONE)
        ->and($booking->depositLabel())->toBe('')
        ->and(fn () => $this->amenities->holdDeposit($booking))
        ->toThrow(DomainException::class, 'no deposit')
        ->and(fn () => $this->amenities->refundDeposit($booking))
        ->toThrow(DomainException::class, 'holds no deposit');
});

/* ------------------------------------------------------------------ */
/* the fee, traced to the lines it is made of */
/* ------------------------------------------------------------------ */

it('charges a booking fee as a debit to 1200 and a credit to 4100', function () {
    $booking = FacilitiesFixture::booking('Gazebo', 'Lot 47');

    expect($booking->fee_minor)->toBe(3_000_00)
        ->and($booking->isCharged())->toBeFalse();

    $receivableBefore = facilitiesNetDebits('1200');
    $incomeBefore = facilitiesNetDebits(Amenities::FEE_ACCOUNT);
    $unitBefore = facilitiesUnitReceivable($booking->unit_id);
    $depositsBefore = facilitiesNetDebits('2200');

    $charge = $this->amenities->recordFee($booking);

    /*
     * THE MONEY-MODULE RULE, in full. Every figure a screen shows that is money
     * has to be traceable to posted lines — so the fee is not proven by the
     * charge row saying 3,000, it is proven by the control account moving by
     * exactly 3,000 and the unit's own sub-ledger moving by the same.
     */
    expect(facilitiesNetDebits('1200') - $receivableBefore)->toBe(3_000_00)
        ->and(facilitiesUnitReceivable($booking->unit_id) - $unitBefore)->toBe(3_000_00)

        // Income sits on the credit side, so the signed form moves down by the
        // fee: 4100 was credited with exactly what 1200 was debited.
        ->and(facilitiesNetDebits(Amenities::FEE_ACCOUNT) - $incomeBefore)->toBe(-3_000_00)

        /*
         * AND THE DEPOSIT DID NOT POST ANYWHERE. This booking has J$5,000 held
         * against it and 2200 Resident Deposits Held is untouched — a deposit is
         * a state here and a posting somewhere else, behind `payments`, which no
         * facilities route holds.
         */
        ->and(facilitiesNetDebits('2200'))->toBe($depositsBefore);

    // The charge is a charge on a unit like any other, on the account board 25
    // keeps amenity income in.
    expect($charge->amount_minor)->toBe(3_000_00)
        ->and($charge->currency)->toBe('JMD')
        ->and($charge->type)->toBe(Amenities::CHARGE_TYPE)
        ->and($charge->journal_ref)->not->toBeNull();

    $lines = DB::connection('tenant')->select('
        SELECT a.code, l.debit_minor, l.credit_minor, l.unit_id
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE l.entry_ref = ?
         ORDER BY l.line_no
    ', [$charge->journal_ref]);

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->code)->toBe('1200')
        ->and((int) $lines[0]->debit_minor)->toBe(3_000_00)

        // The UNIT is on the receivable line, which is the whole of the
        // sub-ledger: a 1200 line with no unit on it sits inside the control
        // balance and outside every household's statement.
        ->and((int) $lines[0]->unit_id)->toBe($booking->unit_id)
        ->and($lines[1]->code)->toBe(Amenities::FEE_ACCOUNT)
        ->and((int) $lines[1]->credit_minor)->toBe(3_000_00)
        ->and($lines[1]->unit_id)->toBeNull();

    // Debits equal credits, on this entry and across the whole estate.
    expect((int) $lines[0]->debit_minor)->toBe((int) $lines[1]->credit_minor);

    $trial = DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(debit_minor), 0) AS debits, COALESCE(SUM(credit_minor), 0) AS credits
          FROM journal_lines
    ');

    expect((int) $trial->debits)->toBe((int) $trial->credits);

    /*
     * THE POINTER, AND NO AMOUNT BESIDE IT. The booking keeps the charge's id
     * and nothing else, so the fee on the resident's statement and the fee
     * behind their booking are one row read two ways. It can happen once: a
     * household billed twice for one Saturday has to be refunded through a
     * credit note nobody would have raised.
     */
    expect($booking->refresh()->fee_charge_id)->toBe($charge->id)
        ->and($booking->isCharged())->toBeTrue()
        ->and(fn () => $this->amenities->recordFee($booking))
        ->toThrow(DomainException::class, 'already charged');
});

it('raises no entry at all for an amenity that costs nothing', function () {
    $booking = $this->amenities->book(
        amenity: FacilitiesFixture::amenity('Pool Deck'),
        unit: FacilitiesFixture::unit('Lot 63'),
        residentName: 'Keith Walters',
        startsAt: Carbon::today()->addDays(55)->addHours(9),
        endsAt: Carbon::today()->addDays(55)->addHours(11),
    );

    $entries = Journal::query()->count();

    // "A booking with zero journal lines rather than a zero-amount entry" —
    // board 20's accounting note, verbatim. A zero-value entry is a row in the
    // ledger claiming something happened.
    expect(fn () => $this->amenities->recordFee($booking))
        ->toThrow(DomainException::class, 'no booking fee');

    expect(Journal::query()->count())->toBe($entries)
        ->and($booking->refresh()->fee_charge_id)->toBeNull();
});

it('refuses to charge a household for a booking the estate turned down', function () {
    $booking = $this->amenities->book(
        amenity: FacilitiesFixture::amenity('Club House'),
        unit: FacilitiesFixture::unit('Lot 3'),
        residentName: 'Rachel Bennett',
        startsAt: Carbon::today()->addDays(60)->addHours(15),
        endsAt: Carbon::today()->addDays(60)->addHours(19),
    );

    // "Declined" on its own tells the household nothing they can do anything
    // about, and they will ask a guard at a gate about it.
    expect(fn () => $this->amenities->decline($booking, '  '))
        ->toThrow(DomainException::class, 'Say why');

    $this->amenities->decline($booking, 'The Club House is closed for resurfacing that weekend.');

    $entries = Journal::query()->count();

    expect(fn () => $this->amenities->recordFee($booking))
        ->toThrow(DomainException::class, 'declined');

    expect(Journal::query()->count())->toBe($entries)
        ->and($booking->refresh()->declined_reason)
        ->toBe('The Club House is closed for resurfacing that weekend.');
});

it('cannot edit or delete the entry a booking fee posted', function () {
    $booking = FacilitiesFixture::booking('Club House', 'Lot 88');

    $charge = $this->amenities->recordFee($booking);

    $entry = Journal::query()->where('reference', $charge->journal_ref)->firstOrFail();

    /*
     * PROBED LIVE, INSIDE ROLLED-BACK TRANSACTIONS, the way `gate:ledger` does
     * it — an audit proves nothing was broken yesterday, a probe proves it
     * cannot be broken today. MySQL aborts the offending STATEMENT when a
     * trigger signals rather than the whole transaction, so the rollback is what
     * guarantees each probe changed nothing either way.
     *
     * The estate's own MySQL user also has UPDATE and DELETE revoked on both
     * tables; this suite connects as the schema owner, so what refuses here is
     * the trigger. Both halves are deliberate and `gate:ledger` proves the grant
     * against the real estates.
     */
    $refuses = function (callable $attempt): bool {
        $connection = DB::connection('tenant');
        $connection->beginTransaction();

        try {
            $attempt();

            return false;
        } catch (Throwable) {
            return true;
        } finally {
            $connection->rollBack();
        }
    };

    // POSITIVE CONTROL FIRST. Without it every refusal below passes when the
    // ledger is simply broken, and the test reports an enforcement it never
    // demonstrated.
    expect($refuses(fn () => app(Ledger::class)->post('facilities probe', [
        Posting::debit('1200', 100, 'probe', unitId: $booking->unit_id),
        Posting::credit(Amenities::FEE_ACCOUNT, 100, 'probe'),
    ])))->toBeFalse();

    expect($refuses(fn () => DB::connection('tenant')->table('journals')
        ->where('id', $entry->id)->update(['memo' => 'edited'])))->toBeTrue();

    expect($refuses(fn () => DB::connection('tenant')->table('journals')
        ->where('id', $entry->id)->delete()))->toBeTrue();

    expect($refuses(fn () => DB::connection('tenant')->table('journal_lines')
        ->where('entry_ref', $entry->reference)->update(['debit_minor' => 1])))->toBeTrue();

    expect($refuses(fn () => DB::connection('tenant')->table('journal_lines')
        ->where('entry_ref', $entry->reference)->delete()))->toBeTrue();

    // And a line cannot be slipped into an entry that is already posted, which
    // is how an unbalanced entry would otherwise be built in two steps.
    $account = (int) DB::connection('tenant')->table('journal_lines')
        ->where('entry_ref', $entry->reference)
        ->value('account_id');

    expect($refuses(fn () => DB::connection('tenant')->table('journal_lines')->insert([
        'entry_ref' => $entry->reference,
        'account_id' => $account,
        'line_no' => 99,
        'debit_minor' => 100,
        'credit_minor' => 0,
        'currency' => 'JMD',
        'created_at' => now(),
    ])))->toBeTrue();

    // Nothing the probes attempted survived them.
    $entry->refresh();

    expect($entry->memo)->not->toBe('edited')
        ->and($entry->lines()->count())->toBe(2);

    /*
     * And the tie the control account rests on still holds: every line on 1200
     * carries the unit it belongs to. A receivable line with no unit named sits
     * inside the control balance and on nobody's statement, which is the drift
     * a sub-ledger exists to make visible.
     */
    $orphaned = (int) DB::connection('tenant')->selectOne('
        SELECT COUNT(*) AS n
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.unit_id IS NULL
    ', ['1200'])->n;

    expect($orphaned)->toBe(0);
});

/* ------------------------------------------------------------------ */
/* the diary itself */
/* ------------------------------------------------------------------ */

it('refuses two households the same Saturday and says which of the two reasons it is', function () {
    $gazebo = FacilitiesFixture::amenity('Gazebo');
    $day = Carbon::today()->addDays(70);

    $this->amenities->book(
        amenity: $gazebo,
        unit: FacilitiesFixture::unit('Lot 47'),
        residentName: 'Andrea Fletcher',
        startsAt: $day->copy()->addHours(11),
        endsAt: $day->copy()->addHours(15),
    );

    // Two households told the same Saturday is theirs is the one failure a
    // booking diary exists to prevent — and a pending booking holds the slot
    // while it waits, or two of them would each be told it was theirs.
    expect(fn () => $this->amenities->book(
        amenity: $gazebo,
        unit: FacilitiesFixture::unit('Lot 3'),
        residentName: 'Rachel Bennett',
        startsAt: $day->copy()->addHours(14),
        endsAt: $day->copy()->addHours(16),
    ))->toThrow(DomainException::class, 'already booked');

    // Half-open on the right: a Gazebo booked until 3:00 PM and another from
    // 3:00 PM are two bookings, which is how a Saturday actually runs.
    $backToBack = $this->amenities->book(
        amenity: $gazebo,
        unit: FacilitiesFixture::unit('Lot 3'),
        residentName: 'Rachel Bennett',
        startsAt: $day->copy()->addHours(15),
        endsAt: $day->copy()->addHours(17),
    );

    expect($backToBack->status)->toBe(AmenityBooking::PENDING);

    // And a blackout cannot be dropped over a booking that already exists: the
    // household would turn up to a locked gate with a confirmation in hand.
    expect(fn () => $this->amenities->blockSlot(
        amenity: $gazebo,
        startsAt: $day->copy()->addHours(10),
        endsAt: $day->copy()->addHours(12),
        reason: 'Resurfacing',
    ))->toThrow(DomainException::class, 'already covers part of that period');
});

it('draws the diary by when each booking starts and not by when it was taken', function () {
    $gazebo = FacilitiesFixture::amenity('Gazebo');

    // Two bookings in the SAME MONTH, taken in the opposite order to the one
    // they happen in. Same month is where this matters: a reference is
    // sequential within the month a booking falls in, so within one month it
    // records the order the office took the calls in and nothing else.
    $month = Carbon::today()->startOfMonth()->addMonths(4);

    $later = $this->amenities->book(
        amenity: $gazebo,
        unit: FacilitiesFixture::unit('Lot 47'),
        residentName: 'Andrea Fletcher',
        startsAt: $month->copy()->addDays(20)->addHours(11),
        endsAt: $month->copy()->addDays(20)->addHours(13),
    );

    $sooner = $this->amenities->book(
        amenity: $gazebo,
        unit: FacilitiesFixture::unit('Lot 3'),
        residentName: 'Rachel Bennett',
        startsAt: $month->copy()->addDays(10)->addHours(11),
        endsAt: $month->copy()->addDays(10)->addHours(13),
    );

    // The booking that happens FIRST carries the LATER reference, which is what
    // makes this pair the case a reference sort gets wrong.
    expect(strcmp($sooner->reference, $later->reference))->toBeGreaterThan(0);

    $rows = $this->amenities->bookingsBoard('Gazebo')['rows'];
    $order = array_flip(array_column($rows, 'reference'));

    expect($order[$sooner->reference])->toBeLessThan($order[$later->reference]);

    /*
     * And the whole diary reads forwards: every booking still to come, in the
     * order it will happen, and the ones that already have at the bottom. A
     * manager scanning board 19 is looking for what they have to do something
     * about, and a Saturday drawn below the Wednesday after it is a Saturday
     * nobody prepared for.
     */
    $bookings = AmenityBooking::query()
        ->whereIn('reference', array_column($rows, 'reference'))
        ->get()
        ->keyBy('reference');

    $previous = null;
    $past = false;

    foreach ($rows as $row) {
        if ($row['is_past']) {
            $past = true;

            continue;
        }

        expect($past)->toBeFalse('a booking still to come was drawn below one that has already happened');

        $starts = $bookings[$row['reference']]->starts_at;

        if ($previous !== null) {
            expect($starts->greaterThanOrEqualTo($previous))->toBeTrue(
                'board 19 drew '.$row['reference'].' out of date order'
            );
        }

        $previous = $starts;
    }

    expect($past)->toBeTrue('the seeded past booking should still be on the board, at the bottom');
});

it('tells a facilities screen whether a household may book and never how much it owes', function () {
    $unit = FacilitiesFixture::unit('Lot 3');
    $settings = EstateSetting::current();

    // OFF unless an estate switches it on, which is the default and the safe
    // direction.
    expect($settings->amenity_arrears_block_enabled)->toBeFalse()
        ->and($this->amenities->mayBook($unit))->toBeTrue();

    app(Dues::class)->charge(
        unit: $unit,
        amount: Money::ofMinor(18_600_00, 'JMD'),
        description: 'Maintenance fee — arrears brought forward',
        dueOn: Carbon::today()->subDays(120),
    );

    $settings->forceFill(['amenity_arrears_block_enabled' => true])->save();

    try {
        $refusal = null;

        try {
            $this->amenities->book(
                amenity: FacilitiesFixture::amenity('Gazebo'),
                unit: $unit,
                residentName: 'Rachel Bennett',
                startsAt: Carbon::today()->addDays(80)->addHours(11),
                endsAt: Carbon::today()->addDays(80)->addHours(13),
            );
        } catch (DomainException $thrown) {
            $refusal = $thrown->getMessage();
        }

        // A BOOLEAN, AND DELIBERATELY NOTHING ELSE. The person reading board 19
        // is the Property Manager, who is locked out of Dues & ledger — so "this
        // household cannot book" is theirs to know and "this household owes
        // J$18,600" is not, and a refusal naming the amount would be the
        // invariant leaking through an error string.
        expect($this->amenities->mayBook($unit))->toBeFalse()
            ->and($refusal)->not->toBeNull()
            ->and($refusal)->toContain('in arrears')
            ->and(strtolower((string) $refusal))->not->toContain('18,600')
            ->and(strtolower((string) $refusal))->not->toContain('balance')
            ->and(strtolower((string) $refusal))->not->toContain('days')
            ->and((string) $refusal)->not->toMatch('/\d[\d,]*\.\d{2}/');
    } finally {
        // The estate's own rule back off, because these tests share one estate.
        $settings->forceFill(['amenity_arrears_block_enabled' => false])->save();
    }

    expect($this->amenities->mayBook($unit))->toBeTrue();
});

it('draws board 19 without one figure about a household financial position', function () {
    $board = $this->amenities->bookingsBoard();

    $serialised = strtolower((string) json_encode($board));

    /*
     * The Deposit column is an amount and belongs here: it is a fact about the
     * booking, snapshotted onto it. What may never appear is anything about the
     * household's ACCOUNT — D-010 is a platform invariant rather than an estate
     * setting, and the whole reason this module and Dues & ledger are separate
     * permissions is that whoever commissions work must never see what a
     * resident owes.
     */
    foreach (['balance', 'arrear', 'bucket', 'receivable', 'owed', 'outstanding', 'statement', 'ageing'] as $forbidden) {
        expect($serialised)->not->toContain(
            $forbidden,
            "board 19's payload contained the word [{$forbidden}], which is a household's financial position"
        );
    }

    expect($board['amenities'])->toBe(['Gazebo', 'Club House', 'Community Centre', 'Pool Deck'])
        ->and($board['rows'])->not->toBeEmpty();
});

/* ------------------------------------------------------------------ */
/* who may do any of it */
/* ------------------------------------------------------------------ */

it('draws the fee control inert for the Property Manager and refuses the post behind it', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    /*
     * D-010, read off the seeded matrix rather than asserted about it. The
     * Property Manager holds Facilities in full and holds nothing at all on
     * Dues & ledger; the Treasurer is the other way round on the second gate.
     */
    expect($manager->can('estate.facilities.update'))->toBeTrue()
        ->and($manager->can('estate.dues_ledger.create'))->toBeFalse()
        ->and($treasurer->can('estate.dues_ledger.create'))->toBeTrue();

    $this->withoutVite()->actingAs($manager)
        ->get(FacilitiesFixture::url('/facilities/amenities/bookings'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Facilities/Bookings')
            ->where('canUpdate', true)
            ->where('canCreate', true)

            // The one control on these four boards that the board's own persona
            // may not use, drawn inert with the reason on it.
            ->where('canCharge', false)
            ->has('chargeReason'));

    $booking = FacilitiesFixture::booking('Community Centre', 'Lot 63');

    expect($booking->isCharged())->toBeFalse();

    // AND IT IS NOT MERELY GREYED. The route carries the second gate, so the
    // POST behind the inert button is refused rather than quietly working.
    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id.'/fee'))
        ->assertForbidden();

    /*
     * AND THE SCREEN AGREES WITH THE ROUTE FROM THE OTHER SIDE TOO. The
     * Treasurer holds Dues & ledger in full and Facilities as VIEW, so they
     * hold one of the two gates this act needs. A `canCharge` that asked only
     * about the ledger would draw them a live button whose POST the route
     * refuses — and the person clicking it would have no way to tell which gate
     * they were missing.
     */
    $this->withoutVite()->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/facilities/amenities/bookings'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canUpdate', false)
            ->where('canCharge', false));

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id.'/fee'))
        ->assertForbidden();

    FacilitiesFixture::boot();

    expect(AmenityBooking::findOrFail($booking->id)->fee_charge_id)->toBeNull();
});

it('lets a role holding both gates record the fee, and posts it', function () {
    /*
     * The Admin Assistant holds `E` on Facilities and `E` on Dues & ledger —
     * data entry on both — which is what this route asks for. The Treasurer
     * holds the ledger gate and only VIEW on Facilities, so board 19's own
     * committee is refused here as well; recording a booking fee is the one act
     * on these four screens that needs a role holding both modules.
     */
    $assistant = FacilitiesFixture::viewer(Role::ESTATE_ADMIN_ASSISTANT);

    expect($assistant->can('estate.facilities.update'))->toBeTrue()
        ->and($assistant->can('estate.dues_ledger.create'))->toBeTrue();

    // The screen says so too, which is the same agreement the other way up:
    // the control is drawn live for exactly the viewer the route will accept.
    $this->withoutVite()->actingAs($assistant)
        ->get(FacilitiesFixture::url('/facilities/amenities/bookings'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canCharge', true));

    $booking = FacilitiesFixture::booking('Community Centre', 'Lot 63');
    $before = facilitiesUnitReceivable($booking->unit_id);

    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id.'/fee'))
        ->assertRedirect(FacilitiesFixture::url('/facilities/amenities/bookings'));

    FacilitiesFixture::boot();

    $charged = AmenityBooking::findOrFail($booking->id);

    expect($charged->fee_charge_id)->not->toBeNull()
        ->and(facilitiesUnitReceivable($booking->unit_id) - $before)->toBe($charged->fee_minor)
        ->and($charged->fee_minor)->toBe(8_000_00);

    // The Treasurer holds the ledger half and not the facilities half.
    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->post(FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id.'/fee'))
        ->assertForbidden();
});

it('needs create rather than update to take a period out of the diary', function () {
    $gazebo = FacilitiesFixture::amenity('Gazebo');
    $day = Carbon::today()->addDays(90);

    $payload = [
        'starts_at' => $day->copy()->addHours(8)->toDateTimeString(),
        'ends_at' => $day->copy()->addHours(18)->toDateTimeString(),
        'reason' => 'Resurfacing',
    ];

    // The Treasurer reads Facilities and creates nothing in it.
    $this->actingAs(FacilitiesFixture::viewer(Role::TREASURER))
        ->post(FacilitiesFixture::url('/facilities/amenities/'.$gazebo->id.'/block'), $payload)
        ->assertForbidden();

    FacilitiesFixture::boot();

    expect(AmenitySlot::query()->where('amenity_id', $gazebo->id)->count())->toBe(0);

    $this->actingAs(FacilitiesFixture::viewer(Role::PROPERTY_MANAGER))
        ->post(FacilitiesFixture::url('/facilities/amenities/'.$gazebo->id.'/block'), $payload)
        ->assertRedirect(FacilitiesFixture::url('/facilities/amenities/bookings'));

    FacilitiesFixture::boot();

    $slot = AmenitySlot::query()->where('amenity_id', $gazebo->id)->firstOrFail();

    expect($slot->kind)->toBe(AmenitySlot::BLOCKED)
        ->and($slot->reason)->toBe('Resurfacing');

    // And the diary now refuses that period for a different reason than a clash
    // — the estate closed it, rather than somebody else having taken it.
    expect(fn () => $this->amenities->book(
        amenity: $gazebo,
        unit: FacilitiesFixture::unit('Lot 47'),
        residentName: 'Andrea Fletcher',
        startsAt: $day->copy()->addHours(9),
        endsAt: $day->copy()->addHours(11),
    ))->toThrow(DomainException::class, 'unavailable then');
});

it('lets the Property Manager decide a booking that is waiting on one', function () {
    $manager = FacilitiesFixture::viewer(Role::PROPERTY_MANAGER);
    $booking = FacilitiesFixture::booking('Club House', 'Lot 88');

    expect($booking->status)->toBe(AmenityBooking::PENDING);

    $this->actingAs($manager)
        ->post(FacilitiesFixture::url('/facilities/amenities/bookings/'.$booking->id.'/approve'))
        ->assertRedirect(FacilitiesFixture::url('/facilities/amenities/bookings'));

    FacilitiesFixture::boot();

    $decided = AmenityBooking::findOrFail($booking->id);

    expect($decided->status)->toBe(AmenityBooking::CONFIRMED)
        ->and($decided->approved_by_name)->toBe($manager->name)
        ->and($decided->approved_at)->not->toBeNull();

    // Approving it again would restate a date the household has already been
    // told about.
    expect(fn () => $this->amenities->approve($decided))
        ->toThrow(DomainException::class, 'not waiting on a decision');
});
