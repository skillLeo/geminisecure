<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\TicketActivity;
use App\Models\Estate\Unit;
use App\Models\Estate\Vendor;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The maintenance queue and one ticket end to end — boards 17 and 18.
 *
 * THE SLA IS MEASURED FROM THE REPORT AND FROM NOTHING ELSE. The Build Spec:
 * "SLA breach is computed from the reported time, not the assigned time, so a
 * ticket that sat unassigned still shows as overdue." Every method below that
 * touches a deadline goes through `MaintenanceTicket::isOverdue()`, whose only
 * terms are `reported_at` and `sla_hours`. `assigned_at` is recorded and appears
 * in no arithmetic anywhere in this class — a queue that measured from
 * assignment would hide precisely the failure it exists to surface, and the
 * ticket that has been ignored longest would be the one it reported as fine.
 *
 * NOTHING HERE IS STORED AS A BREACH. There is no flag to recalculate and no
 * nightly job to keep one true. Overdue is arithmetic against a moment, and the
 * moment is always passed in or taken as now.
 *
 * EVERY STATE CHANGE IS WRITTEN DOWN, TIMESTAMPED AND ATTRIBUTED. The Build
 * Spec: "The reporting resident can follow every state change in the app without
 * asking." So no method here mutates a status without also recording what
 * happened, who did it and when — in the same transaction, because a state
 * change with no entry behind it is a ticket that changed for no reason anybody
 * can see.
 *
 * NOT ONE FIGURE THIS CLASS RETURNS IS A RESIDENT'S FINANCIAL POSITION. The
 * Property Manager holds `facilities` in full and holds no `dues_ledger`, no
 * `payments` and no `accounting_posting` — a platform invariant (D-010) rather
 * than an estate setting. A maintenance board that returned a unit balance
 * beside a ticket would hand the person who commissions the work the thing they
 * are locked out of, and `EstateFacilitiesMaintenanceTest` walks both boards'
 * payloads to prove none does.
 */
class Maintenance
{
    /**
     * How long the estate has to resolve a ticket, by priority.
     *
     * DERIVED FROM BOARD 17, NOT CHOSEN. The board draws five tickets with an
     * age and a status against each, and four of them constrain this ladder:
     *
     *   #1039  High    4 hours  Submitted     — so High is longer than 4 hours
     *   #1041  High    6 days   Overdue       — and shorter than 6 days
     *   #1042  Medium  2 days   In progress   — so Medium is longer than 2 days
     *   #1037  Low     3 days   In progress   — so Low is longer than 3 days
     *
     * One day, three days and seven days is the ordinary trades ladder that sits
     * inside every one of those bounds, and it reproduces the board's own
     * "2 overdue" tile against the tickets it draws.
     */
    public const SLA_HOURS = [
        MaintenanceTicket::HIGH => 24,
        MaintenanceTicket::MEDIUM => 72,
        MaintenanceTicket::LOW => 168,
    ];

    /**
     * Board 17's filter chips, in the order it draws them.
     *
     * The chip reads "Open" and the stored status is `submitted` — the board's
     * own wording for a ticket nobody has picked up yet.
     */
    public const FILTERS = [
        'all' => 'All',
        'open' => 'Open',
        'in_progress' => 'In progress',
        'completed' => 'Completed',
    ];

    /** Where a ticket number starts on an estate that has never had one. */
    private const FIRST_NUMBER = 1000;

    /* ------------------------------------------------------------------ */
    /* writing — each of these is a state change, and each is recorded */
    /* ------------------------------------------------------------------ */

