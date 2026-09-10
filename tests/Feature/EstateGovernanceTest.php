<?php

declare(strict_types=1);

use App\Models\Estate\EstateSetting;
use App\Models\Estate\Meeting;
use App\Models\Estate\Unit;
use App\Services\Estate\Governance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| The two governance rules nobody had asked the client about
|--------------------------------------------------------------------------
|
| THIS FILE EXISTS BECAUSE IT DID NOT. Governance is one of the four hard-stop
| categories in QUESTIONS.md — money, restriction, biometrics, voting — and it
| carried two live assumptions, Q-010 (statutory notice periods) and Q-011
| (candidate tenure), with no test file of any kind behind them. Both were found
| by auditing the `// ASSUMPTION Q-0xx` markers in the source against the
| question queue, and neither was in the queue either. See D-074.
|
| WHAT IS ASSERTED IS THE ASSUMPTION, NOT THE ANSWER. Nobody has ruled on either
| figure. So these tests pin what the platform does TODAY and, for each, the
| direction the default errs in — because that is the part a ruling changes and
| the part somebody has to be able to see before they change it.
|
| Q-010 · notice is ENFORCED by default and the AGM period is the LONGER of the
| two Jamaican readings, 21 days rather than 14. The asymmetry is the whole
| decision: refusing a meeting that could lawfully have been called costs the
| estate a week, and publishing one that could not costs it the meeting and every
| decision taken at it.
|
| Q-011 · tenure is NOT enforced by default and the threshold is zero. The harm
| runs one way here: an invented tenure rule disqualifies a member from standing
| for office in their own community, and no ballot can be un-closed.
|
| A ruling either way lands in this file, on the expectations rather than on the
| structure.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->governance = app(Governance::class);

    /*
     * The estate's own settings row, and its values restored in afterEach. The
     * estate database is built once per process and shared, so a test that
     * switched enforcement off and left it off would silently disarm the next
     * one — which is the failure mode of every shared fixture.
     */
    $this->settings = EstateSetting::current();
    $this->before = $this->settings->only([
        'meeting_notice_enforced',
        'agm_notice_days',
        'egm_notice_days',
        'meeting_notice_days',
        'governance_tenure_check_enabled',
        'governance_min_tenure_months',
    ]);
});

afterEach(function () {
    $this->settings->forceFill($this->before)->save();

    DB::connection('mysql')->rollBack();
});

/**
 * A unit that owes nothing, found rather than named.
 *
 * THE TENURE TESTS BELOW MUST NOT BE ABLE TO PASS FOR THE WRONG REASON, and
 * naming a lot was how they first did. `eligibility()` checks arrears BEFORE
 * tenure and returns the first refusal it finds, so a unit in arrears comes back
 * ineligible whatever its tenure — and these tests were reading "not eligible"
 * as the tenure rule biting when it was the arrears rule.
 *
 * The estate database is built once per process and is NOT rolled back between
 * tests; only the platform connection is. `EstateFacilitiesAmenitiesTest` posts
 * J$18,600 of 120-day arrears against Lot 3 to exercise the amenity block, and
 * leaves it there. Run alone this file was green; run after that one, three
 * tests failed — which is the good version of this bug, because the suite ran
 * both ways.
 *
 * So the unit is chosen at runtime by the condition that matters, and the
 * precondition is asserted rather than assumed. A future test that charges every
 * unit in the estate gets a named failure instead of a silent wrong pass.
 */
function unitOwingNothing(Governance $governance): Unit
{
    foreach (Unit::query()->orderBy('id')->get() as $unit) {
        if ($governance->eligibility($unit)['reason'] === null) {
            return $unit;
        }
    }

    throw new RuntimeException(
        'Every unit in the fixture estate is in arrears, so no tenure assertion here can mean '.
        'anything. A test that charges units is leaking into this file.'
    );
}

/** A draft meeting of a given type, that many days out. */
function draftMeeting(string $type, int $daysOut): Meeting
{
    return Meeting::create([
        'title' => 'Test meeting',
        'type' => $type,
        'status' => Meeting::DRAFT,
        'starts_at' => Carbon::now()->addDays($daysOut)->setTime(18, 0),
        'ends_at' => Carbon::now()->addDays($daysOut)->setTime(20, 0),
        'location' => 'Club House',
        'audience' => Meeting::WHOLE_ESTATE,
        'quorum_basis' => Meeting::HOUSEHOLDS,
    ]);
}

