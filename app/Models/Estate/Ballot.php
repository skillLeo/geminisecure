<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One ballot paper and its whole life — boards 9 and 11.
 *
 * THE CERTIFIABLE UNIT. An "election" is a year's worth of ballots rather than a
 * record of its own: board 9 draws one lifecycle and one stat row across "Ballot
 * A (Community Executive) & Ballot B (Phase Leadership)", and its URL is keyed
 * on the year. So the year groups and the ballot is what gets opened, closed,
 * certified and published.
 *
 * `certified_at` IS THE POINT OF NO RETURN. The Build Spec: "Certification is
 * irreversible. A certified ballot cannot be reopened or edited." Every
 * transition in `App\Services\Estate\Governance` asks `isCertified()` first, and
 * nothing anywhere writes null back to that column. There is no decertify method
 * because there is no decertify act — a certified result that turns out to be
 * wrong is corrected by running another ballot, exactly as a posted journal is
 * corrected by another entry.
 *
 * NOTHING HERE HOLDS A TALLY OR A TURNOUT. `eligible_households` is a
 * denominator and not a count of anything that happened; the numerator is
 * counted from `ballot_receipts` and the tallies from `ballot_marks` when a
 * screen is drawn. A stored tally would agree with the marks by coincidence, and
 * on a ballot paper coincidence and fraud look identical.
 *
 * THE DENOMINATOR IS SNAPSHOTTED, THOUGH, and that is the exception the two
 * paragraphs above have to be read together with. Board 11 prints "318 of 450
 * households (71%)" as a certified fact. Counting `units` at draw time would let
 * a unit added next March restate a turnout that was certified last September.
 * It is refreshed while nominations are still open and frozen when voting opens.
 *
 * @property int $id
 * @property int $year
 * @property string|null $code
 * @property string $title
 * @property string $kind
 * @property string|null $question
 * @property string|null $description
 * @property string $stage
 * @property Carbon|null $nominations_open_at
 * @property Carbon|null $nominations_close_at
 * @property Carbon|null $opens_at
 * @property Carbon|null $closes_at
 * @property int $quorum_percent
 * @property int $eligible_households
 * @property int|null $returning_officer_id
 * @property string|null $returning_officer_name
 * @property Carbon|null $certified_at
 * @property int|null $certified_by
 * @property string|null $certified_by_name
 * @property string|null $outcome_statement
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, BallotPosition> $positions
 * @property-read Collection<int, BallotOption> $options
 * @property-read Collection<int, Nomination> $nominations
 * @property-read Collection<int, BallotReceipt> $receipts
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ballot newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ballot newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Ballot query()
 *
 * @mixin \Eloquent
 */
class Ballot extends Model
{
    public const DRAFT = 'draft';

    public const NOMINATIONS_OPEN = 'nominations_open';

    public const NOMINATIONS_CLOSED = 'nominations_closed';

    public const CANDIDATE_VETTING = 'candidate_vetting';

    public const CAMPAIGN_PERIOD = 'campaign_period';

    public const VOTING_OPEN = 'voting_open';

    public const VOTING_CLOSED = 'voting_closed';

    public const TALLY = 'tally';

    public const CERTIFIED = 'certified';

    public const ELECTION = 'election';

    public const RESOLUTION = 'resolution';

    /**
     * Board 9's nine-step stepper, in order, with the labels it prints.
     *
     * NINE STAGES AND NOT EIGHT OR TEN. The board draws exactly these, in this
     * sequence, and the stepper's done/current/upcoming states are derived by
     * comparing a stage's position in this array against the ballot's own. That
     * is why the order lives here rather than in a Vue file: a screen that
     * decided the order would be a second opinion about what an election is.
     *
     * @var array<string, string>
     */
    public const STAGES = [
        self::DRAFT => 'Draft',
        self::NOMINATIONS_OPEN => 'Nominations Open',
        self::NOMINATIONS_CLOSED => 'Nominations Closed',
        self::CANDIDATE_VETTING => 'Candidate Vetting',
        self::CAMPAIGN_PERIOD => 'Campaign Period',
        self::VOTING_OPEN => 'Voting Open',
        self::VOTING_CLOSED => 'Voting Closed',
        self::TALLY => 'Tally',
        self::CERTIFIED => 'Certified & Published',
    ];

    protected $fillable = [
        'year',
        'code',
        'title',
        'kind',
        'question',
        'description',
        'stage',
        'nominations_open_at',
        'nominations_close_at',
        'opens_at',
        'closes_at',
        'quorum_percent',
        'eligible_households',
        'returning_officer_id',
        'returning_officer_name',
        'certified_at',
        'certified_by',
        'certified_by_name',
        'outcome_statement',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'nominations_open_at' => 'datetime',
            'nominations_close_at' => 'datetime',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'quorum_percent' => 'integer',
            'eligible_households' => 'integer',
            'certified_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * The one question every write in `Governance` asks first.
     *
     * Read off the timestamp rather than the stage, deliberately. The stage is a
     * string a future migration or a hand-run UPDATE could set to anything; the
     * certification timestamp is the fact, and it is the fact the certificate
     * itself is printed from.
     */
    public function isCertified(): bool
    {
        return $this->certified_at !== null;
    }

    /** Whether a mark may be cast right now — the stage AND the window. */
    public function isOpenForVoting(?Carbon $at = null): bool
    {
        $now = $at?->copy() ?? Carbon::now();

        return $this->stage === self::VOTING_OPEN
            && ! $this->isCertified()
            && ($this->opens_at === null || $this->opens_at->lessThanOrEqualTo($now))
            && ($this->closes_at === null || $this->closes_at->greaterThan($now));
    }

    /** Whether a nomination may still be lodged. */
    public function isOpenForNominations(): bool
    {
        return $this->stage === self::NOMINATIONS_OPEN && ! $this->isCertified();
    }

    /** Where this ballot sits in the nine, 1-based, for the stepper. */
    public function stageSequence(): int
    {
        $position = array_search($this->stage, array_keys(self::STAGES), true);

        // A stage nobody recognises reads as Draft rather than as a crash. The
        // stepper is a picture of progress and cannot be the thing that takes a
        // screen down, but the ballot has still not started as far as it knows.
        return $position === false ? 1 : $position + 1;
    }

    /** The label board 9 prints in its amber stage badge. */
    public function stageLabel(): string
    {
        return self::STAGES[$this->stage] ?? self::STAGES[self::DRAFT];
    }

    /**
     * How many households the quorum rule needs, as a head count.
     *
     * Rounded UP. A quorum of "25% of 450" is 112.5 households, and 112 is
     * below a quarter — a meeting or a ballot that declared itself quorate on
     * the strength of a rounding-down is one whose decisions can be challenged.
     */
    public function quorumHouseholds(): int
    {
        return (int) ceil($this->eligible_households * $this->quorum_percent / 100);
    }

    /** @return HasMany<BallotPosition, $this> */
    public function positions(): HasMany
    {
        return $this->hasMany(BallotPosition::class)->orderBy('sort_order');
    }

    /** @return HasMany<BallotOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(BallotOption::class)->orderBy('sort_order');
    }

    /** @return HasMany<Nomination, $this> */
    public function nominations(): HasMany
    {
        return $this->hasMany(Nomination::class);
    }

    /**
     * Who voted. NOT what they chose — there is no relation from here to
     * `ballot_marks` and there cannot be one, because the two tables share no
     * column. See the migration.
     *
     * @return HasMany<BallotReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(BallotReceipt::class);
    }
}
