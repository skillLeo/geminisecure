<?php

declare(strict_types=1);

use App\Models\Estate\Household;
use App\Models\Estate\Journal;
use App\Models\Estate\Phase;
use App\Models\Estate\Resident;
use App\Models\Estate\ResidentInvite;
use App\Models\Estate\Unit;
use App\Models\Estate\UnitClaim;
use App\Services\Estate\Dues;
use App\Services\Estate\Residents;
use App\Services\Restriction\RestrictionPolicy;
use Brick\Money\Money;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Database\Seeders\Estate\ResidentsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The register, the structure, and the one figure these screens may not print
|--------------------------------------------------------------------------
|
| TWO THINGS ARE BEING PROVEN HERE AND THEY ARE NOT THE SAME KIND OF THING.
|
| The first is arithmetic. Board 3's phase cards and board 4's Balance column are
| both derived — the counts from `units` and the balances from posted lines on
| 1200 Dues Receivable — and both are re-summed below in raw SQL written out
| longhand, so the expectation and the application reach the same number by two
| different routes. A test that called the method it was checking would prove
| only that the method is deterministic.
|
| The second is a REFUSAL, and it is the reason this file exists. A household in
| arrears past the threshold is restricted, and what a screen may say about that
| is settled: amber and the word. Never the balance, never the number of days,
| never an ageing bucket. Three screens draw a household's standing — the
| register, the claim review and the resident detail — and all three read it out
| of one function, so the assertions below scan the whole serialised standing for
| a digit and for the vocabulary of money rather than checking one remembered
| field. A leak that arrived as a new key would fail this file.
|
| AND THE SAME WORDS AS THE GATE. `Residents::RESTRICTED_LABEL` is asserted equal
| to what `RestrictionPolicy` hands a guard, because a resident told one thing at
| a gate and another at the estate office is the failure invariant 2 is written
| against.
|
| NOTHING HERE POSTS TO THE LEDGER, and one test proves it of the one act that
| could: approving a unit claim changes who is authorised against a unit and
| changes nothing about what that unit owes.
|
*/

/**
 * The estate these tests read, built once per process.
 *
 * A DATABASE OF ITS OWN, for the same reason `payablesEstate()` has one: the
 * figures under test are the boards' own, and reading the development estate
 * would make the suite pass or fail on whatever somebody last seeded there. It
 * is built by the seeder that builds Phoenix Park, because what is being proven
 * includes that the register extends that estate rather than rebuilding it.
 */
function residentsEstate(): string
{
    static $built = false;

    $database = 'gs_estate_residentstest';

    config([
        'database.connections.tenant' => array_merge(
            config('database.connections.mysql'),
            ['database' => $database],
        ),
        'database.default' => 'tenant',
    ]);

    DB::purge('tenant');

    if ($built) {
        return $database;
    }

    $owner = DB::connection('mysql_owner');
    $owner->statement("DROP DATABASE IF EXISTS `{$database}`");
    $owner->statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    DB::purge('tenant');

    Artisan::call('migrate', [
        '--path' => 'database/migrations/tenant',
        '--database' => 'tenant',
        '--force' => true,
    ]);

    Artisan::call('db:seed', ['--class' => EstateFinanceSeeder::class, '--force' => true]);

    $built = true;

    return $database;
}

/** Runs a closure inside the estate under test. */
function inResidentsEstate(callable $work): mixed
{
    residentsEstate();

    return $work();
}

/**
 * What one unit owes, summed in raw SQL.
 *
 * Written out longhand on purpose, and debits less credits because 1200 is an
 * asset: a charge debits it and a receipt credits it back down. This is the
 * second, independent route to every figure board 4 draws in its Balance column.
 */
function residentUnitBalance(int $unitId): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS owed
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.unit_id = ?
    ', ['1200', $unitId])->owed;
}

/**
 * A restricted household of its own, at a unit nothing else touches, WITH A REAL
 * BALANCE BEHIND IT.
 *
 * ITS OWN UNIT, EVERY TIME. These assertions restrict a household and then read
 * three screens about it; sharing one household between them would make the
 * order the tests run in decide what a screen says about somebody's arrears.
 * The lot numbers are high enough to sit outside the estate's own 450.
 *
 * THE CHARGE MATTERS AS MUCH AS THE FLAG. A restricted household with an empty
 * ledger would let every assertion below pass while the withholding did nothing
 * — there would be no figure to withhold. It is posted through `Dues` like any
 * other charge, so the balance the screens are refusing to print is a real one
 * the accounts actually hold.
 */