    /**
     * Take a report. The clock starts HERE.
     *
     * `reportedAt` is an argument and does not default to now inside the
     * transaction, because a manager keying in a call taken on Saturday is
     * recording a ticket that is already two days old. Defaulting it to the
     * moment of data entry would restart the SLA at the estate's convenience,
     * which is the same defect as measuring from assignment wearing different
     * clothes.
     */
    public function report(
        string $title,
        string $locationLabel,
        string $priority = MaintenanceTicket::MEDIUM,
        ?Unit $unit = null,
        ?string $category = null,
        ?string $description = null,
        ?string $reportedByName = null,
        Carbon|string|null $reportedAt = null,
        ?User $by = null,
    ): MaintenanceTicket {
        $this->guardPriority($priority);

        return DB::connection('tenant')->transaction(function () use (
            $title, $locationLabel, $priority, $unit, $category, $description, $reportedByName, $reportedAt, $by
        ): MaintenanceTicket {
            $when = $this->asMoment($reportedAt ?? Carbon::now());

            $ticket = MaintenanceTicket::create([
                'number' => $this->nextNumber(),
                'title' => $title,
                'location_label' => $locationLabel,
                'unit_id' => $unit?->id,
                'category' => $category,
                'description' => $description,
                'priority' => $priority,
                'status' => MaintenanceTicket::SUBMITTED,
                'reported_by_name' => $reportedByName,
                'reported_at' => $when,
                'sla_hours' => self::SLA_HOURS[$priority],
            ]);

            $this->record(
                ticket: $ticket,
                event: TicketActivity::REPORTED,
                stage: MaintenanceTicket::SUBMITTED,
                to: MaintenanceTicket::SUBMITTED,
                occurredAt: $when,

                /*
                 * Attributed to the RESIDENT where one reported it, and to the
                 * member of staff who raised the work order otherwise. Board 18
                 * draws the submitted stage with a timestamp and no name, because
                 * the reporter is already printed in the ticket meta two lines
                 * above — so the name is stored and the timeline chooses not to
                 * repeat it.
                 */
                actorKind: $reportedByName !== null ? TicketActivity::RESIDENT : TicketActivity::STAFF,
                actorName: $reportedByName ?? $by?->name,
                actorId: $reportedByName !== null ? null : $by?->getKey(),
            );

            return $ticket;
        });
    }

    /** Somebody in the office has seen it. */
    public function acknowledge(MaintenanceTicket $ticket, ?User $by = null, Carbon|string|null $on = null): MaintenanceTicket
    {
        if ($ticket->status !== MaintenanceTicket::SUBMITTED) {
            return $ticket;
        }

        return $this->transition(
            ticket: $ticket,
            to: MaintenanceTicket::ACKNOWLEDGED,
            event: TicketActivity::ACKNOWLEDGED,
            stage: MaintenanceTicket::ACKNOWLEDGED,
            occurredAt: $on,
            actorName: $by?->name,
            actorId: $by?->getKey(),
        );
    }

    /**
     * Put a vendor on it — or a different one.
     *
     * THE SAME METHOD FOR BOTH, and the activity log is what distinguishes them:
     * a first assignment advances the lifecycle and a reassignment does not, but
     * both are state changes the resident is entitled to follow. Board 18's
     * "Reassign vendor" button is this method with a ticket that already has one.
     *
     * ASSIGNMENT DOES NOT MOVE THE DEADLINE. It writes `assigned_at` and nothing
     * that any SLA calculation reads. A ticket assigned on day six of a
     * twenty-four hour target is five days overdue the moment the vendor is
     * named, which is the true statement and the useful one.
     */
    public function assign(
        MaintenanceTicket $ticket,
        Vendor $vendor,
        ?string $technicianName = null,
        ?string $technicianPhone = null,
        Carbon|string|null $etaStartsAt = null,
        Carbon|string|null $etaEndsAt = null,
        ?User $by = null,
        Carbon|string|null $on = null,
    ): MaintenanceTicket {
        if ($ticket->isClosed()) {
            throw new DomainException(sprintf(
                'Ticket %s is %s and cannot be assigned. Reopen it first, so the record says the job '.
                'came back rather than that it never finished.',
                $ticket->label(),
                $ticket->status,
            ));
        }

        $previous = $ticket->vendor;
        $when = $this->asMoment($on ?? Carbon::now());

        return DB::connection('tenant')->transaction(function () use (
            $ticket, $vendor, $previous, $technicianName, $technicianPhone, $etaStartsAt, $etaEndsAt, $when
        ): MaintenanceTicket {
            $from = $ticket->status;

            $ticket->forceFill([
                'assigned_vendor_id' => $vendor->id,
                'assigned_at' => $when,
                'technician_name' => $technicianName,
                'technician_phone' => $technicianPhone,
                'eta_starts_at' => $etaStartsAt === null ? null : $this->asMoment($etaStartsAt),
                'eta_ends_at' => $etaEndsAt === null ? null : $this->asMoment($etaEndsAt),

                // A ticket already in progress stays there: sending a different
                // electrician does not undo the work the first one started.
                'status' => $from === MaintenanceTicket::IN_PROGRESS
                    ? MaintenanceTicket::IN_PROGRESS
                    : MaintenanceTicket::ASSIGNED,
            ])->save();

            $this->record(
                ticket: $ticket,
                event: $previous === null ? TicketActivity::ASSIGNED : TicketActivity::REASSIGNED,

                /*
                 * Only a FIRST assignment fills board 18's third stage. A
                 * reassignment is history rather than progress — the ticket does
                 * not become more assigned — and giving it the stage would
                 * overwrite the timestamp the resident was already shown.
                 */
                stage: $previous === null ? MaintenanceTicket::ASSIGNED : null,
                to: $ticket->status,
                from: $from,
                occurredAt: $when,
                note: $previous === null ? null : 'Reassigned from '.$previous->name,

                // The VENDOR is who this stage is attributed to. Board 18 draws
                // "Sep 3, 11:20 AM · Island Electric Services", not the manager
                // who typed it.
                actorKind: TicketActivity::VENDOR,
                actorName: $vendor->name,
            );

            return $ticket;
        });
    }

