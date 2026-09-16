<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Raising a client's invoice — the invoice run (13 B1).
 *
 * Until this existed, nothing in `app/` wrote `invoices`: the seeder did, and a
 * pilot client could not be billed. This raises the period the billing screen
 * already projects — the month after the client's latest invoice, or the
 * subscription's first — from the same reading of the bill the preview shows,
 * and posts it: Dr 1100 Accounts Receivable (this client), Cr 4000 Revenue.
 *
 * THE LINES ARE THE RECORD, the subtotal is their sum, and the total is the
 * subtotal plus tax — of which there is none ruled (Q-019). In order:
 *
 *   the tier            units × the plan's per-unit price
 *   module add-ons      each active per-guard rate × the guards deployed to the
 *                       client today, and each addition in force on the period's
 *                       first day
 *   removals, discounts negative lines, never a netted-down tier price
 *   one-off charges     only in the period they take effect
 *
 * ONE PERIOD, ONCE. A second invoice for a period the client already has is
 * refused rather than numbered differently; a correction is a credit note.
 * And a period is not billed before it is at most a month away — billing in
 * advance is a client's contract, billing two months ahead is a mistake.
 */
class InvoiceRun
{
    /** Subscriptions that are billed — the billing screen's own definition. */
    private const BILLING_STATES = ['active', 'dunning'];

    /** Days from a period's first day to its due date, for a client with no invoice history. */
    private const FIRST_INVOICE_DUE_DAYS = 14;

