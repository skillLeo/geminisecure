<?php

declare(strict_types=1);

use App\Models\StatutoryRateVersion;
use App\Services\Payroll\PayrollCalculator;
use Database\Seeders\StatutoryRatesSeeder;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| The suite Q-002 closed with — the ruling's own payslips, to the cent
|--------------------------------------------------------------------------
|
| `tests/Fixtures/golden-payslips.php` holds the two worked examples the client
| ruled with (D-082). This file asserts the engine against them field by field,
| on the SEEDED cards found by the SEEDED selector — so it proves the rate data
| and the choice of card as well as the arithmetic. A one-cent divergence names
| the person, the card and the field.
|
| WHAT IS ASSERTED HERE AND WHAT IS ASSERTED ELSEWHERE. `PayrollCalculatorTest`
| owns the structural rules on an in-memory card; this file owns the FIGURES the
| client ruled. Keeping them apart is what stops a future ruling being pasted
| over an invariant.
|
*/

beforeEach(function () {
    $this->golden = require base_path('tests/Fixtures/golden-payslips.php');

    // The real cards, from the real seeder. Idempotent, so running it on every
    // test costs nothing and means no test depends on another having run first.
    Artisan::call('db:seed', ['--class' => StatutoryRatesSeeder::class, '--force' => true]);

    $this->calculator = new PayrollCalculator;
});

/** The card the selector returns for a pay date inside one of the fixture's periods. */
function goldenCard(array $golden, string $period): StatutoryRateVersion
{
    return StatutoryRateVersion::forPayDate($golden['versions'][$period]['pay_date']);
}

it('selects the card by pay date, either side of 1 April', function () {
    $aprDec = goldenCard($this->golden, 'apr_dec_2026');
    $janMar = goldenCard($this->golden, 'jan_mar_2026');

    expect($aprDec->label)->toBe('TAJ 2026/27')
        ->and($janMar->label)->toBe('TAJ 2025/26')
        ->and($aprDec->id)->not->toBe($janMar->id);

    // TAJ's published periodic figures, exactly as the ruling tabulated them.
    expect($aprDec->thresholdPerPeriod(12))->toBe(158_530_00)
        ->and($aprDec->thresholdPerPeriod(26))->toBe(73_234_90)
        ->and($aprDec->thresholdPerPeriod(52))->toBe(36_583_85)
        ->and($janMar->thresholdPerPeriod(12))->toBe(149_948_00)
        ->and($janMar->thresholdPerPeriod(26))->toBe(69_307_26)
        ->and($janMar->thresholdPerPeriod(52))->toBe(34_603_00);
});

it('reads TAJ’s published periodic figure rather than dividing the annual one', function () {
    /*
     * The subtlety that breaks most calculators. TAJ's fortnightly 73,234.90 is
     * not 1,902,360 / 26 = 73,167.69. A calculator that divided would drift by
     * 67.21 on every fortnightly payslip — the kind of difference a golden test
     * catches and an accountant queries.
     */
    $card = goldenCard($this->golden, 'apr_dec_2026');

    expect($card->thresholdPerPeriod(26))->not->toBe(intdiv($card->paye_threshold_annual_minor, 26))
        ->and(intdiv($card->paye_threshold_annual_minor, 26))->toBe(73_167_69);
});

it('matches both golden payslips to the cent on the April–December 2026 card', function () {
    $card = goldenCard($this->golden, 'apr_dec_2026');
    $periods = $this->golden['periods_per_year'];

    foreach ($this->golden['employees'] as $name => $employee) {
        $slip = $this->calculator->payslip($employee['gross_minor'], $card, $periods);
        $employer = $this->calculator->employerCost($employee['gross_minor'], $card, $periods);

        $actual = [
            'nis_minor' => $slip['nis_minor'],
            'education_tax_minor' => $slip['education_tax_minor'],
            'paye_minor' => $slip['paye_minor'],
            'nht_minor' => $slip['nht_minor'],
            'net_minor' => $slip['net_minor'],
            'employer_nis_minor' => $employer['nis_minor'],
            'employer_nht_minor' => $employer['nht_minor'],
            'employer_education_tax_minor' => $employer['education_tax_minor'],
            'employer_heart_minor' => $employer['heart_minor'],
            'employer_total_minor' => $employer['total_minor'],
        ];

        foreach ($employee['expected']['apr_dec_2026'] as $field => $expected) {
            expect($actual[$field])->toBe($expected, "{$name} · 2026-04 card · {$field}");
        }
    }
});

