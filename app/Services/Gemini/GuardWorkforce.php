<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Models\Guard;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads over the guard workforce, scoped to what the viewer may see.
 *
 * Scoping lives here rather than in the controller so the Inertia console and
 * the /api/v1 endpoints the mobile apps will call cannot drift into different
 * answers about who may see which guard.
 */
class GuardWorkforce
{
    /** @return array<int, array<string, mixed>> */
    public function roster(User $viewer): array
    {
        return $this->scoped($viewer)
            ->with(['post', 'estate'])
            ->orderBy('full_name')
            ->get()
            ->map(fn (Guard $guard) => $this->present($guard))
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function compliance(User $viewer): array
    {
        return $this->scoped($viewer)
            ->needingCompliance()
            ->with(['post', 'estate'])
            ->get()
            ->map(fn (Guard $guard) => [
                ...$this->present($guard),
                'licence_state' => $guard->licenceState(),
                'expires_on' => $guard->psra_expires_on?->toDateString(),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    public function summary(User $viewer): array
    {
        $base = $this->scoped($viewer);

        return [
            'total' => (clone $base)->count(),
            'active' => (clone $base)->where('status', 'active')->count(),
            'on_leave' => (clone $base)->where('status', 'on_leave')->count(),
            'compliance' => (clone $base)->needingCompliance()->count(),
        ];
    }

    /**
     * Narrows the query to the estates this viewer may see.
     *
     * Head of Security is the one Gemini role scoped to assigned sites. The
     * scope is read from the ROLE, never inferred from whether assignment rows
     * happen to exist: inferring it would silently promote a newly created
     * Head of Security with no assignments yet into seeing every guard on the
     * platform.
     *
     * @return Builder<Guard>
     */
    private function scoped(User $viewer): Builder
    {
        $query = Guard::query();

        if ($viewer->widestScope() === AccessScope::AssignedSites) {
            $query->whereIn('tenant_id', $viewer->accessibleEstateIds());
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Guard $guard): array
    {
        return [
            'id' => $guard->id,
            'name' => $guard->full_name,
            'initials' => $this->initials($guard->full_name),
            'employment_type' => str($guard->employment_type)->replace('_', '-')->ucfirst()->value(),
            'psra_number' => $guard->psra_number,
            'estate' => $guard->estate->name ?? 'Unassigned',
            'post' => $guard->post->name ?? '—',
            'status' => $guard->status,
            'status_label' => $guard->statusLabel(),
            'status_badge' => $guard->statusBadge(),
        ];
    }

    private function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->filter()
            ->take(2)
            ->map(fn (string $part) => strtoupper($part[0]))
            ->implode('');
    }
}
