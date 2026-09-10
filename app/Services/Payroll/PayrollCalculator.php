<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\StatutoryRateVersion;
use App\Support\MoneyFormatter;
use RuntimeException;

/**
 * Computes one payslip from gross pay and a statutory rate version.
 *
 * ORDER IS LOAD-BEARING AND NOT ARBITRARY:
 *
 *   1. NIS       on gross, capped at the annual ceiling
 *   2. NHT       on gross
 *   3. Education Tax  on gross LESS NIS
 *   4. PAYE      on gross LESS NIS, above the period threshold
 *
 * NIS comes first because Education Tax and PAYE are both computed on income
 * after it. Reordering them changes everybody's net pay, which is why this
 * lives in one place with the order written down rather than being reassembled
 * wherever a figure is needed.
 *
 * The PAYE threshold is ANNUAL and divided by the number of pay periods. For a
 * fortnight that is 1,800,000 / 26 = 69,230.77, which is why most guards show
 * 0.00 PAYE - and why the payslip names the threshold rather than printing a
 * bare zero that reads as a bug.
 *
 * Everything is integer minor units throughout. No float touches a wage.
 *
 * `payslip()` is the employee's half. `employerCost()` is the estate's own, and
 * they are separate methods because they are separate documents — see that
 * method for why the employer's contributions must never appear as deductions
 * on somebody's slip, and for what the ledger does and does not yet carry.
 */
class PayrollCalculator
{
    /**
     * @return array{
     *     gross_minor:int, nis_minor:int, nht_minor:int,
     *     education_tax_minor:int, paye_minor:int, net_minor:int,
     *     paye_note:?string
     * }
     */
    public function payslip(int $grossMinor, StatutoryRateVersion $rates, int $periodsPerYear): array
    {
        if ($periodsPerYear < 1) {
            throw new RuntimeException('periodsPerYear must be at least 1.');
        }

        // 1. NIS, capped at the per-period share of the annual ceiling.
        $nisCeilingPerPeriod = intdiv($rates->nis_ceiling_annual_minor, $periodsPerYear);
        $nisableMinor = min($grossMinor, $nisCeilingPerPeriod);
        $nisMinor = $this->applyBasisPoints($nisableMinor, $rates->nis_employee_bp);

        // 2. NHT, on gross.
        $nhtMinor = $this->applyBasisPoints($grossMinor, $rates->nht_employee_bp);

        // 3 and 4 are both computed on income AFTER NIS.
        $afterNisMinor = $grossMinor - $nisMinor;

        $educationTaxMinor = $this->applyBasisPoints($afterNisMinor, $rates->education_tax_employee_bp);

        // 4. PAYE on the excess above the period threshold, never on the whole.
        $thresholdPerPeriod = intdiv($rates->paye_threshold_annual_minor, $periodsPerYear);
        $taxableMinor = max(0, $afterNisMinor - $thresholdPerPeriod);
        $payeMinor = $this->applyBasisPoints($taxableMinor, $rates->paye_bp);

        return [
            'gross_minor' => $grossMinor,
            'nis_minor' => $nisMinor,
            'nht_minor' => $nhtMinor,
            'education_tax_minor' => $educationTaxMinor,
            'paye_minor' => $payeMinor,
            'net_minor' => $grossMinor - $nisMinor - $nhtMinor - $educationTaxMinor - $payeMinor,
            'paye_note' => $payeMinor === 0
                ? $this->belowThresholdNote($thresholdPerPeriod, $periodsPerYear, $rates)
                : null,
        ];
    }