function restrictedHouseholdAt(string $reference, int $balanceMinor = 18_600_00): Household
{
    $unit = Unit::query()->firstOrCreate(
        ['reference' => $reference],
        ['block' => 'Phase 1', 'street' => 'Phase 1 Drive', 'type' => 'residential', 'status' => 'occupied'],
    );

    $household = Household::query()->firstOrCreate(
        ['unit_id' => $unit->id],
        ['name' => $reference.' household', 'access_restricted' => true],
    );

    $fresh = $household->wasRecentlyCreated;

    $household->forceFill(['access_restricted' => true, 'last_active_at' => now()])->save();

    Resident::query()->firstOrCreate(
        ['household_id' => $household->id, 'full_name' => 'Restricted Householder'],
        ['relationship' => 'owner', 'is_primary' => true, 'status' => Resident::VERIFIED],
    );

    if ($fresh) {
        app(Dues::class)->charge(
            unit: $unit,
            amount: Money::ofMinor($balanceMinor, 'JMD'),
            description: 'Maintenance fee — arrears brought forward',
            dueOn: Carbon::today()->startOfMonth()->subMonths(3),
        );
    }

    return $household->refresh();
}

/**
 * Every word and every digit a restricted household's payload must not contain.
 *
 * SCANNED OVER THE VALUES AND NOT THE KEYS. The shape itself carries the words
 * `balance_minor` and `bucket`, which is correct — a payload that answered
 * "restricted" by omitting the keys would leak the answer by its shape, and a
 * page reading `standing.balance_minor` must get an explicit null rather than an
 * undefined. What must never appear is a VALUE that says any of it.
 *
 * The wording list is `RestrictionPolicyTest`'s, because a screen and a gate are
 * answering the same question and must not diverge. THE DIGIT CHECK IS THE
 * STRONGER HALF: an amount that arrived under a key nobody thought of is still a
 * number on a screen, and this catches it whatever it is called.
 *
 * @param  array<string, mixed>  $payload
 */
function expectNoFigureIn(array $payload): void
{
    $values = [];

    array_walk_recursive($payload, function ($value) use (&$values): void {
        if ($value !== null && ! is_bool($value)) {
            $values[] = (string) $value;
        }
    });

    $serialised = strtolower(implode(' | ', $values));

    foreach (['balance', 'owed', 'arrear', 'amount', 'overdue', 'debt', 'invoice', 'jmd', 'j$'] as $forbidden) {
        expect($serialised)->not->toContain($forbidden);
    }

    expect(preg_match('/\d/', $serialised))->toBe(0);
}

/* ------------------------------------------------------------------ */
/* board 3 — every count on the structure screen is the units, regrouped */
/* ------------------------------------------------------------------ */

/*
 * THE TWO STRUCTURE TESTS READ THE ESTATE AS THE SEEDER LEFT IT, and they are
 * declared first for that reason. The restriction assertions further down add
 * lots of their own to Phase 1 — they need a household nothing else touches —
 * so the 450 and the 433 below are true of the seeded estate and not of the
 * fixture after it has been written to.
 */