/* ------------------------------------------------------------------ */
/* Q-010 — statutory notice periods */
/* ------------------------------------------------------------------ */

it('ships the longer AGM notice period, not the shorter one', function () {
    /*
     * 21 days under the Companies Act rather than 14 under the Registration
     * (Strata Titles) Act. Both are defensible readings of Jamaican law and
     * nobody has told us which governs this estate, so the platform takes the
     * one that cannot invalidate a meeting.
     */
    expect(EstateSetting::DEFAULT_AGM_NOTICE_DAYS)->toBe(21)
        ->and(EstateSetting::DEFAULT_AGM_NOTICE_DAYS)->toBeGreaterThan(14);

    expect($this->settings->noticeDaysFor(Meeting::AGM))->toBe(21)
        ->and($this->settings->noticeDaysFor(Meeting::EGM))->toBe(14)
        ->and($this->settings->noticeDaysFor(Meeting::COMMITTEE))->toBe(7);
});

it('enforces the notice period by default rather than waiting to be switched on', function () {
    // The direction that matters. A platform that shipped this off would let an
    // estate publish an AGM for next Tuesday and find out at the AGM.
    expect($this->settings->meeting_notice_enforced)->toBeTrue();
});

it('refuses to publish an AGM inside its notice period, and says when it could be', function () {
    $meeting = draftMeeting(Meeting::AGM, 10);

    expect(fn () => $this->governance->publishMeeting($meeting))
        ->toThrow(DomainException::class);

    try {
        $this->governance->publishMeeting($meeting);
    } catch (DomainException $e) {
        $refusal = $e->getMessage();
    }

    // Not a bare refusal. It names the period, says the date is inside it, and
    // gives the earliest date that WOULD publish — an officer reading this can
    // act on it without going to find the rule.
    expect($refusal)->toContain('21 days')
        ->and($refusal)->toContain('inside the period')
        ->and($refusal)->toContain(Carbon::now()->addDays(21)->format('M j, Y'));

    // And it did not half-publish on the way out.
    expect($meeting->refresh()->published_at)->toBeNull()
        ->and($meeting->status)->toBe(Meeting::DRAFT);
});

it('publishes the same meeting once it is far enough out', function () {
    // The positive control. Without it this file would pass on an application
    // that refused to publish anything at all.
    $meeting = draftMeeting(Meeting::AGM, 30);

    $this->governance->publishMeeting($meeting);

    expect($meeting->refresh()->published_at)->not->toBeNull()
        ->and($meeting->status)->toBe(Meeting::SCHEDULED);
});

it('copies the notice period onto the meeting instead of looking it up later', function () {
    /*
     * The setting is editable. The question a member asks two years on is
     * whether THIS meeting was properly convened, which is a question about the
     * rule in force on the day it was published — so the figure is stamped on
     * the record and an estate that later moves to 14 cannot restate history.
     */
    $meeting = draftMeeting(Meeting::AGM, 30);

    $this->governance->publishMeeting($meeting);

    expect($meeting->refresh()->notice_days_required)->toBe(21);

    $this->settings->forceFill(['agm_notice_days' => 14])->save();

    expect($meeting->refresh()->notice_days_required)->toBe(21);
});

it('applies each type its own period rather than one figure for all three', function () {
    // An EGM 16 days out clears its 14 and would have failed an AGM's 21. If
    // these ever collapsed to a single figure this is the test that notices.
    $egm = draftMeeting(Meeting::EGM, 16);

    $this->governance->publishMeeting($egm);

    expect($egm->refresh()->notice_days_required)->toBe(14)
        ->and($egm->published_at)->not->toBeNull();

    $agm = draftMeeting(Meeting::AGM, 16);

    expect(fn () => $this->governance->publishMeeting($agm))
        ->toThrow(DomainException::class);
});

it('takes the general period for a type nobody has stated one for', function () {
    // Silence about a type is not a type with no notice period. `noticeDaysFor`
    // falls through to the estate's general rule rather than to zero, which is
    // the difference between an unstated rule and no rule.
    expect($this->settings->noticeDaysFor('extraordinary-something'))->toBe(7)
        ->and($this->settings->noticeDaysFor('extraordinary-something'))->toBeGreaterThan(0);
});

