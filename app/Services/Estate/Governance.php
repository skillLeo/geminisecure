<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Ballot;
use App\Models\Estate\BallotOption;
use App\Models\Estate\BallotPosition;
use App\Models\Estate\BallotReceipt;
use App\Models\Estate\EstateSetting;
use App\Models\Estate\Meeting;
use App\Models\Estate\MeetingAttendance;
use App\Models\Estate\Nomination;
use App\Models\Estate\Unit;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Elections, nominations, certification and meetings — boards 9, 10, 11, 12, 36.
 *
 * THE ONE DOOR. Every write in this module goes through a method here, and that
 * matters more than it usually does: `castVote()` is the only code in the
 * application that touches `ballot_marks`, and it is the reason there is no
 * `BallotMark` model to touch it with. A second path to those rows is a second
 * chance to write a column onto them.
 *
 * WHAT THIS CLASS WILL NOT DO, EVER
 * =================================
 *
 * It will not return a mark. `castVote()` returns void — not the mark ids, not a
 * receipt token, not a confirmation object with a choice in it. Anything handed
 * back to a caller can be logged, and a log line pairing a session with a mark
 * is the breach the schema was shaped to prevent.
 *
 * It will not answer "how did Lot 47 vote". There is no method for it, and there
 * is no query that could implement one: `ballot_receipts` and `ballot_marks`
 * share no column. `EstateGovernanceTest` asserts that before it asserts
 * anything else.
 *
 * It will not decertify. The Build Spec: "Certification is irreversible. A
 * certified ballot cannot be reopened or edited." Every transition below asks
 * `isCertified()` first, and nothing anywhere writes null back to that column. A
 * certified result that turns out to be wrong is corrected by running another
 * ballot, exactly as a posted journal is corrected by another entry.
 *
 * NOTHING HERE STORES A TALLY, A TURNOUT OR A QUORUM. Every figure boards 9, 11
 * and 36 draw is counted from rows at the moment the screen is drawn — marks per
 * option, receipts per ballot, attendance per meeting. The one thing that IS
 * snapshotted is the denominator, because board 11 prints "318 of 450 households
 * (71%)" as a certified fact and a unit added next March must not restate it.
 *
 * ELIGIBILITY IS THE ESTATE'S OWN RULE. Board 10's rejection reads "arrears >90
 * days" and D-024's 90 days is estate-configurable; the tenure rule beside it is
 * ASSUMPTION Q-011 and is off until an estate states one. Both are read from
 * `estate_settings` and both are SNAPSHOTTED onto the nomination when the check
 * runs, because a candidate who clears their arrears next week must not be able
 * to make the record say they were never in arrears.
 */
class Governance
{
    /**
     * The transitions a ballot is allowed to make, and only these.
     *
     * A map rather than a chain of ifs, so the whole lifecycle is legible in one
     * place and a stage nobody has thought about is unreachable rather than
     * accidentally permitted. `certified` appears as a destination and never as
     * a source — that is what irreversibility looks like in a table.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        Ballot::DRAFT => [Ballot::NOMINATIONS_OPEN],
        Ballot::NOMINATIONS_OPEN => [Ballot::NOMINATIONS_CLOSED],
        Ballot::NOMINATIONS_CLOSED => [Ballot::CANDIDATE_VETTING],
        Ballot::CANDIDATE_VETTING => [Ballot::CAMPAIGN_PERIOD, Ballot::VOTING_OPEN],
        Ballot::CAMPAIGN_PERIOD => [Ballot::VOTING_OPEN],
        Ballot::VOTING_OPEN => [Ballot::VOTING_CLOSED],
        Ballot::VOTING_CLOSED => [Ballot::TALLY],
        Ballot::TALLY => [Ballot::CERTIFIED],
        Ballot::CERTIFIED => [],
    ];

    /** MySQL's SQLSTATE for a unique-constraint violation. */
    private const DUPLICATE_KEY = '23000';

    public function __construct(private readonly Dues $dues) {}

    /* ------------------------------------------------------------------ */
    /* the lifecycle — board 9's stepper, one step at a time */
    /* ------------------------------------------------------------------ */

    /** Put a drafted ballot into nominations. */
    public function openNominations(Ballot $ballot, Carbon|string|null $closesOn = null): Ballot
    {
        $this->transition($ballot, Ballot::NOMINATIONS_OPEN);

        $ballot->forceFill([
            'nominations_open_at' => now(),
            'nominations_close_at' => $closesOn === null
                ? $ballot->nominations_close_at
                : $this->asMoment($closesOn),
        ])->save();

        return $ballot;
    }

    /**
     * Stop taking nominations — board 9's "Close nominations early".
     *
     * The close date is rewritten to now rather than left standing, because the
     * screen prints "3 days until nominations close" off that column and an
     * estate that closed early would otherwise keep advertising a window it had
     * already shut.
     */
    public function closeNominations(Ballot $ballot): Ballot
    {
        $this->transition($ballot, Ballot::NOMINATIONS_CLOSED);

        $ballot->forceFill(['nominations_close_at' => now()])->save();

        return $ballot;
    }

    /**
     * Open the poll.
     *
     * THIS IS WHERE THE DENOMINATOR FREEZES. Until now `eligible_households` has
     * been kept current — board 9 prints it while nominations are open and an
     * estate that adds a unit that week should see 451. From this moment it is
     * the denominator of a turnout figure that will end up on a certificate, and
     * it stops moving.
     */
    public function open(Ballot $ballot, Carbon|string|null $closesAt = null): Ballot
    {
        $this->transition($ballot, Ballot::VOTING_OPEN);

        $ballot->forceFill([
            'opens_at' => now(),
            'closes_at' => $closesAt === null ? $ballot->closes_at : $this->asMoment($closesAt),
            'eligible_households' => $this->eligibleHouseholds(),
        ])->save();

        return $ballot;
    }

