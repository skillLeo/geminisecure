<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\GuardRequest;
use App\Models\SecurityIncident;
use App\Models\StatutoryFiling;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Gemini Console's notification centre — board super-admin-01's bell (12 §2,
 * item 41): "read/unread model, feeds from existing events".
 *
 * DERIVED, NEVER STORED. Every item is a row in the table that owns the fact —
 * an alert still open, a request still pending, a licence about to lapse, an
 * incident nobody has closed, an invoice past due, a return owed — and an item
 * stops appearing the moment its record is dealt with. Only the read mark is
 * kept (`platform_notification_reads`).
 *
 * GATED PER ITEM, AND SCOPED. A notification is a summary of a record, so a
 * role that may not open the record does not get the summary; and a Head of
 * Security scoped to assigned sites is told only about the sites they cover.
 */
final class PlatformNotifications
{
    /** A licence inside this many days of lapsing needs somebody now. */
    private const LICENCE_WINDOW_DAYS = 30;

    private const PER_KIND = 20;

    /**
     * @return array{items: list<array<string, mixed>>, unread: int}
     */
    public function attention(User $viewer): array
    {
        $items = [];
        $scoped = $viewer->widestScope() === AccessScope::AssignedSites;
        $sites = $scoped ? $viewer->accessibleEstateIds() : [];

        if ($viewer->can('gemini.dispatch.view')) {
            foreach (DuressAlert::query()->active()->with('estate')->when($scoped, static fn ($query) => $query->whereIn('tenant_id', $sites))->limit(self::PER_KIND)->get() as $alert) {
                $items[] = [
                    'key' => 'alert:'.$alert->id.':'.$alert->status,
                    'icon' => 'alert',
                    'urgent' => true,
                    'title' => ucfirst($alert->kind).' alert — '.($alert->estate->name ?? $alert->tenant_id),
                    'detail' => 'Status: '.str_replace('_', ' ', $alert->status).($alert->raised_by_name === null ? '' : ' · raised by '.$alert->raised_by_name),
                    'href' => '/dispatch/alerts/'.$alert->id,
                    'at' => $alert->server_time,
                ];
            }

            foreach (GuardRequest::query()->pending()->with(['officer', 'estate'])->when($scoped, static fn ($query) => $query->whereIn('tenant_id', $sites))->orderByDesc('created_at')->limit(self::PER_KIND)->get() as $request) {
                $items[] = [
                    'key' => 'request:'.$request->id,
                    'icon' => 'inbox',
                    'urgent' => false,
                    'title' => ucfirst($request->kind).' request waiting — '.($request->officer->full_name ?? 'a guard'),
                    'detail' => $request->subject,
                    'href' => '/dispatch/requests',
                    'at' => $request->created_at,
                ];
            }
        }

        if ($viewer->can('gemini.guard_workforce.view')) {
            $lapsing = Guard::query()
                ->when($scoped, static fn ($query) => $query->whereIn('tenant_id', $sites))
                ->whereNotIn('status', ['inactive'])
                ->whereNotNull('psra_expires_on')
                ->whereDate('psra_expires_on', '<=', Carbon::today()->addDays(self::LICENCE_WINDOW_DAYS))
                ->orderBy('psra_expires_on')
                ->limit(self::PER_KIND)
                ->get();

            foreach ($lapsing as $guard) {
                $expires = $guard->psra_expires_on;
                $lapsed = $expires !== null && $expires->lessThan(Carbon::today());

                $items[] = [
                    // The expiry is in the key, so a renewed licence that later
                    // approaches its NEW date is a new notification, not a read one.
                    'key' => 'licence:'.$guard->id.':'.$expires?->toDateString(),
                    'icon' => 'licence',
                    'urgent' => $lapsed,
                    'title' => $lapsed ? 'PSRA licence lapsed — '.$guard->full_name : 'PSRA licence lapses soon — '.$guard->full_name,
                    'detail' => ($lapsed ? 'Expired ' : 'Expires ').$expires?->format('M j, Y'),
                    'href' => '/guards/'.$guard->id,
                    'at' => $expires?->copy()->startOfDay(),
                ];
            }

            $open = SecurityIncident::query()->with('estate')
                ->when($scoped, static fn ($query) => $query->whereIn('tenant_id', $sites))
                ->where('status', SecurityIncident::OPEN)
                ->orderByDesc('occurred_at')
                ->limit(self::PER_KIND)
                ->get();

            foreach ($open as $incident) {
                $items[] = [
                    'key' => 'incident:'.$incident->id,
                    'icon' => 'incident',
                    'urgent' => $incident->severity === 'high',
                    'title' => 'Open incident — '.($incident->estate->name ?? $incident->tenant_id),
                    'detail' => $incident->kind,
                    'href' => '/guards/incidents/'.$incident->id,
                    'at' => $incident->occurred_at,
                ];
            }
        }

        if ($viewer->can('gemini.billing_subscriptions.view')) {
            $overdue = DB::connection('mysql')->table('invoices')
                ->join('tenants', 'tenants.id', '=', 'invoices.tenant_id')
                ->whereIn('invoices.status', ['issued', 'overdue'])
                ->whereDate('invoices.due_on', '<', Carbon::today())
                ->orderBy('invoices.due_on')
                ->limit(self::PER_KIND)
                ->get(['invoices.id', 'invoices.reference', 'invoices.due_on', 'tenants.name as client']);

            foreach ($overdue as $invoice) {
                $items[] = [
                    'key' => 'invoice:'.$invoice->id,
                    'icon' => 'billing',
                    'urgent' => false,
                    'title' => 'Invoice past due — '.$invoice->client,
                    'detail' => $invoice->reference.' · due '.Carbon::parse((string) $invoice->due_on)->format('M j, Y'),
                    'href' => '/billing/invoices/'.$invoice->id,
                    'at' => Carbon::parse((string) $invoice->due_on),
                ];
            }
        }

        if ($viewer->can('gemini.payroll_accounting.view')) {
            $owed = StatutoryFiling::query()
                ->where('status', '!=', StatutoryFiling::FILED)
                ->whereDate('period_end', '<=', Carbon::today())
                ->orderBy('due_on')
                ->limit(self::PER_KIND)
                ->get();

            foreach ($owed as $filing) {
                $late = $filing->isOverdue(Carbon::today());

                $items[] = [
                    'key' => 'filing:'.$filing->id,
                    'icon' => 'payroll',
                    'urgent' => $late,
                    'title' => ($late ? 'Return overdue — ' : 'Return due — ').$filing->form_code.' '.$filing->period_label,
                    'detail' => 'Due '.$filing->due_on->format('M j, Y'),
                    'href' => '/payroll/filings',
                    'at' => $filing->due_on->copy()->startOfDay(),
                ];
            }
        }

        usort($items, static fn (array $a, array $b): int => [(int) ! $a['urgent'], -($a['at']?->getTimestamp() ?? 0)]
            <=> [(int) ! $b['urgent'], -($b['at']?->getTimestamp() ?? 0)]);

        $read = DB::connection('mysql')
            ->table('platform_notification_reads')
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
                'urgent' => $item['urgent'],
                'title' => $item['title'],
                'detail' => $item['detail'],
                'href' => $item['href'],
                'when' => $item['at'] instanceof Carbon ? $item['at']->format('M j, Y') : '',
                'is_read' => $isRead,
            ];
        }

        return ['items' => $out, 'unread' => $unread];
    }

    /**
     * Mark items seen, for this person only.
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

            DB::connection('mysql')->table('platform_notification_reads')->updateOrInsert(
                ['user_id' => $viewer->getKey(), 'item_key' => $key],
                ['read_at' => $now, 'updated_at' => $now, 'created_at' => $now],
            );

            $marked++;
        }

        return $marked;
    }
}
