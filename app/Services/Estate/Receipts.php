<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Payment;
use App\Support\MoneyFormatter;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receipt numbers, and the register they make — board 5's Receipts tab.
 *
 * THE RULING (12 §1): `{PREFIX}-R-{00001}`, sequential per estate, never reused,
 * gaps recorded and visible. One sequence per estate, allocated at posting,
 * never at draft.
 *
 * ALLOCATED UNDER A LOCK, INSIDE THE PAYMENT'S OWN TRANSACTION. `next()` locks
 * the sequence row, increments it and hands the number back; `Dues::receive()`
 * calls it inside the transaction that writes the payment and posts the entry.
 * Two payments keyed at once cannot be handed the same number, and a posting
 * that fails rolls the increment back with it — so the ordinary path leaves no
 * gap at all.
 *
 * A GAP IS STILL POSSIBLE AND IS NEVER HIDDEN. A number can be handed out and
 * its payment lost some other way — a restore from backup, a row removed by
 * hand at the database. The sequence remembers the high-water mark, so the
 * register draws every number up to it that no receipt carries, as a row that
 * says so. "Never reused" is what makes that honest: the gap stays a gap.
 *
 * THE PREFIX IS THE ESTATE RECORD'S `receipt_prefix` (13 A1) — `PPV`, set at
 * provisioning and fixed by the first receipt. It is found from the connection
 * rather than from tenancy: every estate database is `gs_estate_<subdomain>`
 * and the subdomain is the estate's key, so the lookup is the same in a
 * request, a queued job and a seeder.
 */
class Receipts
{
    /** Numbers are five digits wide, as the ruling writes them. */
    private const WIDTH = 5;

    /** @var array<string, string> the prefix per estate database, read once */
    private array $prefixes = [];

    /**
     * The estate's receipt prefix, from its record.
     *
     * AN ESTATE WITH NO PREFIX NUMBERS NOTHING. Every estate is given one at
     * provisioning and every earlier one by migration, so an empty value means
     * somebody cleared it by hand, and a receipt printed under a guessed
     * prefix would start a series nobody chose.
     *
     * A DATABASE NO ESTATE RECORD NAMES is a test fixture — tenancy resolves an
     * estate only through its record, so no route, job or command reaches one
     * in production. Only under test does it number from its own name.
     *
     * @throws DomainException
     */
    public function prefix(): string
    {
        $database = (string) DB::connection('tenant')->getDatabaseName();

        if (isset($this->prefixes[$database])) {
            return $this->prefixes[$database];
        }

        $key = (string) preg_replace('/^'.preg_quote((string) config('tenancy.database.prefix'), '/').'/', '', $database);

        $record = DB::connection((string) config('tenancy.database.central_connection'))
            ->table('tenants')
            ->where('id', $key)
            ->first(['receipt_prefix']);

        if ($record === null && app()->environment('testing')) {
            return $this->prefixes[$database] = strtoupper(substr($key, 0, 6));
        }

        if ($record === null || $record->receipt_prefix === null || $record->receipt_prefix === '') {
            throw new DomainException("Estate [{$key}] has no receipt prefix, so no receipt can be numbered. Set one with `php artisan estate:receipt-prefix`.");
        }

        return $this->prefixes[$database] = (string) $record->receipt_prefix;
    }