    /**
     * Close the poll and move straight to the tally.
     *
     * Two stages in one act, and board 11's own row-label says why: "Stage
     * advances to 'Tally' once voting closes". There is nothing a human does
     * between shutting the poll and counting it, and a stage nobody can act on
     * is a stage a screen would sit in looking broken.
     */
    public function close(Ballot $ballot): Ballot
    {
        $this->transition($ballot, Ballot::VOTING_CLOSED);
        $ballot->forceFill(['closes_at' => now()])->save();

        $this->transition($ballot, Ballot::TALLY);
        $ballot->save();

        return $ballot;
    }

    /**
     * Give the poll longer.
     *
     * IT MAY ONLY EVER MOVE FORWARD. Shortening a window disenfranchises every
     * household that had not voted yet and was relying on the date it was told —
     * which is not an extension, it is an early close, and an early close is a
     * different decision with a different button. A returning officer who wants
     * one calls `close()` and it goes on the record as such.
     */
    public function extend(Ballot $ballot, Carbon|string $closesAt): Ballot
    {
        $this->refuseIfCertified($ballot, 'extended');

        if ($ballot->stage !== Ballot::VOTING_OPEN) {
            throw new DomainException(
                'Only a poll that is open can be extended. '.$ballot->title.' is at "'.$ballot->stageLabel().
                '", and re-opening a closed poll would let people vote after the result was visible.'
            );
        }

        $new = $this->asMoment($closesAt);

        if ($ballot->closes_at !== null && $new->lessThanOrEqualTo($ballot->closes_at)) {
            throw new DomainException(
                'An extension can only move the closing time later. Bringing it forward would take the '.
                'vote away from every household relying on the date they were given; if the poll should '.
                'stop now, close it — that is a decision the record should show as one.'
            );
        }

        $ballot->forceFill(['closes_at' => $new])->save();

        return $ballot;
    }

    /**
     * Certify the result. THE IRREVERSIBLE ACT.
     *
     * Needs the `approve` verb, which the Secretary does not hold on Governance
     * — the estate matrix gives them `Full` and gives `Full · Approver` to the
     * President and Vice President. That is not an oversight in the matrix: it is
     * exactly what D-013 separated the verb for, so the officer who runs the
     * election is not also the officer who declares it final.
     *
     * The outcome statement is written here and nowhere else, because board 11
     * calls it the estate's formal record and a record that could be edited
     * afterwards is a draft.
     */
    public function certify(Ballot $ballot, User $by, ?string $outcome = null): Ballot
    {
        $this->refuseIfCertified($ballot, 'certified again');

        if ($ballot->stage !== Ballot::TALLY) {
            throw new DomainException(
                $ballot->title.' is at "'.$ballot->stageLabel().'" and cannot be certified. A result is '.
                'certified from the tally of a closed poll; certifying anything earlier would declare a '.
                'winner while votes were still being cast.'
            );
        }

        return DB::connection('tenant')->transaction(function () use ($ballot, $by, $outcome): Ballot {
            $ballot->forceFill([
                'stage' => Ballot::CERTIFIED,
                'certified_at' => now(),
                'certified_by' => $by->getKey(),
                'certified_by_name' => $by->name,
                'outcome_statement' => $outcome ?? $this->outcomeStatement($ballot),
            ])->save();

            return $ballot;
        });
    }

    /**
     * Publish the certified result estate-wide.
     *
     * A SECOND ACT, not a side effect of the first. Board 11's button says
     * "Certify & publish results" and the two are still separate underneath: a
     * ballot can be certified as the estate's record on the night and published
     * to residents when the returning officer has drafted the announcement.
     * Folding them together would make the announcement irreversible too.
     */
    public function publish(Ballot $ballot): Ballot
    {
        if (! $ballot->isCertified()) {
            throw new DomainException(
                $ballot->title.' has not been certified, so there is no result to publish. Publishing an '.
                'uncertified tally would tell 450 households a figure the returning officer has not stood '.
                'behind.'
            );
        }

        if ($ballot->published_at === null) {
            $ballot->forceFill(['published_at' => now()])->save();
        }

        return $ballot;
    }

    /* ------------------------------------------------------------------ */
    /* the vote itself */
    /* ------------------------------------------------------------------ */

    /**
     * Cast one household's paper.
     *
     * RETURNS VOID, AND THAT IS PART OF THE GUARANTEE. There is nothing to hand
     * back that would not pair this household with its marks — not the mark ids,
     * not a count, not a receipt token. A caller that wants to know the vote went
     * through asks `hasVoted()`, which answers about turnout and nothing else.
     *
     * TWO WRITES IN ONE TRANSACTION, AND THAT IS DELIBERATE. Turnout has to be
     * exactly provable, so a crash between the receipt and the marks must leave
     * neither. What the transaction cannot do is hide the correlation from
     * somebody watching the writes happen — nothing can — and it does not need
     * to: the invariant is about what the STORED ROWS allow, and once these two
     * statements have committed there is no column on either side that pairs
     * them, no ordinal, and no clock.
     *
     * @param  list<int>  $optionIds  what the household marked, across every position on the paper
     *
     * @throws DomainException when the poll is shut, the paper is wrong, or the household has already voted
     */
    public function castVote(Ballot $ballot, Unit $unit, array $optionIds): void
    {
        if (! $ballot->isOpenForVoting()) {
            throw new DomainException(
                'The poll for '.$ballot->title.' is not open. It is at "'.$ballot->stageLabel().'"'.
                ($ballot->closes_at === null ? '' : ' and closed on '.$ballot->closes_at->format('M j, Y g:i A')).'.'
            );
        }

        $options = $this->validatePaper($ballot, $optionIds);

        if ($this->hasVoted($ballot, $unit)) {
            throw new DomainException(
                $unit->reference.' has already voted in '.$ballot->title.'. One household, one paper — and '.
                'the vote already cast cannot be shown to anyone, changed or taken back, which is the '.
                'whole point of it.'
            );
        }

        try {
            DB::connection('tenant')->transaction(function () use ($ballot, $unit, $options): void {
                BallotReceipt::create([
                    'ballot_id' => $ballot->id,
                    'unit_id' => $unit->id,
                    'voted_on' => Carbon::today()->toDateString(),
                ]);

                $marks = [];

                foreach ($options as $option) {
                    $marks[] = [
                        /*
                         * 128 CRYPTOGRAPHIC BITS, not an auto-increment and not
                         * a UUIDv7 — a v7 carries a timestamp in its high bits,
                         * which would put the cast order back into the table by
                         * the front door.
                         */
                        'mark_id' => bin2hex(random_bytes(16)),
                        'ballot_option_id' => $option->id,
                    ];
                }

                /*
                 * The insert order within one paper is the only thing about this
                 * batch that is not already random, so it is thrown away too.
                 * It buys little on its own — there is no column that could
                 * record the order anyway — and it costs nothing, which is the
                 * right trade on the one write in this application that has to
                 * be unpickable.
                 */
                shuffle($marks);

                DB::connection('tenant')->table('ballot_marks')->insert($marks);
            });
        } catch (QueryException $clash) {
            /*
             * The unique index on (ballot_id, unit_id) firing after the check
             * above passed. Two tabs open at once is an ordinary thing for a
             * person to do, and the database is what actually settles it — the
             * check exists to give a sentence rather than a stack trace, not to
             * be the guard.
             */
            if ($clash->getCode() === self::DUPLICATE_KEY) {
                throw new DomainException(
                    $unit->reference.' has already voted in '.$ballot->title.'. Two papers arrived at once '.
                    'and the second was refused.'
                );
            }

            throw $clash;
        }
    }