it('publishes anything at all once an estate turns enforcement off', function () {
    /*
     * The escape hatch, asserted so that a ruling which says "our instrument
     * says 14, and sometimes we convene at short notice by consent" has a
     * mechanism waiting rather than a code change.
     */
    $this->settings->forceFill(['meeting_notice_enforced' => false])->save();

    $meeting = draftMeeting(Meeting::AGM, 2);

    $this->governance->publishMeeting($meeting);

    expect($meeting->refresh()->published_at)->not->toBeNull();

    // The required figure is still recorded, even unenforced — so the record
    // says what the rule was, and that it was not applied.
    expect($meeting->notice_days_required)->toBe(21);
});

/* ------------------------------------------------------------------ */
/* Q-011 — candidate tenure */
/* ------------------------------------------------------------------ */

it('disqualifies nobody on tenure until an estate states a rule', function () {
    /*
     * THE ASSUMPTION, AND THE DIRECTION IT ERRS IN. There is no figure on any
     * board. An invented one stops a member standing for office in their own
     * community, and no ballot can be un-closed — so the check ships off and
     * the threshold is zero.
     */
    expect($this->settings->governance_tenure_check_enabled)->toBeFalse()
        ->and($this->settings->governance_min_tenure_months)->toBe(0);

    $unit = unitOwingNothing($this->governance);

    // One month in the estate, and eligible, because nobody has said otherwise.
    $result = $this->governance->eligibility($unit, tenureMonths: 1);

    expect($result['eligible'])->toBeTrue()
        ->and($result['reason'])->toBeNull();
});

it('bites once an estate does state one', function () {
    // The other half. A default that could not be switched on would be a
    // decision disguised as a default.
    $this->settings->forceFill([
        'governance_tenure_check_enabled' => true,
        'governance_min_tenure_months' => 12,
    ])->save();

    $unit = unitOwingNothing($this->governance);

    $short = $this->governance->eligibility($unit, tenureMonths: 6);
    $long = $this->governance->eligibility($unit, tenureMonths: 24);

    expect($short['eligible'])->toBeFalse()
        ->and($short['reason'])->toBe('tenure under 12 months');

    expect($long['eligible'])->toBeTrue()
        ->and($long['reason'])->toBeNull();
});

it('does not disqualify a member whose tenure nobody has recorded', function () {
    /*
     * Nothing on this platform yet records when a household moved in, so
     * `tenureMonths` arrives null for almost everybody. An unknown tenure is
     * NOT a short one: refusing a member on a fact the estate never captured
     * would disenfranchise them for its own missing paperwork.
     */
    $this->settings->forceFill([
        'governance_tenure_check_enabled' => true,
        'governance_min_tenure_months' => 12,
    ])->save();

    $unit = unitOwingNothing($this->governance);

    $result = $this->governance->eligibility($unit, tenureMonths: null);

    expect($result['eligible'])->toBeTrue()
        ->and($result['reason'])->toBeNull()
        ->and($result['tenure_months'])->toBeNull();
});

it('keeps the governance arrears threshold a separate figure from the gate one', function () {
    /*
     * They read 90 and 90 and they agree by COINCIDENCE. One decides whether a
     * household's visitors get through a gate on a Friday night (D-024); the
     * other decides whether a member may hold the estate's chequebook. An estate
     * that softens one must not silently soften the other, and the only thing
     * that guarantees it is that they are two columns.
     */
    expect(EstateSetting::DEFAULT_GOVERNANCE_ARREARS_DAYS)
        ->toBe(EstateSetting::DEFAULT_RESTRICTION_DAYS);

    $this->settings->forceFill(['governance_arrears_days' => 30])->save();

    expect(EstateSetting::current()->governance_arrears_days)->toBe(30)
        ->and(EstateSetting::current()->arrears_restriction_days)->toBe(90);

    $this->settings->forceFill(['governance_arrears_days' => 90])->save();
});

it('records what the rule was when the check ran, not what it is now', function () {
    /*
     * The eligibility answer carries the date it was checked on, because a
     * candidate who clears their arrears next week must not be able to make the
     * record say they were never in arrears. Board 10's rejection is a decision
     * taken on a day.
     */
    $unit = unitOwingNothing($this->governance);

    $result = $this->governance->eligibility($unit, tenureMonths: 36);

    expect($result['checked_on'])->toBe(Carbon::today()->toDateString())
        ->and($result)->toHaveKey('arrears_bucket')
        ->and($result)->toHaveKey('arrears_minor');
});