it('derives every phase card from the units rather than from a stored count', function () {
    inResidentsEstate(function () {
        $board = app(Residents::class)->structureBoard();
        $cards = collect($board['phases'])->keyBy('name');

        expect($cards)->toHaveCount(5);

        foreach ($cards as $name => $card) {
            // The same two figures, counted independently in raw SQL. A phase
            // row that carried its own unit total would agree with this by
            // coincidence and would stop agreeing the first time a lot moved.
            $counted = DB::connection('tenant')->selectOne("
                SELECT COUNT(*) AS units, SUM(status = 'occupied') AS occupied
                  FROM units WHERE block = ?
            ", [$name]);

            expect($card['unit_count'])->toBe((int) $counted->units)
                ->and($card['occupied'])->toBe((int) $counted->occupied)
                ->and($card['vacant'])->toBe($card['unit_count'] - $card['occupied']);
        }

        /*
         * The cross-screen tie board 3 states outright: the five cards sum to
         * the 450 units and the 433 households the dashboard reports. Nothing
         * stands outside a phase, which is the failure a name-keyed join can
         * have and a foreign key cannot.
         */
        expect($board['totals']['units'])->toBe(450)
            ->and($board['totals']['occupied'])->toBe(433)
            ->and($board['unplaced_units'])->toBe(0)
            ->and(array_sum(array_column($board['phases'], 'unit_count')))->toBe(450);
    });
});

it('draws board 3 with the blocks and officers the board prints, and Phase 5 with words', function () {
    inResidentsEstate(function () {
        $cards = collect(app(Residents::class)->structureBoard()['phases'])->keyBy('name');

        expect($cards['Phase 1']['foot'])->toBe('6 blocks · 3 phase officers assigned')
            ->and($cards['Phase 2']['tag'])->toBe('104 units')
            ->and($cards['Phase 4']['foot'])->toBe('6 blocks · 2 phase officers assigned')

            /*
             * Phase 5 has no officer count and words instead — null is "nobody
             * has been appointed yet", which is a different fact from nobody
             * being appointed and is the only one a zero could not say.
             */
            ->and($cards['Phase 5']['officers_assigned'])->toBeNull()
            ->and($cards['Phase 5']['foot'])->toBe('4 blocks · new phase, officers pending')

            /*
             * CONTENT RESIDUAL, asserted rather than reproduced. Board 3 gives
             * Phase 5 seventy units and seventy-eight occupied, which is not
             * something one estate can be. The occupancy is clamped where the
             * estate is built, so the card reads 70 of 70 and the bar reads
             * 100% — a card printing more households than houses would be a
             * screen contradicting itself in public.
             */
            ->and($cards['Phase 5']['unit_count'])->toBe(70)
            ->and($cards['Phase 5']['occupied'])->toBe(70)
            ->and($cards['Phase 5']['occupancy_pct'])->toBe(100);

        expect(Phase::query()->orderBy('sequence')->pluck('name')->all())
            ->toBe(['Phase 1', 'Phase 2', 'Phase 3', 'Phase 4', 'Phase 5']);
    });
});

/* ------------------------------------------------------------------ */
/* board 4 — the Balance column is the ledger, read back */
/* ------------------------------------------------------------------ */

it('traces every balance on the register to that unit\'s own lines on 1200', function () {
    inResidentsEstate(function () {
        $rows = app(Residents::class)->listBoard(maySeeMoney: true)['rows'];

        expect($rows)->not->toBeEmpty();

        foreach ($rows as $row) {
            // A restricted household has no figure to trace, and that is the
            // subject of its own tests below rather than an exception to this
            // one.
            if ($row['standing']['restricted']) {
                expect($row['standing']['balance_minor'])->toBeNull();

                continue;
            }

            // Nothing here is summed from `charges` less `payments`. A screen
            // with its own arithmetic over the billing tables agrees with the
            // accounts by coincidence, and coincidence fails at an audit.
            expect($row['standing']['balance_minor'])->toBe(residentUnitBalance($row['unit_id']));
        }

        // And the estate under test is the one three boards state: J$1,840,000
        // of arrears, before anything below has written to it.
        expect((int) DB::connection('tenant')->selectOne('
            SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS owed
              FROM journal_lines l
              JOIN accounts a ON a.id = l.account_id
             WHERE a.code = ?
        ', ['1200'])->owed)->toBe(1_840_000_00);

        $andrea = collect($rows)->firstWhere('name', 'Andrea Fletcher');

        // Board 38's hero figure and board 6's unit ledger, on the register.
        expect($andrea['standing']['balance_minor'])->toBe(12_400_00)
            ->and($andrea['standing']['label'])->toBe('$12,400')
            ->and($andrea['standing']['bucket'])->toBe('d30')
            ->and($andrea['unit'])->toBe('Phase 2 · Lot 47');
    });
});

it('counts the pending claims once, so the banner and the review screen agree', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);

        $banner = $residents->listBoard(maySeeMoney: true)['pending'];
        $claims = $residents->claimsBoard(maySeeMoney: true);

        // Two counts of one thing is how a banner comes to promise three claims
        // to a screen holding two.
        expect($banner['count'])->toBe(3)
            ->and($banner['title'])->toBe('3 unit claims need review')
            ->and($claims['pending_count'])->toBe(3)
            ->and($claims['title'])->toBe('Unit claims — 3 pending')
            ->and($claims['claims'])->toHaveCount(3);
    });
});

