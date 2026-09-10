<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Enums\AccessScope;
use App\Http\Controllers\Controller;
use App\Models\DuressAlert;
use App\Models\Guard;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dispatch\AlertIntake;
use App\Services\Dispatch\LiveMap;
use App\Services\Dispatch\PatrolMonitor;
use App\Services\Dispatch\PostCoverage;
use App\Services\Dispatch\RequestInbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Inertia\Response;
use RuntimeException;

/**
 * Dispatch — Super Admin screens 14 and 17.
 *
 * Two life-safety surfaces:
 *
 *   14  the active alerts queue, board `super-admin-14`
 *   17  the panic alert response screen, board `super-admin-17`
 *
 * Both read gs_platform and nothing else. A dispatcher watches every estate at
 * once, which is only possible because dispatch records are central; opening an
 * estate database from here would turn one screen into a fan-out across every
 * client and break the isolation model that makes the screen legal in the
 * first place.
 *
 * The queue is deliberately a MIXED feed, as the board draws it: a resident's
 * panic button, a guard-reported incident and a workforce compliance flag sit
 * in one list, each carrying the source tag that says where it came from. The
 * alternative — three separate screens — is not what was approved, and a
 * dispatcher does not have three screens' worth of attention during an
 * incident.
 *
 * NO MONETARY FIGURE APPEARS ANYWHERE HERE, and none of the tables these
 * methods read holds one. That is invariant 2 standing on structure rather
 * than on remembering to leave a column out.
 */
class DispatchController extends Controller
{
    /** The queue's filter tabs, in the order the board draws them. */
    private const TABS = [
        'all' => 'All alerts',
        'panic' => 'Panic alerts',
        'incidents' => 'Incidents',
        'resolved' => 'Resolved',
    ];

    /** Statuses that mean nobody is waiting on this any more. */
    private const SETTLED = ['resolved', 'false_alarm'];

    /**
     * Compliance flags sort below every live alert.
     *
     * DuressAlert::priority() returns 1 to 4. A lapsed licence matters, but it
     * never outranks a person asking for help, so it takes a rank below the
     * lowest an alert can hold rather than being interleaved by timestamp.
     */
    private const COMPLIANCE_PRIORITY = 5;

    /**
     * Dispatch live map — board super-admin-12.
     *
     * The screen polls this method every five seconds, which is what its
     * status pill claims, so it stays cheap: four indexed queries and no
     * per-row lookups.
     *
     * `estateIds` is sent so the screen can also open the private alert
     * channel for each estate it is showing. That channel carries a
     * notification and nothing else — never a position, because there is no
     * position anywhere in this module to carry.
     */
    public function map(Request $request, LiveMap $map): Response
    {
        $viewer = $request->user();

        return inertia('Gemini/Dispatch/Map', [
            'sections' => $this->sectionTabs('map'),
            ...$map->forViewer($viewer),
            'estateIds' => $this->visibleEstateIds($viewer),
            'scoped' => $viewer->widestScope() === AccessScope::AssignedSites,
        ]);
    }

    /**
     * Post coverage board — board super-admin-13.
     *
     * Not a live surface, and deliberately not polled. A roster changes when a
     * supervisor changes it, not second by second, and putting a three-second
     * refresh on a table nobody is watching for movement is load with no
     * reader.
     */
    public function coverage(Request $request, PostCoverage $coverage): Response
    {
        $viewer = $request->user();

        return inertia('Gemini/Dispatch/Coverage', [
            'sections' => $this->sectionTabs('coverage'),
            ...$coverage->forViewer($viewer),
            'scoped' => $viewer->widestScope() === AccessScope::AssignedSites,
        ]);
    }

