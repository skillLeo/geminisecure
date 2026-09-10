<?php

declare(strict_types=1);

use App\Models\Estate\Account;
use App\Models\Estate\BankReconciliation;
use App\Models\Estate\BankStatementLine;
use App\Models\Estate\Bill;
use App\Models\Estate\Journal;
use App\Models\Estate\Vendor;
use App\Services\Estate\Ledger;
use App\Services\Estate\Payables;
use Brick\Money\Money;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Database\Seeders\Estate\PayablesSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| What the estate owes, traced to the lines it is made of
|--------------------------------------------------------------------------
|
| The same rule as `EstateArrearsTest`, on the other side of the books: a screen
| figure is not proven by rendering, it is proven by equalling the sum of
| specific posted journal lines. Total payable, a vendor's balance and what one
| bill still owes are all summed here in raw SQL written out longhand, so that
| the expectation and the application reach the same number by two different
| routes. A test that called the method it was checking would prove only that the
| method is deterministic.
|
| J$121,690 IS THE FIGURE FOUR SCREENS SHARE. Board 27's four open bills, board
| 27's "Total payable" tile, board 25's balance for 2000 Accounts Payable and
| board 25's "Bills payable" KPI. It is one number because every bill posts
| through `Payables`, and this file is where that stops being a claim.
|
| THE REFUSALS ARE PROVEN WITHOUT POSTING ANYTHING. A payment refused for a
| missing TRN, an over-payment refused for a missing reason and a reconciliation
| refused for an outstanding difference all throw before they write, so the
| estate under test still holds exactly the figures the boards state after this
| file has run.
|
*/

/**
 * The estate these tests read, built once per process.
 *
 * A DATABASE OF ITS OWN, for the reason `arrearsEstate()` gives: the figures
 * under test are the boards' own, and reading the development estate would make
 * the suite pass or fail on whatever somebody last seeded there. It is built by
 * the same seeder that builds Phoenix Park, because what is being proven is that
 * the seeder's arithmetic and the ledger's agree.
 */
function payablesEstate(): string
{
    static $built = false;

    $database = 'gs_estate_payablestest';

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

    // Through Artisan rather than by constructing the seeder, so it gets the
    // console output its own `$this->call()` writes to.
    Artisan::call('db:seed', ['--class' => EstateFinanceSeeder::class, '--force' => true]);

    $built = true;

    return $database;
}

/** Runs a closure inside the estate under test. */
function inPayablesEstate(callable $work): mixed
{
    payablesEstate();

    return $work();
}

/**
 * What the estate owes altogether, summed in raw SQL.
 *
 * Written out longhand on purpose, and credits less debits because 2000 is a
 * liability: a bill credits it and a payment debits it back down. This is the
 * second, independent route to every payables figure the screens show, and it
 * must not share an implementation with the first.
 */
function payableTotal(): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.credit_minor - l.debit_minor), 0) AS owed
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ?
    ', ['2000'])->owed;
}

/** What the estate owes one supplier, from that supplier's own lines. */
function payableOfVendor(int $vendorId): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.credit_minor - l.debit_minor), 0) AS owed
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ? AND l.vendor_id = ?
    ', ['2000', $vendorId])->owed;
}

/** One vendor by the name every board identifies it by. */
function vendorNamed(string $name): Vendor
{
    return Vendor::where('name', $name)->firstOrFail();
}

/* ------------------------------------------------------------------ */
/* the tie every payables screen rests on */
/* ------------------------------------------------------------------ */

it('sums the four open bills to the J$121,690 four boards state', function () {
    inPayablesEstate(function () {
        $payables = app(Payables::class);

        // The application's figure, read from the control account.
        $shown = $payables->totalPayable();

        // The same figure, summed independently in raw SQL over the lines.
        expect($shown)->toBe(payableTotal())
            ->and($shown)->toBe(121_690_00);

        // And the board's own tile is that number and not a second arithmetic
        // over the billing rows.
        $kpis = collect($payables->billsBoard()['kpis'])->keyBy('key');

        expect($kpis['payable']['value_minor'])->toBe(121_690_00);

        /*
         * The four bills the board draws as open, added up the way a reader of
         * board 27 would add them. This is the only place the `bills` table is
         * allowed to supply a total, and it is here precisely to show that it
         * agrees with the ledger rather than being where the ledger came from.
         */
        $open = Bill::query()->where('status', Bill::APPROVED)->sum('amount_minor');

        expect((int) $open)->toBe(121_690_00)
            ->and(Bill::query()->where('status', Bill::APPROVED)->count())->toBe(4);
    });
});