it('flags a household under review only where somebody disputes who holds it', function () {
    inResidentsEstate(function () {
        $rows = collect(app(Residents::class)->listBoard(maySeeMoney: true)['rows'])->keyBy('name');

        /*
         * Board 38 settles this: Marlon Fletcher reads "Added Sep 4" in the
         * members panel while the hero pill above him reads "Verified". His
         * membership claim is in the queue and nobody is disputing that Lot 47
         * is Andrea's, so her row is not amber. Keith Walters's is — somebody
         * the register does not recognise is claiming to be the resident there.
         */
        expect($rows['Andrea Fletcher']['verification_label'])->toBe('Verified')
            ->and($rows['K. A. Walters']['verification_label'])->toBe('Pending review')
            ->and($rows['Dwayne Robinson']['verification_label'])->toBe('Verified');
    });
});

/* ------------------------------------------------------------------ */
/* the invariant — amber and the word, on all three screens that draw it */
/* ------------------------------------------------------------------ */

it('gives a restricted household the same words a guard is given, and no figure', function () {
    inResidentsEstate(function () {
        $household = restrictedHouseholdAt('Lot 901');

        $standing = app(Residents::class)->standingOf($household, maySeeMoney: true);

        /*
         * `maySeeMoney` is TRUE here on purpose. This is a Treasurer — the role
         * that may read every balance in the estate — and the figure is still
         * withheld, because restriction is answered before the ledger is
         * consulted at all. A test that passed a viewer who could not see money
         * anyway would prove nothing about restriction.
         */
        expect($standing['restricted'])->toBeTrue()
            ->and($standing['tone'])->toBe('amber')
            ->and($standing['label'])->toBe(Residents::RESTRICTED_LABEL)
            ->and($standing['balance_minor'])->toBeNull()
            ->and($standing['bucket'])->toBeNull()
            ->and($standing['bucket_label'])->toBeNull();

        // The same sentence the gate uses. A resident told one thing at a gate
        // and another at the office is what invariant 2 is written against.
        expect(Residents::RESTRICTED_LABEL)
            ->toBe((new RestrictionPolicy)->decide($household, 'guest')['reason']);

        expectNoFigureIn($standing);
    });
});

it('withholds the figure from the register, the detail screen and the claim review alike', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);
        $household = restrictedHouseholdAt('Lot 902');
        $unit = $household->unit;

        // A REAL BALANCE BEHIND IT. Every assertion below is withholding
        // something the accounts actually hold, rather than reporting an empty
        // ledger and passing for the wrong reason.
        expect(residentUnitBalance($unit->id))->toBe(18_600_00);

        /* -------- board 4, the register -------- */
        $row = collect($residents->listBoard(maySeeMoney: true)['rows'])
            ->firstWhere('unit_id', $unit->id);

        expect($row)->not->toBeNull()
            ->and($row['standing']['label'])->toBe(Residents::RESTRICTED_LABEL);

        expectNoFigureIn($row['standing']);

        /* -------- board 38, the resident detail -------- */
        $detail = $residents->residentBoard($unit, maySeeMoney: true);
        $balanceStat = collect($detail['stats'])->firstWhere('key', 'balance');

        expect($balanceStat['value'])->toBe(Residents::RESTRICTED_LABEL)
            ->and($balanceStat['value_minor'])->toBeNull()
            ->and($balanceStat['tone'])->toBe('amber');

        /*
         * And the cross-link is ABSENT rather than blanked. A "Dues & ledger"
         * row with its figure struck out still says there are arrears, which is
         * the one thing this household's screen may not say.
         */
        expect(array_column($detail['linked'], 'type'))->not->toContain('ledger');

        expectNoFigureIn($detail['standing']);

        /* -------- board 31, the claim review -------- */
        UnitClaim::query()->firstOrCreate(
            ['unit_id' => $unit->id, 'submitted_name' => 'Restricted Claimant'],
            [
                'claim_type' => UnitClaim::TYPE_UNVERIFIED,
                'submitted_phase' => $unit->block,
                'submitted_lot' => $unit->reference,
                'match_result' => 'none',
                'review_flag' => 'Verify identity before approving',
                'status' => UnitClaim::PENDING,
            ],
        );

        $card = collect($residents->claimsBoard(maySeeMoney: true)['claims'])
            ->firstWhere('title', 'Unknown claimant — '.$unit->block.' · '.$unit->reference);

        expect($card)->not->toBeNull();

        // The On-record column names the resident and appends no ageing to it.
        // "90+ days" is the number of days, and days are what a restricted
        // household never shows.
        foreach ($card['record']['rows'] as $recordRow) {
            expect($recordRow['value'])->not->toContain('days')
                ->and($recordRow['value'])->not->toContain('arrears');
        }

        expectNoFigureIn($card['standing']);
    });
});