    /**
     * Guard alertness and patrol monitoring — board super-admin-15.
     *
     * The one question is whether every guard who is supposed to be on duty
     * right now is still demonstrably awake and reporting, and SILENCE IS THE
     * FINDING: a guard who has stopped scanning has either sat down, been hurt
     * or lost their handset, and all three need a dispatcher. So this is a
     * life-safety surface like the queue, polled and streamed rather than left
     * to a reload.
     *
     * `estateIds` is sent so the screen can open the private alert channel for
     * each estate it is watching, exactly as the live map does. That channel
     * carries a notification and nothing else.
     *
     * NO POSITION LEAVES THIS METHOD. PatrolMonitor deliberately holds none, so
     * there is no coordinate anywhere in this response that could be broadcast
     * as where a guard is standing.
     */
    public function alertness(Request $request, PatrolMonitor $monitor): Response
    {
        $viewer = $request->user();

        return inertia('Gemini/Dispatch/Alertness', [
            'sections' => $this->sectionTabs('patrol'),
            ...$monitor->forViewer($viewer),
            'estateIds' => $this->visibleEstateIds($viewer),
            'scoped' => $viewer->widestScope() === AccessScope::AssignedSites,
        ]);
    }

    /**
     * Requests inbox — board super-admin-16.
     *
     * Leave, equipment and the message log in one place, because a dispatcher's
     * question is "what is waiting on me", not "what kind of thing is waiting
     * on me".
     *
     * Not polled, and deliberately not. A leave request is not a life-safety
     * event: it arrived hours ago and will still be there on the next reload,
     * and a five-second refresh on a queue a dispatcher is reading would move
     * rows out from under them mid-decision.
     */
    public function requests(Request $request, RequestInbox $inbox): Response
    {
        return inertia('Gemini/Dispatch/Requests', [
            'sections' => $this->sectionTabs('requests'),
            ...$inbox->forViewer($request->user()),
        ]);
    }

    /**
     * Approve or deny one request. A real state change, audited.
     *
     * Two dispatchers can be on this queue at once, and the second one must not
     * silently overwrite the first — so a request already decided comes back as
     * a message naming what happened to it rather than as a 500 or, worse, as a
     * quiet second write.
     */
    public function decide(Request $request, int $guardRequest, RequestInbox $inbox): RedirectResponse
    {
        try {
            $inbox->decide($request->user(), $guardRequest, $request->all());
        } catch (RuntimeException $refused) {
            return back()->withErrors(['decision' => $refused->getMessage()]);
        }

        return back();
    }

    /**
     * Active alerts queue — board super-admin-14.
     *
     * The screen polls, so this method is also the poll's endpoint. It stays
     * cheap for that reason: two indexed queries and no per-row lookups.
     */
    public function alerts(Request $request): Response
    {
        $viewer = $request->user();

        /*
         * The tab lives in the URL, not in component state.
         *
         * That is what makes it survive the screen's own three-second poll: a
         * partial reload re-runs this method, and a filter held only in Vue
         * would be silently discarded on the first refresh. It also makes a
         * filtered queue linkable, which matters when one dispatcher hands an
         * incident to another.
         *
         * Validated against the known set, so a hand-typed ?tab= produces the
         * default queue rather than an empty screen.
         */
        $requested = $request->query('tab');
        $tab = is_string($requested) && array_key_exists($requested, self::TABS) ? $requested : 'all';

        $rows = array_merge(
            $this->alertRows($viewer, $tab),
            $this->complianceRows($viewer, $tab),
        );

        /*
         * Ordered by what is being asked for, then by recency.
         *
         * Sorted here rather than in SQL because the ordering spans two tables
         * and one domain rule — panic first — that no column expresses.
         */
        usort($rows, function (array $a, array $b): int {
            return [$a['priority'], $b['at']] <=> [$b['priority'], $a['at']];
        });

        return inertia('Gemini/Dispatch/Alerts', [
            'sections' => $this->sectionTabs('alerts'),
            'tabs' => $this->filterTabs($tab),
            'tab' => $tab,

            /*
             * The estates whose alert channel this screen may listen on.
             *
             * THE QUEUE ITSELF WAS THE LAST DISPATCH SCREEN WITHOUT A SOCKET,
             * which is the wrong way round: the map and the alertness board both
             * pushed, and the one screen whose entire job is to show a panic the
             * moment it arrives sat on a three-second poll. Scoped exactly as the
             * queue is, so a site-scoped dispatcher subscribes to the estates
             * they can already see and no others.
             */
            'estateIds' => $this->visibleEstateIds($request->user()),
            // The sort keys were only ever for the sort. They do not travel to
            // the browser, where a second copy of the ordering rule could
            // start disagreeing with this one.
            'alerts' => array_map(
                static fn (array $row): array => Arr::except($row, ['priority', 'at']),
                $rows,
            ),
        ]);
    }