    /** The vendor is on site. */
    public function start(
        MaintenanceTicket $ticket,
        ?string $note = null,
        ?User $by = null,
        Carbon|string|null $on = null,
    ): MaintenanceTicket {
        if ($ticket->isClosed()) {
            throw new DomainException(sprintf(
                'Ticket %s is %s. Work starting again on a closed job is a reopen, which leaves the '.
                'first closure standing in the record.',
                $ticket->label(),
                $ticket->status,
            ));
        }

        return $this->transition(
            ticket: $ticket,
            to: MaintenanceTicket::IN_PROGRESS,
            event: TicketActivity::STARTED,
            stage: MaintenanceTicket::IN_PROGRESS,
            occurredAt: $on,
            note: $note,
            actorKind: $ticket->vendor === null ? TicketActivity::STAFF : TicketActivity::VENDOR,
            actorName: $ticket->vendor->name ?? $by?->name,
        );
    }

    /**
     * Raise or lower the priority.
     *
     * THE DEADLINE MOVES AND THE CLOCK DOES NOT RESET. Raising a three-day-old
     * medium ticket to high makes it overdue immediately, because high means
     * twenty-four hours from the report and the report was three days ago. That
     * is the point of escalating one.
     */
    public function changePriority(MaintenanceTicket $ticket, string $priority, ?User $by = null): MaintenanceTicket
    {
        $this->guardPriority($priority);

        if ($ticket->priority === $priority) {
            return $ticket;
        }

        $was = MaintenanceTicket::PRIORITY_LABELS[$ticket->priority];

        return DB::connection('tenant')->transaction(function () use ($ticket, $priority, $was, $by): MaintenanceTicket {
            $ticket->forceFill([
                'priority' => $priority,
                'sla_hours' => self::SLA_HOURS[$priority],
            ])->save();

            $this->record(
                ticket: $ticket,
                event: TicketActivity::PRIORITY_CHANGED,
                to: $ticket->status,
                from: $ticket->status,
                occurredAt: Carbon::now(),
                note: 'Priority '.strtolower($was).' → '.strtolower(MaintenanceTicket::PRIORITY_LABELS[$priority]),
                actorName: $by?->name,
                actorId: $by?->getKey(),
            );

            return $ticket;
        });
    }

    /**
     * The work is done — board 18's "Mark completed".
     *
     * `closed_at` is what board 17 prints in place of the Age cell and what the
     * average-resolution tile is measured across. It is NOT the same fact as
     * `verified_at`: the estate saying a job is finished and the household
     * agreeing are two statements, and the sixth stage exists because they can
     * differ.
     */
    public function resolve(
        MaintenanceTicket $ticket,
        string $resolution,
        ?User $by = null,
        Carbon|string|null $on = null,
    ): MaintenanceTicket {
        if ($ticket->isClosed()) {
            throw new DomainException(sprintf(
                'Ticket %s is already %s. Closing it a second time would overwrite the day the work '.
                'actually finished, which is the figure the resolution average is measured on.',
                $ticket->label(),
                $ticket->status,
            ));
        }

        if (trim($resolution) === '') {
            throw new DomainException(
                'Say what was done. A ticket closed with no resolution tells the resident who reported '.
                'it nothing, and tells the next person to hit the same fault less than that.'
            );
        }

        $when = $this->asMoment($on ?? Carbon::now());

        return DB::connection('tenant')->transaction(function () use ($ticket, $resolution, $by, $when): MaintenanceTicket {
            $from = $ticket->status;

            $ticket->forceFill([
                'status' => MaintenanceTicket::COMPLETED,
                'resolution' => $resolution,
                'closed_at' => $when,
            ])->save();

            $this->record(
                ticket: $ticket,
                event: TicketActivity::RESOLVED,
                stage: MaintenanceTicket::COMPLETED,
                to: MaintenanceTicket::COMPLETED,
                from: $from,
                occurredAt: $when,
                note: $resolution,
                actorName: $by?->name,
                actorId: $by?->getKey(),
            );

            return $ticket;
        });
    }

