<?php

declare(strict_types=1);

namespace App\Services\Estate;

use App\Models\Estate\AmenityBooking;
use App\Models\Estate\DunningNotice;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Payment;
use App\Models\Estate\Resident;
use App\Models\Estate\UnitClaim;
use App\Models\SecurityIncident;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What has happened in this estate, and what still needs somebody (12 §2).
 *
 * TWO READS, ONE RULE. Neither the feed nor the notification centre keeps a
 * table of its own: a payment, an incident, a verification, a closed ticket, a
 * booking and a notice are each already a row in the table that owns that fact,
 * and a claim waiting for review is a row in `unit_claims`. Copying any of them
 * into an activity or notifications table would make a sixth copy that drifts —
 * a claim approved on board 34 would leave a notification standing that says it
 * still needs review, and the reader would believe the notification.
 *
 * WHAT IS STORED IS THE READ MARK, and only that. See the migration.
 */
class ActivityLog
{
    /** The dashboard panel's five. */
    public const DASHBOARD_ROWS = 5;

    /** One page of the full log. */
    public const PAGE = 25;

    /**
     * The estate's activity, newest first, merged from the tables that own it.
     *
     * The dashboard takes the first five of this; the full log pages through
     * it. `$perCategory` is how deep each table is read before the merge — the
     * dashboard needs one apiece, a page of twenty-five needs more, and reading
     * every row of six tables to print twenty-five is how a dashboard becomes
     * slow on the estate that has been running longest.
     *
     * @return array{rows: list<array<string, mixed>>, has_more: bool}
     */
    public function feed(int $limit = self::DASHBOARD_ROWS, int $offset = 0): array
    {
        $depth = $limit + $offset + 5;
        $rows = [];

        foreach (Payment::query()->with('unit')->orderByDesc('received_at')->limit($depth)->get() as $payment) {
            $rows[] = [
                'at' => $payment->received_at,
                'icon' => 'payment',
                'amber' => false,
                'title' => 'Payment received — '.($payment->unit->reference ?? 'unit'),
                'detail' => $this->money($payment->amount_minor).($payment->receipt_no === '' ? '' : ' · receipt '.$payment->receipt_no),
            ];
        }

        // Central table, filtered to this estate — the same reach board 26's
        // cross-tenant feed has in the other direction. See D-035.
        $incidents = SecurityIncident::query()
            ->where('tenant_id', (string) tenant()->getTenantKey())
            ->orderByDesc('occurred_at')
            ->limit($depth)
            ->get();

        foreach ($incidents as $incident) {
            $rows[] = [
                'at' => $incident->occurred_at,
                'icon' => 'incident',
                'amber' => true,
                'title' => 'New incident report',
                'detail' => $incident->kind,
            ];
        }

        $verified = Resident::query()
            ->with('household.unit')
            ->whereNotNull('verified_at')
            ->orderByDesc('verified_at')
            ->limit($depth)
            ->get();

        foreach ($verified as $resident) {
            $rows[] = [
                'at' => $resident->verified_at,
                'icon' => 'residents',
                'amber' => false,
                'title' => 'New resident verified — '.($resident->household?->unit->reference ?? 'unit'),
                'detail' => $resident->full_name,
            ];
        }

        $closed = MaintenanceTicket::query()
            ->with('unit')
            ->whereNotNull('closed_at')
            ->orderByDesc('closed_at')
            ->limit($depth)
            ->get();

        foreach ($closed as $ticket) {
            $rows[] = [
                'at' => $ticket->closed_at,
                'icon' => 'maintenance',
                'amber' => false,
                'title' => 'Maintenance ticket closed — '.($ticket->unit->reference ?? 'unit'),
                'detail' => trim((string) ($ticket->resolution ?? 'Resolved')),
            ];
        }

        $bookings = AmenityBooking::query()
            ->with(['amenity', 'unit'])
            ->orderByDesc('created_at')
            ->limit($depth)
            ->get();

        foreach ($bookings as $booking) {
            $rows[] = [
                'at' => $booking->created_at ?? $booking->starts_at,
                'icon' => 'facility',
                'amber' => false,
                'title' => ($booking->amenity->name ?? 'Amenity').' booked — '.$booking->starts_at->format('D, M j'),
                'detail' => (string) ($booking->unit->reference ?? 'unit'),
            ];
        }

        /*
         * Dunning is on the FULL log and not on the dashboard's five, and that
         * is the board's own shape rather than an omission: the panel it draws
         * carries five categories and no reminder among them. On a log that
         * exists to answer "what did the estate do in September", a notice sent
         * to a household is exactly the kind of act somebody comes looking for.
         */
        if ($limit > self::DASHBOARD_ROWS) {
            $notices = DunningNotice::query()
                ->with('unit')
                ->orderByDesc('sent_at')
                ->limit($depth)
                ->get();

            foreach ($notices as $notice) {
                $rows[] = [
                    'at' => $notice->sent_at,
                    'icon' => 'dunning',
                    'amber' => false,
                    'title' => $notice->template_label.' sent — '.($notice->unit->reference ?? 'unit'),
                    'detail' => $notice->channelLabel(),
                ];
            }
        }

        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['at'] instanceof Carbon));

        usort($rows, static fn (array $a, array $b): int => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        $page = array_slice($rows, $offset, $limit);

        return [
            'rows' => array_map(fn (array $row): array => [
                'icon' => $row['icon'],
                'amber' => $row['amber'],
                'title' => $row['title'],
                'detail' => $row['detail'],
                'when' => $this->relative($row['at']),
                'at' => $row['at']->format('M j, Y g:i A'),

                // The dashboard panel prints one line under the title; the full
                // log prints the detail and the time in their own columns.
                'subtitle' => trim($row['detail'].' · '.$this->relative($row['at']), ' ·'),
            ], $page),
            'has_more' => count($rows) > $offset + $limit,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* the notification centre */
    /* ------------------------------------------------------------------ */

    /**
     * What still needs somebody, and whether this viewer has seen it.
     *
     * DERIVED, NEVER STORED. An item that resolves stops being derived: a claim
     * approved on board 34 is gone from here the next time the bell is drawn,
     * with nothing to go back and tidy.
     *
     * GATED PER ITEM. A notification is a summary of a record, and a role that
     * may not read the record may not read the summary either — telling a
     * Property Manager that three households are ninety days late is telling
     * them what D-010 says they may not be told.
     *
     * @return array{items: list<array<string, mixed>>, unread: int}
     */
    public function attention(User $viewer): array
    {
        $items = [];

        if ($viewer->can('estate.residents.view')) {
            $claims = UnitClaim::query()->where('status', UnitClaim::PENDING)->orderByDesc('created_at')->limit(20)->get();

            foreach ($claims as $claim) {
                $items[] = [
                    'key' => 'claim:'.$claim->id,
                    'icon' => 'residents',
                    'title' => 'Unit claim needs review',
                    'detail' => 'A self-claimed unit did not match the register exactly.',
                    'at' => $claim->created_at,
                ];
            }
        }

        if ($viewer->can('estate.facilities.view')) {
            $overdue = MaintenanceTicket::query()
                ->with('unit')
                ->whereNull('closed_at')
                ->orderBy('reported_at')
                ->limit(50)
                ->get()
                ->filter(static fn (MaintenanceTicket $ticket): bool => $ticket->isOverdue(Carbon::now()))
                ->take(20);

            foreach ($overdue as $ticket) {
                $items[] = [
                    'key' => 'ticket:'.$ticket->id,
                    'icon' => 'maintenance',
                    'title' => $ticket->label().' is past its target',
                    'detail' => $ticket->title.' · '.$ticket->location_label,
                    'at' => $ticket->reported_at,
                ];
            }
        }

        if ($viewer->can('estate.facilities.approve')) {
            $awaiting = AmenityBooking::query()
                ->with(['amenity', 'unit'])
                ->where('deposit_state', AmenityBooking::DEPOSIT_HELD)
                ->where('ends_at', '<', Carbon::now())
                ->orderBy('ends_at')
                ->limit(20)
                ->get();

            foreach ($awaiting as $booking) {
                $items[] = [
                    'key' => 'deposit:'.$booking->id,
                    'icon' => 'facility',
                    'title' => 'A deposit is still held after the booking',
                    'detail' => ($booking->amenity->name ?? 'Amenity').' · '.($booking->unit->reference ?? 'unit'),
                    'at' => $booking->ends_at,
                ];
            }
        }

        usort($items, static fn (array $a, array $b): int => ($b['at']?->getTimestamp() ?? 0) <=> ($a['at']?->getTimestamp() ?? 0));

        $read = DB::connection('tenant')
            ->table('notification_reads')
            ->where('user_id', $viewer->getKey())
            ->pluck('read_at', 'item_key');

        $unread = 0;
        $out = [];

        foreach ($items as $item) {
            $isRead = isset($read[$item['key']]);
            $unread += $isRead ? 0 : 1;

            $out[] = [
                'key' => $item['key'],
                'icon' => $item['icon'],
                'title' => $item['title'],
                'detail' => $item['detail'],
                'when' => $item['at'] instanceof Carbon ? $this->relative($item['at']) : '',
                'is_read' => $isRead,
            ];
        }

        return ['items' => $out, 'unread' => $unread];
    }

    /**
     * Mark what this viewer is looking at as seen. Per viewer, never per estate.
     *
     * @param  list<mixed>  $keys
     */
    public function markRead(User $viewer, array $keys): int
    {
        $now = Carbon::now();
        $marked = 0;

        foreach (array_unique($keys) as $key) {
            if (! is_string($key) || $key === '' || strlen($key) > 64) {
                continue;
            }

            DB::connection('tenant')->table('notification_reads')->updateOrInsert(
                ['user_id' => $viewer->getKey(), 'item_key' => $key],
                ['read_at' => $now, 'updated_at' => $now, 'created_at' => $now],
            );

            $marked++;
        }

        return $marked;
    }

    /* ------------------------------------------------------------------ */
    /* formatting */
    /* ------------------------------------------------------------------ */

    /**
     * The board's own relative-time style — "2 min ago", "1 hour ago" — never
     * Carbon's spelled-out default, so a dashboard reads in the same words the
     * board draws.
     */
    public function relative(Carbon $at): string
    {
        $minutes = (int) $at->diffInMinutes(Carbon::now());
        $hours = intdiv($minutes, 60);
        $days = intdiv($minutes, 1440);

        return match (true) {
            $minutes < 1 => 'just now',
            $minutes < 60 => $minutes.' min ago',
            $minutes < 1440 => $hours.' hour'.($hours === 1 ? '' : 's').' ago',
            $minutes < 10_080 => $days.' day'.($days === 1 ? '' : 's').' ago',
            default => $at->format('M j, Y'),
        };
    }

    /** Minor units as the activity feed prints them — "$6,200", no decimals. */
    private function money(int $minor): string
    {
        return '$'.number_format($minor / 100, 0);
    }
}