    /**
     * Panic alert response — board super-admin-17.
     *
     * A dispatcher holding this open must see the status change under them —
     * a guard acknowledging on the ground, the alert resolving — without
     * reloading, so the props below are all re-fetched by the screen's poll.
     */
    public function alert(Request $request, DuressAlert $alert): Response
    {
        $viewer = $request->user();

        // 404, not 403. A site-scoped dispatcher should not learn that an
        // alert exists at an estate they do not cover.
        abort_unless($viewer->canAccessEstate($alert->tenant_id), 404);

        $alert->load(['raisedByGuard.post', 'estate']);

        $responder = $this->responder($alert);
        $respondingGuard = $responder?->full_name;
        $who = $this->raisedBy($alert);
        [$statusLabel] = $this->statusPill($alert);

        return inertia('Gemini/Dispatch/Alert', [
            /*
             * One estate, and only this alert's own.
             *
             * The queue and the map watch every estate a dispatcher covers; a
             * single alert's response screen is about one incident at one
             * community, and subscribing it to the rest would push it a refresh
             * every time anything happened anywhere.
             */
            'estateIds' => [$alert->tenant_id],

            'alert' => [
                'headline' => $this->headline($alert),
                'who' => $who,
                'subtitle' => $this->join([
                    $who,
                    $alert->estate->name ?? 'Unknown estate',
                    $alert->unit_reference,
                ]),
                'status_label' => $statusLabel,
                'fired' => $alert->server_time->diffForHumans(),

                // "Nearest" is the board's label; see responder() for why this
                // is the guard on post rather than a distance calculation.
                'responder' => $respondingGuard ?? 'None on post',
                'location_source' => $this->locationSource($alert),
                'message_target' => $respondingGuard ?? 'the responding guard',
                'unit' => $alert->unit_reference ?? 'Not supplied',

                /*
                 * Three of the board's five resident rows are answered by this
                 * console with "not here". They are answered honestly rather
                 * than filled in, because household composition and emergency
                 * contacts live in the estate's own database and medical notes
                 * are not held centrally at all. Inventing a value would make
                 * a dispatcher trust a figure this console cannot see.
                 */
                'household' => 'In the estate database',
                'medical_notes' => 'Never held centrally',
                'emergency_contact' => 'In the estate database',
            ],
            'timeline' => $this->timeline($alert, $responder),
            'actions' => $this->actions($alert, $viewer, $responder),
        ]);
    }

    /**
     * Acknowledge an alert on the console's behalf.
     *
     * A real state change, audited. The guard's own acknowledgement will
     * arrive from the Guard App when it ships; until then a dispatcher who has
     * reached the guard by radio can record it here rather than leaving the
     * queue lying about who is responding.
     */
    public function acknowledge(Request $request, DuressAlert $alert, AlertIntake $intake): RedirectResponse
    {
        abort_unless($request->user()->canAccessEstate($alert->tenant_id), 404);

        $intake->acknowledge($alert, $request->user());

        return back();
    }

