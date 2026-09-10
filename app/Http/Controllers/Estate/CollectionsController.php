<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\DunningTemplate;
use App\Models\Estate\PaymentPlan;
use App\Models\Estate\Unit;
use App\Services\Estate\Collections;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Payment plans and dunning — board screens community-admin-07 and 08.
 *
 * THREE PERMISSION LEVELS ACROSS SIX ROUTES, and the split is the point.
 *
 *   view      reading a household's plan and the dunning log. A committee
 *             member may see how the estate is chasing its arrears.
 *   create    drawing a plan up, recording that a household agreed, sending a
 *             notice. Real writes, and a notice genuinely leaves the estate —
 *             but none of them changes what happens at a gate.
 *   approve   ACTIVATING A PLAN, and only that. It lifts an access restriction
 *             on a household in arrears, which is a decision about who gets
 *             through the gate, not data entry. `approve` is the verb this
 *             system reserves for acts that cannot be walked back by editing
 *             the row afterwards (D-013), and a guest admitted last night is
 *             exactly that.
 *
 * NOTHING HERE POSTS A JOURNAL ENTRY. See `Collections`.
 */
class CollectionsController extends Controller
{
    /** Place on payment plan — board community-admin-07. */
    public function plan(Request $request, Unit $unit, Collections $collections): Response
    {
        return inertia('Estate/Dues/PaymentPlan', [
            'estate' => ['name' => (string) tenant()->name],
            ...$collections->planBoard($unit),
            'notices' => $collections->noticesFor($unit),
            'canCreate' => $request->user()->can('estate.dues_ledger.create'),
            'canApprove' => $request->user()->can('estate.dues_ledger.approve'),

            /*
             * Board 7's sub-copy, verbatim, and it is a promise the code keeps:
             * `Collections::isProtected` is what the automated ladder and the
             * gate both read, so an active plan silences dunning and lifts the
             * restriction from the same fact.
             */
            'note' => 'This pauses all automated dunning reminders for this unit while the plan is active.',
            'approveReason' => 'Activating a plan lifts the arrears restriction on this household\'s guest '.
                'passes. That is an approval, not data entry, and it needs Dues & ledger approve access.',
        ]);
    }

    /** Draw the schedule up. A draft, and nothing at a gate has changed yet. */
    public function generate(Request $request, Unit $unit, Collections $collections): RedirectResponse
    {
        $data = $request->validate([
            'instalments' => ['required', 'integer', 'min:1', 'max:36'],
            'starts_on' => ['required', 'date'],
            'terms' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $plan = $collections->draft(
                unit: $unit,
                instalments: (int) $data['instalments'],
                startsOn: $data['starts_on'],
                terms: $data['terms'] ?? null,
            );
        } catch (DomainException $refused) {
            return back()->withErrors(['instalments' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->planPath($unit))
            ->with('success', 'Plan '.$plan->reference.' drafted. It does nothing until the household agrees to it.');
    }

    /** Record that the household agreed, and who said so. */
    public function agree(Request $request, PaymentPlan $plan, Collections $collections): RedirectResponse
    {
        $data = $request->validate([
            'agreed_by_name' => ['required', 'string', 'max:160'],
        ]);

        try {
            $collections->agree($plan, $data['agreed_by_name']);
        } catch (DomainException $refused) {
            return back()->withErrors(['agreed_by_name' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->planPath($plan->unit))
            ->with('success', 'Agreement recorded for '.$plan->reference.'.');
    }

    /**
     * Activate it — and lift the restriction.
     *
     * The one route in this controller behind `approve`. A refusal here is a
     * refusal with a reason attached: the household has not agreed, or agreed
     * to something other than what is being activated, and the message says
     * which rather than failing the form silently.
     */
    public function activate(Request $request, PaymentPlan $plan, Collections $collections): RedirectResponse
    {
        $data = $request->validate([
            'agreed_by_name' => ['required', 'string', 'max:160'],
        ]);

        try {
            $collections->activate($plan, $data['agreed_by_name'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['agreed_by_name' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->planPath($plan->unit))
            ->with('success', 'Plan '.$plan->reference.' is active. Dunning is paused and the arrears '.
                'restriction is lifted while it is being met.');
    }

    /** Dunning & reminders — board community-admin-08. */
    public function dunning(Request $request, Collections $collections): Response
    {
        return inertia('Estate/Dues/Dunning', [
            'estate' => ['name' => (string) tenant()->name],
            ...$collections->dunningBoard(),
            'canSend' => $request->user()->can('estate.dues_ledger.create'),
            'reasons' => [
                'newTemplate' => 'Not built yet — a new dunning stage is an escalation step with legal '.
                    'weight, and needs a wording review before it needs a button.',
                'saveTemplate' => 'Not built yet — editing a template changes what the NEXT notice says and '.
                    'nothing about the ones already sent, which is the guarantee this log carries. The '.
                    'editor lands with board 8\'s write path.',
            ],
        ]);
    }

    /**
     * Send one notice, and log it as sent.
     *
     * The template is resolved to a row and handed to the service, which
     * renders it against the unit NOW. Nothing downstream re-renders: the log's
     * whole purpose is that a dispute can be settled from what the resident was
     * actually told.
     */
    public function send(Request $request, Collections $collections): RedirectResponse
    {
        $data = $request->validate([
            'unit' => ['required', 'integer'],
            'template' => ['required', 'integer'],
        ]);

        $unit = Unit::findOrFail($data['unit']);
        $template = DunningTemplate::findOrFail($data['template']);

        $notice = $collections->send($unit, $template, $request->user());

        return redirect()
            ->to($this->path('/finance/dunning'))
            ->with('success', $notice->template_label.' logged for '.$unit->reference.'.');
    }

    /** Where board 7 lives for this unit. */
    private function planPath(Unit $unit): string
    {
        return $this->path('/finance/units/'.$unit->id.'/payment-plan');
    }

    /**
     * A console path in whichever shape this environment serves.
     *
     * Production gives each estate its own hostname; local serves them all from
     * one host with the estate in the path. See routes/tenant.php.
     */
    private function path(string $path): string
    {
        return app()->isLocal()
            ? '/estate/'.tenant()->getTenantKey().$path
            : $path;
    }
}