    /**
     * Allocate the next receipt number.
     *
     * MUST BE CALLED INSIDE A TRANSACTION, so that the lock holds until the
     * payment it numbers is committed and a failed posting takes the increment
     * back with it. `Dues::receive()` and `Dues::paymentRun()` both do.
     */
    public function next(): string
    {
        $prefix = $this->prefix();
        $connection = DB::connection('tenant');

        $row = $connection->table('receipt_sequences')->where('prefix', $prefix)->lockForUpdate()->first();

        if ($row === null) {
            $connection->table('receipt_sequences')->insert([
                'prefix' => $prefix,
                'first_no' => 1,
                'last_no' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = $connection->table('receipt_sequences')->where('prefix', $prefix)->lockForUpdate()->first();
        }

        $next = (int) $row->last_no + 1;

        $connection->table('receipt_sequences')
            ->where('prefix', $prefix)
            ->update(['last_no' => $next, 'updated_at' => now()]);

        return $this->format($next);
    }

    /** "PPV-R-04471". */
    public function format(int $number): string
    {
        return sprintf('%s-R-%0'.self::WIDTH.'d', $this->prefix(), $number);
    }

    /** The integer inside a receipt number, or null for one not in the format. */
    public function numberOf(string $receiptNo): ?int
    {
        return preg_match('/-R-(\d+)$/', $receiptNo, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * The register: every receipt, newest first, and every gap in the sequence.
     *
     * NOT ONE FIGURE HERE IS A HOUSEHOLD'S BALANCE. A receipt is an amount the
     * estate took, which is a fact about the receipt; what the household still
     * owes is board 5's and 6's, behind their own gate.
     *
     * @return array{prefix: string, first_no: int, last_no: int, rows: list<array<string, mixed>>, gaps: list<array<string, mixed>>, kpis: list<array<string, mixed>>}
     */
    public function register(?Carbon $asAt = null, int $limit = 200): array
    {
        $today = ($asAt?->copy() ?? Carbon::today())->startOfDay();
        $prefix = $this->prefix();

        $sequence = DB::connection('tenant')
            ->table('receipt_sequences')
            ->where('prefix', $prefix)
            ->first();

        $firstNo = (int) ($sequence->first_no ?? 1);
        $lastNo = (int) ($sequence->last_no ?? 0);

        $payments = Payment::query()
            ->with(['unit', 'entry'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $rows = $payments->map(fn (Payment $payment): array => [
            'id' => $payment->id,
            'receipt_no' => $payment->receipt_no,
            'unit' => $payment->unit->reference,
            'unit_id' => $payment->unit_id,
            'method' => $payment->method,
            'method_label' => ucfirst($payment->method),
            'reference' => $payment->reference,
            'amount' => MoneyFormatter::fromMinor($payment->amount_minor),
            'amount_minor' => $payment->amount_minor,
            'received_on' => $payment->received_at->format('M j, Y'),
            'entered_on' => $payment->entered_at->format('M j, Y'),
            'entered_late' => $payment->enteredLate(),
            'received_by' => $payment->received_by_name,
            'status' => $payment->status,
            'journal_ref' => $payment->journal_ref,

            // The entry's id, for the record screen — which binds on the id
            // and not the reference, because a reference is a display string.
            'journal_id' => $payment->entry?->id,
        ])->values()->all();

        /*
         * The gaps: every number from the sequence's floor to its high-water
         * mark that no receipt carries. Read from the whole table rather than
         * the page, because a gap on page three is still a gap. Below the
         * floor nothing was ever allocated, so nothing is missing.
         */
        $present = Payment::query()
            ->where('receipt_no', 'like', $prefix.'-R-%')
            ->pluck('receipt_no')
            ->map(fn (string $no): ?int => $this->numberOf($no))
            ->filter()
            ->flip();

        $gaps = [];

        for ($n = $firstNo; $n <= $lastNo; $n++) {
            if (! $present->has($n)) {
                $gaps[] = [
                    'receipt_no' => $this->format($n),
                    'number' => $n,
                    'note' => 'Allocated and carried by no receipt. The number is not reused; whatever was posted against it is not in this register.',
                ];
            }
        }

        $thisMonth = Payment::query()
            ->where('status', 'recorded')
            ->whereBetween('received_at', [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()])
            ->sum('amount_minor');

        return [
            'prefix' => $prefix,
            'first_no' => $firstNo,
            'last_no' => $lastNo,
            'rows' => $rows,
            'gaps' => $gaps,
            'kpis' => [
                ['key' => 'issued', 'value' => number_format(max(0, $lastNo - $firstNo + 1)), 'label' => 'Receipts issued'],
                ['key' => 'month', 'value' => MoneyFormatter::fromMinor((int) $thisMonth), 'label' => 'Received in '.$today->format('F')],
                ['key' => 'gaps', 'value' => (string) count($gaps), 'label' => 'Gaps in the sequence'],
            ],
        ];
    }
}
