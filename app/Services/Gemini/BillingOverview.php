<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\MoneyFormatter;
use Brick\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gemini Security billing its client estates — board screen super-admin-32.
 *
 * Reads ONLY gs_platform. Resident dues are a different ledger in a different
 * database and never appear here; conflating the two is how a platform invoice
 * ends up restricting a resident, which must never happen.
 *
 * The shape is the approved board's: four KPI cards, then one table whose rows
 * are each estate's billing position. The board's own table mixes raised
 * invoices with a row marked "Not yet invoiced", so the default view here is
 * the same thing built from real records — the latest raised invoice per
 * estate, followed by the period each billing subscription will be invoiced
 * next. History is not lost: the top-bar search runs over every invoice.
 */
class BillingOverview
{
    /**
     * Subscription states that still produce an invoice next period.
     *
     * `dunning` is in the list deliberately. Chasing an unpaid invoice does not
     * stop the service, so the next period is still billable — and it never
     * withholds access either. `onboarding` has not started billing,
     * `suspended` has billing gated, and `cancelled` will not bill again.
     *
     * @var list<string>
     */
    private const BILLING_STATES = ['active', 'dunning'];

    /** A search is a lookup, not an export. */
    private const SEARCH_LIMIT = 50;

    /**
     * One invoice, taken apart line by line — board screen super-admin-33.
     *
     * THE LINES ARE THE RECORD, and the total is their sum. An invoice
     * carrying only a total is a number nobody can query: a client asking
     * "why is this J$171,000" needs the two lines that make it up, and this
     * screen exists to answer exactly that.
     *
     * So the total shown is `total_minor` as posted, and the lines are read
     * beside it. Where the lines do not add up to the posted total the screen
     * SAYS SO rather than quietly showing one and hiding the other — a posted
     * invoice cannot be edited to make the arithmetic work, and an invoice
     * whose breakdown disagrees with its total is a real finding.
     *
     * @return array<string, mixed>|null
     */
    public function invoice(int $invoiceId): ?array
    {
        $invoice = DB::connection('mysql')
            ->table('invoices')
            ->join('tenants', 'tenants.id', '=', 'invoices.tenant_id')
            ->where('invoices.id', $invoiceId)
            ->select([
                'invoices.id',
                'invoices.reference',
                'invoices.period',
                'invoices.period_start',
                'invoices.period_end',
                'invoices.total_minor',
                'invoices.currency',
                'invoices.due_on',
                'invoices.status',
                'invoices.paid_on',
                'tenants.id as tenant_id',
                'tenants.name as client',
            ])
            ->first();

        if ($invoice === null) {
            return null;
        }

        $currency = $invoice->currency ?? MoneyFormatter::DEFAULT_CURRENCY;

        $lines = DB::connection('mysql')
            ->table('invoice_lines')
            ->where('invoice_id', $invoice->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $line): array => [
                'description' => (string) $line->description,
                /*
                 * "450 units × $340.00/unit". The unit word comes from the
                 * line's own description rather than a column, because a
                 * subscription is priced per unit and the guard add-on per
                 * guard, and inventing a `unit_label` column to hold two
                 * values the description already implies would be a column
                 * for a string.
                 */
                'detail' => number_format((int) $line->quantity).' × '
                    .MoneyFormatter::fromMinor((int) $line->unit_price_minor, $currency),
                'amount' => MoneyFormatter::fromMinor((int) $line->total_minor, $currency),
                'total_minor' => (int) $line->total_minor,
            ])
            ->all();

        $lineSum = array_sum(array_column($lines, 'total_minor'));
        $postedTotal = (int) $invoice->total_minor;

