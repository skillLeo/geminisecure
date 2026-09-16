<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Estate\Charge;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Household;
use App\Models\Estate\Payment;
use App\Models\Estate\Unit;
use App\Models\Tenant;
use App\Services\Estate\Collections;
use App\Services\Estate\Dues;
use App\Services\ResidentApp\ResidentAccounts;
use App\Support\Decimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The resident's OWN household's dues: invoices, balance, statement, payments,
 * receipts, and how to pay (13 D3). Boards resident-app-11 to -14.
 *
 * ONE HOUSEHOLD, THE TOKEN'S. Every query is keyed on the account's unit, and a
 * household id in the path that is not theirs is 404 — the same answer as an id
 * nobody has, so the endpoint cannot be used to learn which households exist.
 *
 * EVERY FIGURE FROM THE LEDGER. The balance is `Dues::balanceOf` (the journal on
 * 1200), and the statement is the journal lines — the same arithmetic board 6
 * draws, so the app and the estate office cannot quote a household two numbers.
 *
 * NO CARD PAYMENT (Q-012, ruled: dues are manual). `pay-intent` answers with how
 * this estate is paid by hand and the reference to quote; `autopay` is refused.
 * The app must never draw a card form that talks to nothing — a resident who
 * believes they have paid is a resident about to be restricted for arrears they
 * thought were settled.
 */
class DuesController extends Controller
{
    public function __construct(
        private readonly ResidentAccounts $accounts,
        private readonly Dues $dues,
    ) {}

    public function invoices(DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];

