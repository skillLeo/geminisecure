<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One state change on one ticket — board 18's timeline.
 *
 * WHY THE TICKET'S OWN STATUS IS NOT ENOUGH. The Build Spec: "The reporting
 * resident can follow every state change in the app without asking. State
 * changes are timestamped and attributed." A status column says where a ticket
 * is; it cannot say when it got there, who moved it, or that it was assigned to
 * one vendor before another. A resident asking why their gate light is still out
 * is asking about the history, and a history that has to be inferred from a
 * single mutable string is not one.
 *
 * THE ACTOR IS A NAME FIRST AND AN ID SECOND. Board 18 attributes one stage to
 * "Patricia Morgan" and the next to "Island Electric Services" — a console user
 * and a supplier, and the supplier has no user account. An `actor_id` foreign
 * key to `users` could record only half of this ticket's own history, so the
 * name is what is stored and `actor_kind` says what kind of party it names.
 *
 * `stage` IS NULLABLE AND `event` IS NOT. Six stages make up the spine board 18
 * draws in fixed lifecycle order; a reassignment and a priority change are state
 * changes a resident is entitled to follow and advance no stage. Storing them in
 * the same table keeps one chronological record of what happened, which is what
 * anybody reading it actually wants.
 *
 * @property int $id
 * @property int $maintenance_ticket_id
 * @property string $event
 * @property string|null $stage
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $note
 * @property string $actor_kind
 * @property int|null $actor_id
 * @property string|null $actor_name
 * @property Carbon $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read MaintenanceTicket $ticket
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketActivity newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketActivity newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TicketActivity query()
 *
 * @mixin \Eloquent
 */
class TicketActivity extends Model
{
    /**
     * The table is named for what it holds rather than pluralised.
     *
     * "Activity" is already a mass noun; `ticket_activities` reads as a
     * different word, and every query in this module says `activity`.
     */
    protected $table = 'maintenance_ticket_activity';

    public const REPORTED = 'reported';

    public const ACKNOWLEDGED = 'acknowledged';

    public const ASSIGNED = 'assigned';

    public const REASSIGNED = 'reassigned';

    public const PRIORITY_CHANGED = 'priority_changed';

    public const STARTED = 'started';

    public const RESOLVED = 'resolved';

    public const REOPENED = 'reopened';

    public const VERIFIED = 'verified';

    public const NOTE = 'note';

    /** Who a change is attributed to. */
    public const STAFF = 'staff';

    public const VENDOR = 'vendor';

    public const RESIDENT = 'resident';

    public const SYSTEM = 'system';

    protected $fillable = [
        'maintenance_ticket_id',
        'event',
        'stage',
        'from_status',
        'to_status',
        'note',
        'actor_kind',
        'actor_id',
        'actor_name',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * Board 18's sub-line: "Sep 3, 8:15 AM · Patricia Morgan".
     *
     * The time alone where nothing attributed it — the resident's own report is
     * drawn with a timestamp and no name, because "Submitted · Andrea Fletcher"
     * would repeat the name already printed two lines above it in the meta.
     */
    public function timelineLine(): string
    {
        $stamp = $this->occurred_at->format('M j, g:i A');

        return trim((string) $this->actor_name) === ''
            ? $stamp
            : $stamp.' · '.$this->actor_name;
    }

    /** @return BelongsTo<MaintenanceTicket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(MaintenanceTicket::class, 'maintenance_ticket_id');
    }
}
