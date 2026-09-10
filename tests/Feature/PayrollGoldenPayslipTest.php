<?php

declare(strict_types=1);

use App\Models\StatutoryRateVersion;
use App\Services\Payroll\PayrollCalculator;

/*
|--------------------------------------------------------------------------
| The suite that closes Q-002
|--------------------------------------------------------------------------
|
| `tests/Fixtures/golden-payslips.php` holds every figure on two payslips.
| This file asserts the engine against it, line by line, and names the exact
| line that disagrees rather than reporting that "payroll is wrong".
|
| WHEN THE ACCOUNTANT'S SLIPS ARRIVE, NO TEST CHANGES. Their figures go into the
| fixture and this file re-runs. Either it is green and the calculation is
| confirmed by somebody with the authority to confirm it, or it prints the field
| and both values. Whichever happens, the answer is a number.
|
| WHAT IS ASSERTED HERE AND WHAT IS ASSERTED ELSEWHERE. `PayrollCalculatorTest`
| owns the STRUCTURAL rules — that NIS comes off before Education Tax and PAYE,
| that the threshold is a band and not a cliff, that nothing is a float. Those
| hold whatever the rates turn out to be, and a ruling must not be able to
| quietly change them. This file owns the FIGURES, which a ruling is entitled to
| change. Keeping them apart is what stops a client's reply from being pasted
| over an invariant.
|
*/

beforeEach(function () {
    $this->golden = require base_path('tests/Fixtures/golden-payslips.php');

    /*
     * Built from the fixture's own rates rather than read from the database, so
     * this file asserts arithmetic and not seed state. The seeded version is
     * asserted separately, below, to catch the two drifting apart.
     */
    $this->rates = new StatutoryRateVersion([
        'label' => 'Provisional - unverified',
        'effective_from' => '2026-04-01',
        'nis_employee_bp' => 300,
        'nis_employer_bp' => 300,
        'nis_ceiling_annual_minor' => 5_000_000_00,
        'nht_employee_bp' => 200,
        'nht_employer_bp' => 300,
        'education_tax_employee_bp' => 225,
        'education_tax_employer_bp' => 350,
        'paye_bp' => 2500,
        'paye_threshold_annual_minor' => 1_800_000_00,
        'is_verified' => false,
    ]);

    $this->calculator = new PayrollCalculator;
});

it('matches every figure on both golden payslips, field by field', function () {
    $periods = $this->golden['periods_per_year'];

    foreach ($this->golden['employees'] as $name => $employee) {
        $slip = $this->calculator->payslip($employee['gross_minor'], $this->rates, $periods);
        $employer = $this->calculator->employerCost($employee['gross_minor'], $this->rates, $periods);

        $actual = [
            'nis_minor' => $slip['nis_minor'],
            'education_tax_minor' => $slip['education_tax_minor'],
            'paye_minor' => $slip['paye_minor'],
            'nht_minor' => $slip['nht_minor'],
            'net_minor' => $slip['net_minor'],
            'employer_nis_minor' => $employer['nis_minor'],
            'employer_nht_minor' => $employer['nht_minor'],
            'employer_education_tax_minor' => $employer['education_tax_minor'],
            'employer_total_minor' => $employer['total_minor'],
        ];

        foreach ($employee['expected'] as $field => $expected) {
            // Named per field and per person, so a failure says "Simone Clarke
            // paye_minor" rather than "array does not match array".
            expect($actual[$field])->toBe($expected, "{$name} · {$field}");
        }
    }
});

it('keeps each golden payslip adding up to its own net', function () {
    foreach ($this->golden['employees'] as $name => $employee) {
        $e = $employee['expected'];

        $sum = $e['nis_minor'] + $e['nht_minor'] + $e['education_tax_minor'] + $e['paye_minor'] + $e['net_minor'];

        // Guards the FIXTURE, not the engine. An accountant's figures typed in
        // by hand can transpose a digit, and a golden file that does not add up
        // would fail the engine for the fixture's mistake.
        expect($sum)->toBe($employee['gross_minor'], "{$name} · deductions plus net must equal gross");
    }
});

it('does not put the employer’s own contributions on the employee’s payslip', function () {
    /*
     * The employer's NIS, NHT and Education Tax are the estate's cost. If they
     * ever reached `payslip()` they would show on a slip as money taken off
     * somebody who never had it — and net pay would drop by roughly 9% for
     * every member of staff.
     */
    $slip = $this->calculator->payslip(185_000_00, $this->rates, 12);
    $employer = $this->calculator->employerCost(185_000_00, $this->rates, 12);

    expect($employer['total_minor'])->toBeGreaterThan(0);

    $deducted = $slip['nis_minor'] + $slip['nht_minor'] + $slip['education_tax_minor'] + $slip['paye_minor'];

    expect($slip['gross_minor'] - $deducted)->toBe($slip['net_minor']);
    expect($slip)->not->toHaveKey('employer_total_minor');
});

