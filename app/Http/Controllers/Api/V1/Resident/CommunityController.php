<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Resident;

use App\Api\ApiError;
use App\Api\DeviceContext;
use App\Http\Controllers\Controller;
use App\Models\Estate\MaintenanceTicket;
use App\Models\Estate\Meeting;
use App\Models\Estate\Notice;
use App\Models\Estate\TicketActivity;
use App\Services\Estate\Maintenance;
use App\Services\Estate\Notices;
use App\Services\ResidentApp\ResidentAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Maintenance tickets, notices and meetings, from the resident's side (13 D3).
 * Boards resident-app-15, -16 (tickets), -17 (notices), -18 (meetings).
 *
 * A TICKET IS THE ESTATE'S QUEUE. Reported here, it is the same row board 17 works
 * and board 18 tracks, through `Maintenance::report` — the SLA clock starts at the
 * resident's report, not when somebody in the office opens it.
 *
 * AN RSVP IS NOT ATTENDANCE. It tells the secretary who to expect. Quorum is
 * counted from the register taken at the meeting (board 36), and nothing here
 * writes to that register.
 */
class CommunityController extends Controller
{
    private const MEDIA_TYPES = ['image/jpeg', 'image/png', 'image/heic', 'image/heif', 'video/mp4', 'video/quicktime'];

    public function __construct(private readonly ResidentAccounts $accounts) {}

    public function tickets(DeviceContext $context): JsonResponse
    {
        $unit = $this->accounts->home($context)['unit'];

        $items = MaintenanceTicket::query()->with(['activity' => static fn ($q) => $q->orderBy('occurred_at')])
            ->where('unit_id', $unit->id)
            ->orderByDesc('reported_at')
            ->limit(100)
            ->get()
            ->map(fn (MaintenanceTicket $t): array => $this->ticketShape($t))
            ->all();

        return response()->json(['items' => $items]);
    }

    public function reportTicket(Request $request, DeviceContext $context, Maintenance $maintenance): JsonResponse
    {
        $home = $this->accounts->home($context);

        $data = $request->validate([
            'title' => ['required', 'string', 'min:4', 'max:140'],
            'category' => ['nullable', 'string', 'in:plumbing,electrical,security,landscaping,roads,water,waste,amenity,other'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', 'string', 'in:low,medium,high'],
        ]);

        $name = (string) ($home['resident']->full_name ?? $home['account']->full_name ?? 'Resident');

        $ticket = $maintenance->report(
            title: $data['title'],
            locationLabel: $data['location'] ?? $home['unit']->reference,
            priority: $data['priority'] ?? MaintenanceTicket::MEDIUM,
            unit: $home['unit'],
            category: $data['category'] ?? null,
            description: $data['description'] ?? null,
            reportedByName: $name,
        );

        $ticket->forceFill(['is_simulated' => $request->boolean('simulated')])->save();

        return response()->json($this->ticketShape($ticket->load('activity')), 201);
    }

    public function ticketMedia(Request $request, int $ticket, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $record = MaintenanceTicket::query()->whereKey($ticket)->where('unit_id', $home['unit']->id)->first();

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No ticket of your household\'s with that id.');
        }

        $request->validate(['file' => ['required', 'file', 'max:51200', 'mimetypes:'.implode(',', self::MEDIA_TYPES)]]);

        $file = $request->file('file');
        $bytes = (string) file_get_contents((string) $file->getRealPath());
        $sha256 = hash('sha256', $bytes);
        $path = 'ticket-media/'.$record->id.'/'.$sha256.'.'.strtolower($file->getClientOriginalExtension() ?: 'bin');

        Storage::disk('local')->put($path, $bytes);

        $id = DB::connection('tenant')->table('ticket_media')->insertGetId([
            'maintenance_ticket_id' => $record->id,
            'path' => $path,
            'filename' => mb_substr($file->getClientOriginalName(), 0, 190),
            'content_type' => (string) $file->getMimeType(),
            'bytes' => strlen($bytes),
            'sha256' => $sha256,
            'uploaded_by_account_id' => $home['account']->id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return response()->json([
            'media_id' => $id,
            'ticket_id' => $record->id,
            'filename' => mb_substr($file->getClientOriginalName(), 0, 190),
            'content_type' => (string) $file->getMimeType(),
            'bytes' => strlen($bytes),
            'sha256' => $sha256,
        ], 201);
    }

    public function notices(DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);

        $read = $home['resident'] === null ? collect() : DB::connection('tenant')->table('notice_reads')
            ->where('resident_id', $home['resident']->id)->pluck('read_at', 'notice_id');

        $items = $this->visibleNotices($home['unit']->block)
            ->map(static fn (Notice $n): array => [
                'id' => $n->id,
                'kind' => $n->kind,
                'title' => $n->title,
                'body' => $n->body,
                'author_name' => $n->author_name,
                'posted_as_role' => $n->posted_as_role,
                'audience' => $n->audience_scope === Notice::ONE_PHASE ? (string) $n->audience_phase : 'Whole estate',
                'published_at' => $n->published_at?->toIso8601String(),
                'read_at' => isset($read[$n->id]) ? Carbon::parse((string) $read[$n->id])->toIso8601String() : null,
            ])->all();

        return response()->json([
            'items' => $items,
            'unread' => count(array_filter($items, static fn (array $i): bool => $i['read_at'] === null)),
        ]);
    }

