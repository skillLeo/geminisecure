<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\Notice;
use App\Models\Estate\Phase;
use App\Models\Estate\Resident;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Estate notices — board 32.
 *
 * THE SEEN BAR IS COUNTED ON EVERY READ AND STORED NOWHERE. Board 32 draws 47%,
 * 71% and 88% against three notices, and that figure is the reason a committee
 * posts one and comes back to look at it. Both halves move: the numerator every
 * time somebody opens the notice, the denominator every time a household moves
 * in or out. A stored percentage is a photograph of a number that is still
 * changing, and it is the photograph a secretary would read.
 *
 * THE AUDIENCE IS A SCOPE, RESOLVED NOW. A notice addressed to "Phase 3 only"
 * means everybody who lives in Phase 3 today, not the list of people who lived
 * there when it was posted — so a household that moved in yesterday is counted
 * in the denominator of a notice about tomorrow's water. Freezing the list at
 * posting time would quietly report a notice as fully seen by an estate that
 * had grown since.
 *
 * PUBLISHING IS THE ACT, NOT WRITING. A notice with no `published_at` has told
 * nobody anything; the list draws published ones only. That separation is what
 * lets a secretary draft an announcement without a half-finished sentence
 * reaching four hundred and fifty households.
 */
class Notices
{
    /**
     * Board 32, both columns.
     *
     * @return array<string, mixed>
     */
    public function board(): array
    {
        $notices = Notice::query()
            ->whereNotNull('published_at')
            ->withCount('reads')
            ->orderByDesc('published_at')
            ->get();

        /*
         * One query for every audience size on the screen rather than one per
         * notice. Three notices is three counts either way; four hundred and
         * fifty residents across five phases is not, and a list screen that
         * issues a COUNT per row is the shape that is fine in review and slow
         * on a real estate.
         */
        $byPhase = $this->residentsByPhase();
        $everybody = array_sum($byPhase);

        return [
            'rows' => $notices->map(function (Notice $notice) use ($byPhase, $everybody): array {
                $audience = $notice->audience_scope === Notice::ONE_PHASE
                    ? ($byPhase[$notice->audience_phase] ?? 0)
                    : $everybody;

                return [
                    'id' => $notice->id,
                    'kind' => $notice->kind,
                    'kind_label' => $notice->kindLabel(),
                    'title' => $notice->title,
                    'body' => $notice->body,

                    // "Posted by Delroy Samuels · Sep 1, 9:00 AM" — the board's
                    // own separator and its own date format.
                    'meta' => 'Posted by '.$notice->byline().' · '.$notice->published_at->format('M j, g:i A'),
                    'audience' => $notice->audienceLabel(),

                    /*
                     * Rounded for the bar and never for the arithmetic. A
                     * notice nobody can read — an audience of zero, which is a
                     * real state for a phase with no households yet — is 0%
                     * rather than a division by zero.
                     */
                    'seen_pct' => $audience === 0 ? 0 : (int) round($notice->reads_count / $audience * 100),
                    'seen_count' => $notice->reads_count,
                    'audience_size' => $audience,
                ];
            })->all(),

            'audiences' => $this->audienceOptions($byPhase, $everybody),
            'kinds' => [
                ['key' => Notice::GENERAL, 'label' => 'General'],
                ['key' => Notice::URGENT, 'label' => 'Urgent'],
            ],
        ];
    }

    /**
     * How many residents live in each phase.
     *
     * Joined through the unit rather than stored on the resident, because a
     * household's phase is a fact about its address and moving house is a change
     * of unit. See `Unit::phase()` — the relation is on the NAME, since `block`
     * has carried "Phase 2" as a string since the estate was first seeded.
     *
     * @return array<string, int>
     */
    private function residentsByPhase(): array
    {
        $rows = DB::connection('tenant')->select('
            SELECT u.block AS phase, COUNT(r.id) AS residents
              FROM residents r
              JOIN households h ON h.id = r.household_id
              JOIN units u ON u.id = h.unit_id
             GROUP BY u.block
        ');

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->phase] = (int) $row->residents;
        }

        return $counts;
    }

    /**
     * What the composer's audience field offers.
     *
     * Estate-wide first, because board 30's own notification default calls these
     * "Estate-wide announcements from Governance" — the whole estate is the
     * ordinary case and a phase is the narrowing.
     *
     * @param  array<string, int>  $byPhase
     * @return list<array{key: string, phase: string|null, label: string, size: int}>
     */
    private function audienceOptions(array $byPhase, int $everybody): array
    {
        $options = [[
            'key' => Notice::ESTATE_WIDE,
            'phase' => null,
            'label' => 'Everybody on the estate',
            'size' => $everybody,
        ]];

        foreach (Phase::query()->orderBy('sequence')->orderBy('name')->pluck('name') as $phase) {
            $options[] = [
                'key' => Notice::ONE_PHASE,
                'phase' => (string) $phase,
                'label' => $phase.' only',
                'size' => $byPhase[(string) $phase] ?? 0,
            ];
        }

        return $options;
    }

    /**
     * Write a notice and tell the estate, in one act.
     *
     * REFUSED WITHOUT A TITLE AND A BODY, and the refusal says why rather than
     * failing validation silently: a notice with an empty body is a headline
     * four hundred and fifty households get pushed at them with nothing to read.
     *
     * THE AUTHOR IS RECORDED EVEN WHEN THE ROLE IS SHOWN. `posted_as_role`
     * changes what the estate sees; it never changes what the record says about
     * who did it.
     *
     * @param  array<string, mixed>  $input
     */
    public function post(array $input, User $by): Notice
    {
        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));

        if ($title === '' || $body === '') {
            throw new DomainException(
                'A notice needs a heading and something to say. An announcement with an empty body is a '.
                'headline every household is pushed and cannot read.'
            );
        }

        $scope = ($input['audience_scope'] ?? Notice::ESTATE_WIDE) === Notice::ONE_PHASE
            ? Notice::ONE_PHASE
            : Notice::ESTATE_WIDE;

        $phase = $scope === Notice::ONE_PHASE ? trim((string) ($input['audience_phase'] ?? '')) : null;

        if ($scope === Notice::ONE_PHASE && ($phase === null || $phase === '')) {
            throw new DomainException('A notice addressed to one phase has to say which phase.');
        }

        return Notice::create([
            'kind' => ($input['kind'] ?? Notice::GENERAL) === Notice::URGENT ? Notice::URGENT : Notice::GENERAL,
            'title' => $title,
            'body' => $body,
            'audience_scope' => $scope,
            'audience_phase' => $phase,
            'author_id' => $by->getKey(),
            'author_name' => $by->name,

            /*
             * Published immediately, because board 32's composer has one button
             * and it says "Post notice". A draft state exists in the schema —
             * `published_at` is nullable — and nothing on this screen creates
             * one, which is the honest position: an estate has not asked for
             * drafts and a control nobody drew is a control nobody wants.
             */
            'published_at' => now(),
        ]);
    }

    /**
     * Record that a resident has read a notice.
     *
     * Idempotent by the table's own unique key rather than by checking first:
     * two devices opening the same notice at the same moment is a real race, and
     * a read-then-write would let both through.
     */
    public function markRead(Notice $notice, Resident $resident): void
    {
        DB::connection('tenant')->table('notice_reads')->insertOrIgnore([
            'notice_id' => $notice->id,
            'resident_id' => $resident->id,
            'read_at' => now(),
        ]);
    }
}
