<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Guard;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\StatutoryRateVersion;
use App\Services\Payroll\PayrollCalculator;
use Illuminate\Database\Seeder;

/**
 * A provisional rate version and one calculated run.
 *
 * The rate version is seeded UNVERIFIED on purpose (Q-002). Build Spec open
 * item [A] says not to go live on unverified numbers, so the run reaches
 * `calculated` and stops there: the figures can be reviewed, and the console
 * shows why approval is blocked rather than silently offering a button that
 * should not be pressed.
 */
class PayrollSeeder extends Seeder
{
    public function run(): void
    {
        $rates = StatutoryRateVersion::updateOrCreate(
            ['label' => 'Provisional 2026/27 - UNVERIFIED'],
            [
                'effective_from' => '2026-04-01',
                'effective_to' => null,
                'nis_employee_bp' => 300,
                'nis_employer_bp' => 300,
                'nis_ceiling_annual_minor' => 5_000_000_00,
                'nht_employee_bp' => 200,
                'nht_employer_bp' => 300,
                'education_tax_employee_bp' => 225,
                'education_tax_employer_bp' => 350,
                'paye_bp' => 2500,
                'paye_threshold_annual_minor' => 1_800_000_00,

                // The whole point. Approval is blocked until this is true.
                'is_verified' => false,
            ],
        );

        $guards = Guard::whereIn('status', ['active', 'on_leave'])->get();

        if ($guards->isEmpty()) {
            $this->command->warn('No guards on the roster; skipping payroll seed.');

            return;
        }

        $start = now()->startOfMonth();

        $payrollRun = PayrollRun::firstOrNew(['reference' => 'GS-PR-'.$start->format('Ym').'-2']);

        // Never rewrite an approved run: that is invariant 4, and the trigger
        // would refuse it anyway.
        if ($payrollRun->exists && in_array($payrollRun->status, ['approved', 'paid'], true)) {
            // The run is settled and must not be rewritten, but the returns
            // owed on it still have to exist.
            $this->call(StatutoryFilingsSeeder::class);

            return;
        }

        $payrollRun->fill([
            'period_label' => $start->format('M Y').' - fortnight 2',
            'period_start' => $start->copy()->addDays(14)->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'periods_per_year' => 26,
            'statutory_rate_version_id' => $rates->id,
            'status' => 'calculated',
            'currency' => 'JMD',
        ])->save();

        $calculator = new PayrollCalculator;
        $gross = 0;
        $net = 0;

        foreach ($guards as $i => $guard) {
            // A spread that straddles the threshold, so the payslip screen
            // shows both the zero-PAYE explanation and a real PAYE figure.
            $grossMinor = [52_000_00, 61_500_00, 74_800_00, 95_200_00][$i % 4];

            $figures = $calculator->payslip($grossMinor, $rates, 26);

            Payslip::updateOrCreate(
                ['payroll_run_id' => $payrollRun->id, 'guard_id' => $guard->id],
                [
                    'tenant_id' => $guard->tenant_id,
                    'currency' => 'JMD',
                    ...$figures,
                ],
            );

            $gross += $figures['gross_minor'];
            $net += $figures['net_minor'];
        }

        $payrollRun->forceFill(['gross_minor' => $gross, 'net_minor' => $net])->save();

        /*
         * The statutory register, seeded last because two of its returns are
         * recorded at the totals this run's payslips come to.
         *
         * Called from here rather than added to DatabaseSeeder so that the
         * payroll module's seed data stays in one ordered piece: the rates, the
         * run they produced, and the returns owed on it.
         */
        $this->call(StatutoryFilingsSeeder::class);
    }
}
