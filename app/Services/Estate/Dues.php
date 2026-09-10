<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Account;
use App\Models\Estate\Charge;
use App\Models\Estate\Payment;
use App\Models\Estate\Unit;
use App\Models\User;
use Brick\Money\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Charges, payments and what a unit owes — boards 5, 6 and 35.
 *
 * EVERY WRITE HERE RAISES A JOURNAL ENTRY, and the entry is the money. A charge
 * debits Dues Receivable and credits the income account the treasurer picked; a
 * payment debits the bank and credits Dues Receivable. Neither is recorded any
 * other way, so a unit's balance and the receivables control account cannot
 * drift apart — they are the same rows read two ways.
 *
 * NOTHING IN THIS CLASS COMPUTES A BALANCE FROM `charges` AND `payments`. Every
 * figure a screen shows comes from the posted journal lines, and the billing
 * records supply only what bookkeeping does not: a due date, a receipt number,
 * a method. That is what makes the totals traceable — a dashboard figure derived
 * from the billing tables would agree with the ledger by coincidence, and the
 * coincidence is what fails at an audit.
 *
 * AGEING IS BY THE OLDEST OPEN CHARGE, not by each charge separately, and the
 * boards are what settle it: board 5 gives each unit a SINGLE ageing badge, and
 * board 6 puts the whole of Lot 47's J$12,400 in one bucket even though its two
 * open charges fell due a month apart. So a unit's whole balance is aged by how
 * long it has had anything outstanding, which is also the question a treasurer
 * is actually asking — "how long has this unit been in arrears" rather than
 * "how old is each individual invoice".
 */
class Dues
{
    /** The buckets board 5 draws, in order, with their lower bound in days. */
    public const BUCKETS = [
        'current' => 0,
        'd30' => 30,
        'd60' => 60,
        'd90' => 90,
    ];

    /** What each bucket is called on screen. */
    public const BUCKET_LABELS = [
        'current' => 'Current',
        'd30' => '30 days',
        'd60' => '60 days',
        'd90' => '90+ days',
    ];

    public function __construct(private readonly Ledger $ledger) {}

    /* ------------------------------------------------------------------ */
    /* writing */
    /* ------------------------------------------------------------------ */

    /**
     * Bill a unit, and post the entry that makes it real.
     *
     * @param  string  $account  the income account code the charge credits — board 35 draws it as a field
     */
    public function charge(
        Unit $unit,
        Money $amount,
        string $description,
        Carbon|string $dueOn,
        string $type = 'dues',
        string $account = '4000',
        ?string $period = null,
        ?User $by = null,
    ): Charge {
        if ($amount->isNegativeOrZero()) {
            throw new DomainException(
                'A charge must be a positive amount. Crediting a unit is a credit note, which is a '.
                'different act with a different record — not a negative charge.'
            );
        }

        return DB::connection('tenant')->transaction(function () use (
            $unit, $amount, $description, $dueOn, $type, $account, $period, $by
        ): Charge {
            $due = $dueOn instanceof Carbon ? $dueOn->copy() : Carbon::parse($dueOn);
            $minor = $amount->getMinorAmount()->toInt();

            $charge = Charge::create([
                'unit_id' => $unit->id,
                'type' => $type,
                'period' => $period ?? $due->format('Y-m'),
                'reference' => $this->nextReference('CHG', $due),
                'description' => $description,
                'amount_minor' => $minor,
                'currency' => $amount->getCurrency()->getCurrencyCode(),
                'account_id' => Account::where('code', $account)->value('id'),
                'due_on' => $due->toDateString(),
                'status' => 'outstanding',
                'posted_by' => $by?->getKey(),
                'posted_by_name' => $by?->name,
            ]);

            $entry = $this->ledger->post(
                memo: $description,
                postings: [
                    Posting::debit('1200', $minor, $description, unitId: $unit->id),
                    Posting::credit($account, $minor, $description),
                ],
                on: $due,
                source: Ledger::SOURCE_CHARGE,
                sourceId: $charge->id,
                by: $by,
                prefix: 'CHG',
            );

            $charge->forceFill(['journal_ref' => $entry->reference])->save();

            return $charge;
        });
    }