    /** Whether this household's paper is already in — turnout, not choice. */
    public function hasVoted(Ballot $ballot, Unit $unit): bool
    {
        return BallotReceipt::query()
            ->where('ballot_id', $ballot->id)
            ->where('unit_id', $unit->id)
            ->exists();
    }

    /**
     * Board 11's headline: "318 of 450 households (71%)".
     *
     * The numerator is `COUNT(*)` over the receipts and the denominator is the
     * snapshot on the ballot. Nothing about the marks is consulted, and it could
     * not be — in a three-seat race a voter makes three marks, so counting marks
     * would put turnout above 100%.
     *
     * @return array{cast: int, eligible: int, percent: int, label: string}
     */
    public function turnout(Ballot $ballot): array
    {
        $cast = BallotReceipt::query()->where('ballot_id', $ballot->id)->count();
        $eligible = $ballot->eligible_households > 0 ? $ballot->eligible_households : $this->eligibleHouseholds();
        $percent = $eligible === 0 ? 0 : (int) round($cast * 100 / $eligible);

        return [
            'cast' => $cast,
            'eligible' => $eligible,
            'percent' => $percent,
            'label' => $cast.' of '.$eligible.' households ('.$percent.'%)',
        ];
    }

    /**
     * Board 11's five phase bars.
     *
     * EACH PHASE IS ITS OWN FRACTION and not a share of the whole — the board's
     * own note says the five percentages are not an average of the 71%. So the
     * denominator is that phase's unit count, which is why the receipts are
     * joined to `units`: the phase of a voter is a property of the property, and
     * duplicating it onto the receipt would be a second source of truth about
     * where a household lives.
     *
     * @return list<array{phase: string, cast: int, eligible: int, percent: int, caption: string}>
     */
    public function turnoutByPhase(Ballot $ballot): array
    {
        $eligible = DB::connection('tenant')
            ->table('units')
            ->selectRaw('block, COUNT(*) as total')
            ->groupBy('block')
            ->pluck('total', 'block');

        $cast = DB::connection('tenant')
            ->table('ballot_receipts')
            ->join('units', 'units.id', '=', 'ballot_receipts.unit_id')
            ->where('ballot_receipts.ballot_id', $ballot->id)
            ->selectRaw('units.block as block, COUNT(*) as total')
            ->groupBy('units.block')
            ->pluck('total', 'block');

        $rows = [];

        foreach ($eligible as $phase => $total) {
            $phase = (string) $phase;
            $voted = (int) ($cast[$phase] ?? 0);
            $percent = (int) $total === 0 ? 0 : (int) round($voted * 100 / (int) $total);

            $rows[] = [
                'phase' => $phase,
                'cast' => $voted,
                'eligible' => (int) $total,
                'percent' => $percent,
                'caption' => $phase.' · '.$percent.'%',
            ];
        }

        return $rows;
    }

    /**
     * How many marks each option carries.
     *
     * A LEFT JOIN, so an option nobody voted for comes back as zero rather than
     * missing. A results screen that silently dropped the candidate who got no
     * votes would be the one screen where an absence looks like an oversight.
     *
     * @return array<int, int> option id => marks
     */
    public function tally(Ballot $ballot): array
    {
        $rows = DB::connection('tenant')
            ->table('ballot_options')
            ->leftJoin('ballot_marks', 'ballot_marks.ballot_option_id', '=', 'ballot_options.id')
            ->where('ballot_options.ballot_id', $ballot->id)
            ->groupBy('ballot_options.id')
            ->selectRaw('ballot_options.id as option_id, COUNT(ballot_marks.mark_id) as votes')
            ->get();

        $tally = [];

        foreach ($rows as $row) {
            $tally[(int) $row->option_id] = (int) $row->votes;
        }

        return $tally;
    }

