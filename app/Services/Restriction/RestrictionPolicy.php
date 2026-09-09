<?php

declare(strict_types=1);

namespace App\Services\Restriction;

use App\Models\Estate\EstateSetting;
use App\Models\Estate\Household;

/**
 * Whether a pass may be issued or admitted for a household, and why not.
 *
 * The single place that answers the restriction question. Both the Estate
 * Console and the Guard App's /api/v1 endpoints call this, so a guard at a gate
 * and a committee member at a desk can never be told different things about the
 * same household.
 *
 * THE RULES (D-024, client ruling)
 * --------------------------------
 * Estate-configurable, with these defaults:
 *   - eligible at 90 days overdue
 *   - 14 days' written notice before restriction takes effect
 *   - guest passes only
 *
 * NOT configurable, and enforced here rather than in a settings table:
 *   - a resident's own entry is never restricted
 *   - emergency and medical vehicles are never restricted
 *
 * Those two live in code because a settings table can be misconfigured, and a
 * misconfigured estate leaving an ambulance at a gate is not a recoverable
 * error. No estate may widen what restriction covers; it may only switch it off.
 *
 * AND THREE RULES THAT PREDATE THE CONFIGURATION
 *   - restriction follows ARREARS, never a failed payment
 *   - a declined card or a gateway outage never restricts anything
 *   - restriction never blocks a safety function
 */
class RestrictionPolicy
{
    /**
     * Pass categories that can never be restricted, whatever the arrears.
     *
     * Hardcoded on purpose. See the class docblock.
     */
    public const UNRESTRICTABLE_CATEGORIES = [
        'emergency',
        'medical',
        'resident',      // a resident's own entry
        'fire',
        'utility_emergency',
    ];

    /** The only category restriction may ever apply to. */
    public const RESTRICTABLE_CATEGORIES = [
        'guest',
        'visitor',
        'delivery',
        'contractor',
    ];

    /**
     * Decide admission for one household and one pass category.
     *
     * Returns a verdict the guard can act on, never a figure. Invariant 2: the
     * guard receives `access_restricted` as a boolean and nothing more, so
     * nothing in this return value carries an amount, an ageing bucket or a
     * payment history — and nothing in the reason wording implies money.
     *
     * @return array{
     *     admitted: bool,
     *     verdict: 'admit'|'restricted',
     *     reason: string|null,
     *     category_exempt: bool
     * }
     */
    public function decide(Household $household, string $passCategory): array
    {
        $category = strtolower(trim($passCategory));

        /*
         * Exemption is checked FIRST, before the household is even consulted.
         *
         * Ordering it this way means no future edit to the arrears logic can
         * accidentally reach an emergency vehicle: the exempt path returns
         * before restriction is considered at all.
         */
        if (in_array($category, self::UNRESTRICTABLE_CATEGORIES, true)) {
            return [
                'admitted' => true,
                'verdict' => 'admit',
                'reason' => null,
                'category_exempt' => true,
            ];
        }

        // Anything not explicitly restrictable is admitted. An unrecognised
        // category must not become a denial by default.
        if (! in_array($category, self::RESTRICTABLE_CATEGORIES, true)) {
            return [
                'admitted' => true,
                'verdict' => 'admit',
                'reason' => null,
                'category_exempt' => true,
            ];
        }

        if (! $household->access_restricted) {
            return [
                'admitted' => true,
                'verdict' => 'admit',
                'reason' => null,
                'category_exempt' => false,
            ];
        }

        /*
         * The only wording a guard ever sees (D-025).
         *
         * No amount, no bucket, no history, and nothing implying money — the
         * guard's next action is to call management, not to discuss a balance
         * with the person at the gate.
         */
        return [
            'admitted' => false,
            'verdict' => 'restricted',
            'reason' => 'Access restricted — contact management',
            'category_exempt' => false,
        ];
    }

    /**
     * Is this household eligible for restriction yet?
     *
     * Eligibility is not restriction. A household becomes eligible at the
     * threshold and may only be restricted after written notice has run.
     */
    public function isEligible(int $daysOverdue, ?EstateSetting $settings = null): bool
    {
        $settings ??= EstateSetting::current();

        if (! $settings->arrears_restriction_enabled) {
            return false;
        }

        return $daysOverdue >= $settings->arrears_restriction_days;
    }

    /**
     * May an eligible household now be restricted?
     *
     * Requires notice to have been served AND the notice period to have
     * elapsed. Serving notice and restricting on the same day is the failure
     * this guards against.
     */
    public function noticePeriodHasElapsed(
        ?\DateTimeInterface $notifiedAt,
        ?EstateSetting $settings = null,
    ): bool {
        if ($notifiedAt === null) {
            return false;
        }

        $settings ??= EstateSetting::current();

        return now()->diffInDays($notifiedAt, absolute: true) >= $settings->arrears_notice_days;
    }
}
