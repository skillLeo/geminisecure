<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Gemini\PlatformOverview;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Clients (estates), Super Admin screens 6 to 12.
 *
 * A "client" here is an estate that subscribes to GeminiSecure. Listing and
 * detail both read from gs_platform only; none of this opens an estate
 * database.
 */
class ClientController extends Controller
{
    public function index(Request $request, PlatformOverview $overview): Response
    {
        $user = $request->user();

        $query = Tenant::query()->orderBy('name');

        /*
         * A role scoped to assigned sites sees only those. Applied here rather
         * than in the view so a scoped user cannot reach an unassigned estate
         * by paginating, sorting or deep-linking past the list.
         */
        if ($user->widestScope()->value === 'assigned_sites') {
            $query->whereIn('id', $user->accessibleEstateIds());
        }

        return inertia('Gemini/Clients/Index', [
            'estates' => $query->get()->map(fn (Tenant $tenant) => [
                'id' => $tenant->getTenantKey(),
                'name' => $tenant->name,
                'status' => $tenant->status,
                'provisioned_at' => $tenant->provisioned_at?->toDateString(),
            ]),
            'scope' => $user->widestScope()->value,
        ]);
    }

    public function show(Request $request, string $tenant): Response
    {
        $estate = Tenant::findOrFail($tenant);

        // 404 rather than 403: a 403 confirms the estate exists.
        abort_unless($request->user()->canAccessEstate($estate->getTenantKey()), 404);

        return inertia('Gemini/Clients/Show', [
            'estate' => [
                'id' => $estate->getTenantKey(),
                'name' => $estate->name,
                'status' => $estate->status,
                'provisioned_at' => $estate->provisioned_at?->toDateString(),
                'database' => $estate->database()->getName(),
            ],
        ]);
    }
}
