<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Enums\AccessScope;
use App\Http\Controllers\Controller;
use App\Models\Guard;
use App\Models\Post;
use App\Models\SecurityIncident;
use App\Models\Shift;
use App\Models\Tenant;
use App\Services\Gemini\IncidentLog;
use App\Services\Gemini\Roster;
use App\Services\Gemini\SecurityOperations;
use App\Services\Gemini\StandingOrders;
use App\Support\SourceBadge;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Response;

/**
 * Security operations — board screens super-admin-24 to 27.
 *
 * The operational view across every client: the week's rota, the standing
 * orders in force, what the posts are reporting, and the incident log. All four
 * read gs_platform only.
 *
 * Nothing is decided here. The controller reads the request, hands the viewer
 * to the service and returns what comes back, because who may see which estate
 * is a rule that has to be enforced in SQL — a controller that filtered rows
 * after the fact would leave the rest reachable by paging or deep-linking.
 */
class OperationsController extends Controller
{
    /**
     * Publishing an order set changes what guards are legally instructed to do
     * at a gate, so it is Guard workforce create, and revising one update.
     */
    private const NO_ORDER_WRITE = 'Publishing or revising standing orders needs Guard workforce create and update access. You are able to read the library.';

    /** Company-wide orders, read by a role that covers only some clients. */
    private const ORDERS_NOT_YOURS = 'Company-wide orders apply at every client, so they are revised by a role that covers every client. You can write the orders for a post you cover.';

    /** Why a role that reads the incident log may not add to it or close one. */
    private const NO_INCIDENT_WRITE = 'Logging or closing an incident needs Guard workforce create and update access. You are able to read this log.';

    public function roster(Request $request, SecurityOperations $operations, Roster $roster): Response
    {
        $on = $request->string('date')->toString();
        $requested = preg_match('/^\d{4}-\d{2}-\d{2}$/', $on) === 1;
        $date = $requested ? $on : now()->toDateString();
        $monday = now()->startOfWeek(Carbon::MONDAY);

        return inertia('Gemini/Operations/Roster', [
            ...$operations->roster($request->user()),

            /*
             * The forward roster (12 §2, Wave 5). An OPEN shift is a real row
             * with no officer on it — a post needing somebody is a fact this
             * console has to hold, and a placeholder guard would instead show
             * an empty gate as manned.
             */
            'day' => $roster->day($date, $request->user()),

            /*
             * WHEN THE DAY VIEW IS DRAWN. Only when a day was asked for — each
             * heading on the week grid is a link to its own day — or when the
             * day has an open shift somebody must act on. Drawn on every load
             * it pushed the board's own week grid down the page for a reader
             * who came to read the week.
             */
            'dayRequested' => $requested,

            // The seven dates behind the grid's Mon–Sun headings, this week.
            'weekDates' => array_map(
                static fn (int $i): string => $monday->copy()->addDays($i)->toDateString(),
                range(0, 6),
            ),
            'posts' => $this->postsFor($request),
            'officers' => $this->officersFor($request),
            'canWrite' => $request->user()->can('gemini.guard_workforce.update'),
            'writeBlockedReason' => 'Posting a shift puts a client\'s gate on the rota, so it needs Guard workforce update access. You are able to read the roster.',
        ]);
    }

    /** Post an open shift — a post, a window, and nobody yet. */
    public function postShift(Request $request, Roster $roster): RedirectResponse
    {
        $data = $request->validate([
            'post_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:190'],
        ]);

        $post = Post::query()->find($data['post_id']);

        if ($post === null || ! $request->user()->canAccessEstate((string) $post->tenant_id)) {
            return back()->withErrors(['post_id' => 'That post is not one this role can roster.']);
        }

