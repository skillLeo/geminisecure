<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one door into Gemini's own books (13 B1).
 *
 * The estate `Ledger`'s rules, on the central connection: lines first, header
 * last, the database refusing an entry that does not balance and any edit to one
 * that does. This class is the ergonomics and the references; the guarantees are
 * the triggers in `create_platform_ledger`.
 *
 * TWO ACCOUNTS, BY NAME. An invoice debits the client's receivable and credits
 * revenue; a credit note does the opposite. Nothing else posts here, and a
 * method per event rather than a general `post(array)` keeps it so.
 */
class PlatformLedger
{
    public const RECEIVABLE = '1100';

    public const REVENUE = '4000';

    public const SOURCE_INVOICE = 'invoice';

    public const SOURCE_CREDIT_NOTE = 'credit_note';

    /** Dr 1100 (this client) / Cr 4000. Returns the entry's reference. */
    public function invoiceRaised(string $tenantId, int $invoiceId, string $invoiceReference, int $amountMinor, string $currency, Carbon $on, ?User $by): string
    {
        return $this->post(
            prefix: 'INV',
            memo: 'Invoice '.$invoiceReference,
            source: self::SOURCE_INVOICE,
            sourceId: $invoiceId,
            lines: [
                ['account' => self::RECEIVABLE, 'debit' => $amountMinor, 'credit' => 0, 'tenant' => $tenantId],
                ['account' => self::REVENUE, 'debit' => 0, 'credit' => $amountMinor, 'tenant' => null],
            ],
            currency: $currency,
            on: $on,
            by: $by,
        );
    }

    /** Dr 4000 / Cr 1100 (this client). Returns the entry's reference. */
    public function creditNoteIssued(string $tenantId, int $creditNoteId, string $noteReference, int $amountMinor, string $currency, Carbon $on, ?User $by): string
    {
        return $this->post(
            prefix: 'CN',
            memo: 'Credit note '.$noteReference,
            source: self::SOURCE_CREDIT_NOTE,
            sourceId: $creditNoteId,
            lines: [
                ['account' => self::REVENUE, 'debit' => $amountMinor, 'credit' => 0, 'tenant' => null],
                ['account' => self::RECEIVABLE, 'debit' => 0, 'credit' => $amountMinor, 'tenant' => $tenantId],
            ],
            currency: $currency,
            on: $on,
            by: $by,
        );
    }

    /** What one client owes on the ledger: debits less credits on 1100 for them. */
    public function receivableFor(string $tenantId): int
    {
        $row = DB::connection('mysql')
            ->table('platform_journal_lines as l')
            ->join('platform_accounts as a', 'a.id', '=', 'l.platform_account_id')
            ->where('a.code', self::RECEIVABLE)
            ->where('l.tenant_id', $tenantId)
            ->selectRaw('COALESCE(SUM(l.debit_minor), 0) AS debits, COALESCE(SUM(l.credit_minor), 0) AS credits')
            ->first();

        return (int) ($row->debits ?? 0) - (int) ($row->credits ?? 0);
    }

    /**
     * @param  list<array{account: string, debit: int, credit: int, tenant: string|null}>  $lines
     */
    private function post(string $prefix, string $memo, string $source, int $sourceId, array $lines, string $currency, Carbon $on, ?User $by): string
    {
        $debits = array_sum(array_column($lines, 'debit'));

        if ($debits <= 0 || $debits !== array_sum(array_column($lines, 'credit'))) {
            throw new DomainException('An entry in Gemini\'s books balances and moves something. This one does not.');
        }

        $connection = DB::connection('mysql');

        return $connection->transaction(function () use ($connection, $prefix, $memo, $source, $sourceId, $lines, $currency, $on, $by, $debits): string {
            $accounts = $connection->table('platform_accounts')->pluck('id', 'code');

            $stem = sprintf('%s-%s-', $prefix, $on->format('Y-m'));
            $last = $connection->table('platform_journals')
                ->where('reference', 'like', $stem.'%')
                ->lockForUpdate()
                ->orderByDesc('reference')
                ->value('reference');

            $reference = $stem.str_pad((string) ($last === null ? 1 : (int) substr((string) $last, strlen($stem)) + 1), 4, '0', STR_PAD_LEFT);

            foreach ($lines as $i => $line) {
                if (! isset($accounts[$line['account']])) {
                    throw new DomainException("No account [{$line['account']}] in Gemini's chart.");
                }

                $connection->table('platform_journal_lines')->insert([
                    'entry_ref' => $reference,
                    'platform_account_id' => $accounts[$line['account']],
                    'line_no' => $i + 1,
                    'debit_minor' => $line['debit'],
                    'credit_minor' => $line['credit'],
                    'currency' => $currency,
                    'memo' => $memo,
                    'tenant_id' => $line['tenant'],
                    'created_at' => Carbon::now(),
                ]);
            }

            // The header last: its insert is what checks the lines balance.
            $connection->table('platform_journals')->insert([
                'reference' => $reference,
                'memo' => $memo,
                'source' => $source,
                'source_id' => $sourceId,
                'amount_minor' => $debits,
                'currency' => $currency,
                'posted_on' => $on->toDateString(),
                'posted_by' => $by?->getKey(),
                'posted_by_name' => $by?->name,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);

            return $reference;
        });
    }
}
