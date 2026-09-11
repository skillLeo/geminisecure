<?php

declare(strict_types=1);

use App\Models\Estate\Account;
use App\Models\Estate\Bill;
use App\Models\Estate\Vendor;
use App\Models\Role;
use App\Services\Estate\Ledger;
use App\Services\Estate\Payables;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Add vendor, record bill, add account — boards 25, 26, 27 and 39
|--------------------------------------------------------------------------
|
| Wave 1, items 5 to 7 (12 §2). Three writes, and what each may not do:
| adding a vendor lists a supplier and posts nothing; recording a bill makes
| a DRAFT and posts nothing; adding an account puts a line in the chart with
| no balance and cannot be undone by deletion. The TRN is asked for on the
| register and required at payment, as ruled.
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

function accountingNetDebits(string $code): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS net
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ?
    ', [$code])->net;
}

it('adds a vendor without a TRN, records a bill against them, and refuses the payment until the TRN arrives', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/accounting/vendors'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canCreate', true));

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/vendors'), [
            'name' => 'Blue Mountain Landscaping',
            'category' => 'Grounds',
            'trn' => '',
            'contact_phone' => '(876) 555 0199',
        ])
        ->assertRedirect();

    FacilitiesFixture::boot();

    $vendor = Vendor::query()->where('name', 'Blue Mountain Landscaping')->firstOrFail();

    expect($vendor->hasTrn())->toBeFalse()
        ->and($vendor->status)->toBe(Vendor::ACTIVE)
        ->and($vendor->category)->toBe('Grounds');

    // A bill can be recorded and approved against them — the estate owes what
    // it owes — and it is a draft that posts nothing until approved.
    $payable = accountingNetDebits('2000');

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/bills'), [
            'vendor_id' => $vendor->id,
            'description' => 'Verge cutting, September',
            'amount' => '18500.00',
            'due_on' => now()->addDays(30)->toDateString(),
            'account' => '5100',
        ])
        ->assertRedirect(FacilitiesFixture::url('/accounting/bills'));

    FacilitiesFixture::boot();

    $bill = Bill::query()->where('vendor_id', $vendor->id)->firstOrFail();

    expect($bill->status)->toBe(Bill::DRAFT)
        ->and($bill->amount_minor)->toBe(18_500_00)
        ->and($bill->journal_ref)->toBeNull()
        ->and(accountingNetDebits('2000'))->toBe($payable);

    // Approved, the liability posts. Paid, the TRN refuses — the ruling's own
    // shape: required before a bill may be PAID.
    app(Payables::class)->approve($bill, $treasurer);

    FacilitiesFixture::boot();

    expect(accountingNetDebits('2000') - $payable)->toBe(-18_500_00);

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/bills/'.$bill->id.'/pay'), [
            'amount' => '18500.00',
            'method' => 'bank',
            'paid_on' => now()->toDateString(),
        ])
        ->assertSessionHasErrors('amount');

    FacilitiesFixture::boot();

    expect(Bill::query()->findOrFail($bill->id)->status)->toBe(Bill::APPROVED);
});

