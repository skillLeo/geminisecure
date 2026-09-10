<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Ballot;
use App\Models\Estate\Meeting;
use App\Models\Estate\Nomination;
use App\Services\Estate\Governance;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Elections, nominations, certification and meetings — boards 9, 10, 11, 12, 36.
 *
 * NOT ONE FIGURE ON THESE SCREENS IS STORED. The tallies are counted from
 * `ballot_marks`, turnout from `ballot_receipts`, quorum from
 * `meeting_attendance`, and the nomination counts from `nominations`. A stored
 * tally would agree with the marks by coincidence, and on a ballot paper
 * coincidence and fraud are indistinguishable — which is why `EstateGovernanceTest`
 * re-counts every one of them in raw SQL.
 *
 * THERE IS NO ROUTE THAT CASTS A VOTE, and there will not be one here. Voting is
 * a resident act in the resident app; the console runs the election and never
 * marks a paper. `Governance::castVote()` exists, is exercised by the test suite
 * and the seeder, and returns void — so even if a console route were added, there
 * would be nothing for it to hand back that could pair a household with a choice.
 *
 * CERTIFYING AND PUBLISHING NEED `approve`, NOT `update`. Both are irreversible:
 * a certified ballot cannot be reopened, and a published meeting has already told
 * 450 households a date they will arrange their Saturday around. The estate matrix
 * gives `Full · Approver` on Governance to the President and Vice President and
 * plain `Full` to the Secretary — so the officer who RUNS an election is
 * deliberately not the officer who declares it final. That is exactly what D-013
 * separated the verb for, and board 11's copy addressing the Returning Officer is
 * a description of who reviews the tally, not of who holds the permission.
 *
 * The gates live on the routes. A controller that checked them again would be a
 * second place to keep in step with the matrix.
 */
class GovernanceController extends Controller
{
    /**
     * Why the controls on these screens that do nothing, do nothing.
     *
     * Each is a real act with a consequence outside the screen it is drawn on,
     * and each needs a document, a template or a table this phase has not built.
     */
    private const NO_MINUTES_EXPORT_YET = 'Not built yet — exported minutes are the estate\'s formal record of a meeting and leave the building as a file, so they need a template and a retention rule before they need a button.';

    private const NO_CERTIFICATE_YET = 'Not built yet — the certificate is a signed document a returning officer stands behind, and it needs a template and a signature block rather than a download link over a table.';

    private const NO_AGENDA_SCREEN_YET = 'Not built yet — an agenda is edited on the meeting itself, and the meeting detail screen is not in this phase.';

    private const NO_MINUTES_SCREEN_YET = 'Not built yet — minutes are drafted, adopted at the next meeting and then published, which is three states and its own screen.';

    /** Election control room — board community-admin-09. */
    public function controlRoom(Request $request, int $year, Governance $governance): Response
    {
        return inertia('Estate/Governance/ControlRoom', [
            'estate' => ['name' => (string) tenant()->name],
            ...$governance->controlRoom($year),
            'canRun' => $request->user()->can('estate.governance.update'),
            'canCertify' => $request->user()->can('estate.governance.approve'),
            'blockedReason' => 'Running an election — opening nominations, closing them, opening and extending the poll — needs Governance update access. You are able to read this screen.',
            'reasons' => [
                'minutes' => self::NO_MINUTES_EXPORT_YET,
            ],
        ]);
    }

    /** Nominations review — board community-admin-10. */
    public function nominations(Request $request, int $year, Governance $governance): Response
    {
        return inertia('Estate/Governance/Nominations', [
            'estate' => ['name' => (string) tenant()->name],
            ...$governance->nominationsBoard($year, $request->string('position')->toString()),
            'canDecide' => $request->user()->can('estate.governance.update'),
            'blockedReason' => 'Vetting a candidate decides who may stand for office and needs Governance update access. You are able to read this screen.',
        ]);
    }

    /** Results & certification — board community-admin-11. */
    public function results(Request $request, int $year, Governance $governance): Response
    {
        return inertia('Estate/Governance/Results', [
            'estate' => ['name' => (string) tenant()->name],
            ...$governance->resultsBoard($year),
            'canCertify' => $request->user()->can('estate.governance.approve'),

            /*
             * The sentence a Secretary sees where board 11 draws the certify
             * button. It is not a permission error — they hold Full on
             * Governance — so it says whose act it is rather than that they are
             * not allowed to be here.
             */
            'blockedReason' => 'Certification is the President or Vice President\'s act. You can run the election and read the tally; declaring the result final is deliberately a second pair of hands.',
            'reasons' => [
                'certificate' => self::NO_CERTIFICATE_YET,
            ],
        ]);
    }

    /** The meeting register — board community-admin-36. */
    public function meetings(Request $request, Governance $governance): Response
    {
        return inertia('Estate/Governance/Meetings', [
            'estate' => ['name' => (string) tenant()->name],
            ...$governance->meetingsBoard(),
            'canSchedule' => $request->user()->can('estate.governance.create'),
            'reasons' => [
                'agenda' => self::NO_AGENDA_SCREEN_YET,
                'minutes' => self::NO_MINUTES_SCREEN_YET,
            ],
        ]);
    }