    /**
     * Record money received against a unit, and post the entry.
     *
     * `receivedAt` and `enteredAt` are separate arguments and neither defaults
     * to the other. A guard takes cash at the gate on Friday and the treasurer
     * keys it on Monday; the resident's receipt is dated Friday, and an arrears
     * report that used the entry date would show them in default over a weekend
     * they had already paid for.
     */
    public function receive(
        Unit $unit,
        Money $amount,
        string $method,
        Carbon|string $receivedAt,
        ?string $receiptNo = null,
        string $bankAccount = '1000',
        ?User $by = null,
        ?string $gatewayRef = null,
        ?Money $gatewayFee = null,
    ): Payment {
        if ($amount->isNegativeOrZero()) {
            throw new DomainException(
                'A payment must be a positive amount. Reversing one is a reversal, which leaves both '.
                'records standing — not a negative receipt.'
            );
        }

        return DB::connection('tenant')->transaction(function () use (
            $unit, $amount, $method, $receivedAt, $receiptNo, $bankAccount, $by, $gatewayRef, $gatewayFee
        ): Payment {
            $received = $receivedAt instanceof Carbon ? $receivedAt->copy() : Carbon::parse($receivedAt);
            $minor = $amount->getMinorAmount()->toInt();

            $payment = Payment::create([
                'unit_id' => $unit->id,
                'receipt_no' => $receiptNo ?? $this->nextReceiptNumber(),
                'amount_minor' => $minor,
                'currency' => $amount->getCurrency()->getCurrencyCode(),
                'method' => $method,
                'received_at' => $received,
                'entered_at' => now(),
                'received_by' => $by?->getKey(),
                'received_by_name' => $by?->name,
                'gateway_ref' => $gatewayRef,
                'gateway_fee_minor' => $gatewayFee?->getMinorAmount()->toInt(),
                'status' => 'recorded',
            ]);

            $memo = 'Payment received — receipt #'.$payment->receipt_no;

            $entry = $this->ledger->post(
                memo: $memo,
                postings: [
                    Posting::debit($bankAccount, $minor, $memo),
                    Posting::credit('1200', $minor, $memo, unitId: $unit->id),
                ],
                on: $received,
                source: Ledger::SOURCE_PAYMENT,
                sourceId: $payment->id,
                by: $by,
                prefix: 'RCT',
            );

            $payment->forceFill(['journal_ref' => $entry->reference])->save();

            return $payment;
        });
    }