    /**
     * What employing somebody costs the estate on top of their gross.
     *
     * A SEPARATE METHOD FROM `payslip()`, AND DELIBERATELY. A payslip is a
     * statement issued to a person: it says what they earned and what was taken
     * off them. The employer's own contributions are neither — they are the
     * estate's cost and the estate's liability, and putting them on the slip
     * would show somebody a "deduction" that never came out of their pay.
     *
     * THIS COMPUTES AND POSTS NOTHING. `Payroll::approve()` debits gross to
     * 5000 and credits the four employee withholdings to 2100, and it did so
     * before this method existed and still does. So the estate's books today
     * carry the employee half of the statutory obligation and not the employer
     * half — see D-073. That is a real gap in the ledger and it is not closed
     * here, because closing it means a new expense account, an accrual against
     * 2100 and a larger S01, and none of those are decisions this service gets
     * to take on a client's behalf.
     *
     * What this method is for is the ASK. Q-002 requests two worked payslips
     * "plus the employer cost", and until now there was nowhere to put the
     * answer: the rate version has carried `nis_employer_bp`,
     * `nht_employer_bp` and `education_tax_employer_bp` since the schema was
     * written, and not one line of code read them. A figure nobody can check is
     * a figure nobody should have asked for.
     *
     * THE EDUCATION TAX BASE IS AN ASSUMPTION AND IS MARKED AS ONE. The
     * employee side charges Education Tax on gross less employee NIS, and the
     * employer side here uses the same statutory income, because consistency
     * with the half that was already reviewed is the most defensible default.
     * On gross instead it is J$15,400 a month across these four rather than
     * J$14,938 — a J$462 monthly difference that only the accountant's own
     * worked slip settles.
     *
     * // ASSUMPTION Q-002
     *
     * @return array{
     *     nis_minor:int, nht_minor:int, education_tax_minor:int,
     *     total_minor:int, base_note:string
     * }
     */
    public function employerCost(int $grossMinor, StatutoryRateVersion $rates, int $periodsPerYear): array
    {
        if ($periodsPerYear < 1) {
            throw new RuntimeException('periodsPerYear must be at least 1.');
        }

        // The ceiling binds the employer's contribution exactly as it binds the
        // employee's; it is a ceiling on the insurable earnings, not on one
        // party's share of them.
        $nisCeilingPerPeriod = intdiv($rates->nis_ceiling_annual_minor, $periodsPerYear);
        $nisableMinor = min($grossMinor, $nisCeilingPerPeriod);

        $nisMinor = $this->applyBasisPoints($nisableMinor, $rates->nis_employer_bp);
        $nhtMinor = $this->applyBasisPoints($grossMinor, $rates->nht_employer_bp);

        // Statutory income — gross less the EMPLOYEE's NIS. The same base the
        // employee's own Education Tax is charged on, above.
        $employeeNisMinor = $this->applyBasisPoints($nisableMinor, $rates->nis_employee_bp);
        $statutoryIncomeMinor = $grossMinor - $employeeNisMinor;

        $educationTaxMinor = $this->applyBasisPoints($statutoryIncomeMinor, $rates->education_tax_employer_bp);

        return [
            'nis_minor' => $nisMinor,
            'nht_minor' => $nhtMinor,
            'education_tax_minor' => $educationTaxMinor,
            'total_minor' => $nisMinor + $nhtMinor + $educationTaxMinor,
            'base_note' => sprintf(
                'Employer Education Tax charged on statutory income (%s), being gross less employee NIS. '.
                'Unconfirmed — see QUESTIONS.md Q-002.',
                MoneyFormatter::fromMinor($statutoryIncomeMinor),
            ),
        ];
    }

    /**
     * Says WHY PAYE is zero, naming the threshold.
     *
     * A bare 0.00 on a payslip reads as a defect to the person holding it.
     */
    private function belowThresholdNote(
        int $thresholdPerPeriod,
        int $periodsPerYear,
        StatutoryRateVersion $rates,
    ): string {
        return sprintf(
            'Below the PAYE threshold of %s per period (%s annually / %d periods).',
            MoneyFormatter::fromMinor($thresholdPerPeriod),
            MoneyFormatter::fromMinor($rates->paye_threshold_annual_minor),
            $periodsPerYear,
        );
    }

    /**
     * Applies a rate held in basis points, rounding half up to the cent.
     *
     * intdiv after adding half the divisor gives banker-free half-up rounding
     * without ever converting to float, which is the whole point: a wage that
     * round-trips through a float is a wage that eventually disagrees with
     * itself by a cent.
     */
    private function applyBasisPoints(int $amountMinor, int $basisPoints): int
    {
        if ($amountMinor <= 0 || $basisPoints <= 0) {
            return 0;
        }

        return intdiv($amountMinor * $basisPoints + 5_000, 10_000);
    }
}