it('normalises a TRN, refuses a bad one and a duplicate name, and refuses the register to a reader', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/vendors'), ['name' => 'Kingston Glass Ltd', 'trn' => '100 555 123'])
        ->assertRedirect();

    FacilitiesFixture::boot();

    expect(Vendor::query()->where('name', 'Kingston Glass Ltd')->value('trn'))->toBe('100-555-123');

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/vendors'), ['name' => 'kingston glass ltd'])
        ->assertSessionHasErrors('name');

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/vendors'), ['name' => 'Short TRN Co', 'trn' => '12345'])
        ->assertSessionHasErrors('trn');

    // The President reads Accounting and adds nothing to it.
    $this->actingAs($president)
        ->get(FacilitiesFixture::url('/accounting/vendors'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canCreate', false));

    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/accounting/vendors'), ['name' => 'Refused Ltd'])
        ->assertForbidden();

    FacilitiesFixture::boot();

    expect(Vendor::query()->whereIn('name', ['Short TRN Co', 'Refused Ltd'])->count())->toBe(0);
});

it('records a bill from the vendor\'s own screen against an open work order, and refuses a number that is not one', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $vendor = FacilitiesFixture::vendor('Island Electric Services');
    $ticket = FacilitiesFixture::ticket(1042);

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/accounting/vendors/'.$vendor->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canCreate', true)
            ->has('expenseAccounts')
            ->has('openTickets'));

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/bills'), [
            'vendor_id' => $vendor->id,
            'description' => 'Photocell and two floodlights',
            'amount' => '42000.00',
            'due_on' => now()->addDays(14)->toDateString(),
            'account' => '5100',
            'ticket_number' => 1042,
            'from_vendor' => true,
        ])
        ->assertRedirect(FacilitiesFixture::url('/accounting/vendors/'.$vendor->id));

    FacilitiesFixture::boot();

    $bill = Bill::query()->where('description', 'Photocell and two floodlights')->firstOrFail();

    expect($bill->ticket_id)->toBe($ticket->number)
        ->and($bill->ticket_label)->toBe($ticket->label())
        ->and($bill->status)->toBe(Bill::DRAFT);

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/accounting/bills'), [
            'vendor_id' => $vendor->id,
            'description' => 'Against nothing',
            'amount' => '10.00',
            'due_on' => now()->toDateString(),
            'account' => '5100',
            'ticket_number' => 999999,
        ])
        ->assertSessionHasErrors('ticket_number');
});

it('adds an account after the review step, files it under its own type only, and archives rather than deletes', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $assistant = FacilitiesFixture::viewer(Role::ESTATE_ADMIN_ASSISTANT);

    // Entry on Accounting reads the chart and may not change it.
    $this->actingAs($assistant)
        ->get(FacilitiesFixture::url('/accounting/chart-of-accounts'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canConfigure', false));

    $this->actingAs($assistant)
        ->post(FacilitiesFixture::url('/accounting/chart-of-accounts'), ['code' => '5300', 'name' => 'Security Services', 'type' => 'expense', 'reviewed' => true])
        ->assertForbidden();

    // Without the review, refused; with it, added.
    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/accounting/chart-of-accounts'), ['code' => '5300', 'name' => 'Security Services', 'type' => 'expense'])
        ->assertSessionHasErrors('reviewed');

    $parent = Account::query()->where('code', '5100')->firstOrFail();

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/accounting/chart-of-accounts'), [
            'code' => '5300',
            'name' => 'Security Services',
            'type' => 'expense',
            'parent_id' => $parent->id,
            'reviewed' => true,
        ])
        ->assertRedirect(FacilitiesFixture::url('/accounting/chart-of-accounts'));

    FacilitiesFixture::boot();

    $account = Account::query()->where('code', '5300')->firstOrFail();

    expect($account->type)->toBe('expense')
        ->and($account->parent_id)->toBe($parent->id)
        ->and($account->is_active)->toBeTrue()
        ->and(app(Ledger::class)->balanceOf($account)->getMinorAmount()->toInt())->toBe(0);

    // The two things a review cannot catch by eye.
    $ledger = app(Ledger::class);

    expect(fn () => $ledger->addAccount('5300', 'Again', 'expense'))
        ->toThrow(DomainException::class, 'already exists')
        ->and(fn () => $ledger->addAccount('1900', 'Wrong parent', 'asset', $parent))
        ->toThrow(DomainException::class, 'has to be the same');

    // Archived, never deleted: the row stays with its history, closed to new entries.
    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/accounting/chart-of-accounts/'.$account->id.'/archive'))
        ->assertRedirect(FacilitiesFixture::url('/accounting/chart-of-accounts'));

    FacilitiesFixture::boot();

    $archived = Account::query()->findOrFail($account->id);

    expect($archived->is_active)->toBeFalse()
        ->and($archived->archived_at)->not->toBeNull();

    // A control account cannot be archived — the sub-ledger has to tie to it.
    $control = Account::query()->where('code', '1200')->firstOrFail();

    expect(fn () => $ledger->archiveAccount($control))->toThrow(DomainException::class, 'control account');

    // And there is no route that deletes an account, on purpose.
    $deletes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'estate.accounting.chart')
            && in_array('DELETE', $route->methods(), true));

    expect($deletes)->toBeEmpty();
});
