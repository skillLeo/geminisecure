<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Account;
use App\Models\Estate\Journal;
use App\Models\Estate\JournalLine;
use App\Models\User;
use Brick\Money\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The one door every journal entry goes through.
 *
 * Nothing else in this application writes to `journals` or `journal_lines`. Not
 * a controller, not a seeder, not a model. A charge posts through here, a
 * payment posts through here, a bill posts through here — so there is exactly
 * one place where the rules of double-entry are expressed, and exactly one
 * place to read to know what they are.
 *
 * THE GUARANTEES ARE NOT IN THIS CLASS. That is the point of it. Debits equal
 * credits because a database trigger refuses a header whose lines do not sum;
 * a posted entry cannot be edited because four more triggers and a revoked
 * grant say so. This class is the ergonomics and the reference numbering. If
 * somebody bypasses it tomorrow with raw SQL, the ledger still cannot be made
 * to lie — it will simply be less pleasant to write to.
 *
 * WRITE ORDER IS LINES FIRST, HEADER LAST, and that is not an implementation
 * detail to tidy up later: the header's insert is what counts the lines and
 * refuses an unbalanced entry. Reversing the order would move the balance
 * guarantee from the database into this file, where a future caller could
 * forget it.
 *
 * A REVERSAL IS EVERY LINE MIRRORED, never a negative amount. Debiting what was
 * credited is what undoes an entry; negating a total is what undoes a number.
 */
class Ledger
{
    /**
     * The prefix on a hand-posted entry's reference.
     *
     * Entries raised by a charge, a payment or a bill carry their own prefixes,
     * so a reference says at a glance what caused the entry — which is the
     * first question anyone asks of a ledger row they do not recognise.
     */
    public const MANUAL = 'JV';

    /** Where an entry came from. Recorded so a reversal can find everything. */
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_CHARGE = 'charge';

    public const SOURCE_PAYMENT = 'payment';

    public const SOURCE_BILL = 'bill';

    public const SOURCE_BILL_PAYMENT = 'bill_payment';

    public const SOURCE_OPENING = 'opening';

    public const SOURCE_REVERSAL = 'reversal';

    public const SOURCE_ADJUSTMENT = 'adjustment';

    /**
     * Post one balanced entry.
     *
     * @param  list<Posting>  $postings  at least two, summing to zero across the two sides
     *
     * @throws DomainException when the entry does not balance, or an account is missing or archived
     */
    public function post(
        string $memo,
        array $postings,
        Carbon|string|null $on = null,
        string $source = self::SOURCE_MANUAL,
        ?int $sourceId = null,
        ?User $by = null,
        ?string $prefix = null,
        ?int $reversesJournalId = null,
    ): Journal {
        if (count($postings) < 2) {
            throw new DomainException(
                'A journal entry needs at least two postings. One side of an entry is not an entry — '.
                'it is half a statement about where money went.'
            );
        }

        $accounts = $this->resolveAccounts($postings);
        $this->assertBalanced($postings);

        $currency = $this->singleCurrency($postings);
        $total = array_sum(array_map(static fn (Posting $p): int => $p->debitMinor, $postings));

        return DB::connection('tenant')->transaction(function () use (
            $memo, $postings, $on, $source, $sourceId, $by, $prefix, $reversesJournalId, $accounts, $currency, $total
        ): Journal {
            $postedOn = $this->postedOn($on);
            $reference = $this->allocateReference($prefix ?? self::MANUAL, $postedOn);

            /*
             * Lines first. The header insert below is what checks that these
             * balance, so they have to exist before it runs.
             */
            foreach ($postings as $i => $posting) {
                JournalLine::create([
                    'entry_ref' => $reference,
                    'account_id' => $accounts[$posting->accountCode]->id,
                    'line_no' => $i + 1,
                    'debit_minor' => $posting->debitMinor,
                    'credit_minor' => $posting->creditMinor,
                    'currency' => $posting->currency,
                    'memo' => $posting->memo,
                    'household_id' => $posting->householdId,
                    'vendor_id' => $posting->vendorId,
                ]);
            }

            return Journal::create([
                'reference' => $reference,
                'memo' => $memo,
                'source' => $source,
                'source_id' => $sourceId,
                'amount_minor' => $total,
                'currency' => $currency,
                'posted_on' => $postedOn->toDateString(),
                'posted_by' => $by?->getKey(),
                'posted_by_name' => $by?->name,
                'reverses_journal_id' => $reversesJournalId,
            ]);
        });
    }

