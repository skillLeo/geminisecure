<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The golden payslips — Q-002
|--------------------------------------------------------------------------
|
| THIS FILE IS THE ANSWER SLOT. It holds, for the two employees the accountant
| was asked about, every figure a payslip carries — and it holds them as the
| PLATFORM currently computes them, marked unconfirmed, because nobody has yet
| sent the worked slips that would replace them.
|
| WHEN THE ACCOUNTANT REPLIES, ONE FILE CHANGES. Type their figures into
| `expected` below, flip `confirmed` to true, set `rate_version` and `period` to
| whatever they computed against, and run:
|
|     php artisan test tests/Feature/PayrollGoldenPayslipTest.php
|
| Either every line agrees and Q-002 closes, or the suite names the exact line
| that does not — "paye_minor: expected 4292800, got 736250" — and the
| disagreement is a figure rather than an argument. That is the whole design:
| the arithmetic is already asserted structurally in `PayrollCalculatorTest`;
| what this adds is a single place for somebody else's authority to land.
|
| WHY THE PLATFORM'S OWN FIGURES SIT HERE MEANWHILE, rather than the board's.
| Board 15 draws a PAYE column that withholds J$85,392 a month across four staff
| where the rules as read here ask J$7,362.50 (D-061). Seeding the board's
| numbers as "expected" would make the suite green while the application withheld
| the wrong tax from four people, which is precisely the failure this file exists
| to prevent. `board_states` records what the board draws so the disagreement
| stays visible and measured, and no assertion treats it as correct.
|
| NOTHING HERE IS AUTHORITY. Every `expected` figure below is this platform's
| reading of the rules, not a ruling. `confirmed => false` is what the payroll
| approval gate reads through the rate version, and it is why no run can be
| disbursed.
|
*/

return [
    /*
     * Flip to true ONLY when an accountant's worked payslips have been typed
     * into `expected` and the rate version below names what they used.
     *
     * This does not itself unblock approval — that reads
     * `statutory_rate_versions.is_verified`, which is a separate deliberate act
     * against the database. Two flags, because agreeing a calculation and
     * authorising money to leave a bank are two decisions.
     */
    'confirmed' => false,

    'rate_version' => '2026-04-DRAFT',
    'period' => 'unconfirmed — the accountant has not named the pay period',
    'periods_per_year' => 12,
    'source' => 'Computed by App\Services\Payroll\PayrollCalculator. Not supplied by the client.',

    /*
     * Two employees, chosen because they sit either side of the threshold and
     * between them settle the whole question.
     *
     * Morgan is the only person on this payroll who pays any PAYE at all, so
     * her slip settles the RATE and the BASE. Clarke settles the THRESHOLD, and
     * she is the more important of the two: her base of 66,828.60 sits inside
     * the window where the board's cliff must lie, and it is the only
     * observation that constrains it at all.
     */
    'employees' => [
        'Patricia Morgan' => [
            'gross_minor' => 185_000_00,

            // What this platform computes. Replace with the accountant's.
            'expected' => [
                'nis_minor' => 5_550_00,
                'education_tax_minor' => 4_037_63,
                'paye_minor' => 7_362_50,
                'nht_minor' => 3_700_00,
                'net_minor' => 164_349_87,

                // Employer's own contributions — the estate's cost, not a
                // deduction from her. Computed by `employerCost()`; the
                // Education Tax base is itself an assumption (Q-002).
                'employer_nis_minor' => 5_550_00,
                'employer_nht_minor' => 5_550_00,
                'employer_education_tax_minor' => 6_280_75,
                'employer_total_minor' => 17_380_75,
            ],

            /*
             * Board 15's own PAYE column, read off the wireframe. Recorded, not
             * asserted as correct — 25% of gross less all three deductions,
             * charged on the whole rather than on the excess above the
             * threshold.
             */
            'board_states' => ['paye_minor' => 42_928_00],
        ],

        'Simone Clarke' => [
            'gross_minor' => 72_000_00,

            'expected' => [
                'nis_minor' => 2_160_00,
                'education_tax_minor' => 1_571_40,
                'paye_minor' => 0,
                'nht_minor' => 1_440_00,
                'net_minor' => 66_828_60,

                'employer_nis_minor' => 2_160_00,
                'employer_nht_minor' => 2_160_00,
                'employer_education_tax_minor' => 2_444_40,
                'employer_total_minor' => 6_764_40,
            ],

            /*
             * The board agrees on the figure and disagrees on the reason, which
             * is why this one matters most. Both say nil PAYE. The board says
             * nil because 66,828.60 falls under a cliff; this platform says nil
             * because her statutory income of 69,840.00 is under the monthly
             * threshold of 150,000.00. Same zero, two different rules — and on
             * a month where she earned more, they diverge.
             */
            'board_states' => ['paye_minor' => 0],
        ],
    ],

    /*
     * The whole August run's PAYE, both ways, because the two multiples that
     * can be quoted from it are easy to swap and mean different things.
     *
     * Board total 85,392.00 against lawful 7,362.50. Morgan's own column
     * overstates by 5.8x (D-061); the RUN ratio is 11.6x, and it is larger only
     * because three of the four are charged tax they do not owe at all. Every
     * client-facing document quotes the money rather than either multiple.
     */
    'run_paye_minor' => [
        'Patricia Morgan' => ['board' => 42_928_00, 'lawful' => 7_362_50],
        'Neil Anderson' => ['board' => 22_044_00, 'lawful' => 0],
        'Wayne Thomas' => ['board' => 20_420_00, 'lawful' => 0],
        'Simone Clarke' => ['board' => 0, 'lawful' => 0],
    ],

    /*
     * What the four data points on board 15 actually pin down about its cliff,
     * and what they do not.
     *
     * Thomas's base of 81,679.40 is charged and Clarke's 66,828.60 is not, so
     * the cliff lies in between. FOUR candidate divisors of the annual
     * threshold land in that window and the board cannot distinguish them:
     *
     *     annual / 23 = 78,260.86
     *     annual / 24 = 75,000.00
     *     annual / 25 = 72,000.00
     *     annual / 26 = 69,230.76   <- the standard fortnightly divisor
     *
     * 26 is the natural reading and is what has been reported, but it is
     * INFERRED rather than proved, and this file says so rather than letting a
     * plausible number harden into a fact. Clarke's worked slip is what settles
     * it — which is why she is on the list and not only Morgan.
     */
    'cliff_window_minor' => ['above' => 66_828_60, 'up_to' => 81_679_40],
];
