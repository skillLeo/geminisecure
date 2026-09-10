<?php

declare(strict_types=1);

namespace App\Services\Dispatch;

use App\Enums\AccessScope;
use App\Models\AuditEntry;
use App\Models\DispatchMessage;
use App\Models\GuardRequest;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * What guards are asking dispatch for — board screen super-admin-16.
 *
 * Leave, equipment and messages, in one inbox, because a dispatcher's question
 * is "what is waiting on me" rather than "what kind of thing is waiting on me".
 * The board tabs them and counts each, so the counts have to be real: a tab
 * reading "(2)" that opens onto three rows is worse than no count.
 *
 * A DECISION HERE CHANGES A ROSTER. Approving leave takes a guard off post for
 * eight days, which is why deciding needs `dispatch.update` rather than
 * `dispatch.view` — an Admin Assistant may watch this queue all day and may not
 * empty it — and why every decision is audited with the decider's name.
 *
 * THE LEAVE BALANCE IS SHOWN BEFORE THE DECISION, NOT AFTER. "Balance after: 6
 * days remaining" is the fact the dispatcher is deciding on, and computing it
 * afterwards would be telling them what they already did.
 */
class RequestInbox
{
    /**
     * The leave subjects that draw down an entitlement.
     *
     * Matched on `subject`, not `kind`. `kind` says whether this is leave or
     * equipment; `subject` says which leave — Vacation, Sick, Bereavement — and
     * only vacation comes off the annual entitlement. Sick leave is a statutory
     * right in Jamaica and is not deducted from it.
     */
    private const DEDUCTS_BALANCE = ['vacation'];

    /**
     * Everything the inbox draws.
     *
     * @return array<string, mixed>
     */
    public function forViewer(User $viewer): array
    {
        $pending = $this->pending($viewer);

        $leave = array_values(array_filter(
            $pending,
            static fn (array $row): bool => $row['group'] === GuardRequest::LEAVE,
        ));

        $equipment = array_values(array_filter(
            $pending,
            static fn (array $row): bool => $row['group'] === GuardRequest::EQUIPMENT,
        ));

        return [
            'leave' => $leave,
            'equipment' => $equipment,
            'messages' => $this->messages($viewer),
            'canDecide' => $viewer->can('gemini.dispatch.update'),
            'decideBlockedReason' => 'Deciding a request changes a roster, and needs Dispatch update access. You are able to read this queue.',
        ];
    }

    /**
     * Approve or deny one request.
     *
     * @param  array<string, mixed>  $input
     */
    public function decide(User $actor, int $requestId, array $input): void
    {
        $denial = ($input['decision'] ?? null) === 'denied';

        $data = Validator::make($input, [
            'decision' => ['required', Rule::in(['approved', 'denied'])],

            /*
             * Required on a denial, optional on an approval. A guard told no
             * without a reason has been given a decision they can neither act
             * on nor appeal; a guard told yes does not need one.
             *
             * The trim is not decoration. A space satisfies `required` and
             * satisfies nobody reading the refusal.
             */
            'note' => $denial
                ? ['required', 'string', 'max:500', new class implements ValidationRule
                {
                    public function validate(string $attribute, mixed $value, Closure $fail): void
                    {
                        if (trim((string) $value) === '') {
                            $fail('A denial needs a reason. The guard sees it, and it is what they act on.');
                        }
                    }
                }]
                : ['nullable', 'string', 'max:500'],
        ], [
            'note.required' => 'A denial needs a reason. The guard sees it, and it is what they act on.',
        ])->validate();

        /*
         * Normalised here, once. `validate()` returns only the keys that were
         * actually sent, so an approval submitted without a note field at all
         * has no `note` key — not a null one — and everything downstream would
         * have to remember that.
         */
        $decision = (string) $data['decision'];
        $note = trim((string) ($data['note'] ?? ''));
        $note = $note === '' ? null : $note;

        DB::connection('mysql')->transaction(function () use ($actor, $requestId, $decision, $note): void {
            $request = DB::connection('mysql')
                ->table('guard_requests')
                ->join('guards', 'guards.id', '=', 'guard_requests.guard_id')
                ->where('guard_requests.id', $requestId)
                ->select('guard_requests.*', 'guards.full_name')
                ->first();

            if ($request === null) {
                throw new RuntimeException('That request no longer exists.');
            }

            if ($request->status !== 'pending') {
                /*
                 * Two dispatchers on the same queue is normal, and the second
                 * one must not silently overwrite the first. The message names
                 * the outcome so they know it was handled rather than lost.
                 */
                throw new RuntimeException(
                    sprintf('That request was already %s by someone else.', $request->status),
                );
            }

            DB::connection('mysql')->table('guard_requests')->where('id', $requestId)->update([
                'status' => $decision,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_note' => $note,
                'updated_at' => now(),
            ]);

            AuditEntry::create([
                'tenant_id' => $request->tenant_id,
                'actor_id' => $actor->getKey(),
                'actor_name' => $actor->name,
                'actor_role' => $actor->roles->isEmpty() ? 'No role assigned' : $actor->roles->first()->label,
                'action' => 'dispatch.request_'.$decision,
                'entity_type' => 'guard_request',
                'entity_id' => (string) $requestId,
                'before' => ['status' => 'pending'],
                'after' => [
                    'status' => $decision,
                    'guard' => $request->full_name,
                    'kind' => $request->kind,
                    'note' => $note,
                ],
            ]);
        });
    }