    /**
     * A whole month's dues, posted as ONE entry.
     *
     * This is how an estate actually bills: a dues run on the first of the
     * month, one entry, one credit to income and a debit line per unit. Four
     * hundred and fifty separate entries would be four hundred and fifty
     * references for a single act, and a treasurer reviewing the month would
     * have to read all of them to see what was billed.
     *
     * The sub-ledger is unaffected — each unit still gets its own line, which
     * is what its statement and the control-account tie both read.
     *
     * @param  array<int, array{unit: Unit, amount: Money, description: string, type?: string, period?: string}>  $rows
     */
    public function chargeRun(
        array $rows,
        Carbon|string $dueOn,
        string $memo,
        string $account = '4000',
        ?User $by = null,
    ): void {
        if ($rows === []) {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($rows, $dueOn, $memo, $account, $by): void {
            $due = $dueOn instanceof Carbon ? $dueOn->copy() : Carbon::parse($dueOn);
            $accountId = Account::where('code', $account)->value('id');

            $postings = [];
            $total = 0;
            $charges = [];

            /*
             * The sequence is taken once and advanced in memory. Asking the
             * table for the next reference inside the loop would hand every row
             * in the batch the same one, because none of them is written until
             * the loop ends.
             */
            $sequence = $this->nextSequence('CHG', $due);

            foreach ($rows as $row) {
                $minor = $row['amount']->getMinorAmount()->toInt();
                $total += $minor;

                $charges[] = [
                    'unit_id' => $row['unit']->id,
                    'type' => $row['type'] ?? 'dues',
                    'period' => $row['period'] ?? $due->format('Y-m'),
                    'reference' => $this->stampReference('CHG', $due, $sequence++),
                    'description' => $row['description'],
                    'amount_minor' => $minor,
                    'currency' => $row['amount']->getCurrency()->getCurrencyCode(),
                    'account_id' => $accountId,
                    'due_on' => $due->toDateString(),
                    'status' => 'outstanding',
                    'posted_by' => $by?->getKey(),
                    'posted_by_name' => $by?->name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $postings[] = Posting::debit('1200', $minor, $row['description'], unitId: $row['unit']->id);
            }

            $postings[] = Posting::credit($account, $total, $memo);

            $entry = $this->ledger->post(
                memo: $memo,
                postings: $postings,
                on: $due,
                source: Ledger::SOURCE_CHARGE,
                by: $by,
                prefix: 'CHG',
            );

            foreach ($charges as $i => $charge) {
                $charges[$i]['journal_ref'] = $entry->reference;
            }

            DB::connection('tenant')->table('charges')->insert($charges);
        });
    }

    /**
     * A batch of receipts banked together, posted as ONE entry.
     *
     * A bank sweep is one deposit and one entry. Each payment keeps its own
     * receipt number and its own credit line, so the resident's statement still
     * shows their payment and nobody else's.
     *
     * @param  array<int, array{unit: Unit, amount: Money, method: string, received_at: Carbon|string, receipt_no?: string}>  $rows
     */
    public function paymentRun(
        array $rows,
        Carbon|string $bankedOn,
        string $memo,
        string $bankAccount = '1000',
        ?User $by = null,
    ): void {
        if ($rows === []) {
            return;
        }

        DB::connection('tenant')->transaction(function () use ($rows, $bankedOn, $memo, $bankAccount, $by): void {
            $banked = $bankedOn instanceof Carbon ? $bankedOn->copy() : Carbon::parse($bankedOn);

            $next = ((int) Payment::query()->lockForUpdate()->max('receipt_no') ?: 4000) + 1;

            $postings = [];
            $total = 0;
            $payments = [];

            foreach ($rows as $row) {
                $minor = $row['amount']->getMinorAmount()->toInt();
                $total += $minor;

                $receipt = $row['receipt_no'] ?? (string) $next++;
                $lineMemo = 'Payment received — receipt #'.$receipt;

                $received = $row['received_at'] instanceof Carbon
                    ? $row['received_at']->copy()
                    : Carbon::parse((string) $row['received_at']);

                $payments[] = [
                    'unit_id' => $row['unit']->id,
                    'receipt_no' => $receipt,
                    'amount_minor' => $minor,
                    'currency' => $row['amount']->getCurrency()->getCurrencyCode(),
                    'method' => $row['method'],
                    'received_at' => $received,
                    'entered_at' => now(),
                    'received_by' => $by?->getKey(),
                    'received_by_name' => $by?->name,
                    'status' => 'recorded',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $postings[] = Posting::credit('1200', $minor, $lineMemo, unitId: $row['unit']->id);
            }

            $postings[] = Posting::debit($bankAccount, $total, $memo);

            $entry = $this->ledger->post(
                memo: $memo,
                postings: $postings,
                on: $banked,
                source: Ledger::SOURCE_PAYMENT,
                by: $by,
                prefix: 'RCT',
            );

            foreach ($payments as $i => $payment) {
                $payments[$i]['journal_ref'] = $entry->reference;
            }

            DB::connection('tenant')->table('payments')->insert($payments);
        });
    }

    /* ------------------------------------------------------------------ */
    /* reading — every figure below comes from posted journal lines */
    /* ------------------------------------------------------------------ */

    /**
     * What one unit owes, from the ledger.
     *
     * The sum of every line posted to Dues Receivable for this unit, debits
     * less credits. Not a sum of charges less payments — that would be a second
     * arithmetic over a second set of rows, free to disagree with the accounts.
     */
    public function balanceOf(Unit $unit, ?Carbon $asAt = null): Money
    {
        return Money::ofMinor($this->unitBalances($asAt)[$unit->id] ?? 0, 'JMD');
    }

    /**
     * Every unit's balance, keyed by unit id, in one query.
     *
     * @return array<int, int> unit id => minor units owed
     */
    public function unitBalances(?Carbon $asAt = null): array
    {
        $query = DB::connection('tenant')
            ->table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.code', '1200')
            ->whereNotNull('journal_lines.unit_id')
            ->selectRaw('journal_lines.unit_id as unit_id, SUM(journal_lines.debit_minor - journal_lines.credit_minor) as balance')
            ->groupBy('journal_lines.unit_id');

        if ($asAt !== null) {
            $query->join('journals', 'journals.reference', '=', 'journal_lines.entry_ref')
                ->where('journals.posted_on', '<=', $asAt->toDateString());
        }

        $balances = [];

        foreach ($query->get() as $row) {
            $balances[(int) $row->unit_id] = (int) $row->balance;
        }

        return $balances;
    }

    /**
     * The running statement board 6 draws, newest first.
     *
     * Built from the JOURNAL, not from charges and payments. The running balance
     * accumulates oldest-first and is then reversed for display, because that is
     * the only order in which a balance means anything.
     *
     * @return list<array<string, mixed>>
     */
    public function statement(Unit $unit, int $limit = 5): array
    {
        $lines = DB::connection('tenant')
            ->table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journals', 'journals.reference', '=', 'journal_lines.entry_ref')
            ->where('accounts.code', '1200')
            ->where('journal_lines.unit_id', $unit->id)
            ->orderBy('journals.posted_on')
            ->orderBy('journal_lines.id')
            ->select([
                'journals.posted_on',
                'journals.memo as entry_memo',
                'journals.reference',
                'journals.source',
                'journal_lines.memo as line_memo',
                'journal_lines.debit_minor',
                'journal_lines.credit_minor',
            ])
            ->get();

        $running = 0;
        $rows = [];

        foreach ($lines as $line) {
            $running += (int) $line->debit_minor - (int) $line->credit_minor;

            $rows[] = [
                'date' => Carbon::parse((string) $line->posted_on)->format('M j'),

                /*
                 * The LINE's memo first. A monthly dues run is one entry across
                 * four hundred and fifty units, and its header says so — but
                 * this resident's statement has to read "Payment received —
                 * receipt #4471", which is their line and nobody else's.
                 */
                'description' => (string) ($line->line_memo ?? $line->entry_memo),
                'reference' => (string) $line->reference,
                'charge_minor' => (int) $line->debit_minor ?: null,
                'payment_minor' => (int) $line->credit_minor ?: null,
                'balance_minor' => $running,
            ];
        }

        return array_slice(array_reverse($rows), 0, $limit);
    }

    /**
     * How long each unit has had anything outstanding, and therefore which
     * bucket its whole balance sits in.
     *
     * @return array<int, string> unit id => bucket key
     */
    public function unitBuckets(?Carbon $asAt = null): array
    {
        $today = $asAt?->copy() ?? Carbon::today();
        $balances = $this->unitBalances($asAt);

        /*
         * The oldest charge that is still open, per unit, under FIFO: payments
         * settle the oldest debt first. Derived rather than stored — an
         * allocation table is a third set of rows to keep in step, and the one
         * time it falls behind, a unit is chased for a bill it has paid.
         */
        $oldestOpen = $this->oldestOpenChargeDates($today);

        $buckets = [];

        foreach ($balances as $unitId => $balance) {
            if ($balance <= 0) {
                continue;
            }

            $due = $oldestOpen[$unitId] ?? null;
            $daysOverdue = $due === null ? 0 : (int) $due->diffInDays($today, absolute: false);

            $buckets[$unitId] = match (true) {
                $daysOverdue >= 90 => 'd90',
                $daysOverdue >= 60 => 'd60',
                $daysOverdue >= 30 => 'd30',
                default => 'current',
            };
        }

        return $buckets;
    }

    /**
     * The four ageing totals board 5 puts across the top.
     *
     * @return array<string, int> bucket key => minor units
     */
    public function ageing(?Carbon $asAt = null): array
    {
        $balances = $this->unitBalances($asAt);
        $buckets = $this->unitBuckets($asAt);

        $totals = array_fill_keys(array_keys(self::BUCKETS), 0);

        foreach ($buckets as $unitId => $bucket) {
            $totals[$bucket] += $balances[$unitId];
        }

        return $totals;
    }

    /**
     * One unit's balance split across the four buckets — board 6's strip.
     *
     * The whole balance falls in one bucket, for the reason in the class
     * docblock. The other three are drawn at zero rather than omitted, because
     * a strip missing three of its four figures reads as a broken screen.
     *
     * @return array<string, int>
     */
    public function ageingOf(Unit $unit, ?Carbon $asAt = null): array
    {
        $totals = array_fill_keys(array_keys(self::BUCKETS), 0);
        $balance = $this->balanceOf($unit, $asAt)->getMinorAmount()->toInt();

        if ($balance > 0) {
            $totals[$this->unitBuckets($asAt)[$unit->id] ?? 'current'] = $balance;
        }

        return $totals;
    }

    /**
     * When each unit last paid anything.
     *
     * @return Collection<int, string>
     */
    public function lastPaymentDates(): Collection
    {
        return Payment::query()
            ->where('status', 'recorded')
            ->selectRaw('unit_id, MAX(received_at) as last_received')
            ->groupBy('unit_id')
            ->pluck('last_received', 'unit_id');
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * The due date of the oldest charge each unit has not yet worked off.
     *
     * FIFO: charges oldest first, payments applied against them in turn. What
     * remains unpaid when the money runs out is the oldest open charge.
     *
     * @return array<int, Carbon>
     */
    private function oldestOpenChargeDates(Carbon $asAt): array
    {
        $charges = DB::connection('tenant')
            ->table('charges')
            ->where('due_on', '<=', $asAt->toDateString())
            ->orderBy('due_on')
            ->orderBy('id')
            ->get(['unit_id', 'due_on', 'amount_minor']);

        $paid = DB::connection('tenant')
            ->table('payments')
            ->where('status', 'recorded')
            ->where('received_at', '<=', $asAt->copy()->endOfDay())
            ->selectRaw('unit_id, SUM(amount_minor) as paid')
            ->groupBy('unit_id')
            ->pluck('paid', 'unit_id');

        $remaining = [];
        $oldest = [];

        foreach ($charges as $charge) {
            $unitId = (int) $charge->unit_id;

            $remaining[$unitId] ??= (int) ($paid[$unitId] ?? 0);

            if ($remaining[$unitId] >= (int) $charge->amount_minor) {
                $remaining[$unitId] -= (int) $charge->amount_minor;

                continue;
            }

            // The money ran out here: this is the oldest charge still open.
            $oldest[$unitId] ??= Carbon::parse((string) $charge->due_on);
            $remaining[$unitId] = 0;
        }

        return $oldest;
    }

    /** "CHG-2026-09-0007" — sequential within a prefix and month. */
    private function nextReference(string $prefix, Carbon $on): string
    {
        return $this->stampReference($prefix, $on, $this->nextSequence($prefix, $on));
    }

    /**
     * The next number in this prefix and month, under a lock.
     *
     * Separated from the formatting so a batch can take the sequence once and
     * advance it in memory across hundreds of rows that are all written at the
     * end.
     */
    private function nextSequence(string $prefix, Carbon $on): int
    {
        $stem = sprintf('%s-%s-', $prefix, $on->format('Y-m'));

        $last = Charge::query()
            ->where('reference', 'like', $stem.'%')
            ->lockForUpdate()
            ->orderByDesc('reference')
            ->value('reference');

        return $last === null ? 1 : ((int) substr((string) $last, strlen($stem))) + 1;
    }

    private function stampReference(string $prefix, Carbon $on, int $sequence): string
    {
        return sprintf('%s-%s-%s', $prefix, $on->format('Y-m'), str_pad((string) $sequence, 4, '0', STR_PAD_LEFT));
    }

    /**
     * The next receipt number.
     *
     * A plain running integer, because that is what a resident reads back over
     * the phone and what the board draws — "#4471". Allocated under a lock so
     * two payments taken at once cannot be handed the same number.
     */
    private function nextReceiptNumber(): string
    {
        $last = Payment::query()->lockForUpdate()->max('receipt_no');

        return (string) (((int) $last ?: 4000) + 1);
    }
}