    /** Close an alert out. A real state change, audited. */
    public function resolve(Request $request, DuressAlert $alert, AlertIntake $intake): RedirectResponse
    {
        abort_unless($request->user()->canAccessEstate($alert->tenant_id), 404);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $intake->resolve($alert, $request->user(), $data['note'] ?? null);

        return back();
    }

    /* ---------------------------------------------------------------- rows */

    /**
     * Alert rows for the queue.
     *
     * @return list<array<string, mixed>>
     */
    private function alertRows(User $viewer, string $tab): array
    {
        $query = $this->scoped(DuressAlert::query(), $viewer)
            ->with(['raisedByGuard.post', 'estate']);

        match ($tab) {
            'panic' => $query->whereIn('kind', ['panic', 'duress'])->whereNotIn('status', self::SETTLED),
            'incidents' => $query->whereNotIn('kind', ['panic', 'duress'])->whereNotIn('status', self::SETTLED),
            'resolved' => $query->whereIn('status', self::SETTLED),
            default => $query->whereNotIn('status', self::SETTLED),
        };

        return $query->orderByDesc('server_time')->get()
            ->map(function (DuressAlert $alert): array {
                $fromGuard = $alert->guard_id !== null;
                [$statusLabel, $statusClass] = $this->statusPill($alert);
                $urgent = in_array($alert->kind, ['panic', 'duress'], true) && $alert->status === 'open';

                return [
                    'priority' => $alert->priority(),
                    'at' => $alert->server_time->format('Y-m-d H:i:s'),

                    'key' => 'alert-'.$alert->id,
                    'href' => '/dispatch/alerts/'.$alert->id,
                    'icon' => $this->alertIcon($alert),

                    // Both queue glyphs bake their own pair of stroke weights.
                    // The prop is sent anyway so the template never has to know
                    // which icons honour it.
                    'icon_stroke' => 1.7,
                    'urgent' => $urgent,
                    'title' => $this->raisedBy($alert).' — '.lcfirst($alert->kindLabel()),
                    'tag' => $fromGuard ? 'Guard-reported' : 'Resident',

                    // The red variant is the board's, and it is reserved for a
                    // resident: a person who is not trained, not on duty and
                    // not carrying a radio.
                    'tag_class' => $fromGuard ? 'source-tag' : 'source-tag resident',
                    'meta' => $this->join([
                        $alert->estate->name ?? 'Unknown estate',
                        $alert->unit_reference ?? $alert->raisedByGuard?->post?->name,

                        /*
                         * Offline capture, clock disagreement and simulated
                         * origin are stated on the row, never quietly
                         * reconciled. Each one changes how much a dispatcher
                         * should trust the timestamp beside it.
                         */
                        $alert->captured_offline ? 'Captured offline' : null,
                        $alert->clock_skewed ? 'Device clock disagrees' : null,
                        $alert->is_simulated ? 'Simulated' : null,

                        $alert->server_time->diffForHumans(),
                    ]),
                    'status' => $statusLabel,
                    'status_class' => $statusClass,
                ];
            })
            ->all();
    }