    /**
     * Every pending request, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function pending(User $viewer): array
    {
        return $this->scoped($viewer, DB::connection('mysql')
            ->table('guard_requests')
            ->join('guards', 'guards.id', '=', 'guard_requests.guard_id'))
            ->where('guard_requests.status', 'pending')

            /*
             * Oldest first. This is a queue, and a queue is ordered by how long
             * something has been waiting on a decision — not by when the leave
             * starts, which would push a request made months ago behind one
             * raised this morning for tomorrow.
             */
            ->orderBy('guard_requests.created_at')
            ->orderBy('guard_requests.id')
            ->select([
                'guard_requests.id',
                'guard_requests.kind',
                'guard_requests.subject',
                'guard_requests.quantity',
                'guard_requests.starts_on',
                'guard_requests.ends_on',
                'guard_requests.reason',
                'guard_requests.certificate_attached',
                'guards.id as guard_id',
                'guards.full_name',
                'guards.leave_entitlement_days',
            ])
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'group' => (string) $row->kind,
                'initials' => $this->initials((string) $row->full_name),
                'title' => sprintf('%s — %s', $row->full_name, $row->subject),
                'detail' => $row->kind === GuardRequest::EQUIPMENT
                    ? $this->equipmentDetail($row)
                    : $this->leaveDetail($row),
                'status' => 'pending',
                'status_label' => 'Pending',
            ])
            ->all();
    }

    /** "Sep 15 – Sep 22, 2026 · 8 days · Balance after: 6 days remaining". */
    private function leaveDetail(object $row): string
    {
        $from = Carbon::parse((string) $row->starts_on);
        $to = $row->ends_on === null ? $from : Carbon::parse((string) $row->ends_on);
        /*
         * Cast, because Carbon 3 returns a FLOAT here. Left alone, a one-day
         * request reads "1 days" — the singular branch below compares against
         * an integer and can never match a float — and the balance arithmetic
         * carries a fraction into a figure a dispatcher decides on.
         *
         * Inclusive of both ends: leave from Monday to Monday is one day off,
         * not none.
         */
        $days = (int) $from->diffInDays($to) + 1;

        $span = $from->isSameDay($to)
            ? $from->format('M j, Y')
            : sprintf('%s – %s', $from->format('M j'), $to->format('M j, Y'));

        $parts = [$span, $days === 1 ? '1 day' : $days.' days'];

        if (in_array(strtolower((string) $row->subject), self::DEDUCTS_BALANCE, true)) {
            /*
             * The entitlement is the guard's own, off their employment record,
             * not a platform-wide figure. Jamaica's Holidays with Pay Act sets a
             * floor; a contract can sit above it, and a dispatcher deciding
             * against the floor when the contract says more would refuse leave
             * the guard has actually earned.
             */
            $entitlement = (int) ($row->leave_entitlement_days ?? 0);
            $taken = $this->leaveTaken((int) $row->guard_id);
            $parts[] = sprintf('Balance after: %d days remaining', max(0, $entitlement - $taken - $days));
        } elseif ($row->certificate_attached) {
            /*
             * Sick leave does not draw down the entitlement, so the fact that
             * matters instead is whether it is evidenced. A dispatcher
             * approving uncertified sick leave is making a different decision
             * from one approving certified.
             */
            $parts[] = 'Medical certificate attached';
        } else {
            $parts[] = 'No medical certificate';
        }

        return implode(' · ', $parts);
    }

    /** "Qty 1 · Reason: worn out · Routes to Admin Assistant on approval". */
    private function equipmentDetail(object $row): string
    {
        return implode(' · ', array_filter([
            'Qty '.(int) ($row->quantity ?? 1),
            $row->reason === null ? null : 'Reason: '.$row->reason,
            'Routes to Admin Assistant on approval',
        ]));
    }

    /**
     * Days of vacation already approved this year.
     *
     * DERIVED, never stored. A running balance column would have to be
     * decremented on approval and put back on every cancellation, reversal and
     * mis-key; the one somebody forgot is the one that refuses a guard leave
     * they are owed. Summing the approved rows cannot fall out of step with
     * them.
     */
    private function leaveTaken(int $guardId): int
    {
        return (int) DB::connection('mysql')
            ->table('guard_requests')
            ->where('guard_id', $guardId)
            ->where('status', 'approved')
            ->where(static function (Builder $subjects): void {
                // Compared in lower case explicitly. MySQL's default collation
                // would match "Vacation" anyway, and a schema that changed
                // collation later would silently stop deducting leave.
                foreach (self::DEDUCTS_BALANCE as $subject) {
                    $subjects->orWhereRaw('LOWER(subject) = ?', [$subject]);
                }
            })
            ->whereYear('starts_on', now()->year)
            ->selectRaw('COALESCE(SUM(DATEDIFF(COALESCE(ends_on, starts_on), starts_on) + 1), 0) as days')
            ->value('days');
    }

    /**
     * Recent traffic between dispatch and the posts.
     *
     * `dispatch_messages`, NOT `client_messages`. The two look alike and are
     * not: client messages are correspondence with an estate's committee about
     * their account, and belong on the client screen. These are operational
     * traffic to and from guards on shift, and a dispatcher reading their inbox
     * needs the second and would be misled by the first.
     *
     * Two directions and two different headings, because a broadcast is
     * addressed to a post and an inbound message comes from a named guard.
     *
     * @return list<array<string, mixed>>
     */
    private function messages(User $viewer, int $limit = 4): array
    {
        return $this->scoped($viewer, DB::connection('mysql')
            ->table('dispatch_messages')
            ->join('tenants', 'tenants.id', '=', 'dispatch_messages.tenant_id')
            ->leftJoin('guards', 'guards.id', '=', 'dispatch_messages.guard_id'), 'dispatch_messages.tenant_id')
            ->orderByDesc('dispatch_messages.sent_at')
            ->limit($limit)
            ->select([
                'dispatch_messages.direction',
                'dispatch_messages.body',
                'dispatch_messages.sent_at',
                'guards.full_name',
                'tenants.name as estate',
            ])
            ->get()
            ->map(function (object $row): array {
                $broadcast = $row->direction === DispatchMessage::BROADCAST;

                return [
                    /*
                     * A broadcast carries a single "D" on a solid navy chip
                     * rather than a person's initials, because it was not sent
                     * by a person to a person — it went to every guard on the
                     * estate, and initialling it with the dispatcher who typed
                     * it would read as a private message.
                     */
                    'initials' => $broadcast ? 'D' : $this->initials((string) ($row->full_name ?? 'Guard')),
                    'broadcast' => $broadcast,
                    'title' => $broadcast
                        ? sprintf('Broadcast — %s, all guards', $row->estate)
                        : sprintf('%s → Dispatch', $row->full_name ?? 'Unnamed guard'),
                    'body' => '"'.$this->truncate((string) $row->body).'"',
                    'time' => $this->relativeTime(Carbon::parse((string) $row->sent_at)),
                ];
            })
            ->all();
    }

    /** "Today, 6:15 AM" — the board's own form. */
    private function relativeTime(Carbon $at): string
    {
        return match (true) {
            $at->isToday() => 'Today, '.$at->format('g:i A'),
            $at->isYesterday() => 'Yesterday, '.$at->format('g:i A'),
            default => $at->format('M j, g:i A'),
        };
    }

    private function truncate(string $body, int $length = 160): string
    {
        return mb_strlen($body) <= $length ? $body : mb_substr($body, 0, $length - 1).'…';
    }

    /**
     * Narrow to the estates this viewer may see — in the query, never the render.
     */
    private function scoped(User $viewer, Builder $query, string $column = 'guard_requests.tenant_id'): Builder
    {
        if ($viewer->widestScope() === AccessScope::AssignedSites) {
            $query->whereIn($column, $viewer->accessibleEstateIds());
        }

        return $query;
    }

    private function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->filter()
            ->take(2)
            ->map(static fn (string $part): string => strtoupper($part[0]))
            ->implode('');
    }
}