    /**
     * It was not fixed. Put it back in the queue.
     *
     * THE ORIGINAL CLOSURE STAYS IN THE LOG. A reopen is a new entry, never an
     * edit to the one that said the job was done — the pair is the record that a
     * fault came back, which is the only way anybody sees a vendor whose repairs
     * do not hold.
     *
     * `closed_at` is cleared and `reported_at` is NOT. The SLA still runs from
     * the resident's original report: a household that has waited a fortnight
     * across two visits has waited a fortnight, and restarting the clock on a
     * reopen would reward failing to fix it the first time.
     */
    public function reopen(MaintenanceTicket $ticket, string $reason, ?User $by = null): MaintenanceTicket
    {
        if (! $ticket->isClosed()) {
            throw new DomainException(sprintf(
                'Ticket %s is %s and is already open. There is nothing to reopen.',
                $ticket->label(),
                $ticket->status,
            ));
        }

        if (trim($reason) === '') {
            throw new DomainException(
                'Say why it is being reopened. A job that was signed off and then was not is the one '.
                'thing a maintenance record has to be able to explain.'
            );
        }

        return DB::connection('tenant')->transaction(function () use ($ticket, $reason, $by): MaintenanceTicket {
            $from = $ticket->status;

            $ticket->forceFill([
                'status' => $ticket->assigned_vendor_id === null
                    ? MaintenanceTicket::ACKNOWLEDGED
                    : MaintenanceTicket::ASSIGNED,
                'closed_at' => null,
                'verified_at' => null,
                'verified_by_name' => null,
            ])->save();

            $this->record(
                ticket: $ticket,
                event: TicketActivity::REOPENED,
                to: $ticket->status,
                from: $from,
                occurredAt: Carbon::now(),
                note: $reason,
                actorName: $by?->name,
                actorId: $by?->getKey(),
            );

            return $ticket;
        });
    }

    /** The household says it is fixed — board 18's sixth stage. */
    public function verify(MaintenanceTicket $ticket, string $residentName, Carbon|string|null $on = null): MaintenanceTicket
    {
        if ($ticket->status !== MaintenanceTicket::COMPLETED) {
            throw new DomainException(sprintf(
                'Ticket %s is %s. A resident verifies work the estate has said is finished; there is '.
                'nothing yet for them to agree with.',
                $ticket->label(),
                $ticket->status,
            ));
        }

        $when = $this->asMoment($on ?? Carbon::now());

        return DB::connection('tenant')->transaction(function () use ($ticket, $residentName, $when): MaintenanceTicket {
            $ticket->forceFill([
                'status' => MaintenanceTicket::VERIFIED,
                'verified_at' => $when,
                'verified_by_name' => $residentName,
            ])->save();

            $this->record(
                ticket: $ticket,
                event: TicketActivity::VERIFIED,
                stage: MaintenanceTicket::VERIFIED,
                to: MaintenanceTicket::VERIFIED,
                from: MaintenanceTicket::COMPLETED,
                occurredAt: $when,
                actorKind: TicketActivity::RESIDENT,
                actorName: $residentName,
            );

            return $ticket;
        });
    }

    /* ------------------------------------------------------------------ */
    /* what the screens read */
    /* ------------------------------------------------------------------ */