    /**
     * Workforce compliance rows — a guard on the roster with a lapsed licence.
     *
     * Real central data, and a genuine dispatch concern: an unlicensed guard
     * standing a post is an operational exposure, not a filing problem. It
     * belongs to neither the panic nor the incident tab, which is exactly
     * where the board puts it — under "All alerts", and under "Resolved" once
     * the guard has been taken off duty.
     *
     * @return list<array<string, mixed>>
     */
    private function complianceRows(User $viewer, string $tab): array
    {
        if (! in_array($tab, ['all', 'resolved'], true)) {
            return [];
        }

        $query = Guard::query()
            ->with(['estate', 'post'])
            ->whereNotNull('psra_expires_on')
            ->whereDate('psra_expires_on', '<', now()->toDateString());

        if ($viewer->widestScope() === AccessScope::AssignedSites) {
            $query->whereIn('tenant_id', $viewer->accessibleEstateIds());
        }

        if ($tab === 'resolved') {
            $query->whereIn('status', ['suspended', 'inactive']);
        }

        return $query->orderBy('psra_expires_on')->get()
            ->map(function (Guard $guard): array {
                $settled = in_array($guard->status, ['suspended', 'inactive'], true);
                $expiry = $guard->psra_expires_on;

                return [
                    'priority' => self::COMPLIANCE_PRIORITY,
                    'at' => $expiry?->format('Y-m-d H:i:s') ?? '',

                    'key' => 'guard-'.$guard->id,
                    'href' => '/guards/'.$guard->id,
                    'icon' => 'shield',

                    // The board draws the shield a notch lighter than the two
                    // alert glyphs beside it.
                    'icon_stroke' => 1.6,
                    'urgent' => false,
                    'title' => $guard->full_name.' — PSRA compliance',
                    'tag' => 'Guard workforce',
                    'tag_class' => 'source-tag',
                    'meta' => $this->join([
                        $guard->estate->name ?? 'Unassigned',
                        $guard->post?->name,
                        'Licence expired '.($expiry?->format('M j') ?? 'date unknown'),
                        $guard->updated_at?->format('M j'),
                    ]),
                    'status' => $settled
                        ? 'Resolved — '.lcfirst($guard->statusLabel())
                        : 'Unacknowledged',
                    'status_class' => $settled ? 'resolved' : 'unack',
                ];
            })
            ->all();
    }

    /* ------------------------------------------------------------- detail */

    /**
     * The response timeline — every state this alert has actually reached,
     * plus the ones it has not, drawn as pending.
     *
     * Nothing here is narrated that the row does not record. The board draws a
     * device-side detail ("panic button held 1.5s") that no column holds, so
     * this says what the row can prove instead of inventing telemetry.
     *
     * @return list<array{title: string, meta: string, pending: bool}>
     */
    private function timeline(DuressAlert $alert, ?Guard $responder): array
    {
        $acknowledgedBy = $alert->acknowledged_by === null
            ? null
            : User::query()->whereKey($alert->acknowledged_by)->value('name');

        return [
            [
                'title' => 'Alert fired',
                'meta' => $this->join([
                    $alert->guard_id !== null
                        ? 'Raised from a guard handset'
                        : 'Raised from a resident device',

                    // Said plainly on the surface that matters most. A
                    // dispatcher must never mistake the simulator for a person.
                    $alert->is_simulated ? 'simulated device' : null,

                    $alert->device_time?->format('g:i:s A') ?? 'Device clock not supplied',
                ]),
                'pending' => false,
            ],
            [
                'title' => 'Received by dispatch',
                'meta' => $this->join([
                    $alert->captured_offline
                        ? 'Captured offline and synced on reconnect'
                        : 'Routed to the gatehouse and this console',
                    $alert->clock_skewed
                        ? 'device clock disagrees with the server and is recorded as sent'
                        : null,
                    $alert->server_time->format('g:i:s A'),
                ]),
                'pending' => false,
            ],
            $alert->acknowledged_at !== null
                ? [
                    'title' => 'Acknowledged'.($acknowledgedBy === null ? '' : ' by '.$acknowledgedBy),
                    'meta' => $alert->acknowledged_at->format('g:i:s A'),
                    'pending' => false,
                ]
                : [
                    'title' => 'Awaiting guard acknowledgement',
                    'meta' => $responder === null
                        ? 'No guard is on post at this estate'
                        : $responder->full_name.' has not yet confirmed response',
                    'pending' => true,
                ],
            $alert->resolved_at !== null
                ? [
                    'title' => $alert->status === 'false_alarm' ? 'Resolved — false alarm' : 'Resolved',
                    'meta' => $this->join([
                        $alert->resolution_note,
                        $alert->resolved_at->format('g:i:s A'),
                    ]),
                    'pending' => false,
                ]
                : [
                    'title' => 'Awaiting resolution',
                    'meta' => 'The alert stays open until dispatch resolves it',
                    'pending' => true,
                ],
        ];
    }

