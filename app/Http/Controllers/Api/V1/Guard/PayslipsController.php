<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Guard;

use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Payslip;
use Illuminate\Http\JsonResponse;

/**
 * The guard's own payslips (13 D2). Board guard-app-05 screen 20.
 *
 * THE ONE MONEY A GUARD'S HANDSET MAY READ, AND ONLY THEIR OWN. The query is
 * keyed on the token's guard and nothing in the request can widen it. No
 * household figure is reachable from here: payslips are central and hold wages.
 * `ApiContract` exempts this route, by name, from the invariant-2 money scan and
 * no other guard route.
 *
 * APPROVED RUNS ONLY. A draft run's figures are a calculation in progress; a
 * guard shown one would plan around a net pay that can still change.
 *
 * DECIMAL STRINGS, NOT FLOATS. "38450.00" is exactly what the payslip says;
 * a float is what a phone rounds.
 */
class PayslipsController extends Controller
{
    public function mine(DeviceContext $context): JsonResponse
    {
        $guard = $context->guardOrFail();

        $items = Payslip::query()->with('run')
            ->where('guard_id', $guard->id)
            ->whereHas('run', static fn ($q) => $q->whereIn('status', ['approved', 'paid']))
            ->get()
            ->sortByDesc(static fn (Payslip $p) => $p->run->period_end)
            ->take(24)
            ->map(fn (Payslip $p): array => [
                'id' => $p->id,
                'run_reference' => $p->run->reference,
                'period_label' => $p->run->period_label,
                'period_start' => $p->run->period_start->toDateString(),
                'period_end' => $p->run->period_end->toDateString(),
                'status' => $p->run->status,
                'currency' => $p->currency,
                'gross' => $this->decimal($p->gross_minor),
                'deductions' => [
                    'nis' => $this->decimal($p->nis_minor),
                    'nht' => $this->decimal($p->nht_minor),
                    'education_tax' => $this->decimal($p->education_tax_minor),
                    'paye' => $this->decimal($p->paye_minor),
                    'pension' => $this->decimal($p->pension_minor),
                ],
                'net' => $this->decimal($p->net_minor),
                'paye_note' => $p->paye_note,
            ])
            ->values()
            ->all();

        return response()->json(['items' => $items]);
    }

    private function decimal(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);

        return $sign.intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