    /** Schedule a meeting — board community-admin-12. */
    public function newMeeting(Request $request, Governance $governance): Response
    {
        return inertia('Estate/Governance/MeetingScheduler', [
            'estate' => ['name' => (string) tenant()->name],
            ...$governance->schedulerDefaults(),
            'canSchedule' => $request->user()->can('estate.governance.create'),
            'canPublish' => $request->user()->can('estate.governance.approve'),
            'blockedReason' => 'Scheduling a meeting needs Governance create access. You are able to read this screen.',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* the acts */
    /* ------------------------------------------------------------------ */

    /** Close nominations — board 9's "Close nominations early". */
    public function closeNominations(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        try {
            $governance->closeNominations($ballot);
        } catch (DomainException $refused) {
            return back()->withErrors(['stage' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$ballot->year))
            ->with('success', 'Nominations closed for '.$ballot->title.'.');
    }

    /** Open the poll. */
    public function openVoting(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'closes_at' => ['required', 'date'],
        ]);

        try {
            $governance->open($ballot, $data['closes_at']);
        } catch (DomainException $refused) {
            return back()->withErrors(['closes_at' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$ballot->year))
            ->with('success', 'Voting is open on '.$ballot->title.'.');
    }

    /** Shut the poll and move to the tally. */
    public function closeVoting(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        try {
            $governance->close($ballot);
        } catch (DomainException $refused) {
            return back()->withErrors(['stage' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$ballot->year.'/results'))
            ->with('success', 'Voting closed. The tally is ready to review.');
    }

    /** Give the poll longer — never less. */
    public function extendVoting(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'closes_at' => ['required', 'date'],
        ]);

        try {
            $governance->extend($ballot, $data['closes_at']);
        } catch (DomainException $refused) {
            return back()->withErrors(['closes_at' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$ballot->year))
            ->with('success', 'Voting on '.$ballot->title.' now closes '.$ballot->closes_at?->format('M j, Y g:i A').'.');
    }

    /**
     * Certify, and publish in the same request — board 11's one button.
     *
     * Two acts underneath, and the order matters: certification is what cannot
     * be undone, publication is what reaches residents. If publication ever
     * needs to be held back, the service already separates them and only this
     * method has to change.
     */
    public function certify(Request $request, Ballot $ballot, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'outcome' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $governance->certify($ballot, $request->user(), $data['outcome'] ?? null);
            $governance->publish($ballot);
        } catch (DomainException $refused) {
            return back()->withErrors(['outcome' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$ballot->year.'/results'))
            ->with('success', $ballot->title.' certified and published estate-wide.');
    }

    /** Accept a candidate onto the paper. */
    public function acceptNomination(Request $request, Nomination $nomination, Governance $governance): RedirectResponse
    {
        try {
            $governance->accept($nomination, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['nomination' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$nomination->ballot->year.'/nominations'))
            ->with('success', $nomination->candidate_name.' approved.');
    }

    /**
     * Refuse one, with a reason.
     *
     * The reason is `required` in the validator as well as in the service. The
     * service is the guarantee — it is the one door — and the validator is what
     * puts the message under the field the officer is looking at.
     */
    public function rejectNomination(Request $request, Nomination $nomination, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:190'],
        ]);

        try {
            $governance->reject($nomination, $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$nomination->ballot->year.'/nominations'))
            ->with('success', $nomination->candidate_name.' rejected — '.$data['reason'].'.');
    }

    /** Ask for more before deciding either way. */
    public function queryNomination(Request $request, Nomination $nomination, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:190'],
        ]);

        try {
            $governance->requestInformation($nomination, $data['reason'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['reason' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->to($this->path('/governance/elections/'.$nomination->ballot->year.'/nominations'))
            ->with('success', 'More information requested from '.$nomination->candidate_name.'.');
    }

    /** Draft a meeting — board 12's form. Publishing it is a separate act. */
    public function storeMeeting(Request $request, Governance $governance): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:agm,egm,committee,phase'],
            'title' => ['required', 'string', 'max:160'],
            'starts_at' => ['required', 'date'],
            'audience_scope' => ['required', 'string', 'in:whole_estate,phase,committee'],
            'phase' => ['nullable', 'string', 'max:32'],
            'venue' => ['nullable', 'string', 'max:190'],
            'recording_enabled' => ['boolean'],
            'recording_consent_notice' => ['boolean'],
            'agenda' => ['array'],
            'agenda.*.text' => ['required', 'string', 'max:200'],
            'agenda.*.start_time' => ['nullable', 'date_format:H:i'],
        ]);

        $meeting = $governance->scheduleMeeting(
            attributes: [
                'type' => $data['type'],
                'title' => $data['title'],
                'starts_at' => $data['starts_at'],
                'audience_scope' => $data['audience_scope'],
                'phase' => $data['phase'] ?? null,
                'venue' => $data['venue'] ?? null,
                'recording_enabled' => (bool) ($data['recording_enabled'] ?? false),
                'recording_consent_notice' => (bool) ($data['recording_consent_notice'] ?? false),
            ],
            agenda: array_values($data['agenda'] ?? []),
            by: $request->user(),
        );

        return redirect()
            ->to($this->path('/governance/meetings'))
            ->with('success', $meeting->title.' saved as a draft. Publish it to notify households.');
    }

    /**
     * Publish it — refused if it is inside the statutory notice period.
     *
     * `approve`, because publication is what tells 450 households a date they
     * will arrange their Saturday around, and because a meeting convened inside
     * its notice period can be challenged along with every decision taken at it.
     */
    public function publishMeeting(Request $request, Meeting $meeting, Governance $governance): RedirectResponse
    {
        try {
            $governance->publishMeeting($meeting, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['starts_at' => $refused->getMessage()]);
        }

        return redirect()
            ->to($this->path('/governance/meetings'))
            ->with('success', $meeting->title.' published to '.strtolower($meeting->audienceLabel()).'.');
    }

    /**
     * Where a screen lives, in whichever shape this environment serves.
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