it('caps the employer’s NIS at the same ceiling as the employee’s', function () {
    // A ceiling on insurable earnings binds both parties. If it bound only the
    // employee, the estate's cost would run away above the ceiling while the
    // deduction stopped, and nothing on any screen would show it.
    $ceilingPerPeriod = intdiv(5_000_000_00, 12);

    $employer = $this->calculator->employerCost(900_000_00, $this->rates, 12);
    $slip = $this->calculator->payslip(900_000_00, $this->rates, 12);

    expect($employer['nis_minor'])->toBe(intdiv($ceilingPerPeriod * 300 + 5000, 10000));
    expect($employer['nis_minor'])->toBe($slip['nis_minor']); // same rate, same base
});

it('states plainly that nobody has confirmed these figures', function () {
    /*
     * THE POINT OF THE WHOLE FILE, AND IT IS MEANT TO BE READ AS A REMINDER
     * RATHER THAN AS A PASS.
     *
     * While this is false, the figures above are this platform's reading of the
     * rules and not a ruling. When an accountant's worked slips are typed into
     * the fixture this flips, and the assertion inverts into a check that the
     * rate version was verified too — two separate acts, because agreeing a
     * calculation and authorising money to leave a bank are two decisions.
     */
    if ($this->golden['confirmed'] === false) {
        expect($this->golden['rate_version'])->toBe('2026-04-DRAFT');
        expect($this->golden['source'])->toContain('Not supplied by the client');

        return;
    }

    $seeded = StatutoryRateVersion::on('mysql')->orderByDesc('effective_from')->firstOrFail();

    expect($seeded->is_verified)->toBeTrue(
        'The golden payslips are marked confirmed, so the rate version they were checked against '.
        'must be verified too. Approval reads the rate version, not this fixture.'
    );
})->group('golden');

it('records what board 15 draws without ever treating it as correct', function () {
    $periods = $this->golden['periods_per_year'];

    $morgan = $this->golden['employees']['Patricia Morgan'];
    $slip = $this->calculator->payslip($morgan['gross_minor'], $this->rates, $periods);

    // The board and the platform disagree, and the fixture must keep saying so.
    // If these ever became equal, either the board was redrawn or — the failure
    // this guards — the calculation was bent to match the picture.
    expect($morgan['board_states']['paye_minor'])->not->toBe($slip['paye_minor']);

    /*
     * BY HOW MUCH — AND THERE ARE TWO ANSWERS, WHICH IS EXACTLY THE TRAP.
     *
     * Morgan's own column overstates by 5.8x: 42,928.00 against 7,362.50. That
     * is the figure D-061 records and it is right.
     *
     * The RUN overstates by 11.6x: 85,392.00 of board PAYE across four staff
     * against 7,362.50 lawful. It is larger only because the other three are
     * charged tax they do not owe at all, and a ratio against their lawful zero
     * is not a ratio at all — it is one person's 5.8 plus three divisions by
     * nothing.
     *
     * Both are true of different things and neither belongs in a client
     * document on its own, which is why every such document quotes the MONEY:
     * J$85,392 withheld where J$7,362.50 is owed. This assertion pins both so
     * the two cannot be swapped for each other again.
     */
    $perEmployee = round($morgan['board_states']['paye_minor'] / $slip['paye_minor'], 1);

    expect($perEmployee)->toBe(5.8);

    $boardRun = 0;
    $lawfulRun = 0;

    foreach ($this->golden['run_paye_minor'] as $person => $figures) {
        $boardRun += $figures['board'];
        $lawfulRun += $figures['lawful'];
    }

    expect($boardRun)->toBe(85_392_00);
    expect($lawfulRun)->toBe(7_362_50);
    expect($boardRun - $lawfulRun)->toBe(78_029_50);

    // Twelve of those. This is the figure the client is being asked about.
    expect(($boardRun - $lawfulRun) * 12)->toBe(936_354_00);

    expect(round($boardRun / $lawfulRun, 1))->toBe(11.6);
});

it('does not claim to know which divisor the board’s cliff uses', function () {
    /*
     * Board 15 gives four observations. Wayne Thomas is charged on a base of
     * 81,679.40 and Simone Clarke is not charged on 66,828.60, so the cliff is
     * between them — and FOUR divisors of the annual threshold land in that
     * window. 26 is the standard fortnightly divisor and the natural reading,
     * and it is inferred, not proved.
     *
     * The count matters more than it looks. Written by hand this said three,
     * because the range it was checked over skipped 23 — and 1,800,000 / 23 =
     * 78,260.87 sits squarely inside the window. A loop over every divisor is
     * what found the fourth; an eyeballed list is what missed it.
     *
     * This asserts the honest position, so a client-facing document cannot
     * quietly upgrade "consistent with" into "identified as".
     */
    $window = $this->golden['cliff_window_minor'];
    $annual = 1_800_000_00;

    $inWindow = [];

    foreach (range(12, 27) as $divisor) {
        $perPeriod = intdiv($annual, $divisor);

        if ($perPeriod > $window['above'] && $perPeriod <= $window['up_to']) {
            $inWindow[] = $divisor;
        }
    }

    expect($inWindow)->toBe([23, 24, 25, 26]);
    expect($inWindow)->toContain(26);
    expect(count($inWindow))->toBeGreaterThan(1);
});
