<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Support\MoneyFormatter;
use Inertia\Response;

/**
 * Guard payroll and accounting, Super Admin screens 28 to 31.
 *
 * Gemini Security paying its own guards. An estate's staff payroll is a
 * separate ledger in the estate database and never appears here.
 */
class PayrollController extends Controller
{
    public function index(): Response
    {
        $runs = PayrollRun::with('rateVersion')->orderByDesc('period_start')->get();

        return inertia('Gemini/Payroll/Index', [
            'runs' => $runs->map(fn (PayrollRun $run) => [
                'id' => $run->id,
                'reference' => $run->reference,
                'period' => $run->period_label,
                'employees' => $run->payslips()->count(),
                'gross' => MoneyFormatter::fromMinor($run->gross_minor, $run->currency),
                'net' => MoneyFormatter::fromMinor($run->net_minor, $run->currency),
                'status' => $run->status,
                'status_badge' => match ($run->status) {
                    'approved', 'paid' => 'approved',
                    'calculated' => 'pending',
                    default => 'ok',
                },
                'can_approve' => $run->canBeApproved(),
                // Shown on the screen, so a blocked approval explains itself
                // rather than presenting a button that quietly does nothing.
                'blocked_reason' => $run->blockedReason(),
            ]),
        ]);
    }

    public function show(PayrollRun $run): Response
    {
        $run->load('rateVersion');

        $payslips = Payslip::with(['employee', 'estate'])
            ->where('payroll_run_id', $run->id)
            ->get();

        return inertia('Gemini/Payroll/Show', [
            'run' => [
                'reference' => $run->reference,
                'period' => $run->period_label,
                'status' => $run->status,
                'periods_per_year' => $run->periods_per_year,
                'can_approve' => $run->canBeApproved(),
                'blocked_reason' => $run->blockedReason(),
                'rates' => [
                    'label' => $run->rateVersion->label,
                    'verified' => (bool) $run->rateVersion->is_verified,
                    'paye_threshold_annual' => MoneyFormatter::fromMinor(
                        $run->rateVersion->paye_threshold_annual_minor
                    ),
                    'paye_threshold_period' => MoneyFormatter::fromMinor(
                        intdiv($run->rateVersion->paye_threshold_annual_minor, max(1, $run->periods_per_year))
                    ),
                ],
            ],
            'payslips' => $payslips->map(fn (Payslip $slip) => [
                'id' => $slip->id,
                'employee' => $slip->employee->full_name ?? 'Unknown',
                'estate' => $slip->estate->name ?? 'Unassigned',
                'gross' => MoneyFormatter::fromMinor($slip->gross_minor, $slip->currency),
                'nis' => MoneyFormatter::fromMinor($slip->nis_minor, $slip->currency),
                'nht' => MoneyFormatter::fromMinor($slip->nht_minor, $slip->currency),
                'education_tax' => MoneyFormatter::fromMinor($slip->education_tax_minor, $slip->currency),
                'paye' => MoneyFormatter::fromMinor($slip->paye_minor, $slip->currency),
                'net' => MoneyFormatter::fromMinor($slip->net_minor, $slip->currency),
                'paye_note' => $slip->paye_note,
            ]),
        ]);
    }
}
