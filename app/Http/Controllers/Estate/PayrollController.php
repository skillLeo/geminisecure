<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\PayrollException;
use App\Models\Estate\PayrollRun;
use App\Models\Estate\StatutoryFiling;
use App\Services\Estate\Payroll;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The estate's own staff payroll — boards 13, 14, 15, 16 and 37.
 *
 * THE PROPERTY MANAGER CANNOT REACH ANY OF THIS, and one of them is on the
 * payroll. Patricia Morgan is board 15's first line and board 37's first row,
 * and D-010 locks her role out of `payroll` entirely: being ON a payroll is not
 * permission to SEE one, least of all everybody else's gross pay. The gate is
 * the route's, not this controller's, and `EstatePayrollTest` asserts the 403
 * rather than assuming the middleware is there.
 *
 * APPROVAL IS OPEN, AND THE FIRST ONE ASKS FOR A TICK. Q-002 is ruled (D-082):
 * the 2026-04 card carries TAJ's published thresholds and is verified, so a
 * calculated run can be approved by somebody who holds Payroll approval and did
 * not prepare it. The first live run in an estate also asks the approver to
 * confirm it was reconciled against current TAJ tables, naming the card — a
 * recorded acknowledgement, not a block. A refusal still reaches the page as text
 * on the control: a disabled button with nothing on it reads as a bug.
 */
class PayrollController extends Controller
{
    /** Board 13 — the run list. */
    public function runs(Request $request, Payroll $payroll): Response
    {
        return inertia('Estate/Payroll/Runs', [
            'estate' => ['name' => (string) tenant()->name],
            ...$payroll->runsBoard(),
            'tabs' => $this->tabs('runs'),
            'canCreate' => $request->user()->can('estate.payroll.create'),
            'reasons' => [
                'create' => 'Starting a run needs Payroll create access, which this role does not hold.',
            ],
        ]);
    }

    /** Board 14 — what stops a run being calculated. */
    public function exceptions(Request $request, string $slug, Payroll $payroll): Response
    {
        $run = $this->resolveRun($slug);

        return inertia('Estate/Payroll/Exceptions', [
            'estate' => ['name' => (string) tenant()->name],
            ...$payroll->exceptionsBoard($run),
            'canResolve' => $request->user()->can('estate.payroll.update'),
            'reasons' => [
                'resolve' => 'Resolving an exception changes what somebody is paid, so it needs Payroll '.
                    'update access, which this role does not hold.',
            ],
        ]);
    }

    /**
     * The run this URL names.
     *
     * Resolved by SLUG rather than id, because boards 14 and 15 address a run
     * by its period in their own URLs — /payroll/runs/sep-2026/exceptions and
     * /payroll/runs/aug-2026. A period is what a treasurer bookmarks and what
     * survives a reseed; an auto-increment id is neither.
     */
    private function resolveRun(string $slug): PayrollRun
    {
        return PayrollRun::query()->where('slug', $slug)->firstOrFail();
    }

    /** Board 15 — one run, every figure tied to its own lines. */
    public function show(Request $request, string $slug, Payroll $payroll): Response
    {
        return inertia('Estate/Payroll/Run', [
            'estate' => ['name' => (string) tenant()->name],
            ...$payroll->runBoard($this->resolveRun($slug), $request->user()),
        ]);
    }

    /** Board 16 — the returns the estate owes. */
    public function filings(Request $request, Payroll $payroll): Response
    {
        return inertia('Estate/Payroll/Filings', [
            'estate' => ['name' => (string) tenant()->name],
            ...$payroll->filingsBoard(),
            'tabs' => $this->tabs('filings'),
            'canFile' => $request->user()->can('estate.payroll.approve'),
            'reasons' => [
                'file' => 'Filing a return remits money to the revenue authority, so it needs Payroll '.
                    'approval access, which this role does not hold.',
                'calendar' => 'Not built yet — a compliance calendar needs every statutory due date for '.
                    'the year ahead, and this register holds only the returns that exist.',
            ],
        ]);
    }

    /** Board 37 — the employee register. */
    public function employees(Request $request, Payroll $payroll): Response
    {
        return inertia('Estate/Payroll/Employees', [
            'estate' => ['name' => (string) tenant()->name],
            ...$payroll->employeesBoard(),
            'tabs' => $this->tabs('employees'),
            'canCreate' => $request->user()->can('estate.payroll.create'),
            'reasons' => [
                'create' => 'Adding an employee puts somebody on a payroll, so it needs Payroll create '.
                    'access, which this role does not hold.',
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* the writes */
    /* ------------------------------------------------------------------ */

    public function calculate(string $slug, Payroll $payroll): RedirectResponse
    {
        try {
            $payroll->calculate($this->resolveRun($slug));
        } catch (DomainException $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        return back()->with('flash', 'Payslips calculated. Nothing has been paid yet.');
    }

    public function approve(Request $request, string $slug, Payroll $payroll): RedirectResponse
    {
        try {
            $payroll->approve($this->resolveRun($slug), $request->user(), $request->boolean('reconciled'));
        } catch (DomainException $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        return back()->with('flash', 'Run approved and posted.');
    }

    public function requestChanges(Request $request, string $slug, Payroll $payroll): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:190']]);

        try {
            $payroll->requestChanges($this->resolveRun($slug), $data['reason'], $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['run' => $e->getMessage()]);
        }

        return back()->with('flash', 'Sent back to whoever prepared it, with your reason.');
    }

    public function resolveException(Request $request, PayrollException $exception, Payroll $payroll): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => ['required', 'string', 'in:resolved,excluded'],
            'note' => ['nullable', 'string', 'max:190'],
        ]);

        try {
            $payroll->resolveException(
                $exception,
                $data['outcome'],
                $data['note'] ?? null,
                $request->user(),
            );
        } catch (DomainException $e) {
            return back()->withErrors(['exception' => $e->getMessage()]);
        }

        return back()->with('flash', 'Exception recorded.');
    }

    public function file(Request $request, StatutoryFiling $filing, Payroll $payroll): RedirectResponse
    {
        try {
            $payroll->fileReturn($filing, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['filing' => $e->getMessage()]);
        }

        return back()->with('flash', 'Filed, and the deductions it remits are cleared.');
    }

    /**
     * The module's three tabs. All three are built, so all three link.
     *
     * @return list<array{key: string, label: string}>
     */
    private function tabs(string $active): array
    {
        return array_map(
            static fn (array $tab): array => [...$tab, 'active' => $tab['key'] === $active],
            [
                ['key' => 'runs', 'label' => 'Pay runs'],
                ['key' => 'employees', 'label' => 'Employees'],
                ['key' => 'filings', 'label' => 'Statutory filings'],
            ],
        );
    }
}