        try {
            $shift = $roster->postOpenShift($post, $data['starts_at'], $data['ends_at'], $request->user(), $data['reason'] ?? null);
        } catch (DomainException $refused) {
            return back()->withErrors(['starts_at' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', $post->name.' is on the rota for '.$shift->rostered_start->format('M j, g:i A').', open until somebody is assigned.');
    }

    /** Put an officer on an open shift. */
    public function assignShift(Request $request, int $shift, Roster $roster): RedirectResponse
    {
        $data = $request->validate(['guard_id' => ['required', 'integer']]);

        $row = Shift::query()->find($shift);
        $guard = Guard::query()->find($data['guard_id']);

        if ($row === null || $guard === null || ! $request->user()->canAccessEstate((string) $row->tenant_id)) {
            return back()->withErrors(['guard_id' => 'That shift or that officer is not one this role can roster.']);
        }

        try {
            $roster->assign($row, $guard, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['guard_id' => $refused->getMessage()]);
        }

        return back()->with('success', $guard->full_name.' is on that shift.');
    }

    /**
     * The posts this viewer may roster.
     *
     * @return list<array<string, mixed>>
     */
    private function postsFor(Request $request): array
    {
        $user = $request->user();

        return Post::query()
            ->where('is_active', true)
            ->when(
                $user->widestScope() === AccessScope::AssignedSites,
                static fn ($query) => $query->whereIn('tenant_id', $user->accessibleEstateIds()),
            )
            ->with('estate')
            ->orderBy('name')
            ->get()
            ->map(static fn (Post $post): array => [
                'id' => $post->id,
                'label' => $post->name.' — '.($post->estate->name ?? 'Unassigned'),
            ])
            ->all();
    }

    /**
     * The officers this viewer may put on a shift.
     *
     * SUSPENDED OFFICERS ARE ABSENT rather than listed and refused: a roster
     * that offered somebody who cannot legally stand a post would be inviting
     * the breach the compliance screens exist to prevent.
     *
     * @return list<array<string, mixed>>
     */
    private function officersFor(Request $request): array
    {
        $user = $request->user();

        return Guard::query()
            ->whereNotIn('status', ['suspended', 'inactive'])
            ->when(
                $user->widestScope() === AccessScope::AssignedSites,
                static fn ($query) => $query->whereIn('tenant_id', $user->accessibleEstateIds()),
            )
            ->orderBy('full_name')
            ->get()
            ->map(static fn (Guard $guard): array => [
                'id' => $guard->id,
                'label' => $guard->full_name.' · '.$guard->psra_number,
            ])
            ->all();
    }

    /** The written orders in force at every post — board screen 25. */
    public function standingOrders(Request $request, SecurityOperations $operations): Response
    {
        $user = $request->user();

        return inertia('Gemini/Operations/StandingOrders', [
            ...$operations->standingOrders($user),

            // Publishing a set (12 §2, item 28). A site-scoped role writes only
            // the orders for a post it covers; the form offers what it may.
            'canCreate' => $user->can('gemini.guard_workforce.create'),
            'categories' => $user->widestScope() === AccessScope::AssignedSites
                ? ['post_specific' => StandingOrders::CATEGORIES['post_specific']]
                : StandingOrders::CATEGORIES,
            'posts' => $this->postsFor($request),
            'writeDisabledReason' => self::NO_ORDER_WRITE,
        ]);
    }

    /** One order set, its versions and its acknowledgements. 404 outside scope. */
    public function standingOrder(Request $request, int $set, StandingOrders $orders): Response
    {
        $detail = $orders->detail($set, $request->user());

        abort_if($detail === null, 404);

        return inertia('Gemini/Operations/StandingOrder', [
            'set' => $detail,
            'canUpdate' => $request->user()->can('gemini.guard_workforce.update') && $detail['writable'],
            'writeDisabledReason' => $detail['writable'] ? self::NO_ORDER_WRITE : self::ORDERS_NOT_YOURS,
        ]);
    }

    /** Board 25's "New order set" — published at version 1. */
    public function createOrders(Request $request, StandingOrders $orders): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'in:general,emergency,post_specific'],
            'post_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:140'],
            'summary' => ['nullable', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:20000'],
            'effective_on' => ['required', 'date'],
        ]);

        try {
            $id = $orders->create($data, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['orders' => $refused->getMessage()])->withInput();
        }

        return redirect()->to('/guards/standing-orders/'.$id)
            ->with('success', 'Published as version 1. Guards on a post acknowledge it from their handsets.');
    }

    /** Publish the next version of a set. */
    public function reviseOrders(Request $request, int $set, StandingOrders $orders): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'summary' => ['nullable', 'string', 'max:190'],
            'effective_on' => ['required', 'date'],
            'change_note' => ['required', 'string', 'max:300'],
        ]);

        try {
            $version = $orders->revise($set, $data, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['orders' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', 'Version '.$version.' published. Every guard on post acknowledges it afresh — an acknowledgement of the last version does not carry over.');
    }

    /** Record a review that changes nothing. */
    public function reviewOrders(Request $request, int $set, StandingOrders $orders): RedirectResponse
    {
        try {
            $orders->markReviewed($set, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['orders' => $refused->getMessage()]);
        }

        return back()->with('success', 'Reviewed today. The text is unchanged, so no acknowledgement is reset.');
    }

    /** What the gates are reporting, across every client — board screen 26. */
    public function gateActivity(Request $request, SecurityOperations $operations): Response
    {
        return inertia('Gemini/Operations/GateActivity', $operations->gateActivity($request->user()));
    }

    /** What has gone wrong on a Gemini post — board screen 27. */
    public function incidents(Request $request, SecurityOperations $operations): Response
    {
        $user = $request->user();
        $scoped = $user->widestScope() === AccessScope::AssignedSites;

        $board = $operations->incidents($user);

        return inertia('Gemini/Operations/Incidents', [
            ...$board,

            // Category B (13 C3): a guard reports an incident from the post (13 D2).
            'sourceBadge' => SourceBadge::guard(SourceBadge::anySimulatedIn('mysql', 'security_incidents', array_column($board['incidents'], 'id'))),

            // The intake (12 §2, item 27): the clients and officers this role covers.
            'canLog' => $user->can('gemini.guard_workforce.create'),
            'clients' => Tenant::estates()
                ->when($scoped, static fn ($estates) => $estates->whereIn('id', $user->accessibleEstateIds()))
                ->sortBy('name')
                ->map(static fn (Tenant $estate): array => ['id' => (string) $estate->getTenantKey(), 'name' => (string) $estate->name])
                ->values()
                ->all(),
            'officers' => Guard::query()
                ->when($scoped, static fn ($query) => $query->whereIn('tenant_id', $user->accessibleEstateIds()))
                ->orderBy('full_name')
                ->get(['id', 'full_name', 'employee_number', 'tenant_id'])
                ->map(static fn (Guard $guard): array => [
                    'id' => $guard->id,
                    'label' => $guard->full_name.' · '.$guard->employee_number,
                    'tenant_id' => $guard->tenant_id,
                ])
                ->all(),
            'severities' => IncidentLog::SEVERITIES,
            'writeDisabledReason' => self::NO_INCIDENT_WRITE,
        ]);
    }

    /** One incident — board 27's "View". 404 outside the viewer's scope. */
    public function incident(Request $request, int $incident, IncidentLog $log): Response
    {
        $detail = $log->detail($incident, $request->user());

        abort_if($detail === null, 404);

        return inertia('Gemini/Operations/Incident', [
            'incident' => $detail,
            'sourceBadge' => SourceBadge::guard(SourceBadge::anySimulatedIn('mysql', 'security_incidents', [$incident])),
            'canResolve' => $request->user()->can('gemini.guard_workforce.update'),
            'writeDisabledReason' => self::NO_INCIDENT_WRITE,
        ]);
    }

    /** Board 27's "Log incident" — a structured intake. */
    public function logIncident(Request $request, IncidentLog $log): RedirectResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'string', 'max:64'],
            'guard_id' => ['nullable', 'integer'],
            'occurred_at' => ['required', 'date'],
            'kind' => ['required', 'string', 'max:160'],
            'severity' => ['required', 'string', 'in:low,med,high'],
            'detail' => ['required', 'string', 'max:4000'],
        ]);

        try {
            $incident = $log->log($data, $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['incident' => $refused->getMessage()])->withInput();
        }

        return redirect()->to('/guards/incidents/'.$incident->id)
            ->with('success', 'Incident logged. It stays open until what was done about it is recorded.');
    }

    /** Close an incident with what was done about it. */
    public function resolveIncident(Request $request, int $incident, IncidentLog $log): RedirectResponse
    {
        $data = $request->validate(['resolution' => ['required', 'string', 'max:4000']]);

        $row = SecurityIncident::query()->find($incident);

        if ($row === null) {
            abort(404);
        }

        try {
            $log->resolve($row, (string) $data['resolution'], $request->user());
        } catch (DomainException $refused) {
            return back()->withErrors(['resolution' => $refused->getMessage()])->withInput();
        }

        return back()->with('success', 'Incident closed with what was done. A closed incident is not rewritten.');
    }
}