    /**
     * What the four action buttons may do, and why not when they may not.
     *
     * Two of the board's four have no state to change: there is no table that
     * assigns a second guard to an alert, and no escalation record at all. The
     * screen renders those disabled with the reason attached rather than
     * accepting a click and doing nothing with it.
     *
     * @return array<string, mixed>
     */
    private function actions(DuressAlert $alert, User $viewer, ?Guard $responder): array
    {
        $mayAct = $viewer->can('gemini.dispatch.update');
        $settled = in_array($alert->status, self::SETTLED, true);
        $acknowledged = $alert->acknowledged_at !== null;

        return [
            'acknowledge_url' => '/dispatch/alerts/'.$alert->id.'/acknowledge',
            'acknowledge_label' => $responder === null
                ? 'Acknowledge alert'
                : 'Acknowledge on '.Str::before($responder->full_name, ' ')."'s behalf",
            'can_acknowledge' => $mayAct && ! $acknowledged && ! $settled,
            'acknowledge_title' => match (true) {
                ! $mayAct => 'Your role may watch dispatch but not act on an alert',
                $settled => 'This alert is already closed',
                $acknowledged => 'Already acknowledged at '.$alert->acknowledged_at->format('g:i A'),
                default => null,
            },

            'resolve_url' => '/dispatch/alerts/'.$alert->id.'/resolve',
            'can_resolve' => $mayAct && ! $settled,
            'resolve_title' => match (true) {
                ! $mayAct => 'Your role may watch dispatch but not act on an alert',
                $settled => 'This alert is already closed',
                default => null,
            },
        ];
    }

    /**
     * The guard this alert is being asked of.
     *
     * NOT computed by distance — no guard position is modelled anywhere in
     * this system, so nothing here could honestly answer "nearest". This is
     * the guard standing a post at the estate, which is the guard a dispatcher
     * would actually raise, and it is the same guard the acknowledge button
     * names.
     */
    private function responder(DuressAlert $alert): ?Guard
    {
        if ($alert->raisedByGuard !== null) {
            return $alert->raisedByGuard;
        }

        return Guard::query()
            ->with('post')
            ->where('tenant_id', $alert->tenant_id)
            ->where('status', 'active')
            ->whereNotNull('post_id')
            ->orderBy('full_name')
            ->first();
    }

    /** The board's phrasing for the screen heading and the hero. */
    private function headline(DuressAlert $alert): string
    {
        return match ($alert->kind) {
            'panic' => 'Panic alert',
            'duress' => 'Guard duress',
            'medical' => 'Medical alert',
            'fire' => 'Fire alert',
            'intrusion' => 'Intrusion alert',
            default => $alert->kindLabel(),
        };
    }

    /**
     * How well this alert's position is known.
     *
     * A refused location permission degrades accuracy and says so here; it
     * never removes panic (invariant 12), which is why "Not supplied" is a
     * displayed answer rather than an error.
     */
    private function locationSource(DuressAlert $alert): string
    {
        $gps = $alert->latitude !== null && $alert->longitude !== null;
        $unit = $alert->unit_reference !== null;

        return match (true) {
            $gps && $unit => 'GPS + unit fix',
            $gps => 'GPS fix',
            $unit => 'Unit reference',
            default => 'Not supplied',
        };
    }

    private function raisedBy(DuressAlert $alert): string
    {
        return $alert->raisedByGuard->full_name ?? $alert->raised_by_name ?? 'Unknown';
    }

    /**
     * Which of the board's two alert glyphs this kind is drawn with.
     *
     * The triangle is the board's mark for someone in danger; the ring is its
     * mark for an incident. Panic, duress and fire take the triangle.
     */
    private function alertIcon(DuressAlert $alert): string
    {
        return in_array($alert->kind, ['panic', 'duress', 'fire'], true) ? 'panic' : 'alert';
    }