    /**
     * Board 17 — the four tiles, the chips and the queue.
     *
     * EVERY TILE IS DERIVED, including the overdue count, which is the same
     * arithmetic the rows use rather than a second one over a stored flag.
     *
     * @param  string  $filter  one of the keys of self::FILTERS
     * @return array<string, mixed>
     */
    public function queueBoard(string $filter = 'all', ?Carbon $asAt = null): array
    {
        $now = $asAt?->copy() ?? Carbon::now();

        $tickets = MaintenanceTicket::query()
            ->with(['vendor', 'unit'])
            ->orderByDesc('number')
            ->get();

        $open = 0;
        $inProgress = 0;
        $overdue = 0;
        $resolvedHours = [];

        $rows = [];

        foreach ($tickets as $ticket) {
            $state = $ticket->boardStatus($now);

            if ($ticket->isOpen()) {
                $open++;
            }

            if ($state === 'progress') {
                $inProgress++;
            }

            if ($state === 'overdue') {
                $overdue++;
            }

            if ($ticket->closed_at !== null && $ticket->isClosed()) {
                $resolvedHours[] = (float) $ticket->reported_at->diffInHours($ticket->closed_at, absolute: true);
            }

            if (! $this->matchesFilter($ticket, $state, $filter)) {
                continue;
            }

            $rows[] = [
                'id' => $ticket->id,
                'number' => $ticket->number,
                'ticket' => $ticket->label(),
                'title' => $ticket->title,
                'location' => $ticket->location_label,
                'priority' => $ticket->priority,
                'priority_label' => MaintenanceTicket::PRIORITY_LABELS[$ticket->priority],
                'status' => $state,
                'status_label' => MaintenanceTicket::STATUS_LABELS[$state],
                'assignee' => $ticket->assigneeLabel(),
                'age' => $ticket->ageLabel($now),

                /*
                 * The unit's REFERENCE, and never anything else about it. Board
                 * 17 draws "Phase 1 · Lot 9" as a place, and a maintenance
                 * screen has no business carrying what that household owes —
                 * D-010.
                 */
                'unit' => $ticket->unit->reference ?? null,
            ];
        }

        return [
            'kpis' => [
                ['key' => 'open', 'value' => $open, 'label' => 'Open tickets'],
                ['key' => 'in_progress', 'value' => $inProgress, 'label' => 'In progress'],
                ['key' => 'overdue', 'value' => $overdue, 'label' => 'Overdue'],

                /*
                 * A duration, not a count, and the page prints it with the unit
                 * word — "3.2 days". One decimal place because that is what the
                 * board draws and because a second one would suggest an accuracy
                 * an average over a dozen jobs does not have.
                 */
                ['key' => 'avg_resolution', 'value' => $this->averageResolutionDays($resolvedHours), 'label' => 'Avg resolution'],
            ],
            'filters' => array_map(
                static fn (string $key): array => ['key' => $key, 'label' => self::FILTERS[$key]],
                array_keys(self::FILTERS),
            ),
            'filter' => array_key_exists($filter, self::FILTERS) ? $filter : 'all',

            // The red chip board 17 pushes to the right of the row. The same
            // figure as the tile, from the same count — not a second query that
            // could disagree with it.
            'overdue' => $overdue,
            'rows' => $rows,
        ];
    }

    /**
     * Board 18 — one ticket, its timeline and its vendor.
     *
     * @return array<string, mixed>
     */
    public function ticketBoard(MaintenanceTicket $ticket, ?Carbon $asAt = null): array
    {
        $now = $asAt?->copy() ?? Carbon::now();
        $state = $ticket->boardStatus($now);

        return [
            'ticket' => [
                'id' => $ticket->id,
                'number' => $ticket->number,
                'heading' => 'Ticket '.$ticket->label(),
                'summary_title' => $ticket->summaryTitle(),
                'title' => $ticket->title,
                'location' => $ticket->location_label,
                'category' => $ticket->category,
                'description' => $ticket->description,
                'priority' => $ticket->priority,
                'priority_label' => MaintenanceTicket::PRIORITY_LABELS[$ticket->priority],
                'status' => $state,
                'status_label' => MaintenanceTicket::STATUS_LABELS[$state],

                /*
                 * "Submitted by Andrea Fletcher · Lot 47 · Sep 2, 6:40 PM ·
                 * Category: Electrical" — composed here so the page prints one
                 * string, and composed of only the parts that exist.
                 */
                'meta' => implode(' · ', array_filter([
                    $ticket->reported_by_name === null ? null : 'Submitted by '.$ticket->reported_by_name,
                    $ticket->unit->reference ?? null,
                    $ticket->reported_at->format('M j, g:i A'),
                    $ticket->category === null ? null : 'Category: '.$ticket->category,
                ])),
                'reported_at' => $ticket->reported_at->format('M j, g:i A'),
                'age' => $ticket->ageLabel($now),
                'is_overdue' => $ticket->isOverdue($now),
                'sla_due' => $ticket->slaDueAt()->format('M j, g:i A'),
                'resolution' => $ticket->resolution,
                'closed_at' => $ticket->closed_at?->format('M j, Y'),
                'is_closed' => $ticket->isClosed(),
            ],
            'timeline' => $this->timeline($ticket),
            'vendor' => $this->vendorPanel($ticket),

            /*
             * The history that is not the spine — reassignments, priority
             * changes, notes. Drawn nowhere on board 18 and carried anyway,
             * because it is the answer to "why has this taken three weeks" and
             * the timeline's six fixed stages cannot hold it.
             */
            'history' => $ticket->activity()
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->get()
                ->map(static fn (TicketActivity $entry): array => [
                    'id' => $entry->id,
                    'event' => $entry->event,
                    'note' => $entry->note,
                    'line' => $entry->timelineLine(),
                ])
                ->all(),
        ];
    }

