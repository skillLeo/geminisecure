<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Support\MoneyFormatter;
use Inertia\Response;

/**
 * Billing and subscriptions, Super Admin screens 32 to 35.
 *
 * Gemini Security billing its clients. Resident dues are a different ledger in
 * a different database and never appear here.
 */
class BillingController extends Controller
{
    public function index(): Response
    {
        $invoices = Invoice::with('estate')->orderByDesc('period_start')->limit(100)->get();
        $subscriptions = Subscription::with(['plan', 'estate'])->get();

        $mrrMinor = $subscriptions
            ->where('status', 'active')
            ->sum(fn (Subscription $s) => $s->mrrMinor());

        $outstandingMinor = $invoices
            ->filter(fn (Invoice $i) => $i->status !== 'paid' && $i->status !== 'void')
            ->sum('total_minor');

        return inertia('Gemini/Billing/Index', [
            'kpis' => [
                ['key' => 'mrr', 'value' => $this->format($mrrMinor), 'label' => 'Monthly recurring revenue'],
                ['key' => 'active', 'value' => (string) $subscriptions->where('status', 'active')->count(), 'label' => 'Active subscriptions'],
                ['key' => 'outstanding', 'value' => $this->format($outstandingMinor), 'label' => 'Outstanding'],
                [
                    'key' => 'dunning',
                    'value' => (string) $subscriptions->where('status', 'dunning')->count(),
                    'label' => 'In dunning',
                    'alert' => $subscriptions->where('status', 'dunning')->isNotEmpty(),
                ],
            ],
            'invoices' => $invoices->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'reference' => $invoice->reference,
                'estate' => $invoice->estate?->name ?? 'Unknown',
                'period' => $invoice->period,
                'amount' => $this->format($invoice->total_minor, $invoice->currency),
                'due_on' => $invoice->due_on?->toDateString(),
                'status' => $invoice->isOverdue() ? 'overdue' : $invoice->status,
                'status_badge' => $invoice->statusBadge(),
            ]),
        ]);
    }

    /**
     * Formats minor units through brick/money.
     *
     * Never a float and never a manual divide by 100: the scale belongs to the
     * currency, and a zero-decimal currency would silently be wrong.
     */
    private function format(int $minor, string $currency = MoneyFormatter::DEFAULT_CURRENCY): string
    {
        return MoneyFormatter::fromMinor($minor, $currency);
    }
}