it('proves the version selector on the January–March 2026 card', function () {
    /*
     * The same two people, three months earlier. Patricia's PAYE moves from
     * 5,230.00 to 7,375.50 because the monthly threshold is 149,948.00 there;
     * Simone's stays nil. If the selector returned "the latest" card instead of
     * the one in force on the pay date, this is the assertion that fails.
     */
    $card = goldenCard($this->golden, 'jan_mar_2026');

    foreach ($this->golden['employees'] as $name => $employee) {
        $slip = $this->calculator->payslip($employee['gross_minor'], $card, $this->golden['periods_per_year']);

        expect($slip['paye_minor'])
            ->toBe($employee['expected']['jan_mar_2026']['paye_minor'], "{$name} · 2025-04 card · paye_minor");
    }
});

it('charges Education Tax from the first dollar — Simone pays it with no PAYE at all', function () {
    $card = goldenCard($this->golden, 'apr_dec_2026');
    $simone = $this->golden['employees']['Simone Clarke'];

    $slip = $this->calculator->payslip($simone['gross_minor'], $card, 12);

    expect($slip['paye_minor'])->toBe(0)
        ->and($slip['education_tax_minor'])->toBe(1_571_40)
        ->and($slip['education_tax_minor'])->toBeGreaterThan(0)
        ->and($slip['paye_note'])->toContain('158,530.00')
        ->and($slip['paye_note'])->toContain("TAJ's published monthly figure");
});

it('keeps each golden payslip adding up to its own net', function () {
    foreach ($this->golden['employees'] as $name => $employee) {
        $e = $employee['expected']['apr_dec_2026'];

        $sum = $e['nis_minor'] + $e['nht_minor'] + $e['education_tax_minor'] + $e['paye_minor'] + $e['net_minor'];

        // Guards the FIXTURE: a hand-typed figure can transpose a digit, and a
        // golden file that does not add up would fail the engine for its own error.
        expect($sum)->toBe($employee['gross_minor'], "{$name} · deductions plus net must equal gross");
    }
});

it('does not put the employer’s own contributions on the employee’s payslip', function () {
    $card = goldenCard($this->golden, 'apr_dec_2026');

    $slip = $this->calculator->payslip(185_000_00, $card, 12);
    $employer = $this->calculator->employerCost(185_000_00, $card, 12);

    expect($employer['total_minor'])->toBe(22_930_75);

    $deducted = $slip['nis_minor'] + $slip['nht_minor'] + $slip['education_tax_minor'] + $slip['paye_minor'];

    expect($slip['gross_minor'] - $deducted)->toBe($slip['net_minor'])
        ->and($slip)->not->toHaveKey('employer_total_minor');
});

it('caps NIS at 416,666.67 a month for employer and employee alike', function () {
    // Rounded, not truncated: the ruling states the ceiling as 416,666.67, and
    // truncating gave 416,666.66 — a cent short on exactly the capped payslips.
    $card = goldenCard($this->golden, 'apr_dec_2026');

    expect($card->nisCeilingPerPeriod(12))->toBe(416_666_67);

    $slip = $this->calculator->payslip(900_000_00, $card, 12);
    $employer = $this->calculator->employerCost(900_000_00, $card, 12);

    expect($slip['nis_minor'])->toBe(12_500_00)
        ->and($employer['nis_minor'])->toBe(12_500_00);
});

