<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per estate, holding that estate's configurable thresholds.
 *
 * Estate database only, so each community's settings are physically separate
 * and one estate cannot read or change another's.
 *
 * @property int $id
 * @property int $arrears_restriction_days
 * @property int $arrears_notice_days
 * @property bool $arrears_restriction_enabled
 * @property bool $amenity_arrears_block_enabled
 * @property int $amenity_arrears_block_days
 * @property int $governance_arrears_days
 * @property bool $governance_tenure_check_enabled
 * @property int $governance_min_tenure_months
 * @property bool $meeting_notice_enforced
 * @property int $agm_notice_days
 * @property int $egm_notice_days
 * @property int $meeting_notice_days
 * @property int $meeting_quorum_percent
 */
class EstateSetting extends Model
{
    /** The client's defaults (D-024), applied to a new estate. */
    public const DEFAULT_RESTRICTION_DAYS = 90;

    public const DEFAULT_NOTICE_DAYS = 14;

    /**
     * Whether arrears block an amenity booking. OFF unless an estate says so.
     *
     * The Build Spec: "A household in arrears may be blocked from booking, and
     * that is a configurable estate rule rather than a platform default." Off is
     * the safe direction — switched on in error it stops a household holding a
     * birthday party, and left off in error the estate bills a booking fee it
     * was going to bill anyway.
     */
    public const DEFAULT_AMENITY_BLOCK_ENABLED = false;

    /**
     * How far into arrears a member may be and still stand for the committee.
     *
     * THE SAME 90 DAYS THE GATE USES, AND DELIBERATELY A SEPARATE FIGURE. One
     * decides whether a household's visitors get through a gate on a Friday
     * night (D-024); this decides whether a member may stand for the committee
     * that holds the estate's chequebook. They agree today by coincidence, and
     * an estate that softened one must not silently soften the other.
     */
    public const DEFAULT_GOVERNANCE_ARREARS_DAYS = 90;

    /**
     * Statutory notice periods — ASSUMPTION Q-010.
     *
     * The AGM figure is the LONGER of the two Jamaican readings, 21 days under
     * the Companies Act rather than 14 under the Registration (Strata Titles)
     * Act, and enforcement is on by default. Refusing a meeting that could
     * lawfully have been called costs the estate a week; publishing one that
     * could not costs it the meeting and every decision taken at it.
     */
    public const DEFAULT_AGM_NOTICE_DAYS = 21;

    public const DEFAULT_EGM_NOTICE_DAYS = 14;

    public const DEFAULT_MEETING_NOTICE_DAYS = 7;

    /** Board 12's "25% of eligible households". */
    public const DEFAULT_MEETING_QUORUM_PERCENT = 25;

    protected $fillable = [
        'arrears_restriction_days',
        'arrears_notice_days',
        'arrears_restriction_enabled',
        'amenity_arrears_block_enabled',
        'amenity_arrears_block_days',
        'governance_arrears_days',
        'governance_tenure_check_enabled',
        'governance_min_tenure_months',
        'meeting_notice_enforced',
        'agm_notice_days',
        'egm_notice_days',
        'meeting_notice_days',
        'meeting_quorum_percent',
    ];

    protected function casts(): array
    {
        return [
            'arrears_restriction_days' => 'integer',
            'arrears_notice_days' => 'integer',
            'arrears_restriction_enabled' => 'boolean',
            'amenity_arrears_block_enabled' => 'boolean',
            'amenity_arrears_block_days' => 'integer',
            'governance_arrears_days' => 'integer',
            'governance_tenure_check_enabled' => 'boolean',
            'governance_min_tenure_months' => 'integer',
            'meeting_notice_enforced' => 'boolean',
            'agm_notice_days' => 'integer',
            'egm_notice_days' => 'integer',
            'meeting_notice_days' => 'integer',
            'meeting_quorum_percent' => 'integer',
        ];
    }

    /**
     * How many days' notice a meeting of this type needs before publication.
     *
     * An unknown type takes the general figure rather than zero. A type nobody
     * has taught this method about is not a type with no notice period — it is
     * one whose period nobody has stated, and the safe reading of silence is the
     * estate's general rule rather than none at all.
     */
    public function noticeDaysFor(string $type): int
    {
        return match ($type) {
            'agm' => $this->agm_notice_days,
            'egm' => $this->egm_notice_days,
            default => $this->meeting_notice_days,
        };
    }

    /**
     * This estate's settings, creating the defaults on first read.
     *
     * Returns a real row rather than a null-object, so a caller reading
     * `arrears_notice_days` always gets a number and never has to decide what
     * a missing setting means.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'arrears_restriction_days' => self::DEFAULT_RESTRICTION_DAYS,
            'arrears_notice_days' => self::DEFAULT_NOTICE_DAYS,
            'arrears_restriction_enabled' => true,

            /*
             * ASSUMPTION Q-008: how far into arrears the amenity block bites is
             * defaulted to the same threshold as the gate restriction, so an
             * estate that switches it on can never turn a household away from
             * the Gazebo while still admitting its visitors at the gate.
             */
            'amenity_arrears_block_enabled' => self::DEFAULT_AMENITY_BLOCK_ENABLED,
            'amenity_arrears_block_days' => self::DEFAULT_RESTRICTION_DAYS,
        ]);
    }
}
