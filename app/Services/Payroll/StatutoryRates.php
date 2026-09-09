<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\StatutoryRateVersion;
use App\Support\MoneyFormatter;

/**
 * What the calculation engine is allowed to say about its own rates.
 *
 * D-021 IS THE WHOLE SUBJECT OF THIS CLASS.
 *
 * The rates in force were entered from published figures and have never been
 * checked against a worked example by anyone qualified to check them. Until the
 * client's accountant supplies those examples they are a DRAFT: the engine may
 * calculate with them so the figures can be reviewed, and no run may be
 * approved against them. That is not a limitation to be engineered around, and
 * this class exists so that every screen states the same reason in the same
 * words rather than each inventing its own.
 *
 * There is deliberately no method here that approves anything.
 *
 * Rates are basis points throughout - 3% is 300 - so a percentage is formatted
 * by integer division, never by dividing by 100 into a float. A rate that
 * round-trips through a float is a rate that eventually disagrees with the
 * payslip it produced.
 */
final class StatutoryRates
{
    /**
     * Why approval is blocked, in the words every screen uses.
     *
     * Named as a constant rather than written into each controller so that the
     * day the accountant signs off, the sentence disappears from the whole
     * console at once instead of surviving in the one screen somebody missed.
     */
    public const BLOCKED_REASON = 'D-021: these statutory rates are a draft. Approval is blocked until the '
        ."client's accountant supplies worked examples confirming them.";

    /**
     * The version the engine is calculating with today.
     *
     * Null only on an installation where no rates have ever been recorded,
     * which is a real first-use state and not an error - the engine simply
     * cannot calculate until somebody enters a version.
     */
    public function current(?string $on = null): ?StatutoryRateVersion
    {
        return StatutoryRateVersion::inForceOn($on ?? now()->toDateString());
    }

    /** A specific version, for a screen that is linked to one. */
    public function version(int $id): ?StatutoryRateVersion
    {
        return StatutoryRateVersion::query()->find($id);
    }

    /**
     * The tag a version is known by: "2026-04-DRAFT".
     *
     * Built from the effective date and the verification flag rather than from
     * the stored label, because the label is prose somebody typed and the two
     * halves of this tag are facts. A version that has been signed off reads
     * 2026-04-APPROVED, and nothing else in the console has to change for that
     * to happen.
     */
    public function tag(StatutoryRateVersion $version): string
    {
        return $version->effective_from->format('Y-m').'-'.($version->is_verified ? 'APPROVED' : 'DRAFT');
    }

    /** Whether this version may be approved, and if not, why not. */
    public function approvalBlockedReason(StatutoryRateVersion $version): ?string
    {
        return $version->is_verified ? null : self::BLOCKED_REASON;
    }

    /**
     * The four deductions, in the order the engine applies them.
     *
     * ORDER IS LOAD-BEARING and is the same order PayrollCalculator uses: NIS
     * first, because Education Tax and PAYE are both computed on income after
     * it. The screen listing them in a different order would be describing a
     * different calculation from the one that produced the payslips.
     *
     * @return list<array{key: string, icon: string, name: string, basis: string, rate: string}>
     */
    public function deductionLines(StatutoryRateVersion $version): array
    {
        return [
            [
                'key' => 'nis',
                'icon' => 'guards',
                'name' => 'NIS — National Insurance Scheme',
                'basis' => sprintf(
                    'Employee contribution on gross, to a %s annual ceiling',
                    MoneyFormatter::whole($version->nis_ceiling_annual_minor),
                ),
                'rate' => $this->percent($version->nis_employee_bp),
            ],
            [
                'key' => 'nht',
                'icon' => 'clients',
                'name' => 'NHT — National Housing Trust',
                'basis' => 'Employee contribution, on total gross earnings',
                'rate' => $this->percent($version->nht_employee_bp),
            ],
            [
                'key' => 'education_tax',
                'icon' => 'ledger',
                'name' => 'Education Tax',
                'basis' => 'On gross earnings minus NIS contribution',
                'rate' => $this->percent($version->education_tax_employee_bp),
            ],
            [
                'key' => 'paye',
                'icon' => 'calendar',
                'name' => 'PAYE — Income Tax',
                'basis' => sprintf(
                    '%s above the tax-free threshold for the pay period',
                    $this->percent($version->paye_bp),
                ),
                'rate' => $this->percent($version->paye_bp),
            ],
        ];
    }

    /**
     * The tax-free threshold for ONE pay period, in minor units.
     *
     * The threshold is published annually and divided by the number of pay
     * periods - for a fortnight, 1,800,000 / 26 = 69,230.76. This single number
     * is why most guards show no PAYE at all, and it is computed here by
     * integer division so it can never disagree by a cent with the figure
     * PayrollCalculator used on the payslip.
     */
    public function thresholdPerPeriod(StatutoryRateVersion $version, int $periodsPerYear): int
    {
        return $periodsPerYear < 1 ? 0 : intdiv($version->paye_threshold_annual_minor, $periodsPerYear);
    }

    /**
     * A basis-point rate as a percentage: 300 becomes "3%", 225 becomes "2.25%".
     *
     * Integer arithmetic on the whole and fractional parts separately. Trailing
     * zeros are dropped, so the four rates read 3%, 2%, 2.25% and 25% rather
     * than a mixture of 3.0% and 25%.
     */
    public function percent(int $basisPoints): string
    {
        $whole = intdiv($basisPoints, 100);
        $hundredths = $basisPoints % 100;

        if ($hundredths === 0) {
            return $whole.'%';
        }

        // 25 hundredths is ".25"; 20 hundredths is ".2", not ".20".
        $fraction = $hundredths % 10 === 0 ? (string) intdiv($hundredths, 10) : str_pad((string) $hundredths, 2, '0', STR_PAD_LEFT);

        return $whole.'.'.$fraction.'%';
    }
}