    public function readNotice(int $notice, DeviceContext $context, Notices $notices): JsonResponse
    {
        $home = $this->accounts->home($context);
        $record = $this->visibleNotices($home['unit']->block)->firstWhere('id', $notice);

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No notice for your household with that id.');
        }

        if ($home['resident'] === null) {
            throw ApiError::forbidden('account_pending', 'This account is not linked to a resident on the register yet.');
        }

        $notices->markRead($record, $home['resident']);

        $at = DB::connection('tenant')->table('notice_reads')->where('notice_id', $record->id)->where('resident_id', $home['resident']->id)->value('read_at');

        return response()->json(['notice_id' => $record->id, 'read_at' => Carbon::parse((string) $at)->toIso8601String()]);
    }

    public function meetings(DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $rsvps = DB::connection('tenant')->table('meeting_rsvps')->where('unit_id', $home['unit']->id)->pluck('response', 'meeting_id');

        $items = $this->visibleMeetings($home['unit']->block)
            ->map(static fn (Meeting $m): array => [
                'id' => $m->id,
                'type' => $m->type,
                'title' => $m->title,
                'starts_at' => $m->starts_at->toIso8601String(),
                'venue' => $m->venue,
                'virtual_link' => $m->virtual_link,
                'status' => $m->status,
                'recording_enabled' => (bool) $m->recording_enabled,
                'agenda' => $m->agenda->map(static fn ($item): array => [
                    'start_time' => $item->getAttribute('start_time'),
                    'text' => (string) $item->getAttribute('text'),
                ])->values()->all(),
                'minutes_available' => $m->minutes !== null,
                'my_rsvp' => $rsvps[$m->id] ?? null,
            ])->all();

        return response()->json(['items' => $items]);
    }

    public function rsvp(Request $request, int $meeting, DeviceContext $context): JsonResponse
    {
        $home = $this->accounts->home($context);
        $data = $request->validate(['response' => ['required', 'string', 'in:attending,apologies,not_attending']]);

        $record = $this->visibleMeetings($home['unit']->block)->firstWhere('id', $meeting);

        if ($record === null) {
            throw ApiError::notFound('not_found', 'No meeting for your household with that id.');
        }

        if ($record->status !== Meeting::SCHEDULED || $record->starts_at->isPast()) {
            throw ApiError::conflict('meeting_closed', 'This meeting has already started or is no longer scheduled.');
        }

        $now = Carbon::now();

        DB::connection('tenant')->table('meeting_rsvps')->updateOrInsert(
            ['meeting_id' => $record->id, 'unit_id' => $home['unit']->id],
            [
                'resident_id' => $home['resident']?->id,
                'responded_by_name' => $home['resident']->full_name ?? $home['account']->full_name,
                'response' => $data['response'],
                'responded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        return response()->json([
            'meeting_id' => $record->id,
            'response' => $data['response'],
            'responded_at' => $now->toIso8601String(),
            'households_attending' => DB::connection('tenant')->table('meeting_rsvps')->where('meeting_id', $record->id)->where('response', 'attending')->count(),
        ]);
    }

    /** @return Collection<int, Notice> */
    private function visibleNotices(?string $phase): Collection
    {
        return Notice::query()
            ->whereNotNull('published_at')
            ->where('published_at', '<=', Carbon::now())
            ->where(static fn ($q) => $q->where('audience_scope', Notice::ESTATE_WIDE)->orWhere(static fn ($p) => $p->where('audience_scope', Notice::ONE_PHASE)->where('audience_phase', $phase)))
            ->orderByDesc('published_at')
            ->limit(100)
            ->get();
    }

    /** @return Collection<int, Meeting> */
    private function visibleMeetings(?string $phase): Collection
    {
        return Meeting::query()->with(['agenda', 'minutes'])
            ->whereNotNull('published_at')
            ->where('type', '!=', Meeting::COMMITTEE)
            ->where('status', '!=', Meeting::DRAFT)
            ->where(static fn ($q) => $q->where('audience_scope', '!=', Meeting::PHASE_SUBSET)->orWhere('phase', $phase))
            ->where('starts_at', '>=', Carbon::now()->subMonths(6))
            ->orderByDesc('starts_at')
            ->limit(50)
            ->get();
    }

    /** @return array<string, mixed> */
    private function ticketShape(MaintenanceTicket $t): array
    {
        return [
            'id' => $t->id,
            'number' => $t->number,
            'title' => $t->title,
            'category' => $t->category,
            'location' => $t->location_label,
            'description' => $t->description,
            'priority' => $t->priority,
            'status' => $t->status,
            'reported_at' => $t->reported_at->toIso8601String(),
            'technician_name' => $t->technician_name,
            'eta_starts_at' => $t->eta_starts_at?->toIso8601String(),
            'eta_ends_at' => $t->eta_ends_at?->toIso8601String(),
            'resolution' => $t->resolution,
            'closed_at' => $t->closed_at?->toIso8601String(),
            'media_count' => DB::connection('tenant')->table('ticket_media')->where('maintenance_ticket_id', $t->id)->count(),
            'timeline' => $t->activity->map(static fn (TicketActivity $a): array => [
                'event' => $a->event,
                'status' => $a->to_status,
                'note' => $a->note,
                'occurred_at' => $a->occurred_at->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