    /**
     * Board 18's six stages, in lifecycle order and never in timestamp order.
     *
     * Each stage is drawn whether or not it has happened: done where an entry
     * exists, active on the first one that has not, pending after that. The
     * board greys the pending ones and draws no timestamp against them, which is
     * why a stage with no entry carries a null line rather than an empty string.
     *
     * @return list<array{key: string, label: string, state: string, line: string|null, note: string|null}>
     */
    public function timeline(MaintenanceTicket $ticket): array
    {
        /** @var array<string, TicketActivity> $entries */
        $entries = [];

        foreach ($ticket->activity()->whereNotNull('stage')->orderBy('occurred_at')->orderBy('id')->get() as $entry) {
            /*
             * FIRST ENTRY WINS PER STAGE. A ticket reassigned twice has three
             * `assigned` entries and board 18 draws one row: the timestamp the
             * resident was already shown, which is when the job was first given
             * to somebody. The later ones are history and live in `history`.
             */
            $entries[(string) $entry->stage] ??= $entry;
        }

        $rows = [];

        foreach (MaintenanceTicket::STAGES as $key => $label) {
            $entry = $entries[$key] ?? null;

            $rows[] = [
                'key' => $key,
                'label' => $label,
                'state' => $entry !== null ? 'done' : 'pending',
                'line' => $entry?->timelineLine(),
                'note' => $entry?->note,
            ];
        }

        /*
         * The furthest stage reached is ACTIVE while the ticket is still open.
         *
         * Board 18 draws "In progress" with the amber marker and its note
         * beneath, and that stage HAS an entry — so "the first stage with no
         * entry" would put the marker one row too low, on a stage nothing has
         * happened at. A closed ticket has no active stage at all: every stage
         * it reached is done, and the remaining ones stay pending.
         */
        if ($ticket->isOpen()) {
            for ($i = count($rows) - 1; $i >= 0; $i--) {
                if ($rows[$i]['state'] === 'done') {
                    $rows[$i]['state'] = 'active';

                    break;
                }
            }
        }

        return $rows;
    }

