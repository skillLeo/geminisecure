<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Carbon;

/**
 * The forward roster — boards 26, 20 and 21's remaining writes (12 §2, Wave 5).
 *
 * AN OPEN SHIFT IS A REAL ROW WITH NO OFFICER ON IT. That is the whole shape of
 * this module: a post needing somebody is a fact the operations console has to
 * be able to hold, and the alternatives are both worse. A placeholder guard
 * would appear on the coverage board as somebody standing an empty gate; not
 * recording it at all would mean the gap exists only in a dispatcher's head.
 *
 * NOTHING HERE DELETES A SHIFT. Releasing one to open clears the officer and
 * says why; a shift that has already been worked is never touched, because its
 * `actual_start` is the evidence a post was covered.
 */
class Roster
{
    /** How far ahead a shift may be posted. */
    public const HORIZON_DAYS = 180;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Post an open shift — a post, a window, and nobody yet.
     *
     * REFUSED IN THE PAST. A roster is forward-looking: posting a shift for
     * last Tuesday would be inventing a gap nobody could have filled, and the
     * coverage board would then report an estate as uncovered on a night it was
     * covered.
     */
    public function postOpenShift(Post $post, string $start, string $end, User $by, ?string $reason = null): Shift
    {
        $from = Carbon::parse($start);
        $to = Carbon::parse($end);

        if ($to->lessThanOrEqualTo($from)) {
            throw new DomainException('A shift ends after it starts. A night shift running past midnight is still a start before an end.');
        }

        if ($from->lessThan(Carbon::now()->startOfDay())) {
            throw new DomainException('A roster is forward-looking. Posting a shift in the past would invent a gap nobody could have filled.');
        }

        if ($from->greaterThan(Carbon::now()->addDays(self::HORIZON_DAYS))) {
            throw new DomainException('A shift is posted within the next six months. Further out is a plan rather than a roster.');
        }

        if ($to->diffInHours($from, absolute: true) > 24) {
            throw new DomainException('A shift runs up to twenty-four hours. Longer than that is two shifts, and posting it as one would hide the handover.');
        }

        $clash = Shift::query()
            ->where('post_id', $post->id)
            ->whereNotIn('status', Shift::SETTLED)
            ->where('rostered_start', '<', $to)
            ->where('rostered_end', '>', $from)
            ->first();

        if ($clash !== null) {
            throw new DomainException(sprintf(
                '%s already has a shift covering part of that window (%s to %s). Two shifts on one post at one time is two officers told the same gate is theirs.',
                $post->name,
                $clash->rostered_start->format('M j, g:i A'),
                $clash->rostered_end->format('g:i A'),
            ));
        }

        $shift = Shift::create([
            'tenant_id' => (string) $post->tenant_id,
            'guard_id' => null,
            'post_id' => $post->id,
            'rostered_start' => $from,
            'rostered_end' => $to,
            'status' => Shift::OPEN,
            'released_reason' => $reason,
            'posted_by_id' => $by->getKey(),
            'posted_by_name' => (string) $by->name,
        ]);

        $this->audit->record(
            action: 'roster.shift_posted',
            entityType: 'Shift',
            entityId: (string) $shift->id,
            after: [
                'post' => (string) $post->name,
                'from' => $from->toDateTimeString(),
                'to' => $to->toDateTimeString(),
                'reason' => $reason,
            ],
            tenantId: (string) $post->tenant_id,
        );

        return $shift;
    }

    /**
     * Put an officer on an open shift.
     *
     * THE OFFICER HAS TO BE ABLE TO STAND IT. A suspended guard, or one whose
     * PSRA licence has expired, cannot legally be posted — and a roster that
     * let one be assigned would be the console producing the very breach the
     * compliance screens exist to prevent.
     */
    public function assign(Shift $shift, Guard $guard, User $by): Shift
    {
        if ($shift->status !== Shift::OPEN) {
            throw new DomainException('That shift already has an officer on it. Release it first if somebody else is to stand it.');
        }

        if ($guard->status === 'suspended') {
            throw new DomainException($guard->full_name.' is suspended from duty and cannot be posted.');
        }

        if ($guard->psra_expires_on !== null && $guard->psra_expires_on->lessThan(Carbon::parse($shift->rostered_start))) {
            throw new DomainException(sprintf(
                '%s\'s PSRA licence expires %s, before this shift starts. Posting them would be rostering an officer who cannot legally stand the post.',
                $guard->full_name,
                $guard->psra_expires_on->format('M j, Y'),
            ));
        }

        $shift->forceFill([
            'guard_id' => $guard->id,
            'status' => Shift::ROSTERED,
            'released_reason' => null,
        ])->save();

        $this->audit->record(
            action: 'roster.shift_assigned',
            entityType: 'Shift',
            entityId: (string) $shift->id,
            after: ['guard' => (string) $guard->full_name, 'by' => (string) $by->name],
            tenantId: (string) $shift->tenant_id,
        );

        return $shift;
    }

