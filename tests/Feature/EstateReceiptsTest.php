<?php

declare(strict_types=1);

use App\Models\Estate\Payment;
use App\Models\Role;
use App\Services\Estate\Dues;
use App\Services\Estate\Receipts;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Recording a payment, and the receipt register — boards 6 and 5
|--------------------------------------------------------------------------
|
| THE DAY-ONE COLLECTION PATH. No card gateway exists, so every dollar an
| estate collects is keyed from board 6's "Record manual payment" and lands
| here: Dr 1000 Bank, Cr 1200 Dues Receivable against the unit, summed below
| in raw SQL by a second route to the same figure.
|
| THE NUMBER IS RULED (12 §1): `{PREFIX}-R-{00001}`, sequential per estate,
| never reused, allocated at posting and never before, gaps recorded and
| visible. Each clause is a test.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

function receiptsNetDebits(string $code, ?int $unitId = null): int
{
    $sql = '
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS net
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ?'.($unitId === null ? '' : ' AND l.unit_id = ?');

    return (int) DB::connection('tenant')->selectOne($sql, $unitId === null ? [$code] : [$code, $unitId])->net;
}

function receiptsUrl(string $path): string
{
    return FacilitiesFixture::url($path);
}

it('records a manual payment from the ledger, posts it, and numbers the receipt from the estate\'s sequence', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $unit = FacilitiesFixture::unit('Lot 47');

    $bank = receiptsNetDebits('1000');
    $receivable = receiptsNetDebits('1200', $unit->id);
    $issued = Payment::query()->count();

    $this->actingAs($treasurer)
        ->get(receiptsUrl('/finance/units/'.$unit->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Dues/UnitLedger')
            ->where('canRecord', true));

    $this->actingAs($treasurer)
        ->post(receiptsUrl('/finance/units/'.$unit->id.'/payments'), [
            'method' => 'cheque',
            'amount' => '12400.00',
            'received_on' => now()->subDays(3)->toDateString(),
            'reference' => 'NCB 004521',
        ])
        ->assertRedirect(receiptsUrl('/finance/units/'.$unit->id));

    FacilitiesFixture::boot();

    $payment = Payment::query()->orderByDesc('id')->firstOrFail();
    $prefix = strtoupper(FacilitiesFixture::ESTATE);

    // The number came from the sequence, in the ruled format, and the paper's
    // own reference is on the record beside it.
    expect(Payment::query()->count())->toBe($issued + 1)
        ->and($payment->receipt_no)->toBe(sprintf('%s-R-%05d', $prefix, $issued + 1))
        ->and($payment->method)->toBe('cheque')
        ->and($payment->reference)->toBe('NCB 004521')
        ->and($payment->amount_minor)->toBe(12_400_00)
        ->and($payment->received_by_name)->toBe($treasurer->name)
        ->and($payment->enteredLate())->toBeTrue()
        ->and($payment->journal_ref)->not->toBeNull();

    // Dr 1000 Bank, Cr 1200 against THIS unit — the bank up by the payment,
    // the household's receivable down by the same figure, summed independently.
    expect(receiptsNetDebits('1000') - $bank)->toBe(12_400_00)
        ->and(receiptsNetDebits('1200', $unit->id) - $receivable)->toBe(-12_400_00);
});