        return response()->json([
            'currency' => 'JMD',
            'items' => $this->charges($unit),
        ]);
    }

    public function balance(int $household, DeviceContext $context): JsonResponse
    {
        $home = $this->household($household, $context);
        $unit = $home['unit'];
        $today = Carbon::today();
        $oldest = $this->dues->oldestOpenChargeDate($unit, $today);

        return response()->json([
            'household_id' => $home['household']->id,
            'unit' => $unit->reference,
            'balance' => Decimal::of($this->dues->balanceOf($unit)->getMinorAmount()->toInt()),
            'currency' => 'JMD',
            'as_at' => $today->toDateString(),
            'oldest_unpaid_due_on' => $oldest?->toDateString(),
            'days_overdue' => $oldest === null || $oldest->isFuture() ? 0 : (int) $oldest->diffInDays($today),
            'on_payment_plan' => app(Collections::class)->activePlanFor($unit) !== null,
        ]);
    }

    public function statement(Request $request, int $household, DeviceContext $context): JsonResponse
    {
        $unit = $this->household($household, $context)['unit'];
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:200']]);

        $lines = DB::connection('tenant')->table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->join('journals', 'journals.reference', '=', 'journal_lines.entry_ref')
            ->where('accounts.code', '1200')
            ->where('journal_lines.unit_id', $unit->id)
            ->orderBy('journals.posted_on')
            ->orderBy('journal_lines.id')
            ->get(['journals.posted_on', 'journals.memo as entry_memo', 'journals.reference', 'journal_lines.memo as line_memo', 'journal_lines.debit_minor', 'journal_lines.credit_minor']);

        $running = 0;
        $rows = [];

        foreach ($lines as $line) {
            $running += (int) $line->debit_minor - (int) $line->credit_minor;

            $rows[] = [
                'date' => Carbon::parse((string) $line->posted_on)->toDateString(),
                'description' => (string) ($line->line_memo ?? $line->entry_memo),
                'reference' => (string) $line->reference,
                'charge' => (int) $line->debit_minor > 0 ? Decimal::of((int) $line->debit_minor) : null,
                'payment' => (int) $line->credit_minor > 0 ? Decimal::of((int) $line->credit_minor) : null,
                'balance' => Decimal::of($running),
            ];
        }

        return response()->json([
            'unit' => $unit->reference,
            'currency' => 'JMD',
            'closing_balance' => Decimal::of($running),
            'items' => array_slice(array_reverse($rows), 0, (int) ($data['limit'] ?? 50)),
        ]);
    }

    public function payIntent(int $invoice, DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];
        $charge = Charge::query()->whereKey($invoice)->where('unit_id', $unit->id)->first();

        if ($charge === null) {
            throw ApiError::notFound('not_found', 'No invoice of your household\'s with that id.');
        }

        $open = collect($this->charges($unit))->firstWhere('id', $charge->id);
        $instructions = EstateSetting::current()->getAttribute('dues_payment_instructions');

        return response()->json([
            'invoice_id' => $charge->id,
            'online_payment_available' => false,
            'reason' => 'This estate takes dues by bank transfer, cash or cheque. Card payment is not offered in the app.',
            'amount_due' => $open['outstanding'] ?? Decimal::of(0),
            'currency' => 'JMD',
            'quote_reference' => $unit->reference.' · '.$charge->getAttribute('reference'),
            'instructions' => $instructions ?? 'Pay at the estate office, or by bank transfer to the estate\'s account shown on your statement. Quote the reference above so the payment reaches your household.',
            'estate' => (string) Tenant::query()->whereKey($context->tenantId)->value('name'),
        ]);
    }

    public function payments(DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];

        return response()->json([
            'currency' => 'JMD',
            'items' => Payment::query()->where('unit_id', $unit->id)->orderByDesc('received_at')->limit(100)->get()
                ->map(fn (Payment $p): array => $this->paymentShape($p))->all(),
        ]);
    }

    public function receipt(int $payment, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $record = Payment::query()->whereKey($payment)->where('unit_id', $home['unit']->id)->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No payment of your household\'s with that id.');
        }

        return response()->json([
            ...$this->paymentShape($record),
            'estate' => (string) Tenant::query()->whereKey($context->tenantId)->value('name'),
            'unit' => $home['unit']->reference,
            'household' => $home['household']->name,
            'received_by_name' => $record->received_by_name,
            'entered_at' => $record->entered_at->toIso8601String(),
        ]);
    }

    public function autopay(DeviceContext $context): JsonResponse
    {
        $this->accounts->home($context);

        throw ApiError::conflict('autopay_unavailable', 'AutoPay is not offered: this estate takes dues by hand, not by card. Pay by bank transfer, cash or cheque.');
    }

    public function cancelAutopay(int $autopay, DeviceContext $context): JsonResponse
    {
        $this->accounts->home($context);

        throw ApiError::notFound('not_found', 'No AutoPay arrangement with that id — none can exist while dues are paid by hand.');
    }

    /**
     * The household's charges, newest first, each with what is still owed on it.
     *
     * PAYMENTS CLEAR THE OLDEST CHARGE FIRST — the walk the ageing uses — so the
     * outstanding balance sits on the newest charges, and a charge is `paid` once
     * nothing of the balance reaches back to it.
     *
     * @return list<array<string, mixed>>
     */
    private function charges(Unit $unit): array
    {
        $left = max(0, $this->dues->balanceOf($unit)->getMinorAmount()->toInt());

        return Charge::query()->where('unit_id', $unit->id)->orderByDesc('due_on')->orderByDesc('id')->limit(120)->get()
            ->map(static function (Charge $c) use (&$left): array {
                $amount = (int) $c->getAttribute('amount_minor');
                $owed = min($amount, $left);
                $left -= $owed;

                return [
                    'id' => $c->id,
                    'reference' => (string) $c->getAttribute('reference'),
                    'type' => (string) $c->getAttribute('type'),
                    'period' => $c->getAttribute('period'),
                    'description' => (string) $c->getAttribute('description'),
                    'amount' => Decimal::of($amount),
                    'outstanding' => Decimal::of($owed),
                    'due_on' => Carbon::parse((string) $c->getRawOriginal('due_on'))->toDateString(),
                    'status' => match (true) {
                        $owed === 0 => 'paid',
                        $owed < $amount => 'part_paid',
                        Carbon::parse((string) $c->getRawOriginal('due_on'))->isPast() => 'overdue',
                        default => 'due',
                    },
                ];
            })->all();
    }

    /** @return array{unit: Unit, household: Household} */
    private function household(int $household, DeviceContext $context): array
    {
        $home = $this->accounts->home($context);

        if ($home['household']->id !== $household) {
            throw ApiError::notFound('not_found', 'No household of yours with that id.');
        }

        return ['unit' => $home['unit'], 'household' => $home['household']];
    }

    /** @return array<string, mixed> */
    private function paymentShape(Payment $p): array
    {
        return [
            'id' => $p->id,
            'receipt_no' => $p->receipt_no,
            'amount' => Decimal::of((int) $p->amount_minor),
            'currency' => $p->currency,
            'method' => $p->method,
            'reference' => $p->reference,
            'received_at' => $p->received_at->toIso8601String(),
            'status' => $p->status,
        ];
    }
}