    /**
     * Whether the poll cleared its quorum rule.
     *
     * Counted from the receipts against the ballot's own snapshot, and rounded
     * UP on the requirement — a quorum of "25% of 450" is 112.5 households, and
     * declaring a ballot quorate on 112 is a result somebody can challenge.
     *
     * @return array{required: int, cast: int, percent: int, met: bool}
     */
    public function quorum(Ballot $ballot): array
    {
        $turnout = $this->turnout($ballot);
        $required = $ballot->quorumHouseholds();

        return [
            'required' => $required,
            'cast' => $turnout['cast'],
            'percent' => $ballot->quorum_percent,
            'met' => $turnout['cast'] >= $required,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* nominations — board 10 */
    /* ------------------------------------------------------------------ */

    /**
     * Accept a candidate onto the paper.
     *
     * The eligibility snapshot is written even on an acceptance. A record that
     * only kept the arithmetic behind refusals could not answer the more awkward
     * question — why this candidate was let through — and that is the one a
     * losing candidate asks.
     */
    public function accept(Nomination $nomination, User $by): Nomination
    {
        $this->refuseIfCertified($nomination->ballot, 'changed');

        return $this->decide($nomination, Nomination::APPROVED, null, $by);
    }

    /**
     * Refuse one, WITH A REASON. There is no other kind of refusal.
     *
     * "Rejection always carries a recorded reason" — the Build Spec. The reason
     * is what board 10 prints inside the badge and what a candidate is entitled
     * to be told, so an empty one is refused here rather than stored as an empty
     * string that a screen then renders as "Rejected — ".
     */
    public function reject(Nomination $nomination, string $reason, User $by): Nomination
    {
        $this->refuseIfCertified($nomination->ballot, 'changed');

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(
                'Record why '.$nomination->candidate_name.' is being refused. A rejection with no reason on '.
                'it cannot be explained to the member it is about, and the estate has to be able to explain '.
                'it — this is a decision about who may stand for office in their own community.'
            );
        }

        return $this->decide($nomination, Nomination::REJECTED, $reason, $by);
    }

    /**
     * Ask for more before deciding.
     *
     * A STATE OF ITS OWN, not a rejection with a softer word. A candidate
     * waiting on a proposer's signature has not been refused, and folding this
     * into `rejected` would put a refusal on a member's permanent record for a
     * missing form. It can still become either of the other two.
     */
    public function requestInformation(Nomination $nomination, string $reason, User $by): Nomination
    {
        $this->refuseIfCertified($nomination->ballot, 'changed');

        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(
                'Say what is missing. "More information requested" with nothing after it leaves the '.
                'candidate unable to supply it and the officer unable to remember what they asked for.'
            );
        }

        return $this->decide($nomination, Nomination::MORE_INFO, $reason, $by);
    }

