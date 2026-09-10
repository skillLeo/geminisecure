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
 * @property string|null $enquiries_email
 * @property string|null $enquiries_phone
 * @property string $security_provider
 * @property string|null $logo_path
 * @property string $staff_payroll_routing
 * @property string $security_payroll_routing
 * @property bool $biometric_consent_enabled
 * @property string $payment_gateway_mode
 * @property bool $geofencing_enabled
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

    /**
     * Who guards the estate, until an estate says otherwise — board 21.
     *
     * A default rather than a constant. Every estate on this platform is
     * guarded by Gemini Security Limited today, and an estate that changed
     * provider would still be an estate: who stood on its gate is a fact about
     * the community, not about this platform's identity.
     */
    public const DEFAULT_SECURITY_PROVIDER = 'Gemini Security Limited';

    /** Board 23's two payroll routings — the same vocabulary, different answers. */
    public const IN_HOUSE = 'in_house';

    public const GEMINI_MANAGED = 'gemini_managed';

    /**
     * How a resident's payment reaches the estate. MANUAL, and D-023 says so.
     *
     * Card capture is behind the `PaymentGateway` interface, whose only
     * implementation is a null one. `manual` is the true state of every estate
     * on this platform, not a placeholder waiting to be changed.
     */
    public const PAYMENT_MANUAL = 'manual';

    public const PAYMENT_GATEWAY = 'gateway';

    /**
     * The three settings this module may READ and must never WRITE.
     *
     * Each is a hard-stop category — money, biometrics, restriction — with a
     * recorded ruling behind it, and each ships in its safest position:
     * biometric consent off (D-022), payments manual (D-023), geofencing off
     * and deferred (D-033). The columns exist so a screen can state the
     * decision rather than silently omit the feature; `App\Services\Estate\
     * Settings` refuses every attempt to change one from this console and names
     * the ruling in the refusal.
     *
     * @var list<string>
     */
    public const HELD_BACK = [
        'biometric_consent_enabled',
        'payment_gateway_mode',
        'geofencing_enabled',
    ];

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

        /*
         * Board 21's editable fields, and the two payroll routings behind board
         * 23's segmented controls.
         *
         * NONE OF `HELD_BACK` IS HERE, and that absence is load-bearing rather
         * than tidy. A settings form posts an array; a fillable
         * `biometric_consent_enabled` would let one extra key in that array
         * switch a legal-review-pending feature on, past every guard in the
         * service, without anybody typing the word "biometric". Laravel would
         * simply assign it. `EstateSettingsTest` asserts this list holds none of
         * them.
         */
        'enquiries_email',
        'enquiries_phone',
        'security_provider',
        'logo_path',
        'staff_payroll_routing',
        'security_payroll_routing',
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
            'biometric_consent_enabled' => 'boolean',
            'geofencing_enabled' => 'boolean',
        ];
    }

    /**
     * Is this estate's own staff payroll run here, or by Gemini?
     *
     * Board 23 draws the answer as a caption under the segmented control —
     * "Estate's own staff" against In-house, "Managed in Gemini Console"
     * against Gemini-managed — so the caption is a function of the value and
     * cannot be stored beside it and left to disagree.
     */
    public function routingCaption(string $routing): string
    {
        return $routing === self::GEMINI_MANAGED
            ? 'Managed in Gemini Console'
            : "Estate's own staff";
    }

    /** "In-house" / "Gemini-managed", as board 23 prints them on the control. */
    public function routingLabel(string $routing): string
    {
        return $routing === self::GEMINI_MANAGED ? 'Gemini-managed' : 'In-house';
    }

    /**
     * The same two, as board 23 prints them inside a sentence.
     *
     * "Staff payroll: in-house · Security payroll: Gemini-managed". One falls to
     * lower case mid-sentence and the other does not, because "in-house" is a
     * description and "Gemini" is a company's name — lower-casing the label
     * mechanically would print "gemini-managed" against the firm that owns the
     * platform.
     */
    public function routingSentenceLabel(string $routing): string
    {
        return $routing === self::GEMINI_MANAGED ? 'Gemini-managed' : 'in-house';
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

            /*
             * THE GOVERNANCE RULES ARE WRITTEN HERE TOO, and they were not.
             *
             * These columns carry database defaults, so an estate whose row
             * already existed reads them back correctly — but the row this
             * method CREATES is the one it hands straight back to its caller,
             * and a column that was never in the INSERT is null on that
             * in-memory model however sensible the column default is. The first
             * meeting scheduled on a brand-new estate therefore went in with a
             * null quorum percentage and the database refused it.
             *
             * The whole point of this method is the sentence above it: a caller
             * always gets a number and never has to decide what a missing
             * setting means. Leaving eight columns out of it made that untrue
             * for exactly one estate — a new one. See D-052.
             */
            'governance_arrears_days' => self::DEFAULT_GOVERNANCE_ARREARS_DAYS,
            'governance_tenure_check_enabled' => false,
            'governance_min_tenure_months' => 0,
            'meeting_notice_enforced' => true,
            'agm_notice_days' => self::DEFAULT_AGM_NOTICE_DAYS,
            'egm_notice_days' => self::DEFAULT_EGM_NOTICE_DAYS,
            'meeting_notice_days' => self::DEFAULT_MEETING_NOTICE_DAYS,
            'meeting_quorum_percent' => self::DEFAULT_MEETING_QUORUM_PERCENT,
        ]);
    }
}