    /**
     * Release an officer's future shifts back to open — boards 20 and 21.
     *
     * FUTURE ONLY, AND NEVER ONE ALREADY WORKED. A shift with an `actual_start`
     * is evidence that a post was covered; clearing its officer would erase who
     * covered it. What this does is make tomorrow's gaps visible so somebody
     * can fill them.
     *
     * @return int how many shifts were released
     */
    public function releaseFutureShifts(Guard $guard, string $reason, User $by): int
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('Say why these shifts are being released. A dispatcher picking one up needs to know whether the estate asked for cover or an officer was stood down.');
        }

        $shifts = Shift::query()
            ->where('guard_id', $guard->id)
            ->whereNull('actual_start')
            ->where('rostered_start', '>', Carbon::now())
            ->whereNotIn('status', Shift::SETTLED)
            ->get();

        foreach ($shifts as $shift) {
            $shift->forceFill([
                'guard_id' => null,
                'status' => Shift::OPEN,
                'released_reason' => $reason,
                'posted_by_id' => $by->getKey(),
                'posted_by_name' => (string) $by->name,
            ])->save();
        }

        if ($shifts->isNotEmpty()) {
            $this->audit->record(
                action: 'roster.shifts_released',
                entityType: 'Guard',
                entityId: (string) $guard->id,
                after: [
                    'guard' => (string) $guard->full_name,
                    'shifts' => $shifts->count(),
                    'reason' => $reason,
                ],
                tenantId: $guard->tenant_id === null ? null : (string) $guard->tenant_id,
            );
        }

        return $shifts->count();
    }

    /**
     * How many future shifts an officer holds, for a screen that is about to
     * offer to release them.
     */
    public function futureShiftCount(Guard $guard): int
    {
        return Shift::query()
            ->where('guard_id', $guard->id)
            ->whereNull('actual_start')
            ->where('rostered_start', '>', Carbon::now())
            ->whereNotIn('status', Shift::SETTLED)
            ->count();
    }

    /**
     * The roster for one day, by post.
     *
     * @return array{date: string, rows: list<array<string, mixed>>, open: int}
     */
    public function day(string $date, ?User $viewer = null): array
    {
        $on = Carbon::parse($date)->startOfDay();

        $posts = Post::query()
            ->where('is_active', true)
            ->when(
                $viewer !== null && $viewer->widestScope() === AccessScope::AssignedSites,
                static fn ($query) => $query->whereIn('tenant_id', $viewer->accessibleEstateIds()),
            )
            ->with('estate')
            ->orderBy('name')
            ->get();

        $shifts = Shift::query()
            ->with(['officer', 'post'])
            ->whereIn('post_id', $posts->modelKeys())
            ->whereBetween('rostered_start', [$on, $on->copy()->endOfDay()])
            ->orderBy('rostered_start')
            ->get();

        $rows = [];
        $open = 0;

        foreach ($shifts as $shift) {
            $isOpen = $shift->guard_id === null;
            $open += $isOpen ? 1 : 0;

            $rows[] = [
                'id' => $shift->id,
                'post' => $shift->post->name ?? 'Post',
                'estate' => $shift->post->estate->name ?? 'Unassigned',
                'window' => $shift->rostered_start->format('g:i A').' – '.$shift->rostered_end->format('g:i A'),
                'officer' => $shift->officer->full_name ?? null,
                'is_open' => $isOpen,
                'released_reason' => $shift->released_reason,
                'status' => $shift->status,
            ];
        }

        return [
            'date' => $on->toDateString(),
            'rows' => $rows,
            'open' => $open,
        ];
    }
}