    /**
     * Undo a posted entry the only way it can be undone.
     *
     * Every line comes back mirrored — what was debited is credited and what
     * was credited is debited — so the two entries together net to nothing and
     * both remain visible. That pair IS the audit trail: an accountant looking
     * at this account in a year has to be able to see that a mistake was made
     * and that it was corrected, which a deleted row cannot show them.
     */
    public function reverse(Journal $original, string $memo, ?User $by = null): Journal
    {
        if ($original->isReversed()) {
            throw new DomainException(sprintf(
                'Entry [%s] has already been reversed. Reversing it twice would post the original '.
                'amount a second time rather than cancelling it.',
                $original->reference,
            ));
        }

        if ($original->reverses_journal_id !== null) {
            throw new DomainException(sprintf(
                'Entry [%s] is itself a reversal. Reversing a reversal re-posts what was corrected; '.
                'if that is genuinely intended, post it as a new entry so the ledger says so.',
                $original->reference,
            ));
        }

        $postings = $original->lines
            ->map(fn (JournalLine $line): Posting => $this->postingFrom($line)->mirrored())
            ->all();

        return $this->post(
            memo: $memo,
            postings: $postings,
            on: now(),
            source: self::SOURCE_REVERSAL,
            sourceId: $original->id,
            by: $by,
            prefix: 'REV',
            reversesJournalId: $original->id,
        );
    }

    /* ------------------------------------------------------------------ */
    /* reading */
    /* ------------------------------------------------------------------ */

