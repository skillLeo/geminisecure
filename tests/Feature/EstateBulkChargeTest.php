<?php

declare(strict_types=1);

use App\Models\Estate\Charge;
use App\Models\Estate\Unit;
use App\Models\Role;
use App\Services\Estate\Dues;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| A charge to a whole phase or the whole estate — board 35 (12 §2, Wave 2)
|--------------------------------------------------------------------------
|
| TWO PRESSES, and the first posts nothing. A charge against the estate is
| hundreds of journal entries at once; the list is what a treasurer needs in
| front of them before the second press, and the posting is all of them or
| none — a half-posted assessment bills some households and not others.
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

function receivableNet(): int
{
    return (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.debit_minor - l.credit_minor), 0) AS net
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
         WHERE a.code = ?
    ', ['1200'])->net;
}

it('draws the list first and posts nothing, then bills every unit on it in one go', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    $units = Unit::query()->count();
    $receivable = receivableNet();
    $charges = Charge::query()->count();

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/finance/charges/new'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canPost', true)
            ->where('bulkPreview', null)
            ->has('phases'));

    // A reader of the ledger raises nothing against anybody.
    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/finance/charges/preview'), [
            'scope' => 'estate', 'type' => 'special_assessment', 'amount' => '1.00',
            'description' => 'x', 'due_on' => now()->toDateString(), 'account' => '4000',
        ])
        ->assertForbidden();

    // STEP ONE. The list, and not one journal line.
    $response = $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charges/preview'), [
            'scope' => 'estate',
            'type' => 'special_assessment',
            'amount' => '12000.00',
            'description' => 'Perimeter wall assessment',
            'due_on' => now()->addDays(30)->toDateString(),
            'account' => '4000',
        ])
        ->assertRedirect();

    FacilitiesFixture::boot();

    expect(receivableNet())->toBe($receivable)
        ->and(Charge::query()->count())->toBe($charges);

    $token = (string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY);
    $token = str_replace('preview=', '', $token);

    $this->actingAs($treasurer)
        ->withSession($response->baseResponse->getSession()->all())
        ->get(FacilitiesFixture::url('/finance/charges/new').'?preview='.$token)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('bulkPreview.count', $units)
            ->where('bulkPreview.total_minor', 12_000_00 * $units)
            ->where('bulkPreview.description', 'Perimeter wall assessment')
            ->has('bulkPreview.units', $units));

    // STEP TWO. Every unit, each with its own charge and its own journal.
    $this->actingAs($treasurer)
        ->withSession($response->baseResponse->getSession()->all())
        ->post(FacilitiesFixture::url('/finance/charges/bulk'), ['token' => $token])
        ->assertRedirect(FacilitiesFixture::url('/finance/arrears'));

    FacilitiesFixture::boot();

    expect(Charge::query()->count())->toBe($charges + $units)
        ->and(receivableNet() - $receivable)->toBe(12_000_00 * $units)
        ->and(Charge::query()->where('description', 'Perimeter wall assessment')->distinct()->count('unit_id'))->toBe($units);

    // Spent: a second press on the same preview cannot bill the estate twice.
    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charges/bulk'), ['token' => $token])
        ->assertRedirect();

    FacilitiesFixture::boot();

    expect(Charge::query()->count())->toBe($charges + $units);
});

it('refuses a phase nobody lives in, and posts nothing when one unit of the list has gone', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $dues = app(Dues::class);

    $this->actingAs($treasurer)
        ->post(FacilitiesFixture::url('/finance/charges/preview'), [
            'scope' => 'phase',
            'phase' => 'Phase 404',
            'type' => 'dues',
            'amount' => '500.00',
            'description' => 'Nowhere',
            'due_on' => now()->toDateString(),
            'account' => '4000',
        ])
        ->assertSessionHasErrors('amount');

    // ALL OF THEM OR NONE. A list carrying a unit the register no longer has
    // posts nothing at all, rather than billing the rest and losing the one.
    $real = Unit::query()->orderBy('id')->pluck('id')->all();
    $receivable = receivableNet();

    expect(fn () => $dues->chargeMany(
        unitIds: [...$real, 999_999],
        amount: Money::of('500.00', 'JMD'),
        description: 'Half a run',
        dueOn: now()->toDateString(),
        type: 'dues',
        account: '4000',
        by: $treasurer,
    ))->toThrow(DomainException::class);

    FacilitiesFixture::boot();

    expect(receivableNet())->toBe($receivable)
        ->and(Charge::query()->where('description', 'Half a run')->count())->toBe(0);
});
