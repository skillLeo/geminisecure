<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Models\Guard;
use App\Services\Gemini\GuardWorkforce;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Guard workforce, Super Admin screens 18 to 27.
 *
 * Every read is scoped by the service, not by the view, so a role restricted
 * to assigned sites cannot reach an unassigned estate's guards by sorting,
 * paginating or deep-linking past the list.
 */
class GuardController extends Controller
{
    public function index(Request $request, GuardWorkforce $workforce): Response
    {
        return inertia('Gemini/Guards/Index', [
            'guards' => $workforce->roster($request->user()),
            'summary' => $workforce->summary($request->user()),
            'scope' => $request->user()->widestScope()->value,
        ]);
    }

    public function compliance(Request $request, GuardWorkforce $workforce): Response
    {
        return inertia('Gemini/Guards/Compliance', [
            'guards' => $workforce->compliance($request->user()),
            'warningDays' => Guard::LICENCE_WARNING_DAYS,
        ]);
    }

    public function show(Request $request, Guard $guard): Response
    {
        /*
         * 404 rather than 403. A 403 confirms this guard exists and is posted
         * at an estate the viewer cannot see, which is more than a
         * site-restricted role should be able to learn.
         */
        abort_unless(
            $guard->tenant_id === null || $request->user()->canAccessEstate($guard->tenant_id),
            404,
        );

        $guard->load(['post', 'estate']);

        return inertia('Gemini/Guards/Show', [
            'guard' => [
                'id' => $guard->id,
                'name' => $guard->full_name,
                'employee_number' => $guard->employee_number,
                'psra_number' => $guard->psra_number,
                'psra_expires_on' => $guard->psra_expires_on?->toDateString(),
                'licence_state' => $guard->licenceState(),
                'employment_type' => $guard->employment_type,
                'status' => $guard->status,
                'status_label' => $guard->statusLabel(),
                'status_badge' => $guard->statusBadge(),
                'phone' => $guard->phone,
                'email' => $guard->email,
                'hired_on' => $guard->hired_on?->toDateString(),
                'estate' => $guard->estate->name ?? 'Unassigned',
                'post' => $guard->post->name ?? '—',
            ],
        ]);
    }
}
