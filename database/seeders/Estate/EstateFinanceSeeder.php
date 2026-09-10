<?php

declare(strict_types=1);

namespace Database\Seeders\Estate;

use App\Models\Estate\Account;
use App\Models\Estate\Household;
use App\Models\Estate\Journal;
use App\Models\Estate\Unit;
use App\Services\Estate\Dues;
use App\Services\Estate\Ledger;
use Brick\Money\Money;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Phoenix Park's estate: 450 units, 441 households, and six months of dues.
 *
 * Runs INSIDE tenancy. Every row is written to one estate's own database.
 *
 * WHY IT IS THIS BIG. Board 2 states 450 units and 441 occupied; board 5 states
 * J$1,840,000 of arrears split four ways by age; board 2 splits the same total
 * five ways by phase; boards 5 and 6 name five specific units and what each
 * owes. Seeding four households would leave every one of those figures
 * unreachable, and the screens that exist to show them unreviewable.
 *
 * EVERY BALANCE COMES FROM POSTED JOURNAL ENTRIES. Nothing here writes a
 * balance. Dues are billed as a monthly run — one entry, one credit to income,
 * a debit line per unit — and receipts are banked in batches the same way, so
 * the arrears on every screen are the ledger read back rather than a number
 * kept beside it.
 *
 * THE FIT IS SOLVED, NOT HAND-WRITTEN. `ArrearsPlan` works out which units make
 * up the difference and proves both margins land exactly before a row is
 * written. See its docblock for the constraints.
 */
class EstateFinanceSeeder extends Seeder
{
    /** Board 2's phase table: name, units, occupied. */
    private const PHASES = [
        ['Phase 1', 92, 90],
        ['Phase 2', 104, 98],
        ['Phase 3', 88, 86],
        ['Phase 4', 96, 89],
        ['Phase 5', 70, 78],
    ];

    /**
     * How many months of dues the estate has billed on this platform.
     *
     * Six, ending with the current month. Board 6's statement for Lot 47 runs
     * back to a payment on June 3, and the 90+ bucket needs a charge at least
     * ninety days overdue to sit in it — four months would leave that bucket
     * unreachable and six gives it room without inventing history nobody drew.
     */
    private const MONTHS = 6;

    public function run(): void
    {
        /*
         * Which estate this is, read from the connection rather than a helper.
         * The seeder runs inside `$tenant->run()`, where the `tenant` connection
         * is already pointed at exactly one estate's database — that is the
         * fact, and it cannot be out of step with the context the way a
         * container binding can.
         */
        $database = DB::connection('tenant')->getDatabaseName();

        /*
         * WHICH estate gets a dues history is the caller's decision, not this
         * seeder's. Ocean View is mid-onboarding on every board that draws it,
         * and giving it six months of billing would make the onboarding state —
         * which four Gemini screens depend on — unreachable. DemoDataSeeder
         * knows that; this class only knows how to build an estate, which is
         * also what lets the traceability suite point it at a database of its
         * own.
         */
        $this->call(ChartOfAccountsSeeder::class);

        /*
         * Leave a correctly seeded estate alone, and rebuild anything else.
         *
         * The test is the ARREARS, not the row counts. An estate whose
         * receivable already equals the figure every board states is finished,
         * and re-running would raise a second six months of dues on top of the
         * first. An estate that is half-seeded, or seeded by an older and
         * wronger version of this file, looks complete by row count and is not
         * — and there is no application path that could put it right, because
         * the ledger cannot be unposted.
         */
        $arrears = app(Ledger::class)
            ->balanceOf(Account::where('code', '1200')->firstOrFail())
            ->getMinorAmount()
            ->toInt();

        /*
         * The ageing, not just the total. An estate can hold the right total in
         * the wrong buckets — that is exactly what an earlier version of the
         * arrears fit produced — and a guard that only checked the sum would
         * declare it finished and leave three of board 5's four figures wrong.
         */
        $built = Unit::query()->count() === 450
            && $arrears === array_sum(ArrearsPlan::AGEING)
            && app(Dues::class)->ageing() === ArrearsPlan::AGEING;

        if (! $built) {
            $this->resetLedger($database);

            $lotsByPhase = $this->buildEstate();
            $plan = (new ArrearsPlan)->solve($lotsByPhase);

            $this->billAndCollect($plan);
        }

        // Outside the guard, and idempotent on its own terms: an estate seeded
        // before this existed still needs its corrected entry.
        $this->correctOneChargeInError();
    }

