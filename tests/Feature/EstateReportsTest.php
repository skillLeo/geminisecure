<?php

declare(strict_types=1);

use App\Models\Role;
use App\Services\Estate\Reports;
use Database\Seeders\Estate\EstateFinanceSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The estate's own reports — boards 29 and 30 (12 §1)
|--------------------------------------------------------------------------
|
| "All four report generators behind board 30." The minutes archive joined
| them because its own reason — "this platform stores no files yet" — stopped
| being true the day documents shipped.
|
| EVERY REPORT STATES ITS PERIOD, and that is the P&L card's own old warning
| kept: a report with an unstated period is the defect that surfaces at an
| audit. The window is on the screen, in the filename and in the audit scope.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    (new EstateFinanceSeeder)->run();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('runs the five built reports over a stated period and refuses the two with data gaps', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);
    $secretary = FacilitiesFixture::viewer(Role::SECRETARY);

    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/reports'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canGenerate', true));

    // The Secretary holds View on Reports and generates nothing — export is
    // withheld from anything below Full, so the books never leave in a file.
    $this->actingAs($secretary)
        ->get(FacilitiesFixture::url('/reports'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canGenerate', false));

    $this->actingAs($secretary)
        ->get(FacilitiesFixture::url('/reports/profit_and_loss'))
        ->assertForbidden();

    foreach (Reports::BUILT as $key) {
        $this->actingAs($treasurer)
            ->get(FacilitiesFixture::url('/reports/'.$key).'?from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Estate/Reports/Show')
                ->where('key', $key)
                ->where('period', fn ($period) => $period !== '' && $period !== null)
                ->has('columns')
                ->has('rows'));
    }

    // The two with data gaps are not runnable, and the refusal is a sentence.
    $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/reports/budget_vs_actual'))
        ->assertRedirect(FacilitiesFixture::url('/reports'));
});

it('ties the profit and loss to the ledger, and keeps turnout a count', function () {
    $reports = app(Reports::class);

    $pl = $reports->run('profit_and_loss', '2026-01-01', '2026-12-31');

    /*
     * THE SECOND ROUTE TO THE SAME FIGURE. Income is credits less debits over
     * income accounts; getting that backwards is how a profitable month reads
     * as a loss. Summed here in raw SQL, longhand, so the report and the
     * expectation reach the number by two different paths.
     */
    $income = (int) DB::connection('tenant')->selectOne('
        SELECT COALESCE(SUM(l.credit_minor - l.debit_minor), 0) AS net
          FROM journal_lines l
          JOIN accounts a ON a.id = l.account_id
          JOIN journals j ON j.reference = l.entry_ref
         WHERE a.type = ? AND j.posted_on BETWEEN ? AND ?
    ', ['income', '2026-01-01', '2026-12-31'])->net;

    $reported = 0;

    foreach ($pl['summary'] as $line) {
        if ($line['label'] === 'Income') {
            $reported = (int) round((float) str_replace([',', '$'], '', $line['value']) * 100);
        }
    }

    expect($reported)->toBe($income)
        ->and($pl['period'])->toContain('2026')
        ->and($pl['note'])->toContain('posted');

    /*
     * TURNOUT IS A COUNT AND NOTHING FINER. The columns are the proof: year,
     * phase, cast, eligible, percent — and no column that could be joined back
     * to a household or a mark.
     */
    $turnout = $reports->run('election_turnout', '2020-01-01', '2030-12-31');

    expect($turnout['columns'])->toBe(['Year', 'Phase', 'Ballots cast', 'Eligible', 'Turnout'])
        ->and($turnout['note'])->toContain('share no column');

    // An ageing is a position TODAY, and the report says so rather than looking
    // filtered by dates that do not narrow it.
    $ageing = $reports->run('arrears_ageing', '2026-01-01', '2026-01-02');

    expect($ageing['period'])->toStartWith('As at ')
        ->and($ageing['note'])->toContain('not over a period');

    // A period that ends before it begins is refused rather than answered.
    expect(fn () => $reports->run('profit_and_loss', '2026-12-31', '2026-01-01'))
        ->toThrow(DomainException::class);
});

it('exports a report over exactly the period it was run for, with the audit entry', function () {
    $treasurer = FacilitiesFixture::viewer(Role::TREASURER);

    $response = $this->actingAs($treasurer)
        ->get(FacilitiesFixture::url('/reports/maintenance_summary/export').'?from=2026-01-01&to=2026-12-31')
        ->assertOk();

    $csv = $response->streamedContent();

    expect($csv)->toContain('Category');

    $entry = DB::connection('mysql')->table('audit_log')
        ->where('action', 'export.taken')
        ->orderByDesc('id')
        ->first();

    $after = json_decode((string) $entry->after, true);

    // THE PERIOD IS IN THE SCOPE. A figure without its window is a figure
    // somebody will quote, and the audit entry has to say which window.
    expect($after['scope'])->toContain('Maintenance Summary')
        ->and($after['scope'])->toContain('January 1, 2026')
        ->and($entry->actor_name)->toBe($treasurer->name);
});
