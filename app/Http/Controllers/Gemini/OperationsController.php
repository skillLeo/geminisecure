<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Enums\AccessScope;
use App\Http\Controllers\Controller;
use App\Models\Guard;
use App\Models\Post;
use App\Models\Shift;
use App\Services\Gemini\Roster;
use App\Services\Gemini\SecurityOperations;
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
     * at a gate. It needs a review and an acknowledgement cycle before it
     * needs a button.
     */
    private const NO_ORDER_WRITE = 'Not built yet — publishing an order set changes what guards are instructed to do at a post, and needs a review and acknowledgement cycle first.';

    /**
     * An incident record is evidence: an insurer, a client and the PSRA all
     * read it. Logging one wants a structured intake with severity criteria,
     * not a free-text box on a list screen.
     */
    private const NO_INCIDENT_WRITE = 'Not built yet — an incident record is evidence for an insurer and the PSRA, and needs a structured intake before it needs a button.';

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
        return inertia('Gemini/Operations/StandingOrders', [
            ...$operations->standingOrders($request->user()),
            'writeDisabledReason' => self::NO_ORDER_WRITE,
        ]);
    }

    /** What the gates are reporting, across every client — board screen 26. */
    public function gateActivity(Request $request, SecurityOperations $operations): Response
    {
        return inertia('Gemini/Operations/GateActivity', $operations->gateActivity($request->user()));
    }

    /** What has gone wrong on a Gemini post — board screen 27. */
    public function incidents(Request $request, SecurityOperations $operations): Response
    {
        return inertia('Gemini/Operations/Incidents', [
            ...$operations->incidents($request->user()),
            'writeDisabledReason' => self::NO_INCIDENT_WRITE,
        ]);
    }
}