    /**
     * The status pill: label, and the board class that colours it.
     *
     * @return array{0: string, 1: string}
     */
    private function statusPill(DuressAlert $alert): array
    {
        return match ($alert->status) {
            'open' => ['Unacknowledged', 'unack'],
            'acknowledged' => ['Acknowledged', 'responding'],
            'responding' => ['Responding', 'responding'],
            'false_alarm' => ['Resolved — false alarm', 'resolved'],
            default => ['Resolved', 'resolved'],
        };
    }

    /* ---------------------------------------------------------------- nav */

    /**
     * The dispatch section tabs.
     *
     * A section with no screen behind it yet is rendered disabled and says
     * why, rather than being hidden — a dispatcher who was told the live map
     * exists should see where it will be, not wonder whether their role is
     * missing it.
     *
     * @return list<array{label: string, href: string|null, active: bool, reason: string|null}>
     */
    private function sectionTabs(string $current): array
    {
        $sections = [
            ['key' => 'map', 'label' => 'Live map', 'href' => '/dispatch/map', 'reason' => null],
            ['key' => 'coverage', 'label' => 'Coverage board', 'href' => '/dispatch/coverage', 'reason' => null],
            ['key' => 'alerts', 'label' => 'Alerts', 'href' => '/dispatch/alerts', 'reason' => null],
            ['key' => 'patrol', 'label' => 'Patrol monitoring', 'href' => '/dispatch/alertness', 'reason' => null],
            ['key' => 'requests', 'label' => 'Requests', 'href' => '/dispatch/requests', 'reason' => null],
        ];

        return array_map(
            static fn (array $section): array => [
                'label' => $section['label'],
                'href' => $section['href'],
                'active' => $section['key'] === $current,
                'reason' => $section['reason'],
            ],
            $sections,
        );
    }

    /**
     * The estates this viewer may watch.
     *
     * Sent to the live screens so each can open the private alert channel for
     * every estate it is showing — and for no estate it is not.
     *
     * @return list<string>
     */
    private function visibleEstateIds(User $viewer): array
    {
        if ($viewer->widestScope() === AccessScope::AssignedSites) {
            return $viewer->accessibleEstateIds();
        }

        /** @var list<string> $ids */
        $ids = Tenant::query()->orderBy('id')->pluck('id')->all();

        return $ids;
    }

    /**
     * The queue's filter tabs. Each is a real link and the filter is applied
     * in SQL, so the tab survives a reload, a bookmark and the screen's poll.
     *
     * @return list<array{label: string, href: string, active: bool}>
     */
    private function filterTabs(string $current): array
    {
        $tabs = [];

        foreach (self::TABS as $key => $label) {
            $tabs[] = [
                'label' => $label,
                'href' => $key === 'all' ? '/dispatch/alerts' : '/dispatch/alerts?tab='.$key,
                'active' => $key === $current,
            ];
        }

        return $tabs;
    }

    /* -------------------------------------------------------------- utils */

    /**
     * The board's meta separator, skipping anything absent.
     *
     * @param  array<int, string|null>  $parts
     */
    private function join(array $parts): string
    {
        return implode(' · ', array_filter($parts, fn (?string $part): bool => $part !== null && $part !== ''));
    }

    /**
     * Narrows to the estates this viewer may see.
     *
     * Head of Security watches assigned sites only. A dispatcher watches all.
     *
     * @param  Builder<DuressAlert>  $query
     * @return Builder<DuressAlert>
     */
    private function scoped(Builder $query, User $viewer): Builder
    {
        if ($viewer->widestScope() === AccessScope::AssignedSites) {
            $query->whereIn('tenant_id', $viewer->accessibleEstateIds());
        }

        return $query;
    }
}