    /**
     * Board 18's vendor panel, or null where nobody is assigned.
     *
     * @return array<string, mixed>|null
     */
    public function vendorPanel(MaintenanceTicket $ticket): ?array
    {
        $vendor = $ticket->vendor;

        if ($vendor === null) {
            return null;
        }

        /*
         * "Tech: Owen Grant · (876) 555 0110 · ETA today 2:00–4:00 PM" — and
         * only the parts that exist. A vendor with no named technician and no
         * window still has a name and a phone number, and a line reading
         * "Tech:  ·  · ETA" is a broken panel rather than a missing detail.
         */
        $detail = array_filter([
            $ticket->technician_name === null ? null : 'Tech: '.$ticket->technician_name,
            $ticket->technician_phone ?? $vendor->contact_phone,
            $this->etaLabel($ticket),
        ]);

        return [
            'id' => $vendor->id,
            'name' => $vendor->name,
            'initials' => $vendor->initials(),
            'detail' => implode(' · ', $detail),
            'technician' => $ticket->technician_name,
            'phone' => $ticket->technician_phone ?? $vendor->contact_phone,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* internals */
    /* ------------------------------------------------------------------ */

    /** "ETA today 2:00–4:00 PM", or nothing where no window was given. */
    private function etaLabel(MaintenanceTicket $ticket): ?string
    {
        $from = $ticket->eta_starts_at;

        if ($from === null) {
            return null;
        }

        // "today" where it is today, and the date where it is not — a window a
        // resident reads as "today" on the wrong day is worse than a date.
        $day = $from->isToday() ? 'today' : $from->format('M j');

        $to = $ticket->eta_ends_at;

        return 'ETA '.$day.' '.$from->format('g:i A').($to === null ? '' : '–'.$to->format('g:i A'));
    }

    /**
     * The mean time to close, in days to one decimal place.
     *
     * @param  list<float>  $hours
     */
    private function averageResolutionDays(array $hours): float
    {
        if ($hours === []) {
            return 0.0;
        }

        return round(array_sum($hours) / count($hours) / 24, 1);
    }

    /** Whether a ticket belongs under one of board 17's chips. */
    private function matchesFilter(MaintenanceTicket $ticket, string $state, string $filter): bool
    {
        return match ($filter) {
            // The chip says "Open" and the stored status is `submitted`: board
            // 17's own wording for a ticket nobody has picked up yet.
            'open' => $ticket->status === MaintenanceTicket::SUBMITTED,
            'in_progress' => $state === 'progress' || $state === 'overdue',
            'completed' => $ticket->isClosed(),
            default => true,
        };
    }

    /**
     * Move the status and write the entry, in one transaction.
     */
    private function transition(
        MaintenanceTicket $ticket,
        string $to,
        string $event,
        ?string $stage = null,
        Carbon|string|null $occurredAt = null,
        ?string $note = null,
        string $actorKind = TicketActivity::STAFF,
        ?string $actorName = null,
        ?int $actorId = null,
    ): MaintenanceTicket {
        $when = $this->asMoment($occurredAt ?? Carbon::now());

        return DB::connection('tenant')->transaction(function () use (
            $ticket, $to, $event, $stage, $when, $note, $actorKind, $actorName, $actorId
        ): MaintenanceTicket {
            $from = $ticket->status;

            $ticket->forceFill(['status' => $to])->save();

            $this->record(
                ticket: $ticket,
                event: $event,
                stage: $stage,
                to: $to,
                from: $from,
                occurredAt: $when,
                note: $note,
                actorKind: $actorKind,
                actorName: $actorName,
                actorId: $actorId,
            );

            return $ticket;
        });
    }

    /**
     * Write one line of the ticket's history.
     *
     * PUBLIC, because a seeder builds board 18's timeline as it happened rather
     * than by replaying six method calls against a clock it does not control —
     * and because a state change recorded any other way would be one the
     * resident cannot follow.
     */
    public function record(
        MaintenanceTicket $ticket,
        string $event,
        ?string $stage = null,
        ?string $to = null,
        ?string $from = null,
        Carbon|string|null $occurredAt = null,
        ?string $note = null,
        string $actorKind = TicketActivity::STAFF,
        ?string $actorName = null,
        ?int $actorId = null,
    ): TicketActivity {
        return TicketActivity::create([
            'maintenance_ticket_id' => $ticket->id,
            'event' => $event,
            'stage' => $stage,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
            'actor_kind' => $actorKind,
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'occurred_at' => $this->asMoment($occurredAt ?? Carbon::now()),
        ]);
    }

    /**
     * The next ticket number, under a lock.
     *
     * Sequential with gaps, which is what board 17's own list shows: 1042, 1041,
     * 1039, 1037, 1031. Numbers are never reused — two people looking at the
     * same number have to be looking at the same job.
     */
    private function nextNumber(): int
    {
        $last = (int) MaintenanceTicket::query()->lockForUpdate()->max('number');

        return max($last, self::FIRST_NUMBER) + 1;
    }

    private function guardPriority(string $priority): void
    {
        if (! array_key_exists($priority, self::SLA_HOURS)) {
            throw new DomainException(sprintf(
                'No such priority [%s]. A ticket is high, medium or low, and each carries its own '.
                'service target measured from the moment it was reported.',
                $priority,
            ));
        }
    }

    private function asMoment(Carbon|string $moment): Carbon
    {
        return $moment instanceof Carbon ? $moment->copy() : Carbon::parse($moment);
    }
}