        return [
            'id' => (int) $invoice->id,
            'reference' => (string) $invoice->reference,
            'client' => (string) $invoice->client,
            'clientHref' => route('gemini.clients.show', ['tenant' => $invoice->tenant_id], absolute: false),
            'period' => (string) $invoice->period,
            'periodLabel' => $this->periodLabel($invoice),
            'amount' => MoneyFormatter::fromMinor($postedTotal, $currency),
            'status' => (string) $invoice->status,
            'statusLabel' => ucfirst((string) $invoice->status),
            'settlement' => $this->settlement($invoice),
            'lines' => array_map(
                static fn (array $line): array => [
                    'description' => $line['description'],
                    'detail' => $line['detail'],
                    'amount' => $line['amount'],
                ],
                $lines,
            ),
            /*
             * Null when the breakdown reconciles, which is the ordinary case.
             * A string when it does not, and the screen shows it: the posted
             * total is what the client owes and cannot be edited, so a
             * mismatch is reported rather than resolved.
             */
            'reconciliation' => $lines !== [] && $lineSum !== $postedTotal
                ? sprintf(
                    'These lines total %s, which does not match the %s posted on this invoice. The posted amount stands — a posted invoice cannot be edited, and a correction is a credit note.',
                    MoneyFormatter::fromMinor($lineSum, $currency),
                    MoneyFormatter::fromMinor($postedTotal, $currency),
                )
                : null,
        ];
    }

    /** "Billing period: Aug 1–31, 2026", as the board writes it. */
    private function periodLabel(object $invoice): string
    {
        $start = Carbon::parse((string) $invoice->period_start);
        $end = Carbon::parse((string) $invoice->period_end);

        return sprintf(
            'Invoice #%s · Billing period: %s–%s, %s',
            $invoice->reference,
            $start->format('M j'),
            $end->format('j'),
            $end->format('Y'),
        );
    }

    /**
     * How and when it was settled, or when it falls due.
     *
     * The board's caption under the amount. A paid invoice says when and by
     * what means; an unpaid one says when it is due, because that is the fact
     * a reader of an outstanding invoice is looking for.
     */
    private function settlement(object $invoice): string
    {
        if ($invoice->status === 'paid' && $invoice->paid_on !== null) {
            /*
             * Bank transfer, stated rather than stored.
             *
             * D-023: manual recording is the day-one path and every settlement
             * on this platform is a transfer someone reconciled by hand. When
             * a gateway exists it will record its own method and this reads it
             * instead; asserting a method column now would be a column holding
             * one value.
             */
            return strtoupper(sprintf(
                'Paid %s · bank transfer',
                Carbon::parse((string) $invoice->paid_on)->format('M j, Y'),
            ));
        }

        return strtoupper(sprintf(
            'Due %s',
            Carbon::parse((string) $invoice->due_on)->format('M j, Y'),
        ));
    }

    /**
     * The four KPI cards, in the order the board draws them.
     *
     * No estate scoping anywhere in this class, and that is not an oversight:
     * the only site-scoped Gemini role is Head of Security, whose access to
     * `billing_subscriptions` is '-' in the role matrix. Nobody who can reach
     * this screen sees a subset, so a scope filter here would be a branch that
     * can never run and a "Platform MRR" that could quietly mean something
     * narrower than its label.
     *
     * @return list<array{key: string, icon: string, value: string, label: string, alert: bool}>
     */
    public function kpis(): array
    {
        $overdue = $this->overdue();

        return [
            [
                'key' => 'mrr',
                'icon' => 'billing',
                'value' => $this->whole($this->monthlyRecurring()),
                'label' => 'Platform MRR',
                'alert' => false,
            ],
            [
                'key' => 'overdue',
                'icon' => 'alert',
                'value' => $this->whole($overdue),
                'label' => 'Overdue',
                'alert' => $overdue->isPositive(),
            ],
            [
                'key' => 'next-run',
                'icon' => 'calendar',
                'value' => $this->nextInvoiceRun(),
                'label' => 'Next invoice run',
                'alert' => false,
            ],
            [
                'key' => 'billable',
                'icon' => 'clients',
                'value' => (string) $this->billableClients(),
                'label' => 'Billable clients',
                'alert' => false,
            ],
        ];
    }

    /**
     * The table's rows.
     *
     * With a search term this is every matching invoice, newest first. Without
     * one it is the current billing position: each estate's most recent raised
     * invoice, then the period each billing subscription is due to be invoiced
     * next.
     *
     * @return list<array<string, mixed>>
     */
    public function invoiceRows(string $search = ''): array
    {
        $term = trim($search);

        if ($term !== '') {
            return $this->matching($term);
        }

        $latest = $this->latestInvoicePerEstate();

        $raised = array_map(
            fn (Invoice $invoice): array => $this->rowFromInvoice($invoice),
            array_values($latest)
        );

        return array_merge($raised, $this->projectedRows($latest));
    }

    /* --- rows ------------------------------------------------------------ */

    /**
     * Each estate's most recent raised invoice, keyed by estate id.
     *
     * A correlated subquery rather than loading every invoice and picking the
     * newest per estate in PHP: this screen is a platform-wide view, and the
     * invoice table grows by one row per estate per month forever.
     *
     * @return array<string, Invoice>
     */
    private function latestInvoicePerEstate(): array
    {
        $invoices = Invoice::query()
            ->with('estate')
            ->whereRaw(
                'invoices.period_start = ('
                .'select max(latest.period_start) from invoices as latest '
                .'where latest.tenant_id = invoices.tenant_id)'
            )
            ->orderBy('tenant_id')
            ->get();

        $latest = [];

        foreach ($invoices as $invoice) {
            $latest[$invoice->tenant_id] = $invoice;
        }

        return $latest;
    }

    /**
     * The period each billing subscription will be invoiced for next.
     *
     * These rows are not invoices and are never presented as one: they carry
     * the board's "Not yet invoiced" badge, and their action is a preview
     * rather than a link to a document that does not exist.
     *
     * @param  array<string, Invoice>  $latest
     * @return list<array<string, mixed>>
     */
    private function projectedRows(array $latest): array
    {
        $subscriptions = Subscription::query()
            ->with(['plan', 'estate'])
            ->whereIn('status', self::BILLING_STATES)
            ->orderBy('tenant_id')
            ->get();

        $rows = [];

        foreach ($subscriptions as $subscription) {
            $last = $latest[$subscription->tenant_id] ?? null;

            $start = $last !== null
                ? $last->period_end->copy()->addDay()
                : ($subscription->started_on?->copy()->startOfMonth() ?? Carbon::today()->startOfMonth());

            $estate = $subscription->estate->name ?? $subscription->tenant_id;

            /*
             * The expected due date is derived from this estate's own last
             * invoice — the day of the month it was billed on — rather than a
             * constant invented here. An estate that has never been invoiced
             * has no such history, and gets an em dash instead of a guess.
             */
            $due = $last !== null ? $this->sameDayOfMonth($last->due_on, $start) : null;

            $rows[] = [
                'key' => 'projected-'.$subscription->tenant_id,
                'estate' => $estate,
                'initials' => $this->initials($estate),
                'period' => $start->format('F Y'),
                'amount' => $this->exact(
                    $subscription->unit_count * $subscription->plan->price_per_unit_minor,
                    $subscription->plan->currency
                ),
                'due_on' => $due?->format('M j, Y') ?? '—',
                'status' => 'due',
                'status_label' => 'Not yet invoiced',
                'action' => 'Preview',
                /*
                 * Nothing to open. This row is a period that has not been
                 * invoiced yet, so there is no posted record behind it — and a
                 * preview of an invoice nobody has raised would be a figure
                 * presented as a document.
                 */
                'href' => null,
                'action_reason' => 'Nothing raised yet for this period — there is no invoice to open until it is billed',
            ];
        }

        return $rows;
    }

    /**
     * Every invoice matching a search term, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function matching(string $term): array
    {
        // Escape the wildcards before wrapping in them, so a term containing %
        // searches for a percent sign instead of matching every invoice.
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        $invoices = Invoice::query()
            ->with('estate')
            ->where(function ($query) use ($like): void {
                $query->where('reference', 'like', $like)
                    ->orWhere('period', 'like', $like)
                    ->orWhereHas('estate', function ($estate) use ($like): void {
                        $estate->where('name', 'like', $like);
                    });
            })
            ->orderByDesc('period_start')
            ->orderBy('tenant_id')
            ->limit(self::SEARCH_LIMIT)
            ->get();

        return array_values(
            $invoices->map(fn (Invoice $invoice): array => $this->rowFromInvoice($invoice))->all()
        );
    }

    /** @return array<string, mixed> */
    private function rowFromInvoice(Invoice $invoice): array
    {
        $estate = $invoice->estate->name ?? $invoice->tenant_id;

        return [
            'key' => 'invoice-'.$invoice->id,
            'estate' => $estate,
            'initials' => $this->initials($estate),
            // The period column, formatted from period_start rather than
            // echoed from the free-text `period` string, so every row reads
            // the same way whatever a seeder or an importer wrote.
            'period' => $invoice->period_start->format('F Y'),
            'amount' => $this->exact($invoice->total_minor, $invoice->currency),
            'due_on' => $invoice->due_on->format('M j, Y'),
            'status' => $invoice->statusBadge(),
            'status_label' => $this->statusLabel($invoice),
            'action' => 'View invoice',
            // A raised invoice has a detail screen. The row below it — a
            // period not yet invoiced — has nothing to open, which is why
            // `href` is per-row rather than a property of the table.
            'href' => route('gemini.billing_subscriptions.invoice', ['invoice' => $invoice->id], absolute: false),
            'action_reason' => null,
        ];
    }

    private function statusLabel(Invoice $invoice): string
    {
        return match (true) {
            $invoice->status === 'paid' => 'Paid',
            $invoice->status === 'void' => 'Void',
            $invoice->status === 'draft' => 'Draft',
            $invoice->isOverdue() => 'Overdue',
            default => 'Due',
        };
    }

    /* --- figures ---------------------------------------------------------- */

    /**
     * Platform MRR.
     *
     * Active subscriptions only — the same rule PlatformOverview uses for the
     * dashboard card of the same name. Two screens printing the same label must
     * print the same number, so if that definition ever changes it changes in
     * both places or the platform contradicts itself.
     *
     * OPEN QUESTION. That rule excludes `dunning`, so an estate whose invoices
     * are being chased is billed in the table below this card but is absent
     * from the figure on it. Most definitions of MRR would count it: dunning is
     * a collection state, not a cancellation. Deliberately not changed here,
     * because the fix belongs wherever the definition is ruled on, and one of
     * the two screens quietly disagreeing with the other is worse than both
     * being conservative.
     */
    private function monthlyRecurring(): Money
    {
        $minor = (int) Subscription::query()
            ->where('subscriptions.status', 'active')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->sum(DB::raw('subscriptions.unit_count * plans.price_per_unit_minor'));

        return Money::ofMinor($minor, $this->platformCurrency());
    }

    /**
     * Money past its due date and still unpaid.
     *
     * Overdue is derived from the due date, never read from a stored status, so
     * an invoice that passes midnight is overdue the moment it is asked about
     * rather than whenever a job next runs. Invoice::isOverdue() says the same
     * thing one record at a time.
     */
    private function overdue(): Money
    {
        $total = Money::zero($this->platformCurrency());

        $unpaid = Invoice::query()
            ->where('status', 'issued')
            ->whereDate('due_on', '<', Carbon::today())
            ->get(['id', 'total_minor', 'currency']);

        foreach ($unpaid as $invoice) {
            /*
             * brick refuses to add two currencies, and that refusal is the
             * point: a single total across currencies is not a number. Summing
             * minor units in SQL would invent one silently.
             */
            $total = $total->plus(Money::ofMinor($invoice->total_minor, $invoice->currency));
        }

        return $total;
    }

    /** "Oct 1" — the earliest renewal date across the billing subscriptions. */
    private function nextInvoiceRun(): string
    {
        $next = Subscription::query()
            ->whereIn('status', self::BILLING_STATES)
            ->whereNotNull('renews_on')
            ->orderBy('renews_on')
            ->first();

        return $next?->renews_on?->format('M j') ?? '—';
    }

    /** Estates that will be invoiced. One live subscription per estate. */
    private function billableClients(): int
    {
        return Subscription::query()
            ->whereIn('status', self::BILLING_STATES)
            ->distinct()
            ->count('tenant_id');
    }

    /* --- formatting ------------------------------------------------------- */

    /**
     * "$243,900" — the board's format for a headline figure.
     *
     * brick's own whole-number flag, not a hand-rolled trim: the decimals are
     * dropped only when the amount really has no minor units, and the scaling
     * stays with the currency. Nothing here divides by 100.
     */
    private function whole(Money $money): string
    {
        if (! extension_loaded('intl')) {
            return MoneyFormatter::format($money);
        }

        return $money->formatToLocale(MoneyFormatter::DEFAULT_LOCALE, true);
    }

    /** An invoice amount, to the cent. A bill is never rounded for display. */
    private function exact(int $minor, string $currency): string
    {
        return MoneyFormatter::fromMinor($minor, $currency);
    }

    private function platformCurrency(): string
    {
        $currency = Plan::query()->value('currency');

        return is_string($currency) ? $currency : MoneyFormatter::DEFAULT_CURRENCY;
    }

    /* --- helpers ---------------------------------------------------------- */

    /** The same day of the month as $reference, in $target's month. */
    private function sameDayOfMonth(Carbon $reference, Carbon $target): Carbon
    {
        $month = $target->copy()->startOfMonth();

        // addDays from the first, rather than setting the day directly: a
        // reference day of 31 against a 30-day month would otherwise roll into
        // the following month instead of clamping to its last day.
        return $month->addDays(min($reference->day, $month->daysInMonth) - 1);
    }

    /** "Phoenix Park" -> "PP". The board's two-letter estate avatar. */
    private function initials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($words) >= 2) {
            return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1));
        }

        return mb_strtoupper(mb_substr($name, 0, 2));
    }
}