    /**
     * Every account with a posted balance, in code order.
     *
     * THE TRIAL BALANCE IS THE PROOF. If the debit column and the credit column
     * do not agree, the ledger is broken — and because the database refuses an
     * unbalanced entry, they can only disagree if something wrote around it.
     * `gate:ledger` checks exactly that.
     *
     * @return Collection<int, array{
     *     id: int,
     *     code: string,
     *     name: string,
     *     type: string,
     *     is_active: bool,
     *     debit_minor: int,
     *     credit_minor: int,
     *     balance_minor: int,
     * }>
     */
    public function trialBalance(?Carbon $asAt = null): Collection
    {
        $totals = $this->accountTotals($asAt);

        return Account::query()
            ->orderBy('code')
            ->get()
            ->map(function (Account $account) use ($totals): array {
                $row = $totals[$account->id] ?? ['debit' => 0, 'credit' => 0];

                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'is_active' => $account->is_active,
                    'debit_minor' => (int) $row['debit'],
                    'credit_minor' => (int) $row['credit'],
                    'balance_minor' => $account->signedMinor((int) $row['debit'], (int) $row['credit']),
                ];
            })
            ->values();
    }

    /** The balance of one account, on the side it normally sits. */
    public function balanceOf(Account $account, ?Carbon $asAt = null): Money
    {
        $row = $this->accountTotals($asAt)[$account->id] ?? ['debit' => 0, 'credit' => 0];

        return $account->balanceFrom((int) $row['debit'], (int) $row['credit']);
    }

    /**
     * What the sub-ledger behind a control account adds up to.
     *
     * This is the other half of the tie. A control account's balance is the sum
     * of the lines posted to it; the subsidiary total is the same sum grouped
     * by the household or vendor each line belongs to. They are computed from
     * the same rows on purpose — what the tie actually proves is that every
     * line hitting a control account CARRIES its sub-ledger link, because a
     * line that does not would appear in the first total and vanish from the
     * second.
     *
     * @return array<int|string, int> subsidiary id => signed minor units
     */
    public function subsidiaryBalances(Account $control): array
    {
        if (! $control->is_control || $control->subsidiary === null) {
            throw new InvalidArgumentException(
                "Account [{$control->code}] is not a control account, so it has no sub-ledger to total."
            );
        }

        $column = $control->subsidiary === Account::SUBSIDIARY_VENDORS ? 'vendor_id' : 'household_id';

        /*
         * The query builder, not the model. These rows are aggregates — a sum
         * and a grouping key — not journal lines, and asking Eloquent to hydrate
         * a JournalLine out of them would produce a model whose own columns are
         * absent and whose totals are not properties of anything.
         */
        $rows = DB::connection('tenant')
            ->table('journal_lines')
            ->where('account_id', $control->id)
            ->selectRaw(
                $column.' as subject, SUM(debit_minor) as debits, SUM(credit_minor) as credits'
            )
            ->groupBy($column)
            ->get();

        $balances = [];

        foreach ($rows as $row) {
            // A line with no sub-ledger link groups under the empty key, which
            // is what makes it visible instead of silently lost.
            $key = $row->subject === null ? '' : (int) $row->subject;

            $balances[$key] = $control->signedMinor((int) $row->debits, (int) $row->credits);
        }

        return $balances;
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * Debit and credit totals per account, in one query.
     *
     * @return array<int, array{debit: int, credit: int}>
     */
    private function accountTotals(?Carbon $asAt = null): array
    {
        // The query builder rather than the model, for the reason given in
        // subsidiaryBalances(): these are aggregate rows, not journal lines.
        $query = DB::connection('tenant')
            ->table('journal_lines')
            ->selectRaw('account_id, SUM(debit_minor) as debit, SUM(credit_minor) as credit')
            ->groupBy('account_id');

        if ($asAt !== null) {
            // Joined to the header, because the date an entry BELONGS to is the
            // posting date on the header, not the moment the row was written.
            $query->join('journals', 'journals.reference', '=', 'journal_lines.entry_ref')
                ->where('journals.posted_on', '<=', $asAt->toDateString());
        }

        $totals = [];

        foreach ($query->get() as $row) {
            $totals[(int) $row->account_id] = [
                'debit' => (int) $row->debit,
                'credit' => (int) $row->credit,
            ];
        }

        return $totals;
    }

    /**
     * @param  list<Posting>  $postings
     * @return array<string, Account>
     */
    private function resolveAccounts(array $postings): array
    {
        $codes = array_values(array_unique(array_map(
            static fn (Posting $p): string => $p->accountCode,
            $postings,
        )));

        $accounts = Account::query()->whereIn('code', $codes)->get()->keyBy('code');

        foreach ($codes as $code) {
            $account = $accounts->get($code);

            if ($account === null) {
                throw new DomainException(
                    "No account [{$code}] in this estate's chart. An entry cannot post against an ".
                    'account that does not exist, and creating one silently would let a typo become '.
                    'a permanent line in the chart.'
                );
            }

            /*
             * An archived account still holds its history and still appears on
             * a trial balance. What it cannot do is take new postings — that is
             * the whole difference between archiving and deleting.
             */
            if (! $account->is_active) {
                throw new DomainException(
                    "Account [{$account->code} {$account->name}] is archived and cannot take new postings. ".
                    'Its history remains; reactivate it deliberately if it is still in use.'
                );
            }
        }

        /** @var array<string, Account> */
        return $accounts->all();
    }

    /**
     * @param  list<Posting>  $postings
     */
    private function assertBalanced(array $postings): void
    {
        $debits = array_sum(array_map(static fn (Posting $p): int => $p->debitMinor, $postings));
        $credits = array_sum(array_map(static fn (Posting $p): int => $p->creditMinor, $postings));

        if ($debits !== $credits) {
            /*
             * The database would refuse this a moment later. It is caught here
             * so the message can name both totals and the difference — a raw
             * SQLSTATE 45000 tells whoever hits it that something is wrong and
             * nothing about what.
             */
            throw new DomainException(sprintf(
                'This entry does not balance: %s debited against %s credited, a difference of %s. '.
                'Every entry states where an amount came from and where it went, so the two sides '.
                'are the same number by construction.',
                number_format($debits / 100, 2),
                number_format($credits / 100, 2),
                number_format(abs($debits - $credits) / 100, 2),
            ));
        }
    }

    /**
     * @param  list<Posting>  $postings
     */
    private function singleCurrency(array $postings): string
    {
        $currencies = array_values(array_unique(array_map(
            static fn (Posting $p): string => $p->currency,
            $postings,
        )));

        if (count($currencies) > 1) {
            throw new DomainException(
                'One entry, one currency: '.implode(' and ', $currencies).' cannot be added together. '.
                'A foreign-currency transaction posts at a stated rate as two entries, so the rate '.
                'used is on the record rather than implied.'
            );
        }

        return $currencies[0];
    }

    private function postedOn(Carbon|string|null $on): Carbon
    {
        return match (true) {
            $on instanceof Carbon => $on->copy()->startOfDay(),
            is_string($on) => Carbon::parse($on)->startOfDay(),
            default => Carbon::today(),
        };
    }

    /**
     * The next reference in this prefix and month — "JV-2026-09-0007".
     *
     * Allocated inside the caller's transaction with the month's rows locked,
     * so two committee members posting at once cannot be handed the same
     * number. Derived from the entries themselves rather than from a counter
     * table: a counter is a second thing to keep in step, and the one time it
     * falls behind it hands out a reference that already exists.
     */
    private function allocateReference(string $prefix, Carbon $postedOn): string
    {
        $stem = sprintf('%s-%s-', $prefix, $postedOn->format('Y-m'));

        $last = Journal::query()
            ->where('reference', 'like', $stem.'%')
            ->lockForUpdate()
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last === null ? 1 : ((int) substr($last, strlen($stem))) + 1;

        return $stem.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function postingFrom(JournalLine $line): Posting
    {
        return $line->isDebit()
            ? Posting::debit($line->account->code, $line->debit_minor, $line->memo, $line->household_id, $line->vendor_id, $line->currency)
            : Posting::credit($line->account->code, $line->credit_minor, $line->memo, $line->household_id, $line->vendor_id, $line->currency);
    }
}