it('shows a role locked out of the ledger no balance anywhere on the register', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);

        /*
         * D-010: the Property Manager holds Full on Residents and nothing at
         * all on Dues & ledger — "whoever commissions work must never be able
         * to pay for it, nor see a resident's financial position". A resident
         * detail screen that printed a balance would be a side door into
         * exactly that.
         */
        foreach ($residents->listBoard(maySeeMoney: false)['rows'] as $row) {
            expect($row['standing']['balance_minor'])->toBeNull()
                ->and($row['standing']['bucket'])->toBeNull()

                /*
                 * A restricted household reads the VERDICT even here, because
                 * restriction is answered before the ledger gate is: the
                 * household's standing is a fact about the household, and a
                 * viewer who may not see money is still told the gate is shut.
                 */
                ->and($row['standing']['label'])->toBe(
                    $row['standing']['restricted'] ? Residents::RESTRICTED_LABEL : Residents::WITHHELD_LABEL
                );
        }

        $unit = Unit::query()->where('reference', 'Lot 47')->firstOrFail();
        $detail = $residents->residentBoard($unit, maySeeMoney: false);

        expect($detail['standing']['balance_minor'])->toBeNull()
            ->and(collect($detail['stats'])->firstWhere('key', 'balance')['value_minor'])->toBeNull()
            ->and(array_column($detail['linked'], 'type'))->not->toContain('ledger')

            /*
             * And the open-item count follows the links this viewer can
             * actually see. Counting a link that is not drawn would let
             * somebody learn a withheld balance was non-zero by reading a
             * number that was one higher than the rows beneath it.
             */
            ->and(collect($detail['stats'])->firstWhere('key', 'open_items')['value'])
            ->toBe(count(array_filter($detail['linked'], static fn (array $l): bool => $l['is_open_item'])));
    });
});

it('keeps the ageing flag on a claim behind ledger access while Q-014 stands open', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);

        // The flag names the assumption. When the client rules the other way
        // this constant goes and so does the second half of this test.
        expect(Residents::ARREARS_FLAG_NEEDS_LEDGER_ACCESS)->toBeTrue();

        // Found by the claimant's own name rather than by position, so an
        // unrelated claim added by another test cannot silently change what
        // this one is asserting about.
        $simms = function (bool $money) use ($residents): string {
            $card = collect($residents->claimsBoard($money)['claims'])
                ->first(fn (array $c): bool => str_starts_with($c['submitted']['rows'][0]['value'], 'Tanya Simms'));

            return $card['record']['rows'][0]['value'];
        };

        /*
         * Board 31's own persona is the Property Manager and its third card
         * prints "Tanya Simms — 90+ days arrears". An ageing bucket is a
         * financial position, which D-010 locks that role out of, so the safe
         * reading is that the bucket needs ledger access — a Treasurer sees it
         * and a Property Manager sees the identity flag alone.
         */
        expect($simms(true))->toBe('Tanya Simms — 90+ days arrears')
            ->and($simms(false))->toBe('Tanya Simms');
    });
});

/* ------------------------------------------------------------------ */
/* board 31 — the comparison is the screen */
/* ------------------------------------------------------------------ */