it('ties the payables control account to the vendor sub-ledger', function () {
    inPayablesEstate(function () {
        $control = Account::where('code', '2000')->firstOrFail();

        $subsidiary = app(Ledger::class)->subsidiaryBalances($control);

        // Every vendor's balance, re-summed one at a time in raw SQL.
        $traced = 0;

        foreach ($subsidiary as $vendorId => $balance) {
            expect($vendorId)->not->toBe('')  // a 2000 line with no vendor on it
                ->and($balance)->toBe(payableOfVendor((int) $vendorId));

            $traced += $balance;
        }

        // The tie itself: the control account is the sub-ledger, totalled.
        expect($traced)->toBe(payableTotal())
            ->and($traced)->toBe(121_690_00);

        /*
         * And it is a tie rather than a coincidence because no line can escape
         * it. A 2000 line without a vendor would sit inside the control balance
         * and outside every vendor's, which is exactly the drift the sub-ledger
         * exists to make visible.
         */
        $orphaned = (int) DB::connection('tenant')->selectOne('
            SELECT COUNT(*) AS n
              FROM journal_lines l
              JOIN accounts a ON a.id = l.account_id
             WHERE a.code = ? AND l.vendor_id IS NULL
        ', ['2000'])->n;

        expect($orphaned)->toBe(0);
    });
});

it('names each vendor on its own lines and each open bill on its own entries', function () {
    inPayablesEstate(function () {
        $payables = app(Payables::class);
        $outstanding = $payables->outstandingByBill();

        foreach (Bill::query()->with('vendor')->get() as $bill) {
            // Every posted bill has an entry, and a draft has none. A billing
            // row with no entry behind it is money the accounts never heard of.
            expect($bill->journal_ref)->not->toBeNull();

            $owed = $outstanding[$bill->id] ?? 0;

            expect($owed)->toBe($bill->status === Bill::PAID ? 0 : $bill->amount_minor);
        }

        // What each bill still owes adds up to what the estate owes.
        expect(array_sum($outstanding))->toBe(payableTotal());
    });
});

it('leaves the liability exactly where it is when the seeder runs again', function () {
    inPayablesEstate(function () {
        $before = payableTotal();

        /*
         * THE SEEDER IS THE ONLY THING THAT COULD DOUBLE THIS. Journals are
         * append-only, so a second run that re-approved the five bills would add
         * another J$121,690 to what the estate owes and no application path
         * could take it back off — the ledger has no delete, by design. This is
         * the test that stops a routine `db:seed` from doing it.
         */
        Artisan::call('db:seed', ['--class' => PayablesSeeder::class, '--force' => true]);

        expect(payableTotal())->toBe($before)
            ->and($before)->toBe(121_690_00)
            ->and(BankReconciliation::query()->count())->toBe(1);
    });
});

it('draws board 27 as five rows with the badges the board draws', function () {
    inPayablesEstate(function () {
        // A fixed date, because "overdue" is arithmetic against today and board
        // 27 was drawn in early September 2026. The board's own figures are only
        // true on a day, and the day is stated rather than assumed.
        $board = app(Payables::class)->billsBoard(Carbon::parse('2026-09-10'));

        $rows = collect($board['rows'])->keyBy('reference');

        expect($rows->get('Ticket #1042 — Gate lighting')['status'])->toBe('unpaid')
            ->and($rows->get('Ticket #1041 — Pool filter fault')['status'])->toBe('overdue')
            ->and($rows->get('Ticket #1037 — Gym equipment')['status'])->toBe('unpaid')
            ->and($rows->get('Common area electricity — August')['status'])->toBe('unpaid')

            // Settled five days before it fell due, and still on the screen
            // because a treasurer who has just paid something needs to see it go.
            ->and($rows->get('Ticket #1031 — Barrier arm sensor')['status_label'])->toBe('Paid Sep 5');

        $kpis = collect($board['kpis'])->keyBy('key');

        // The overdue tile is the AquaTech bill and nothing else — summed from
        // that bill's own lines, not from its `amount_minor`.
        expect($kpis['overdue']['value_minor'])->toBe(12_000_00)
            ->and($kpis['paid_month']['value_minor'])->toBe(14_200_00);
    });
});

it('posts a bill as a debit to its expense account and a credit to 2000', function () {
    inPayablesEstate(function () {
        $bill = Bill::where('description', 'Common area electricity — August')->firstOrFail();

        $lines = DB::connection('tenant')->select('
            SELECT a.code, l.debit_minor, l.credit_minor, l.vendor_id
              FROM journal_lines l
              JOIN accounts a ON a.id = l.account_id
             WHERE l.entry_ref = ?
             ORDER BY l.line_no
        ', [$bill->journal_ref]);

        expect($lines)->toHaveCount(2)

            // The electricity bill is a utility, not maintenance — which is what
            // makes board 26's Category column derivable from the books.
            ->and($lines[0]->code)->toBe('5200')
            ->and((int) $lines[0]->debit_minor)->toBe(94_300_00)
            ->and($lines[0]->vendor_id)->toBeNull()

            // And the vendor is on the payable line and nowhere else. The
            // expense belongs to the estate's costs; the liability belongs to
            // one supplier, and that is the whole of the sub-ledger.
            ->and($lines[1]->code)->toBe('2000')
            ->and((int) $lines[1]->credit_minor)->toBe(94_300_00)
            ->and((int) $lines[1]->vendor_id)->toBe($bill->vendor_id);
    });
});

it('leaves a settled bill outside what the estate owes', function () {
    inPayablesEstate(function () {
        $gate2 = vendorNamed('Gate2 Contractor Ltd');
        $bill = Bill::where('vendor_id', $gate2->id)->firstOrFail();

        // Board 27 draws it "Paid Sep 5" and excludes its J$14,200 from the
        // total payable. It is excluded here because its credit to 2000 has a
        // debit against it, not because a status column says so.
        expect($bill->status)->toBe(Bill::PAID)
            ->and(app(Payables::class)->outstandingOf($bill))->toBe(0)
            ->and(payableOfVendor($gate2->id))->toBe(0);
    });
});

it('traces what each vendor has been paid to the debits on the control account', function () {
    inPayablesEstate(function () {
        $paid = app(Payables::class)->paidByVendor(Carbon::today()->startOfYear());

        foreach ($paid as $vendorId => $amount) {
            $traced = (int) DB::connection('tenant')->selectOne('
                SELECT COALESCE(SUM(l.debit_minor), 0) AS paid
                  FROM journal_lines l
                  JOIN accounts a ON a.id = l.account_id
                  JOIN journals j ON j.reference = l.entry_ref
                 WHERE a.code = ? AND l.vendor_id = ? AND j.source = ?
                   AND j.posted_on >= ?
            ', ['2000', $vendorId, Ledger::SOURCE_BILL_PAYMENT, Carbon::today()->startOfYear()->toDateString()])->paid;

            expect($amount)->toBe($traced);
        }

        // Only the settled bill has moved money out of the estate so far.
        expect(array_sum($paid))->toBe(14_200_00);
    });
});

it('draws one vendor board from the ledger and not from its bill rows', function () {
    inPayablesEstate(function () {
        $vendor = vendorNamed('Island Electric Services');
        $board = app(Payables::class)->vendorBoard($vendor);

        $stats = collect($board['stats'])->keyBy('key');

        // Board 39's "Currently owed" against the one unpaid bill it draws.
        expect($stats['owed']['value_minor'])->toBe(payableOfVendor($vendor->id))
            ->and($stats['owed']['value_minor'])->toBe(8_500_00)
            ->and($board['bills'][0]['reference'])->toBe('Ticket #1042 — Gate lighting');
    });
});

/* ------------------------------------------------------------------ */
/* the refusals — none of which writes anything */
/* ------------------------------------------------------------------ */

it('refuses to pay a vendor with no TRN, having let the bill be recorded', function () {
    inPayablesEstate(function () {
        $payables = app(Payables::class);
        $before = payableTotal();

        $vendor = Vendor::create([
            'name' => 'Harbour Glazing Co.',
            'category' => 'Glazing',
            'status' => Vendor::ACTIVE,
        ]);

        // RECORDING IT IS ALLOWED. An invoice arrives whether or not the
        // supplier's paperwork is in order, and refusing to record it would
        // understate what the estate owes.
        $bill = $payables->recordBill(
            vendor: $vendor,
            amount: Money::ofMinor(4_000_00, 'JMD'),
            description: 'Clubhouse window replacement',
            dueOn: Carbon::today()->addDays(30),
        );

        expect($bill->status)->toBe(Bill::DRAFT)
            ->and($bill->journal_ref)->toBeNull();

        expect(fn () => $payables->pay(
            bill: $bill,
            amount: Money::ofMinor(4_000_00, 'JMD'),
            method: 'bank',
            paidOn: Carbon::today(),
        ))->toThrow(DomainException::class, 'no TRN on file');

        // And nothing was posted on the way to being refused.
        expect(payableTotal())->toBe($before);
    });
});

it('refuses a payment above what a bill still owes unless a reason is given', function () {
    inPayablesEstate(function () {
        $payables = app(Payables::class);
        $before = payableTotal();

        $bill = Bill::where('description', 'Gate lighting')->firstOrFail();

        expect($payables->outstandingOf($bill))->toBe(8_500_00);

        expect(fn () => $payables->pay(
            bill: $bill,
            amount: Money::ofMinor(9_000_00, 'JMD'),
            method: 'bank',
            paidOn: Carbon::today(),
        ))->toThrow(DomainException::class, 'over-payment reason');

        expect(payableTotal())->toBe($before);
    });
});

it('refuses to complete a reconciliation while a difference remains', function () {
    inPayablesEstate(function () {
        $payables = app(Payables::class);
        $reconciliation = $payables->currentReconciliation();

        expect($reconciliation)->not->toBeNull();

        /*
         * The difference, summed independently: what the bank says moved over
         * the period, less every line the estate has claimed as one of its own
         * entries. The seed claims none of them, so the whole month's movement
         * is outstanding.
         */
        $movement = $reconciliation->closing_minor - $reconciliation->opening_minor;

        $claimed = (int) DB::connection('tenant')->selectOne('
            SELECT COALESCE(SUM(amount_minor), 0) AS claimed
              FROM bank_statement_lines
             WHERE bank_reconciliation_id = ? AND matched_entry_ref IS NOT NULL
        ', [$reconciliation->id])->claimed;

        expect($payables->differenceOf($reconciliation))->toBe($movement - $claimed)
            ->and($payables->differenceOf($reconciliation))->not->toBe(0);

        expect(fn () => $payables->complete($reconciliation))
            ->toThrow(DomainException::class, 'cannot be completed');

        expect($reconciliation->refresh()->status)->toBe(BankReconciliation::OPEN);
    });
});

it('closes a period once every line on the statement is claimed', function () {
    inPayablesEstate(function () {
        $payables = app(Payables::class);

        /*
         * A statement of its own, against the reserve account, rather than the
         * seeded one. Completing a period is irreversible by design, and a test
         * that signed off the estate's live August would leave every test after
         * it reading a closed book.
         */
        $reconciliation = BankReconciliation::create([
            'account_id' => Account::where('code', '1010')->firstOrFail()->id,
            'statement_date' => Carbon::today()->startOfYear()->subMonth()->endOfMonth()->toDateString(),
            'opening_minor' => 0,
            'closing_minor' => 5_000_00,
            'currency' => 'JMD',
            'status' => BankReconciliation::OPEN,
        ]);

        $line = BankStatementLine::create([
            'bank_reconciliation_id' => $reconciliation->id,
            'value_date' => $reconciliation->statement_date->copy()->startOfMonth()->toDateString(),
            'description' => 'TRANSFER — RESERVE TOP UP',
            'amount_minor' => 5_000_00,
            'currency' => 'JMD',
        ]);

        $entry = Journal::query()->orderBy('id')->firstOrFail();

        // A match is an assertion recorded on the statement side; the entry it
        // names is never touched, because a posted entry cannot be.
        $payables->match($line, $entry->reference);

        expect($line->refresh()->matched_entry_ref)->toBe($entry->reference)
            ->and($payables->differenceOf($reconciliation))->toBe(0);

        $payables->complete($reconciliation);

        expect($reconciliation->refresh()->status)->toBe(BankReconciliation::COMPLETED)

            // And a completed period is closed to matching, or the figure
            // somebody signed off would move underneath them.
            ->and(fn () => $payables->unmatch($line->refresh()))->toThrow(DomainException::class);
    });
});
