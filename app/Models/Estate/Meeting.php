<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A meeting of the community — boards 12 and 36.
 *
 * PUBLISHING IS THE ACT THE NOTICE PERIOD GUARDS. The Build Spec, board 12:
 * "Statutory notice periods for an AGM are validated before publication, and the
 * system refuses to publish a meeting inside the required period." Drafting one
 * inside the period is fine — a secretary sketching next month's AGM has done
 * nothing wrong — and `Governance::publishMeeting()` is where the refusal lives,
 * because publication is the moment households are told and the clock starts.
 *
 * `notice_days_required` IS COPIED, NOT LOOKED UP. The estate's setting is
 * editable, and the question a member asks two years later is whether THIS
 * meeting was properly convened. That is a question about the rule in force on
 * the day it was published, so the day's rule is written onto the row.
 *
 * QUORUM IS COUNTED, NEVER STORED. Board 36 prints two different measures in one
 * column — "Quorum met · 6/7" for a committee and "Quorum met · 41%" for an
 * estate-wide meeting — and both are counts over `meeting_attendance` against
 * this row's denominator. A stored `quorum_met` boolean sitting beside a register
 * that disagreed with it would be the estate's minutes arguing with the estate's
 * own attendance sheet.
 *
 * `quorum_basis` IS WHY THERE ARE TWO MEASURES. A committee's quorum is a head
 * count of its members; a general meeting's is a proportion of the estate, and
 * "Quorum counts households, not individuals" — the Build Spec, board 36. One
 * column holding both would have to guess which it was looking at.
 *
 * @property int $id
 * @property string $type
 * @property string $title
 * @property Carbon $starts_at
 * @property string|null $venue
 * @property string|null $virtual_link
 * @property string $audience_scope
 * @property string|null $phase
 * @property int $quorum_percent
 * @property string $quorum_basis
 * @property int|null $quorum_required_total
 * @property int $eligible_households
 * @property bool $recording_enabled
 * @property bool $recording_consent_notice
 * @property string $status
 * @property int|null $notice_days_required
 * @property Carbon|null $published_at
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, MeetingAgendaItem> $agenda
 * @property-read Collection<int, MeetingAttendance> $attendance
 * @property-read MeetingMinutes|null $minutes
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Meeting query()
 *
 * @mixin \Eloquent
 */
class Meeting extends Model
{
    public const AGM = 'agm';

    public const EGM = 'egm';

    public const COMMITTEE = 'committee';

    public const PHASE = 'phase';

    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    public const HELD = 'held';

    public const CANCELLED = 'cancelled';

    public const HOUSEHOLDS = 'households';

    public const MEMBERS = 'members';

    public const WHOLE_ESTATE = 'whole_estate';

    public const PHASE_SUBSET = 'phase';

    public const COMMITTEE_ONLY = 'committee';

    /** Board 12's segmented control, in the order it draws them. */
    public const TYPES = [
        self::AGM => 'AGM',
        self::EGM => 'EGM',
        self::COMMITTEE => 'Committee',
        self::PHASE => 'Phase meeting',
    ];

    /** The uppercase form board 12's preview eyebrow prints. */
    public const TYPE_EYEBROWS = [
        self::AGM => 'ANNUAL GENERAL MEETING',
        self::EGM => 'EXTRAORDINARY GENERAL MEETING',
        self::COMMITTEE => 'COMMITTEE MEETING',
        self::PHASE => 'PHASE MEETING',
    ];

    protected $fillable = [
        'type',
        'title',
        'starts_at',
        'venue',
        'virtual_link',
        'audience_scope',
        'phase',
        'quorum_percent',
        'quorum_basis',
        'quorum_required_total',
        'eligible_households',
        'recording_enabled',
        'recording_consent_notice',
        'status',
        'notice_days_required',
        'published_at',
        'created_by',
        'created_by_name',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'quorum_percent' => 'integer',
            'quorum_required_total' => 'integer',
            'eligible_households' => 'integer',
            'recording_enabled' => 'boolean',
            'recording_consent_notice' => 'boolean',
            'notice_days_required' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Whether the meeting is still ahead — board 36 keys two things off this. */
    public function isUpcoming(?Carbon $asAt = null): bool
    {
        return $this->starts_at->greaterThan($asAt?->copy() ?? Carbon::now());
    }

    /**
     * Board 36's Date column, which has two formats and a rule between them.
     *
     * An upcoming meeting shows the weekday and the time, because that is what a
     * resident needs in order to turn up. A meeting already held shows the date
     * alone — the time it started stopped mattering the moment it ended.
     */
    public function dateLabel(?Carbon $asAt = null): string
    {
        return $this->isUpcoming($asAt)
            ? $this->starts_at->format('D, M j').' · '.$this->starts_at->format('g:i A')
            : $this->starts_at->format('M j, Y');
    }

    /** Board 36's Audience column: "Whole estate", "Phase 2 only", "Committee members". */
    public function audienceLabel(): string
    {
        return match ($this->audience_scope) {
            self::PHASE_SUBSET => ($this->phase ?? 'Phase').' only',
            self::COMMITTEE_ONLY => 'Committee members',
            default => 'Whole estate',
        };
    }

    /**
     * How many households or members the quorum rule needs.
     *
     * Rounded UP. A quorum of "25% of 450" is 112.5 households, and 112 is below
     * a quarter — a meeting that declared itself quorate on the strength of a
     * rounding-down is one whose decisions can be challenged.
     *
     * ONE RULE, TWO DENOMINATORS. `quorum_percent` is the rule in both cases,
     * which is why there is one of it; `quorum_basis` says only what it is a
     * percentage OF — this committee's seven members, or the estate's 450
     * households. This used to return `quorum_required_total` unchanged for a
     * committee, which made the requirement equal to the committee's whole
     * membership: board 36's "Quorum met · 6/7" was then unreachable, because
     * six of seven present would be one short of a quorum of seven and the same
     * column was being read as both the numerator's denominator and the bar to
     * clear. See D-052.
     */
    public function quorumRequired(): int
    {
        $basis = $this->quorum_basis === self::MEMBERS
            ? ($this->quorum_required_total ?? 0)
            : $this->eligible_households;

        return (int) ceil($basis * $this->quorum_percent / 100);
    }

    /** @return HasMany<MeetingAgendaItem, $this> */
    public function agenda(): HasMany
    {
        return $this->hasMany(MeetingAgendaItem::class)->orderBy('sort_order');
    }

    /** @return HasMany<MeetingAttendance, $this> */
    public function attendance(): HasMany
    {
        return $this->hasMany(MeetingAttendance::class);
    }

    /** @return HasOne<MeetingMinutes, $this> */
    public function minutes(): HasOne
    {
        return $this->hasOne(MeetingMinutes::class);
    }
}