    /**
     * Whether a household may stand, under the estate's own rules.
     *
     * TWO CHECKS, BOTH THE ESTATE'S. Arrears is the one board 10 draws —
     * "Rejected — arrears >90 days" — and the threshold is a setting rather than
     * a constant. Tenure is named by the Build Spec, has no figure on any board,
     * and is therefore off by default: see ASSUMPTION Q-011 on the settings row.
     *
     * A UNIT WITH NO TENURE ON RECORD IS NOT DISQUALIFIED. Nothing in this
     * system yet records when a household moved in, and an unknown tenure is not
     * a short one — refusing a member on a fact nobody has captured would
     * disenfranchise them for the estate's own missing paperwork.
     *
     * @return array{eligible: bool, reason: string|null, checked_on: string, arrears_bucket: string, arrears_minor: int, tenure_months: int|null}
     */
    public function eligibility(Unit $unit, ?int $tenureMonths = null, ?Carbon $asAt = null): array
    {
        $settings = EstateSetting::current();
        $today = $asAt?->copy() ?? Carbon::today();

        $balance = $this->dues->balanceOf($unit, $today)->getMinorAmount()->toInt();
        $bucket = $this->dues->unitBuckets($today)[$unit->id] ?? 'current';

        $oldest = $this->dues->oldestOpenChargeDate($unit, $today);
        $daysOverdue = $oldest === null ? 0 : (int) $oldest->diffInDays($today, absolute: false);

        $reason = null;

        if ($balance > 0 && $daysOverdue >= $settings->governance_arrears_days) {
            // The board's own wording, built from the estate's own threshold so
            // an estate that moves it to 60 gets a reason that says 60.
            $reason = 'arrears >'.$settings->governance_arrears_days.' days';
        }

        if ($reason === null
            && $settings->governance_tenure_check_enabled
            && $tenureMonths !== null
            && $tenureMonths < $settings->governance_min_tenure_months) {
            $reason = 'tenure under '.$settings->governance_min_tenure_months.' months';
        }

        return [
            'eligible' => $reason === null,
            'reason' => $reason,
            'checked_on' => $today->toDateString(),
            'arrears_bucket' => $bucket,
            'arrears_minor' => $balance,
            'tenure_months' => $tenureMonths,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* meetings — boards 12 and 36 */
    /* ------------------------------------------------------------------ */

    /**
     * Draft a meeting. WRITES A DRAFT AND NOTHING ELSE.
     *
     * The notice period is not checked here, and deliberately: a secretary
     * sketching an AGM for a fortnight's time has done nothing wrong, and a form
     * that refused to save would lose the agenda they had just typed. The
     * refusal belongs at publication, which is when households are actually told.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{start_time?: string|null, text: string, fiscal_year?: string|null, motion_reference?: string|null}>  $agenda
     */
    public function scheduleMeeting(array $attributes, array $agenda = [], ?User $by = null): Meeting
    {
        $settings = EstateSetting::current();

        return DB::connection('tenant')->transaction(function () use ($attributes, $agenda, $by, $settings): Meeting {
            $meeting = Meeting::create([
                'quorum_percent' => $settings->meeting_quorum_percent,
                'quorum_basis' => Meeting::HOUSEHOLDS,

                /*
                 * The denominator, snapshotted at drafting for the same reason a
                 * ballot's is: a quorum determination recorded in July's minutes
                 * must not move because a unit was added in September.
                 */
                'eligible_households' => $this->eligibleHouseholds(),
                'status' => Meeting::DRAFT,
                'created_by' => $by?->getKey(),
                'created_by_name' => $by?->name,
                ...$attributes,
            ]);

            foreach ($agenda as $index => $item) {
                $meeting->agenda()->create([
                    'start_time' => $item['start_time'] ?? null,
                    'text' => $item['text'],
                    'sort_order' => $index + 1,
                    'fiscal_year' => $item['fiscal_year'] ?? null,
                    'motion_reference' => $item['motion_reference'] ?? null,
                ]);
            }

            return $meeting;
        });
    }

    /**
     * Publish it, and REFUSE IF IT IS INSIDE THE NOTICE PERIOD.
     *
     * The Build Spec, board 12: "Statutory notice periods for an AGM are
     * validated before publication, and the system refuses to publish a meeting
     * inside the required period." This is that refusal, and the period is the
     * estate's own — 21 days for an AGM by default, which is ASSUMPTION Q-010
     * and the longer of the two Jamaican readings.
     *
     * The required figure is COPIED ONTO THE MEETING rather than left to be
     * looked up later. The setting is editable, and the question a member asks
     * two years on is whether THIS meeting was properly convened — which is a
     * question about the rule in force on the day it was published.
     */
    public function publishMeeting(Meeting $meeting, ?User $by = null): Meeting
    {
        if ($meeting->published_at !== null) {
            return $meeting;
        }

        $settings = EstateSetting::current();
        $required = $settings->noticeDaysFor($meeting->type);

        if ($settings->meeting_notice_enforced) {
            $earliest = Carbon::now()->addDays($required);

            if ($meeting->starts_at->lessThan($earliest)) {
                throw new DomainException(sprintf(
                    '%s needs %d days\' notice and is on %s, which is inside the period. Publishing it now '.
                    'would convene a meeting that could be challenged, and every decision taken at it with '.
                    'it. The earliest date that can be published today is %s.',
                    $meeting->typeLabel(),
                    $required,
                    $meeting->starts_at->format('M j, Y'),
                    $earliest->format('M j, Y'),
                ));
            }
        }

        $meeting->forceFill([
            'status' => Meeting::SCHEDULED,
            'notice_days_required' => $required,
            'published_at' => now(),
            'created_by' => $meeting->created_by ?? $by?->getKey(),
            'created_by_name' => $meeting->created_by_name ?? $by?->name,
        ])->save();

        return $meeting;
    }

    /**
     * Board 36's quorum badge, counted from the register.
     *
     * TWO MEASURES IN ONE COLUMN, which is why `quorum_basis` exists. A
     * committee's is a head count of its members and reads "6/7"; a general
     * meeting's is a proportion of the estate and reads "41%". Both are counts
     * over `meeting_attendance` — nothing stores whether quorum was met, because
     * a stored flag disagreeing with the register would be the estate's minutes
     * arguing with its own attendance sheet.
     *
     * @return array{present: int, required: int, percent: int, met: bool, label: string, state: string}
     */
    public function quorumOf(Meeting $meeting): array
    {
        $present = MeetingAttendance::query()
            ->where('meeting_id', $meeting->id)
            ->where('state', MeetingAttendance::PRESENT)
            ->count();

        $required = $meeting->quorumRequired();
        $eligible = $meeting->quorum_basis === Meeting::MEMBERS
            ? ($meeting->quorum_required_total ?? 0)
            : $meeting->eligible_households;

        $percent = $eligible === 0 ? 0 : (int) round($present * 100 / $eligible);
        $met = $required > 0 && $present >= $required;

        /*
         * An upcoming meeting has not failed its quorum, it has not tried yet —
         * board 36 draws that as amber "Not yet met" rather than as a failure.
         */
        $state = match (true) {
            $meeting->isUpcoming() => 'upcoming',
            $met => 'done',
            default => 'failed',
        };

        $measure = $meeting->quorum_basis === Meeting::MEMBERS
            ? $present.'/'.$eligible
            : $percent.'%';

        return [
            'present' => $present,
            'required' => $required,
            'percent' => $percent,
            'met' => $met,
            'state' => $state,
            'label' => $state === 'upcoming' ? 'Not yet met' : ($met ? 'Quorum met · '.$measure : 'Quorum not met · '.$measure),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* what the screens read */
    /* ------------------------------------------------------------------ */

    /**
     * Everything board 9 draws.
     *
     * @return array<string, mixed>
     */
    public function controlRoom(int $year): array
    {
        $ballots = Ballot::query()->where('year', $year)->orderBy('code')->orderBy('id')->get();
        $lead = $this->leadBallot($ballots);

        $positions = BallotPosition::query()
            ->whereIn('ballot_id', $ballots->pluck('id'))
            ->withCount('nominations')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $nominated = Nomination::query()->whereIn('ballot_id', $ballots->pluck('id'))->count();
        $pending = Nomination::query()
            ->whereIn('ballot_id', $ballots->pluck('id'))
            ->where('status', Nomination::PENDING)
            ->count();

        $closesIn = $lead?->nominations_close_at === null
            ? null
            : max(0, (int) Carbon::today()->diffInDays($lead->nominations_close_at, absolute: false));

        return [
            'year' => $year,
            'heading' => $year.' Election',

            // "Ballot A (Community Executive) & Ballot B (Phase Leadership) ·
            // Returning Officer: Delroy Samuels", composed rather than stored —
            // it is three facts joined for one line of a header.
            'subtitle' => trim(implode(' · ', array_filter([
                $ballots->pluck('title')->implode(' & '),
                $lead?->returning_officer_name === null ? null : 'Returning Officer: '.$lead->returning_officer_name,
            ]))),
            'stage' => $lead === null ? Ballot::DRAFT : $lead->stage,
            'stage_label' => $lead?->stageLabel() ?? Ballot::STAGES[Ballot::DRAFT],
            'stepper' => $this->stepper($lead),
            'stats' => [
                ['value' => (string) $positions->count(), 'label' => 'POSITIONS CONFIGURED'],
                ['value' => (string) $nominated, 'label' => 'CANDIDATES NOMINATED'],
                [
                    // "3 days". Null renders as an em dash rather than "0 days",
                    // because a ballot with no nomination window set has not
                    // closed today — nobody has said when it closes.
                    'value' => $closesIn === null ? '—' : $closesIn.' day'.($closesIn === 1 ? '' : 's'),
                    'label' => 'UNTIL NOMINATIONS CLOSE',
                ],
                ['value' => (string) ($lead?->eligible_households ?: $this->eligibleHouseholds()), 'label' => 'ELIGIBLE HOUSEHOLDS'],
            ],
            'positions' => $positions->map(static fn (BallotPosition $position): array => [
                'id' => $position->id,
                'name' => $position->displayName(),
                'seat_label' => $position->seatLabel(),
                'scope' => $position->scope,
                'phase' => $position->phase,
                'nominated' => (int) ($position->nominations_count ?? 0),
                'nominated_label' => (int) ($position->nominations_count ?? 0).' nominated',
            ])->all(),
            'pending' => $pending,
            'ballotId' => $lead?->id,
            'canCloseEarly' => $lead !== null && $lead->isOpenForNominations(),
            'certified' => $lead?->isCertified() ?? false,
        ];
    }

    /**
     * Everything board 10 draws.
     *
     * @return array<string, mixed>
     */
    public function nominationsBoard(int $year, string $position = ''): array
    {
        $ballotIds = Ballot::query()->where('year', $year)->pluck('id');

        $nominations = Nomination::query()
            ->whereIn('ballot_id', $ballotIds)
            ->with(['position', 'unit'])
            ->get()

            /*
             * Board 10: "rows grouped by position sought (Chairman rows first,
             * then Vice Chairman)", then oldest nomination first within a seat.
             *
             * TWO-ARGUMENT COMPARATORS, NOT KEY EXTRACTORS. A callable handed to
             * `sortBy([...])` is called as `$fn($a, $b)` and its return value IS
             * the comparison — Laravel only treats the argument as a key when it
             * is a string. A one-argument closure here silently returned the
             * first row's sort order as the verdict, which put the whole board in
             * an order nobody chose. See D-052.
             */
            ->sortBy([
                static fn (Nomination $a, Nomination $b): int => $a->position->sort_order <=> $b->position->sort_order,
                static fn (Nomination $a, Nomination $b): int => $a->id <=> $b->id,
            ]);

        $rows = [];

        foreach ($nominations as $nomination) {
            $name = $nomination->position->displayName();

            if ($position !== '' && $name !== $position) {
                continue;
            }

            $rows[] = [
                'id' => $nomination->id,
                'initials' => $nomination->initials(),
                'name' => $nomination->candidate_name,

                /*
                 * "Phase 1 · Lot 12", read off the UNIT and not off the
                 * nomination. One estate has one map, and a lot that showed a
                 * different phase here from the one the arrears board gives it
                 * would be two estates.
                 */
                'sub' => trim(($nomination->unit->block ?? '').' · '.($nomination->unit->reference ?? ''), ' ·'),
                'position' => $name,
                'nominator' => $nomination->nominator_name,
                'seconder' => $nomination->seconder_name,
                'status' => $nomination->status,
                'status_label' => $nomination->statusLabel(),
                'reason' => $nomination->decision_reason,

                // Board 10's row actions are status-driven: a pending row gets
                // the tick and the cross, a decided one gets a "View" link.
                'decidable' => $nomination->status === Nomination::PENDING,
            ];
        }

        return [
            'year' => $year,
            'filter' => $position,
            'positions' => $nominations
                ->map(static fn (Nomination $n): string => $n->position->displayName())
                ->unique()
                ->values()
                ->all(),

            // The counter chip spans the UNFILTERED set — board 10 draws "5
            // pending review" beside a page slice holding two of them.
            'pending' => $nominations->where('status', Nomination::PENDING)->count(),
            'rows' => $rows,
        ];
    }

    /**
     * Everything board 11 draws.
     *
     * @return array<string, mixed>
     */
    public function resultsBoard(int $year): array
    {
        $ballots = Ballot::query()->where('year', $year)->orderBy('code')->orderBy('id')->get();
        $ballot = $this->leadBallot($ballots, furthest: true);

        if ($ballot === null) {
            return ['year' => $year, 'ballot' => null, 'turnout' => null, 'phases' => [], 'cards' => []];
        }

        $tally = $this->tally($ballot);
        $turnout = $this->turnout($ballot);

        $cards = [];

        foreach ($ballot->positions()->with('options')->get() as $position) {
            $options = $position->options
                ->map(static fn (BallotOption $option): array => [
                    'id' => $option->id,
                    'initials' => $option->initials(),
                    'name' => $option->label,
                    'phase' => $option->phase,
                    'votes' => 0,
                ])
                ->all();

            foreach ($options as $i => $row) {
                $options[$i]['votes'] = $tally[$row['id']] ?? 0;
            }

            usort($options, static fn (array $a, array $b): int => $b['votes'] <=> $a['votes']);

            foreach ($options as $i => $row) {
                /*
                 * THE DENOMINATOR IS BALLOTS CAST, NOT VOTES CAST, and board 11
                 * says so: in a three-seat race the shares deliberately sum to
                 * more than 100 because one voter marks three names. Dividing by
                 * total votes would make a three-seat race look like a
                 * three-way split of one seat.
                 */
                $options[$i]['percent'] = $turnout['cast'] === 0
                    ? 0
                    : (int) round($row['votes'] * 100 / $turnout['cast']);

                $options[$i]['is_winner'] = $i < $position->seat_count && $row['votes'] > 0;
            }

            $cards[] = [
                'position' => $position->displayName(),

                // "Vice Chairman · 3 seats", and a bare "Chairman" for one seat.
                'heading' => $position->seat_count > 1
                    ? $position->displayName().' · '.$position->seat_count.' seats'
                    : $position->displayName(),
                'seat_count' => $position->seat_count,
                'percent_basis' => 'ballots_cast',
                'rows' => $options,
            ];
        }

        return [
            'year' => $year,
            'ballot' => [
                'id' => $ballot->id,
                'title' => $ballot->title,
                'stage' => $ballot->stage,
                'stage_label' => $ballot->stageLabel(),
                'certified' => $ballot->isCertified(),
                'certified_at' => $ballot->certified_at?->format('M j, Y g:i A'),
                'certified_by' => $ballot->certified_by_name,
                'returning_officer' => $ballot->returning_officer_name,
                'outcome' => $ballot->outcome_statement,
                'published' => $ballot->published_at !== null,
            ],
            'turnout' => $turnout,
            'quorum' => $this->quorum($ballot),
            'phases' => $this->turnoutByPhase($ballot),
            'cards' => $cards,
        ];
    }

    /**
     * Everything board 36 draws.
     *
     * @return array<string, mixed>
     */
    public function meetingsBoard(): array
    {
        $meetings = Meeting::query()->with('minutes')->get();

        $rows = $meetings
            ->sortBy([
                /*
                 * Upcoming soonest-first, then past newest-first — board 36's
                 * order, and the only one a register reads sensibly in: the next
                 * thing a resident has to turn up to, then the history behind it.
                 *
                 * TWO-ARGUMENT COMPARATORS, NOT KEY EXTRACTORS, for the reason
                 * spelled out on `nominationsBoard()` above: `sortBy([...])`
                 * calls a callable as `$fn($a, $b)` and takes what it returns as
                 * the verdict. By the time the second one runs the first has
                 * already settled that both rows are in the same group, so it is
                 * free to reverse itself for the held ones.
                 */
                static fn (Meeting $a, Meeting $b): int => ($a->isUpcoming() ? 0 : 1) <=> ($b->isUpcoming() ? 0 : 1),
                static fn (Meeting $a, Meeting $b): int => $a->isUpcoming()
                    ? $a->starts_at->getTimestamp() <=> $b->starts_at->getTimestamp()
                    : $b->starts_at->getTimestamp() <=> $a->starts_at->getTimestamp(),
            ])
            ->map(function (Meeting $meeting): array {
                $quorum = $this->quorumOf($meeting);

                return [
                    'id' => $meeting->id,
                    'title' => $meeting->title,
                    'type' => $meeting->type,
                    'type_label' => $meeting->typeLabel(),
                    'date' => $meeting->dateLabel(),
                    'audience' => $meeting->audienceLabel(),
                    'quorum_label' => $quorum['label'],
                    'quorum_state' => $quorum['state'],

                    // Board 36's row action: an agenda before, minutes after.
                    'action' => $meeting->minutes === null ? 'View agenda' : 'View minutes',
                    'has_minutes' => $meeting->minutes !== null,
                    'published' => $meeting->published_at !== null,
                ];
            })
            ->values()
            ->all();

        return ['rows' => $rows];
    }

    /**
     * The blank form board 12 draws, and what it must refuse.
     *
     * Board 12 is `/governance/meetings/new` — it draws a draft nobody has
     * saved, so these are DEFAULTS and not a stored meeting. The earliest
     * publishable date for each type is returned with them, because a form that
     * only learns about the notice period when it is refused has wasted the
     * secretary's afternoon.
     *
     * @return array<string, mixed>
     */
    public function schedulerDefaults(): array
    {
        $settings = EstateSetting::current();
        $phases = Unit::query()->distinct()->orderBy('block')->pluck('block')->filter()->values();
        $year = Carbon::today()->year;

        return [
            'types' => array_map(
                static fn (string $key): array => ['value' => $key, 'label' => Meeting::TYPES[$key]],
                array_keys(Meeting::TYPES),
            ),
            'phases' => $phases->all(),
            'defaults' => [
                'type' => Meeting::AGM,
                'title' => 'Annual General Meeting '.$year,
                'audience_scope' => Meeting::WHOLE_ESTATE,
                'audience_label' => 'Whole estate — all '.$phases->count().' phases',
                'quorum_percent' => $settings->meeting_quorum_percent,
                'quorum_label' => $settings->meeting_quorum_percent.'% of eligible households',
                'recording_enabled' => true,
                'recording_consent_notice' => true,
                'recording_label' => 'Enabled, with consent notice',
            ],
            'eligibleHouseholds' => $this->eligibleHouseholds(),

            /*
             * What the form has to honour, by type. Surfaced rather than left
             * implicit so the date field can refuse before the submit does — and
             * so the screen can say WHY, which is the part a secretary needs.
             */
            'notice' => [
                'enforced' => $settings->meeting_notice_enforced,
                'days' => [
                    Meeting::AGM => $settings->agm_notice_days,
                    Meeting::EGM => $settings->egm_notice_days,
                    Meeting::COMMITTEE => $settings->meeting_notice_days,
                    Meeting::PHASE => $settings->meeting_notice_days,
                ],
                'earliest' => [
                    Meeting::AGM => Carbon::today()->addDays($settings->agm_notice_days)->toDateString(),
                    Meeting::EGM => Carbon::today()->addDays($settings->egm_notice_days)->toDateString(),
                    Meeting::COMMITTEE => Carbon::today()->addDays($settings->meeting_notice_days)->toDateString(),
                    Meeting::PHASE => Carbon::today()->addDays($settings->meeting_notice_days)->toDateString(),
                ],
            ],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /**
     * Move a ballot one step, or refuse.
     *
     * SETS THE STAGE AND DOES NOT SAVE. Every caller has its own columns to
     * write alongside — a closing time, a snapshot, a certification — and two
     * saves where one would do is a window in which a ballot is half moved.
     */
    private function transition(Ballot $ballot, string $to): void
    {
        $this->refuseIfCertified($ballot, 'moved on');

        if (! in_array($to, self::TRANSITIONS[$ballot->stage] ?? [], true)) {
            throw new DomainException(sprintf(
                '%s is at "%s" and cannot move to "%s". An election runs in one direction and skipping a '.
                'stage would leave a decision nobody made — %s.',
                $ballot->title,
                $ballot->stageLabel(),
                Ballot::STAGES[$to] ?? $to,
                self::TRANSITIONS[$ballot->stage] === []
                    ? 'this is the end of it'
                    : 'the only step from here is "'.Ballot::STAGES[self::TRANSITIONS[$ballot->stage][0]].'"',
            ));
        }

        $ballot->stage = $to;
    }

    /** The one sentence certification says to everything that comes after it. */
    private function refuseIfCertified(Ballot $ballot, string $verb): void
    {
        if (! $ballot->isCertified()) {
            return;
        }

        throw new DomainException(sprintf(
            '%s was certified on %s by %s and cannot be %s. Certification is irreversible — a certified '.
            'result that turns out to be wrong is put right by running another ballot, not by reopening '.
            'this one.',
            $ballot->title,
            $ballot->certified_at?->format('M j, Y') ?? 'an unrecorded date',
            $ballot->certified_by_name ?? 'the returning officer',
            $verb,
        ));
    }

    /**
     * Check the paper before a single row is written.
     *
     * @param  list<int>  $optionIds
     * @return Collection<int, BallotOption>
     */
    private function validatePaper(Ballot $ballot, array $optionIds): mixed
    {
        $ids = array_values(array_unique(array_map('intval', $optionIds)));

        if ($ids === []) {
            throw new DomainException(
                'A blank paper is not a vote. If the household means to abstain, that is an option on the '.
                'paper and it is marked like any other — an empty submission would be indistinguishable '.
                'from a household that never voted, and turnout would be wrong.'
            );
        }

        $options = BallotOption::query()
            ->where('ballot_id', $ballot->id)
            ->whereIn('id', $ids)
            ->with('position')
            ->get();

        if ($options->count() !== count($ids)) {
            throw new DomainException(
                'This paper carries a choice that is not on '.$ballot->title.'. A mark against an option '.
                'from another ballot would be counted into the wrong result.'
            );
        }

        foreach ($options->groupBy('ballot_position_id') as $group) {
            $seats = $group->first()?->position->seat_count ?? 1;

            if ($group->count() > $seats) {
                throw new DomainException(sprintf(
                    '%s has %d seat%s and this paper marks %d candidates for it. Over-marking spoils a '.
                    'paper, and the household has to be told before it is cast rather than after.',
                    $group->first()?->position->displayName() ?? 'That position',
                    $seats,
                    $seats === 1 ? '' : 's',
                    $group->count(),
                ));
            }
        }

        return $options;
    }

    /** Write the decision and the snapshot behind it, in one go. */
    private function decide(Nomination $nomination, string $status, ?string $reason, User $by): Nomination
    {
        $unit = $nomination->unit;
        $check = $unit === null ? null : $this->eligibility($unit);

        $nomination->forceFill([
            'status' => $status,
            'decision_reason' => $reason,
            'decided_at' => now(),
            'decided_by' => $by->getKey(),
            'decided_by_name' => $by->name,
            'eligibility_checked_on' => $check['checked_on'] ?? null,
            'arrears_bucket_at_check' => $check['arrears_bucket'] ?? null,
            'arrears_minor_at_check' => $check['arrears_minor'] ?? null,
            'tenure_months_at_check' => $check['tenure_months'] ?? null,
        ])->save();

        return $nomination;
    }

    /**
     * Board 9's nine-step stepper, with each step's state.
     *
     * @return list<array{key: string, label: string, state: string}>
     */
    private function stepper(?Ballot $ballot): array
    {
        $current = $ballot?->stageSequence() ?? 1;
        $steps = [];
        $position = 0;

        foreach (Ballot::STAGES as $key => $label) {
            $position++;

            $steps[] = [
                'key' => $key,
                'label' => $label,
                'state' => match (true) {
                    $position < $current => 'done',
                    $position === $current => 'current',
                    default => 'upcoming',
                },
            ];
        }

        return $steps;
    }

    /**
     * Which of a year's ballots a screen speaks for.
     *
     * Board 9 draws ONE lifecycle badge over an election that may have two
     * papers, so something has to choose. The control room takes the LEAST
     * advanced — an election is not at "Voting Open" while one of its papers is
     * still taking nominations — and the results screen takes the most advanced,
     * because that is the one with a tally on it.
     *
     * @param  Collection<int, Ballot>  $ballots
     */
    private function leadBallot(mixed $ballots, bool $furthest = false): ?Ballot
    {
        if ($ballots->isEmpty()) {
            return null;
        }

        $sorted = $ballots->sortBy(static fn (Ballot $b): int => $b->stageSequence());

        return $furthest ? $sorted->last() : $sorted->first();
    }

    /**
     * How many households may vote.
     *
     * EVERY UNIT, INCLUDING THE VACANT ONES, and boards 9 and 11 both say 450
     * against an estate with 441 occupied. That is the same rule the dues follow:
     * the entitlement attaches to the property, not to whoever is living in it
     * this year, and an owner who lets their lot stand empty has not forfeited
     * their vote on the estate's affairs.
     */
    private function eligibleHouseholds(): int
    {
        return Unit::query()->count();
    }

    /**
     * The sentence board 11 calls the outcome statement, when nobody writes one.
     *
     * Composed from the tally rather than left blank, because a certificate with
     * an empty outcome is a certificate of nothing. A returning officer who wants
     * their own words passes them in and this is never used.
     */
    private function outcomeStatement(Ballot $ballot): string
    {
        $turnout = $this->turnout($ballot);
        $quorum = $this->quorum($ballot);
        $tally = $this->tally($ballot);

        $winners = [];

        foreach ($ballot->positions()->with('options')->get() as $position) {
            $ranked = $position->options
                ->sortByDesc(static fn (BallotOption $option): int => $tally[$option->id] ?? 0)
                ->take($position->seat_count)
                ->filter(static fn (BallotOption $option): bool => ($tally[$option->id] ?? 0) > 0);

            foreach ($ranked as $option) {
                $winners[] = $option->label.' ('.$position->displayName().')';
            }
        }

        return sprintf(
            'Turnout %s. Quorum of %d households %s. Elected: %s.',
            $turnout['label'],
            $quorum['required'],
            $quorum['met'] ? 'met' : 'NOT met',
            $winners === [] ? 'no seat filled' : implode('; ', $winners),
        );
    }

    private function asMoment(Carbon|string $value): Carbon
    {
        return $value instanceof Carbon ? $value->copy() : Carbon::parse($value);
    }
}