    public function __construct(
        private readonly PlatformLedger $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The invoice as it would be raised, without raising it. The run's own
     * arithmetic, so what the raise posts is what this returned.
     *
     * @return array{tenant_id: string, estate: string, period: string, period_start: Carbon, period_end: Carbon, due_on: Carbon, reference: string, currency: string, lines: list<array{description: string, quantity: int, unit_price_minor: int, total_minor: int}>, subtotal_minor: int, tax_minor: int, total_minor: int}
     */
    public function draft(string $tenantId, ?Carbon $today = null): array
    {
        $today = ($today?->copy() ?? Carbon::today())->startOfDay();

        $subscription = Subscription::query()
            ->with(['plan', 'estate'])
            ->where('tenant_id', $tenantId)
            ->whereIn('status', self::BILLING_STATES)
            ->first();

        if ($subscription === null) {
            throw new DomainException('This client has no billing subscription, so there is no period to invoice.');
        }

        $latest = Invoice::query()->where('tenant_id', $tenantId)->orderByDesc('period_start')->first();

        $start = $latest !== null
            ? $latest->period_end->copy()->addDay()->startOfDay()
            : ($subscription->started_on?->copy()->startOfMonth() ?? $today->copy()->startOfMonth());
        $end = $start->copy()->endOfMonth()->startOfDay();

        if ($start->gt($today->copy()->startOfMonth()->addMonthNoOverflow())) {
            throw new DomainException(sprintf(
                '%s is the next period to invoice, and it is more than a month away. A period is billed at most a month ahead.',
                $start->format('F Y'),
            ));
        }

        if (Invoice::query()->where('tenant_id', $tenantId)->whereDate('period_start', $start)->exists()) {
            throw new DomainException('This client already has an invoice for '.$start->format('F Y').'. A correction is a credit note, not a second invoice.');
        }

        $currency = (string) $subscription->plan->currency;
        $lines = [$this->line(
            $subscription->plan->name.' subscription',
            (int) $subscription->unit_count,
            (int) $subscription->plan->price_per_unit_minor,
        )];

        /*
         * Module add-ons billed per guard — the same definition of "deployed" the
         * preview and the client directory use, counted when the invoice is
         * raised. A client with no guards gets no line reading zero.
         */
        $guards = DB::connection('mysql')
            ->table('guards')
            ->where('tenant_id', $tenantId)
            ->whereNotIn('status', ['on_leave', 'suspended'])
            ->count();

        $rates = DB::connection('mysql')
            ->table('platform_rates')
            ->where('is_active', true)
            ->where('basis', 'guard')
            ->orderBy('sort')
            ->get();

        foreach ($rates as $rate) {
            if ($guards > 0 && (int) $rate->amount_minor > 0) {
                $lines[] = $this->line((string) $rate->label, $guards, (int) $rate->amount_minor);
            }
        }

        $overrides = DB::connection('mysql')
            ->table('subscription_line_items')
            ->where('tenant_id', $tenantId)
            ->whereDate('effective_from', '<=', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();

        foreach ($overrides as $override) {
            $amount = (int) $override->amount_minor;

            // A one-off belongs to the period it takes effect in, and no other.
            if ($override->recurrence === 'one_off' && Carbon::parse((string) $override->effective_from)->lt($start)) {
                continue;
            }

            $signed = $override->type === 'removal' ? -abs($amount) : $amount;

            if ($signed !== 0) {
                $lines[] = $this->line((string) $override->description, 1, $signed);
            }
        }

        $subtotal = array_sum(array_column($lines, 'total_minor'));

        // ASSUMPTION Q-019 — no GCT is charged on Gemini's subscription invoices.
        // Nobody has ruled whether General Consumption Tax applies to the
        // platform's services, and adding 15% to a client's bill is not a
        // default to guess at. The column is here so the ruling is one line.
        $tax = 0;

        $estate = $subscription->estate;

        return [
            'tenant_id' => $tenantId,
            'estate' => $estate->name ?? $tenantId,
            'period' => $start->format('M Y'),
            'period_start' => $start,
            'period_end' => $end,
            'due_on' => $latest !== null
                ? $this->sameDayOfMonth($latest->due_on, $start)
                : $start->copy()->addDays(self::FIRST_INVOICE_DUE_DAYS),
            'reference' => $this->reference($estate, $tenantId, $start),
            'currency' => $currency,
            'lines' => $lines,
            'subtotal_minor' => $subtotal,
            'tax_minor' => $tax,
            'total_minor' => $subtotal + $tax,
        ];
    }

    /**
     * Raise it: the invoice, its lines and its journal entry, together or not at all.
     *
     * @throws DomainException
     */
    public function raise(string $tenantId, User $by, ?Carbon $today = null): Invoice
    {
        $draft = $this->draft($tenantId, $today);

        if ($draft['total_minor'] <= 0) {
            throw new DomainException('This invoice would total '.number_format($draft['total_minor'] / 100, 2).'. An invoice for nothing, or for less, is not raised — check the client\'s line items.');
        }

        $subscriptionId = Subscription::query()->where('tenant_id', $tenantId)->value('id');

        $invoice = DB::connection('mysql')->transaction(function () use ($draft, $subscriptionId, $by): Invoice {
            $invoice = Invoice::create([
                'tenant_id' => $draft['tenant_id'],
                'subscription_id' => $subscriptionId,
                'reference' => $draft['reference'],
                'period' => $draft['period'],
                'period_start' => $draft['period_start']->toDateString(),
                'period_end' => $draft['period_end']->toDateString(),
                'subtotal_minor' => $draft['subtotal_minor'],
                'total_minor' => $draft['total_minor'],
                'currency' => $draft['currency'],
                'due_on' => $draft['due_on']->toDateString(),
                'status' => 'issued',
            ]);

            foreach ($draft['lines'] as $line) {
                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    ...$line,
                    'currency' => $draft['currency'],
                ]);
            }

            $journal = $this->ledger->invoiceRaised(
                tenantId: $draft['tenant_id'],
                invoiceId: $invoice->id,
                invoiceReference: $draft['reference'],
                amountMinor: $draft['total_minor'],
                currency: $draft['currency'],
                on: Carbon::today(),
                by: $by,
            );

            $invoice->forceFill(['journal_ref' => $journal])->save();

            return $invoice;
        });

        $this->audit->record(
            action: 'billing.invoice_raised',
            entityType: 'Invoice',
            entityId: (string) $invoice->id,
            after: [
                'reference' => $invoice->reference,
                'period' => $draft['period'],
                'total_minor' => $draft['total_minor'],
                'lines' => count($draft['lines']),
                'journal' => $invoice->journal_ref,
            ],
            tenantId: $draft['tenant_id'],
        );

        return $invoice;
    }

    /** @return array{description: string, quantity: int, unit_price_minor: int, total_minor: int} */
    private function line(string $description, int $quantity, int $unitPriceMinor): array
    {
        return [
            'description' => $description,
            'quantity' => $quantity,
            'unit_price_minor' => $unitPriceMinor,
            'total_minor' => $quantity * $unitPriceMinor,
        ];
    }

    /**
     * "PPV-INV-202610" — the client's receipt prefix, the month.
     *
     * The prefix is unique across the platform (D-089), so two clients whose
     * names begin alike cannot collide the way two-letter stems could.
     */
    private function reference(?Tenant $estate, string $tenantId, Carbon $start): string
    {
        $prefix = $estate->receipt_prefix ?? strtoupper(substr($tenantId, 0, 6));

        return $prefix.'-INV-'.$start->format('Ym');
    }

    private function sameDayOfMonth(Carbon $reference, Carbon $target): Carbon
    {
        $month = $target->copy()->startOfMonth();

        return $month->addDays(min($reference->day, $month->daysInMonth) - 1);
    }
}
