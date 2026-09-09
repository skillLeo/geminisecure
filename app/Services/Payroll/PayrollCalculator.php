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