it('refuses the post to a role that reads the ledger and may not credit it', function () {
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);
    $unit = FacilitiesFixture::unit('Lot 63');

    // The President holds Dues & ledger and Payments as View: the ledger opens,
    // the control is inert with its reason, and the route says no.
    $this->actingAs($president)
        ->get(receiptsUrl('/finance/units/'.$unit->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canRecord', false));

    $before = Payment::query()->count();

    $this->actingAs($president)
        ->post(receiptsUrl('/finance/units/'.$unit->id.'/payments'), [
            'method' => 'cash',
            'amount' => '500.00',
            'received_on' => now()->toDateString(),
        ])
        ->assertForbidden();

    FacilitiesFixture::boot();

    expect(Payment::query()->count())->toBe($before);
});

it('allocates a number only when the payment posts, so a refused one leaves no gap', function () {
    $dues = app(Dues::class);
    $receipts = app(Receipts::class);
    $unit = FacilitiesFixture::unit('Lot 88');

    $lastBefore = (int) DB::connection('tenant')->table('receipt_sequences')->value('last_no');

    // A future date is refused before the transaction opens.
    expect(fn () => $dues->receive($unit, Money::ofMinor(1_000_00, 'JMD'), 'cash', now()->addDay()))
        ->toThrow(DomainException::class, 'future');

    // A bank account that does not exist is refused INSIDE it — by the ledger,
    // after the number was handed out — and the rollback takes the number back.
    expect(fn () => $dues->receive($unit, Money::ofMinor(1_000_00, 'JMD'), 'cash', now(), bankAccount: '9999'))
        ->toThrow(DomainException::class, 'No account [9999]');

    expect((int) DB::connection('tenant')->table('receipt_sequences')->value('last_no'))->toBe($lastBefore);

    $payment = $dues->receive($unit, Money::ofMinor(1_000_00, 'JMD'), 'cash', now());

    expect($receipts->numberOf($payment->receipt_no))->toBe($lastBefore + 1);
});

it('draws the register in sequence and draws a missing number as a gap, in its place', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $dues = app(Dues::class);
    $unit = FacilitiesFixture::unit('Lot 3');

    $first = $dues->receive($unit, Money::ofMinor(2_000_00, 'JMD'), 'bank', now()->subDays(2), reference: 'JN 88120');
    $second = $dues->receive($unit, Money::ofMinor(3_000_00, 'JMD'), 'cash', now()->subDay());

    $this->actingAs($treasurer)
        ->get(receiptsUrl('/finance/receipts'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Dues/Receipts')
            ->where('prefix', strtoupper(FacilitiesFixture::ESTATE))
            ->where('gaps', [])
            ->where('rows', function ($rows) use ($first, $second): bool {
                $byNo = collect($rows)->keyBy('receipt_no');

                return $byNo[$second->receipt_no]['method_label'] === 'Cash'
                    && $byNo[$first->receipt_no]['reference'] === 'JN 88120'
                    && $byNo[$first->receipt_no]['amount'] === '$2,000.00'
                    && $byNo[$first->receipt_no]['journal_id'] !== null;
            }));

    /*
     * A number handed out and carried by nothing — a payment lost to a restore,
     * or removed by hand. The sequence remembers, and the register says so
     * rather than closing the gap: "never reused" is the whole of the promise.
     */
    FacilitiesFixture::boot();

    DB::connection('tenant')->table('receipt_sequences')->increment('last_no');

    $missing = app(Receipts::class)->numberOf($second->receipt_no) + 1;

    $this->actingAs($treasurer)
        ->get(receiptsUrl('/finance/receipts'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('gaps', 1)
            ->where('gaps.0.number', $missing)
            ->where('gaps.0.receipt_no', app(Receipts::class)->format($missing)));

    FacilitiesFixture::boot();

    // And the next receipt takes the number AFTER the gap, never the gap.
    $third = $dues->receive($unit, Money::ofMinor(100_00, 'JMD'), 'cash', now());

    expect(app(Receipts::class)->numberOf($third->receipt_no))->toBe($missing + 1);
});

it('carries no household balance onto the register', function () {
    $board = app(Receipts::class)->register();

    $serialised = strtolower((string) json_encode($board));

    foreach (['balance', 'arrear', 'bucket', 'owed', 'outstanding', 'ageing'] as $forbidden) {
        expect($serialised)->not->toContain(
            $forbidden,
            "the receipt register's payload contained the word [{$forbidden}], which is a household's financial position"
        );
    }
});