it('draws the three claims with the columns and the amber the board draws', function () {
    inResidentsEstate(function () {
        // Keyed on the CLAIMANT, not on the claim type. Other tests in this file
        // queue claims of their own, and keying on a type that repeats would
        // quietly swap which card each assertion below was reading.
        $cards = collect(app(Residents::class)->claimsBoard(maySeeMoney: true)['claims'])
            ->keyBy(fn (array $c): string => $c['submitted']['rows'][0]['value']);

        $unit = $cards['Keith Walters'];
        $member = $cards['Marlon Fletcher'];
        $unknown = $cards['Tanya Simms'];

        /*
         * Card 1 flags only the On-record column: the claimant wrote his name
         * in full where the register holds initials, and gave a phone the
         * estate does not hold at all. Two differences, neither of them a
         * reason to refuse him — which is why the outline action asks for a
         * document rather than rejecting.
         */
        expect($unit['submitted']['mismatch'])->toBeFalse()
            ->and($unit['record']['mismatch'])->toBeTrue()
            ->and($unit['record']['rows'][0]['value'])->toBe('K. A. Walters')
            ->and($unit['record']['rows'][2]['value'])->toBe('Not on file')
            ->and(array_column($unit['actions'], 'label'))->toBe(['Request ID document', 'Approve claim']);

        /*
         * Card 2 flags neither. The estate matched it exactly — Marlon Fletcher
         * is already on the register as a member of this household — so the
         * only question left is whether the estate agrees, and the outline
         * action is a refusal rather than another verification step.
         */
        expect($member['submitted']['mismatch'])->toBeFalse()
            ->and($member['record']['mismatch'])->toBeFalse()
            ->and($member['record']['rows'][2]['value'])->toBe('Exact')
            ->and(array_column($member['actions'], 'label'))->toBe(['Reject', 'Approve claim']);

        // Card 3 flags both: a claimant the estate cannot place, against a lot
        // somebody has a reason to want authorisation on.
        expect($unknown['submitted']['mismatch'])->toBeTrue()
            ->and($unknown['record']['mismatch'])->toBeTrue()
            ->and($unknown['title'])->toContain('Unknown claimant')
            ->and($unknown['record']['rows'][1]['value'])->toBe('Verify identity before approving');
    });
});

/* ------------------------------------------------------------------ */
/* board 38 — one household in full */
/* ------------------------------------------------------------------ */

it('draws Andrea Fletcher\'s household as board 38 draws it', function () {
    inResidentsEstate(function () {
        $unit = Unit::query()->where('reference', 'Lot 47')->firstOrFail();
        $board = app(Residents::class)->residentBoard($unit, maySeeMoney: true);

        $stats = collect($board['stats'])->keyBy('key');

        expect($board['resident']['name'])->toBe('Andrea Fletcher')
            ->and($board['resident']['sub'])->toBe('Phase 2 · Lot 47 · Fletcher household')
            ->and($board['resident']['verification_label'])->toBe('Verified')
            ->and($stats['members']['value'])->toBe(3)
            ->and($stats['balance']['value_minor'])->toBe(12_400_00)
            ->and($stats['since']['value'])->toBe('Sep 2, 2024')

            /*
             * TWO OPEN ITEMS, and the rule is stated rather than counted off a
             * screen: an arrears balance and an unfinished ticket each need
             * somebody to act. The gazebo booking does not — the deposit is in
             * the right place and the date is in the diary — which is exactly
             * the arithmetic that makes the board's own "2" reachable.
             */
            ->and($stats['open_items']['value'])->toBe(2);

        // The panel names the primary and the brother and rolls the third up.
        // The roll-up is a privacy affordance: a detail screen naming every
        // occupant of a house is a roster of who lives where.
        expect($board['members'][0]['label'])->toBe('Andrea Fletcher (primary)')
            ->and($board['members'][0]['value'])->toBe('Verified')
            ->and($board['members'][1]['label'])->toBe('Marlon Fletcher (brother)')
            ->and($board['members'][1]['value'])->toStartWith('Added ')
            ->and($board['members'][2]['label'])->toBe('1 additional member');

        expect(array_column($board['linked'], 'type'))->toBe(['ledger', 'ticket', 'booking']);
    });
});

