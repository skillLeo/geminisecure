<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Enums\AccessScope;
use App\Http\Controllers\Controller;
use App\Models\DuressAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Dispatch, Super Admin screens 12 to 17.
 *
 * Every figure on these screens originates on a mobile device, so each one
 * carries a source badge. Until the Guard and Resident apps exist the
 * simulator drives the same tables through the same endpoints, and the badge
 * says plainly which it is rather than letting simulated data pass as real.
 */
class DispatchController extends Controller
{
    public function alerts(Request $request): Response
    {
        $alerts = $this->scoped(DuressAlert::query(), $request->user())
            ->active()
            ->with(['raisedByGuard', 'estate'])
            ->get()
            // Panic and duress first: a person is asking for help, and that
            // outranks recency. Sorted in PHP because the ordering is a domain
            // rule that belongs with the model, not a column.
            ->sortBy([
                fn (DuressAlert $a, DuressAlert $b) => $a->priority() <=> $b->priority(),
                fn (DuressAlert $a, DuressAlert $b) => $b->server_time <=> $a->server_time,
            ])
            ->values();

        return inertia('Gemini/Dispatch/Alerts', [
            'alerts' => $alerts->map(fn (DuressAlert $alert) => [
                'id' => $alert->id,
                'kind' => $alert->kind,
                'kind_label' => $alert->kindLabel(),
                'status' => $alert->status,
                'status_badge' => $alert->statusBadge(),
                'estate' => $alert->estate->name ?? 'Unknown',
                'raised_by' => $alert->raisedByGuard->full_name ?? $alert->raised_by_name ?? 'Unknown',
                'unit' => $alert->unit_reference,
                'server_time' => $alert->server_time->format('Y-m-d H:i:s'),
                'clock_skewed' => $alert->clock_skewed,
                'captured_offline' => $alert->captured_offline,
                'is_simulated' => $alert->is_simulated,
            ]),
            // The badge tells the truth about the whole screen: if every row
            // is simulated, say so; if any row is real, do not claim otherwise.
            'anyReal' => $alerts->contains(fn (DuressAlert $a) => ! $a->is_simulated),
        ]);
    }

    /**
     * Panic alert response, Super Admin screen 17.
     *
     * A life-safety surface: the screen polls, so a dispatcher watching one
     * alert sees it change status under them without reloading.
     */
    public function alert(Request $request, DuressAlert $alert): Response
    {
        // 404, not 403. A site-scoped dispatcher should not learn that an
        // alert exists at an estate they do not cover.
        abort_unless(
            $request->user()->canAccessEstate($alert->tenant_id),
            404,
        );

        $alert->load(['raisedByGuard', 'estate']);

        return inertia('Gemini/Dispatch/Alert', [
            'alert' => [
                'id' => $alert->id,
                'kind' => $alert->kind,
                'kind_label' => $alert->kindLabel(),
                'status' => $alert->status,
                'status_badge' => $alert->statusBadge(),
                'estate' => $alert->estate->name ?? 'Unknown',
                'raised_by' => $alert->raisedByGuard->full_name ?? $alert->raised_by_name ?? 'Unknown',
                'unit' => $alert->unit_reference,
                'device_time' => $alert->device_time?->format('Y-m-d H:i:s'),
                'server_time' => $alert->server_time->format('Y-m-d H:i:s'),
                'clock_skewed' => $alert->clock_skewed,
                'captured_offline' => $alert->captured_offline,
                'is_simulated' => $alert->is_simulated,

                /*
                 * Coarse, and only when the device supplied it. Refusing a
                 * location permission degrades accuracy and says so; it never
                 * removes panic (invariant 12).
                 */
                'has_location' => $alert->latitude !== null && $alert->longitude !== null,
                'latitude' => $alert->latitude,
                'longitude' => $alert->longitude,
            ],
        ]);
    }

    /**
     * Narrows to the estates this viewer may see.
     *
     * Head of Security watches assigned sites only. A dispatcher watches all.
     */
    /**
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