it('charges 30% on chargeable income above 500,000 a month, as ruled', function () {
    /*
     * 900,000 gross: NIS 12,500.00, statutory income 887,500.00, chargeable
     * 728,970.00 above the 158,530.00 threshold. 25% of the first 500,000.00 is
     * 125,000.00 and 30% of the remaining 228,970.00 is 68,691.00 — 193,691.00.
     * Where that breakpoint is measured from is Q-018; this is the ruled reading.
     */
    $card = goldenCard($this->golden, 'apr_dec_2026');

    expect($card->higherBandPerPeriod(12))->toBe(500_000_00);

    $slip = $this->calculator->payslip(900_000_00, $card, 12);

    expect($slip['paye_minor'])->toBe(193_691_00);
});

it('marks the golden payslips confirmed and the cards they came from verified — and says by whom', function () {
    expect($this->golden['confirmed'])->toBeTrue();

    foreach (['apr_dec_2026', 'jan_mar_2026'] as $period) {
        $card = goldenCard($this->golden, $period);

        expect($card->is_verified)->toBeTrue()
            // Honest about the one thing the ruling could not supply.
            ->and($card->verified_by)->toContain('Not countersigned');
    }

    // The cards ruled on their annual figure alone are seeded unverified, so no
    // run on them can be approved until TAJ's periodic figures are recorded.
    $annualOnly = StatutoryRateVersion::query()
        ->whereNull('superseded_at')
        ->whereNull('paye_threshold_monthly_minor')
        ->get();

    expect($annualOnly)->not->toBeEmpty();

    foreach ($annualOnly as $card) {
        expect($card->is_verified)->toBeFalse($card->label.' has no TAJ periodic figures and must not be verified.');
    }
});

it('refuses a pay date no card covers rather than falling back to the latest', function () {
    expect(fn () => StatutoryRateVersion::forPayDate('2023-01-31'))->toThrow(DomainException::class);
});

it('records what board 15 draws without ever treating it as correct', function () {
    $morgan = $this->golden['employees']['Patricia Morgan'];
    $slip = $this->calculator->payslip($morgan['gross_minor'], goldenCard($this->golden, 'apr_dec_2026'), 12);

    expect($morgan['board_states']['paye_minor'])->not->toBe($slip['paye_minor']);

    /*
     * BY HOW MUCH — two answers, and that is the trap D-073 recorded. Morgan's
     * own column overstates by 8.2x; the RUN by 16.3x, larger only because three
     * of the four are charged tax they do not owe at all. Before the ruling set
     * the real threshold the same comparison read 5.8x and 11.6x. Every
     * client-facing sentence quotes the money.
     */
    expect(round($morgan['board_states']['paye_minor'] / $slip['paye_minor'], 1))->toBe(8.2);

    $boardRun = 0;
    $lawfulRun = 0;

    foreach ($this->golden['run_paye_minor'] as $figures) {
        $boardRun += $figures['board'];
        $lawfulRun += $figures['lawful'];
    }

    expect($boardRun)->toBe(85_392_00)
        ->and($lawfulRun)->toBe(5_230_00)
        ->and($boardRun - $lawfulRun)->toBe(80_162_00)
        ->and(($boardRun - $lawfulRun) * 12)->toBe(961_944_00)
        ->and(round($boardRun / $lawfulRun, 1))->toBe(16.3);
});

it('identifies the board’s cliff as TAJ’s fortnightly figure — which no whole-number divisor gives', function () {
    /*
     * The four observations only narrowed the cliff to a window, and four
     * divisors of the old annual figure landed inside it (D-073). The ruling
     * identified it as TAJ's published 2026 fortnightly threshold. It lies in
     * the window, and it is not the annual figure divided by any whole number —
     * which is why "narrowed, not identified" was the honest report, and why the
     * natural-looking ÷26 was wrong.
     */
    $window = $this->golden['cliff_window_minor'];
    $cliff = $this->golden['board_cliff_minor'];

    expect($cliff)->toBeGreaterThan($window['above'])
        ->and($cliff)->toBeLessThanOrEqual($window['up_to'])
        ->and($cliff)->toBe(goldenCard($this->golden, 'apr_dec_2026')->thresholdPerPeriod(26));

    $divisions = array_map(static fn (int $d): int => intdiv(1_902_360_00, $d), range(12, 27));

    expect($divisions)->not->toContain($cliff);
});
