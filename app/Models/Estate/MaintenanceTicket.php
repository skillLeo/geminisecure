<?php

declare(strict_types=1);

namespace App\Models\Estate;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One reported fault — boards 17 and 18.
 *
 * THE SLA CLOCK STARTS AT `reported_at` AND NOWHERE ELSE. The Build Spec is
 * explicit: "SLA breach is computed from the reported time, not the assigned
 * time, so a ticket that sat unassigned still shows as overdue." So the deadline
 * is `reported_at` plus `sla_hours`, and `assigned_at` — which is recorded, and
 * which a queue built the obvious way would have measured from — appears in no
 * arithmetic on this class. A ticket that sat in the queue for a week is a week
 * overdue, which is the failure a maintenance queue exists to surface and the
 * one a start-at-assignment model would hide.
 *
 * THERE IS NO `is_overdue` COLUMN AND NO `sla_due_at` COLUMN. A stored breach
 * flag is wrong from the second after it is written and needs a job to keep it
 * true; a stored deadline needs rewriting every time the priority changes, and
 * the rewrite somebody forgets is a ticket quietly given longer than its
 * priority allows. Both are derived here, from two columns of this row.
 *
 * `status` IS THE LIFECYCLE, NOT THE BOARD'S BADGE. Board 17 draws Submitted, In
 * progress, Overdue and Completed in one column; this column holds submitted,
 * acknowledged, assigned, in_progress, completed, verified and cancelled.
 * "Overdue" is not a state anything sets — it is an open ticket past its
 * deadline, arithmetic against now — and `boardStatus()` derives it, the same
 * way `Bill::boardStatus()` derives an overdue bill.
 *
 * COMPLETED AND VERIFIED ARE TWO FACTS. Board 18's timeline draws "Verified by
 * resident" as a sixth stage after "Completed": a job the estate believes is
 * finished and one the household agrees is finished are different states, and
 * the gap between them is where a reopen comes from.
 *
 * @property int $id
 * @property int $number
 * @property string $title
 * @property string $location_label
 * @property int|null $unit_id
 * @property string|null $category
 * @property string|null $description
 * @property string $priority
 * @property string $status
 * @property string|null $reported_by_name
 * @property int|null $reported_by
 * @property Carbon $reported_at
 * @property int $sla_hours
 * @property int|null $assigned_vendor_id
 * @property Carbon|null $assigned_at
 * @property string|null $technician_name
 * @property string|null $technician_phone
 * @property Carbon|null $eta_starts_at
 * @property Carbon|null $eta_ends_at
 * @property string|null $resolution
 * @property Carbon|null $closed_at
 * @property Carbon|null $verified_at
 * @property string|null $verified_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Unit|null $unit
 * @property-read Vendor|null $vendor
 * @property-read Collection<int, TicketActivity> $activity
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceTicket newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceTicket newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|MaintenanceTicket query()
 *
 * @mixin \Eloquent
 */
class MaintenanceTicket extends Model
{
    public const SUBMITTED = 'submitted';

    public const ACKNOWLEDGED = 'acknowledged';

    public const ASSIGNED = 'assigned';

    public const IN_PROGRESS = 'in_progress';

    public const COMPLETED = 'completed';

    public const VERIFIED = 'verified';

    public const CANCELLED = 'cancelled';

    public const HIGH = 'high';

    public const MEDIUM = 'medium';

    public const LOW = 'low';

    /**
     * Board 18's timeline, in the order it draws them.
     *
     * The order is the LIFECYCLE's and not the timestamps': board 18 renders all
     * six rows whether or not each has happened, greying the ones that have not,
     * so a stage nobody has reached still occupies its place.
     */
    public const STAGES = [
        self::SUBMITTED => 'Submitted',
        self::ACKNOWLEDGED => 'Acknowledged',
        self::ASSIGNED => 'Assigned to vendor',
        self::IN_PROGRESS => 'In progress',
        self::COMPLETED => 'Completed',
        self::VERIFIED => 'Verified by resident',
    ];

    /** What board 17's priority dot prints beside its colour. */
    public const PRIORITY_LABELS = [
        self::HIGH => 'High',
        self::MEDIUM => 'Medium',
        self::LOW => 'Low',
    ];