    /**
     * Clear the demonstration estate so it can be rebuilt from nothing.
     *
     * NEEDED BECAUSE THE LEDGER CANNOT BE UNDONE, which is the point of it. A
     * seed that fails half way through — as this one did, after writing 450
     * units and before billing them — leaves an estate that is neither empty
     * nor correct, and no application path can put it right: journals are
     * append-only, and posting six more months on top would double the arrears.
     *
     * TRUNCATE, not DELETE, and by the schema owner. It is DDL, so it bypasses
     * the append-only triggers, and the estate's own MySQL user could not issue
     * it. That is a deliberately narrow door and it is why this refuses to open
     * outside local development: on any other environment these rows are a
     * community's actual accounts.
     */
    private function resetLedger(string $database): void
    {
        /*
         * Local and testing only. The traceability suite builds its own estate
         * with this seeder — that is the point of it, since what is being proven
         * is that this arithmetic and the ledger's agree — so `testing` belongs
         * here beside `local`. Anywhere else these tables hold a community's
         * real accounts and this must never open.
         */
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'EstateFinanceSeeder rebuilds an estate from empty and runs only in local and testing. '.
                'On any other environment these tables hold a community\'s real accounts.'
            );
        }

        $owner = DB::connection('mysql_owner');

        $owner->statement('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['journal_lines', 'journals', 'payments', 'charges', 'residents', 'households', 'units'] as $table) {
            $owner->statement("TRUNCATE TABLE `{$database}`.`{$table}`");
        }

        $owner->statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * The physical estate: phases, units, households and their residents.
     *
     * @return array<string, list<int>> phase name => lot numbers
     */
    private function buildEstate(): array
    {
        $named = ArrearsPlan::NAMED;
        $lotsByPhase = [];
        $remaining = [];

        foreach (self::PHASES as [$phase, $units]) {
            $lotsByPhase[$phase] = [];
            $remaining[$phase] = $units;
        }

        // The five the boards name sit where the boards put them, whatever the
        // sequential fill would otherwise have done with those lot numbers.
        foreach ($named as $lot => $row) {
            $lotsByPhase[$row['phase']][] = $lot;
            $remaining[$row['phase']]--;
        }

        $phases = array_keys($remaining);
        $cursor = 0;

        for ($lot = 1; $lot <= 450; $lot++) {
            if (isset($named[$lot])) {
                continue;
            }

            while ($remaining[$phases[$cursor]] === 0) {
                $cursor++;
            }

            $lotsByPhase[$phases[$cursor]][] = $lot;
            $remaining[$phases[$cursor]]--;
        }

        foreach ($lotsByPhase as $phase => $lots) {
            sort($lots);
            $lotsByPhase[$phase] = $lots;
        }

        $this->writeUnits($lotsByPhase);

        return $lotsByPhase;
    }

    /**
     * @param  array<string, list<int>>  $lotsByPhase
     */
    private function writeUnits(array $lotsByPhase): void
    {
        $occupancy = [];

        foreach (self::PHASES as [$phase, $units, $occupied]) {
            // Board 5 lists Phase 5 with more occupied units than its unit
            // count, which cannot be true of one estate. Clamped, and the
            // discrepancy recorded rather than silently propagated.
            $occupancy[$phase] = min($occupied, $units);
        }

        $unitRows = [];

        foreach ($lotsByPhase as $phase => $lots) {
            $vacantFrom = $occupancy[$phase];

            foreach ($lots as $i => $lot) {
                $unitRows[] = [
                    'reference' => 'Lot '.$lot,
                    'block' => $phase,
                    'street' => $phase.' Drive',
                    'type' => 'residential',
                    'status' => $i < $vacantFrom ? 'occupied' : 'vacant',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($unitRows, 200) as $chunk) {
            DB::connection('tenant')->table('units')->insert($chunk);
        }

        $units = Unit::query()->get()->keyBy('reference');
        $householdRows = [];

        foreach ($units as $reference => $unit) {
            if ($unit->status !== 'occupied') {
                continue;
            }

            $lot = (int) substr((string) $reference, 4);
            $named = ArrearsPlan::NAMED[$lot] ?? null;

            $householdRows[] = [
                'unit_id' => $unit->id,
                'name' => $named['household'] ?? 'Lot '.$lot.' household',
                'access_restricted' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($householdRows, 200) as $chunk) {
            DB::connection('tenant')->table('households')->insert($chunk);
        }

        /*
         * Residents are named only where a board names them. Four hundred and
         * forty invented Jamaican names would be four hundred and forty pieces
         * of fiction on a screen a client reviews, and the five that matter are
         * the five the boards draw.
         */
        $residentRows = [];

        foreach (Household::query()->with('unit')->get() as $household) {
            $lot = (int) substr((string) $household->unit->reference, 4);
            $named = ArrearsPlan::NAMED[$lot] ?? null;

            $residentRows[] = [
                'household_id' => $household->id,
                'full_name' => $named['resident'] ?? 'Lot '.$lot.' householder',
                'email' => 'lot'.$lot.'@phoenixpark.test',
                'phone' => '876-555-'.str_pad((string) $lot, 4, '0', STR_PAD_LEFT),
                'relationship' => 'owner',
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($residentRows, 200) as $chunk) {
            DB::connection('tenant')->table('residents')->insert($chunk);
        }
    }

    /**
     * Six monthly dues runs, and the receipts that settle all but the arrears.
     *
     * A unit's payments are worked backwards from what it should still owe: it
     * has paid everything except its target balance, and FIFO puts what remains
     * on the oldest months. That is the only construction under which the
     * seeded ageing and the seeded balances agree with each other.
     *
     * @param  array<int, array{balance: int, bucket: string}>  $plan
     */
    private function billAndCollect(array $plan): void
    {
        $dues = app(Dues::class);
        $units = Unit::query()->get()->keyBy('reference');
        $firstOfThisMonth = Carbon::today()->startOfMonth();

        $months = [];

        for ($back = self::MONTHS - 1; $back >= 0; $back--) {
            $months[] = $firstOfThisMonth->copy()->subMonths($back);
        }

        /*
         * A unit owing more than the whole months its bucket accounts for has a
         * special levy on top, DATED IN THE SAME MONTH AS ITS OLDEST OPEN
         * CHARGE. That placement is what keeps the unit in its bucket: the levy
         * sits behind the same oldest charge, so it enlarges the balance without
         * ageing it.
         *
         * This is what boards 5 and 35 show — Lot 21 owes J$44,900, which is not
         * a multiple of the monthly fee and never could be, and board 35 posts a
         * "Perimeter wall repair — Phase 2 special levy" for exactly this
         * reason.
         *
         * @var array<int, array{amount: int, month: int}>
         */
        $levies = [];

        foreach ($plan as $lot => $target) {
            $monthsOpen = ArrearsPlan::MONTHS_OPEN[$target['bucket']];
            $excess = $target['balance'] - ($monthsOpen * ArrearsPlan::MONTHLY_DUES);

            if ($excess > 0) {
                $levies[$lot] = ['amount' => $excess, 'month' => self::MONTHS - $monthsOpen];
            }
        }

        foreach ($months as $index => $month) {
            $charges = [];

            foreach ($units as $unit) {
                $charges[] = [
                    'unit' => $unit,
                    'amount' => Money::ofMinor(ArrearsPlan::MONTHLY_DUES, 'JMD'),
                    'description' => 'Maintenance fee — '.$month->format('F'),
                ];
            }

            $dues->chargeRun(
                rows: $charges,
                dueOn: $month,
                memo: 'Maintenance fee — '.$month->format('F Y'),
            );

            $thisMonth = [];

            foreach ($levies as $lot => $levy) {
                if ($levy['month'] === $index) {
                    $thisMonth[] = [
                        'unit' => $units['Lot '.$lot],
                        'amount' => Money::ofMinor($levy['amount'], 'JMD'),
                        'description' => 'Perimeter wall repair — special levy',
                        'type' => 'special_assessment',
                    ];
                }
            }

            if ($thisMonth !== []) {
                $dues->chargeRun(
                    rows: $thisMonth,
                    dueOn: $month,
                    memo: 'Perimeter wall repair — special levy',
                );
            }
        }

        $this->collect($dues, $units, $plan, $months, $levies);

    }

    /**
     * One levy raised against the wrong unit, and the entry that corrects it.
     *
     * DELIBERATELY IMPERFECT, for the same reason the dispatch seed leaves a
     * gate uncovered. A correction is the one thing an append-only ledger has to
     * be able to express, and an estate where nothing was ever posted in error
     * leaves the reversing path — and the pair of statement rows a unit ledger
     * must show for it — permanently unreachable, which is to say untested.
     *
     * It disturbs no total. A reversed pair nets to nothing on every account it
     * touched, so the arrears remain exactly what the boards state.
     */
    private function correctOneChargeInError(): void
    {
        $memo = 'Perimeter wall repair — Phase 2 special levy';

        if (Journal::where('memo', $memo)->exists()) {
            return;
        }

        $wrongUnit = Unit::where('reference', 'Lot 112')->first() ?? Unit::query()->firstOrFail();

        $mistake = app(Dues::class)->charge(
            unit: $wrongUnit,
            amount: Money::ofMinor(15_000_00, 'JMD'),
            description: $memo,
            dueOn: Carbon::today()->startOfMonth()->addDays(2),
            type: 'special_assessment',
        );

        $entry = Journal::query()
            ->where('source', Ledger::SOURCE_CHARGE)
            ->where('source_id', $mistake->id)
            ->firstOrFail();

        app(Ledger::class)->reverse($entry, 'Reversal — levy belonged to a Phase 2 unit');

        $mistake->forceFill(['status' => 'reversed'])->save();
    }

    /**
     * What each unit has paid: everything charged, less what it should owe.
     *
     * Banked in one batch per month so the estate's cash book reads the way a
     * bank statement does — a deposit a month, each carrying the receipts that
     * make it up.
     *
     * @param  Collection<string, Unit>  $units
     * @param  array<int, array{balance: int, bucket: string}>  $plan
     * @param  list<Carbon>  $months
     * @param  array<int, array{amount: int, month: int}>  $levies
     */
    private function collect(Dues $dues, $units, array $plan, array $months, array $levies): void
    {
        $owed = [];

        foreach ($units as $reference => $unit) {
            $lot = (int) substr((string) $reference, 4);
            $charged = self::MONTHS * ArrearsPlan::MONTHLY_DUES + ($levies[$lot]['amount'] ?? 0);

            $owed[$lot] = $charged - ($plan[$lot]['balance'] ?? 0);
        }

        /*
         * Paid month by month, oldest first, a full month's dues at a time.
         * The last payment a unit makes may be a part month — which is exactly
         * how a unit ends up owing an amount that is not a whole number of
         * months, and how board 5's odd balances arise.
         */
        foreach ($months as $index => $month) {
            $receipts = [];

            foreach ($units as $reference => $unit) {
                $lot = (int) substr((string) $reference, 4);

                if ($owed[$lot] <= 0) {
                    continue;
                }

                /*
                 * A month's payment covers that month's charges and no more —
                 * the dues, plus a levy if one fell due the same month. Paying
                 * ahead would settle a charge that has not been raised, and
                 * FIFO would then age the unit into the wrong bucket.
                 */
                $dueThisMonth = ArrearsPlan::MONTHLY_DUES
                    + (($levies[$lot]['month'] ?? null) === $index ? $levies[$lot]['amount'] : 0);

                $amount = min($owed[$lot], $dueThisMonth);
                $owed[$lot] -= $amount;

                $receipts[] = [
                    'unit' => $unit,
                    'amount' => Money::ofMinor($amount, 'JMD'),
                    'method' => 'bank',

                    // Paid a few days after the due date, which is what a
                    // standing order looks like against a first-of-month bill.
                    'received_at' => $month->copy()->addDays(2),
                ];
            }

            $dues->paymentRun(
                rows: $receipts,
                bankedOn: $month->copy()->addDays(2),
                memo: 'Dues receipts banked — '.$month->format('F Y'),
            );
        }
    }
}