/* ------------------------------------------------------------------ */
/* the acts */
/* ------------------------------------------------------------------ */

it('binds a person to a household when a claim is approved, and posts nothing', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);
        $household = restrictedHouseholdAt('Lot 903');
        $unit = $household->unit;

        $claim = UnitClaim::create([
            'unit_id' => $unit->id,
            'claim_type' => UnitClaim::TYPE_UNIT,
            'submitted_name' => 'Approved Claimant',
            'submitted_phase' => $unit->block,
            'submitted_lot' => $unit->reference,
            'match_result' => 'partial',
            'status' => UnitClaim::PENDING,
        ]);

        $entriesBefore = Journal::query()->count();

        $residents->approveClaim($claim);

        $resident = $claim->refresh()->resolvedResident;

        expect($claim->status)->toBe(UnitClaim::APPROVED)
            ->and($claim->reviewed_at)->not->toBeNull()
            ->and($resident)->not->toBeNull()
            ->and($resident->household_id)->toBe($household->id)
            ->and($resident->isVerified())->toBeTrue();

        /*
         * NOT ONE JOURNAL ENTRY. Approving a claim decides who is authorised
         * against a unit and decides nothing about what that unit owes — board
         * 31's own note says so, and an approval that quietly posted would put
         * a resident's authorisation and the estate's accounts on the same
         * button.
         */
        expect(Journal::query()->count())->toBe($entriesBefore);

        /*
         * AND THE RESTRICTION IS UNTOUCHED. The household's arrears did not go
         * away because somebody proved who they were; a restriction cleared as
         * a side effect of an identity check would open a gate nobody decided
         * to open.
         */
        expect($household->refresh()->access_restricted)->toBeTrue();
    });
});

it('refuses to decide a claim twice, so the record of who decided it survives', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);
        $household = restrictedHouseholdAt('Lot 904');

        $claim = UnitClaim::create([
            'unit_id' => $household->unit_id,
            'claim_type' => UnitClaim::TYPE_UNIT,
            'submitted_name' => 'Twice Decided',
            'match_result' => 'partial',
            'status' => UnitClaim::PENDING,
        ]);

        $residents->approveClaim($claim);

        expect(fn () => $residents->approveClaim($claim->refresh()))
            ->toThrow(DomainException::class, 'already approved');

        expect(fn () => $residents->requestDocument($claim->refresh()))
            ->toThrow(DomainException::class, 'nothing left to verify');
    });
});

it('refuses to reject a claim without a reason the claimant can be given', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);
        $household = restrictedHouseholdAt('Lot 905');

        $claim = UnitClaim::create([
            'unit_id' => $household->unit_id,
            'claim_type' => UnitClaim::TYPE_UNIT,
            'submitted_name' => 'Refused Claimant',
            'match_result' => 'none',
            'status' => UnitClaim::PENDING,
        ]);

        expect(fn () => $residents->rejectClaim($claim, '   '))
            ->toThrow(DomainException::class, 'needs a reason');

        // And nothing was written on the way to being refused.
        expect($claim->refresh()->status)->toBe(UnitClaim::PENDING);

        $residents->rejectClaim($claim, 'The lot is occupied by a household we have verified.');

        expect($claim->refresh()->status)->toBe(UnitClaim::REJECTED)
            ->and($claim->decision_reason)->toContain('verified');
    });
});

it('adds a resident at an address the estate has, and refuses one at an address it does not', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);

        /*
         * A lot typed in error must not become a house. This form files a
         * person at an address; it does not create one, and the refusal names
         * both halves because both are what the person can fix.
         */
        expect(fn () => $residents->add('Phase 5', '9999', 'Nobody At All'))
            ->toThrow(DomainException::class, 'No Lot 9999 in Phase 5');

        $unit = Unit::query()->where('status', 'vacant')->orderBy('id')->firstOrFail();

        $resident = $residents->add(
            phase: (string) $unit->block,
            lot: (string) $unit->reference,
            fullName: 'Simone Barrett',
            email: 'simone.barrett@email.com',
            phone: '876 555 0388',
        );

        expect($resident->full_name)->toBe('Simone Barrett')
            ->and($resident->is_primary)->toBeTrue()

            // The invite path leaves them PENDING. Board 34's own copy says the
            // transition to Verified happens when they prove who they are.
            ->and($resident->status)->toBe(Resident::PENDING)

            // And a lot with somebody living at it is not vacant, or board 3's
            // occupancy would count a household that exists as an empty house.
            ->and($unit->refresh()->status)->toBe('occupied');

        $invite = ResidentInvite::query()->where('resident_id', $resident->id)->firstOrFail();

        /*
         * `sent_at` IS NULL AND THAT IS THE POINT. There is no mail driver and
         * no SMS gateway on this path yet; a row claiming a message had gone
         * would be a lie the moment somebody read it while chasing a resident
         * who never got one.
         */
        expect($invite->sent_at)->toBeNull()
            ->and($invite->channels)->toBe('sms,email');
    });
});