    /** What board 17's status badge prints, including the derived one. */
    public const STATUS_LABELS = [
        'submitted' => 'Submitted',
        'progress' => 'In progress',
        'overdue' => 'Overdue',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    protected $fillable = [
        'number',
        'title',
        'location_label',
        'unit_id',
        'category',
        'description',
        'priority',
        'status',
        'reported_by_name',
        'reported_by',
        'reported_at',
        'sla_hours',
        'assigned_vendor_id',
        'assigned_at',
        'technician_name',
        'technician_phone',
        'eta_starts_at',
        'eta_ends_at',
        'resolution',
        'closed_at',
        'verified_at',
        'verified_by_name',
    ];

    /**
     * The route binds on the NUMBER.
     *
     * Board 18's own URL is `/facilities/maintenance/1042`, which is the number
     * a resident was given and a bill already stores — not a surrogate id that
     * would make the same job answer to two different addresses.
     */
    public function getRouteKeyName(): string
    {
        return 'number';
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'sla_hours' => 'integer',
            'reported_at' => 'datetime',
            'assigned_at' => 'datetime',
            'eta_starts_at' => 'datetime',
            'eta_ends_at' => 'datetime',
            'closed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* the SLA, derived from the report and from nothing else */
    /* ------------------------------------------------------------------ */

    /** When this ticket was due, counted from the moment it was REPORTED. */
    public function slaDueAt(): Carbon
    {
        return $this->reported_at->copy()->addHours($this->sla_hours);
    }

    /**
     * Whether it has breached.
     *
     * `$asAt` rather than `now()` inside, because "overdue" is a statement about
     * a moment and a report run asks it about a moment that is not this one.
     *
     * A CLOSED TICKET IS NOT OVERDUE, however late it was. Board 17 replaces the
     * Age cell with "Closed Sep 2" for a completed ticket and gives it the
     * completed badge — how long a finished job took is a question for the
     * average-resolution tile, not for a queue of work outstanding.
     */
    public function isOverdue(?Carbon $asAt = null): bool
    {
        if ($this->isClosed()) {
            return false;
        }

        return ($asAt?->copy() ?? Carbon::now())->greaterThan($this->slaDueAt());
    }

    /** Whether the estate still owes anybody work on this. */
    public function isClosed(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::VERIFIED, self::CANCELLED], true);
    }

    /** Whether it counts towards board 17's "Open tickets" tile. */
    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }

    /**
     * The badge board 17 draws, which is not what `status` holds.
     *
     * The overdue test comes FIRST among the open states: a ticket that is both
     * in progress and past its deadline is the one somebody has to look at, and
     * a queue that showed it as merely in progress would bury it.
     */
    public function boardStatus(?Carbon $asAt = null): string
    {
        return match (true) {
            $this->status === self::CANCELLED => 'cancelled',
            $this->status === self::COMPLETED, $this->status === self::VERIFIED => 'completed',
            $this->isOverdue($asAt) => 'overdue',
            $this->status === self::SUBMITTED => 'submitted',
            default => 'progress',
        };
    }

    /* ------------------------------------------------------------------ */
    /* what the boards print */
    /* ------------------------------------------------------------------ */

    /** "#1042 — Gate lighting", board 17's Ticket cell and board 27's reference. */
    public function label(): string
    {
        return '#'.$this->number.' — '.$this->title;
    }

    /**
     * Board 17's Age column.
     *
     * A closed ticket's age is replaced by the day it closed, because how old a
     * finished job is tells a manager nothing and when it finished tells them
     * whether the estate is keeping up.
     *
     * Humanised to the LARGEST unit the board uses — "2 days", "4 hours" — and
     * never to minutes: a ticket raised twenty minutes ago reads "1 hour", which
     * is the resolution a queue is triaged at.
     */
    public function ageLabel(?Carbon $asAt = null): string
    {
        if ($this->closed_at !== null && $this->isClosed()) {
            return 'Closed '.$this->closed_at->format('M j');
        }

        $now = $asAt?->copy() ?? Carbon::now();
        $hours = (int) $this->reported_at->diffInHours($now, absolute: true);

        if ($hours >= 24) {
            $days = intdiv($hours, 24);

            return $days.' day'.($days === 1 ? '' : 's');
        }

        $hours = max($hours, 1);

        return $hours.' hour'.($hours === 1 ? '' : 's');
    }

    /** "Island Electric Services", or the word board 17 draws when there is none. */
    public function assigneeLabel(): string
    {
        return $this->vendor->name ?? 'Unassigned';
    }

    /** "Gate lighting — Phase 2 visitor parking" — board 18's summary heading. */
    public function summaryTitle(): string
    {
        // The middot form flattened to a space, which is what board 18 draws
        // where board 17 draws "Phase 2 · visitor parking".
        return $this->title.' — '.str_replace(' · ', ' ', $this->location_label);
    }

    /* ------------------------------------------------------------------ */
    /* relations */
    /* ------------------------------------------------------------------ */

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'assigned_vendor_id');
    }

    /** @return HasMany<TicketActivity, $this> */
    public function activity(): HasMany
    {
        return $this->hasMany(TicketActivity::class, 'maintenance_ticket_id');
    }

    /**
     * The bills raised against this job.
     *
     * Joined on the NUMBER, because that is what board 27 prints and what the
     * bill was recorded with. This is the trace a committee walks when it asks
     * what a job cost — ticket to bill to payment to bank line — and the reason
     * `bills.ticket_id` has a foreign key at all.
     *
     * @return HasMany<Bill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class, 'ticket_id', 'number');
    }
}