it('ships the biometric consent flag off and refuses enrolment without it', function () {
    inResidentsEstate(function () {
        $residents = app(Residents::class);
        $unit = Unit::query()->where('status', 'vacant')->orderBy('id')->firstOrFail();

        // Q-003 / D-022. The form may collect it; the default is off, and no
        // estate setting grants it on anybody's behalf.
        expect($residents->newResidentBoard()['biometric_consent']['default'])->toBeFalse();

        $resident = $residents->add(
            phase: (string) $unit->block,
            lot: (string) $unit->reference,
            fullName: 'Unconsenting Resident',
            verification: 'manual',
        );

        expect($resident->biometric_consent)->toBeFalse()
            ->and($resident->status)->toBe(Resident::VERIFIED);

        expect(fn () => $residents->enrolBiometrics($resident))
            ->toThrow(DomainException::class, 'has not consented');

        // And with consent recorded, the gate opens — so the refusal above is
        // the consent doing its job rather than the method never working.
        $resident->forceFill(['biometric_consent' => true])->save();

        $residents->enrolBiometrics($resident);

        expect($resident->biometric_consent)->toBeTrue();
    });
});

/* ------------------------------------------------------------------ */
/* the seeder — it extends the estate and never rebuilds it */
/* ------------------------------------------------------------------ */

it('changes nothing at all when the register seeder runs a second time', function () {
    inResidentsEstate(function () {
        $arrears = fn (): int => (int) DB::connection('tenant')->selectOne('
            SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS owed
              FROM journal_lines l
              JOIN accounts a ON a.id = l.account_id
             WHERE a.code = ?
        ', ['1200'])->owed;

        $arrearsBefore = $arrears();

        $before = [
            'units' => Unit::query()->count(),
            'households' => Household::query()->count(),
            'residents' => Resident::query()->count(),
            'claims' => UnitClaim::query()->count(),
            'phases' => Phase::query()->count(),
            'entries' => Journal::query()->count(),
        ];

        /*
         * THE SEEDER IS THE ONLY THING THAT COULD DOUBLE ANY OF THIS. It renames
         * households by finding a free unit, and a second run that went looking
         * again would find the NEXT free one and put a second Natalie Wong at a
         * second address — added silently by a routine `db:seed`. Every
         * household is found by the resident already on it, and this is the test
         * that keeps that true.
         */
        Artisan::call('db:seed', ['--class' => ResidentsSeeder::class, '--force' => true]);

        expect([
            'units' => Unit::query()->count(),
            'households' => Household::query()->count(),
            'residents' => Resident::query()->count(),
            'claims' => UnitClaim::query()->count(),
            'phases' => Phase::query()->count(),
            'entries' => Journal::query()->count(),
        ])->toBe($before);

        /*
         * AND THE RECEIVABLE HAS NOT MOVED. Compared against itself rather than
         * against the boards' J$1,840,000, because the restriction assertions
         * above have deliberately charged fixture households of their own and
         * that total is asserted before any of them run. What matters here is
         * the delta: this seeder posts nothing, so a second run must leave 1200
         * exactly where it found it.
         */
        expect($arrears())->toBe($arrearsBefore)

            // The one thing a re-run could plausibly duplicate: a household
            // placed by searching for a free unit rather than by finding the
            // person already on it.
            ->and(Resident::query()->where('full_name', 'Natalie Wong')->count())->toBe(1);
    });
});
