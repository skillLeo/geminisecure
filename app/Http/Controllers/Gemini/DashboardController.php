<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Gemini\PlatformNotifications;
use App\Services\Gemini\PlatformOverview;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Gemini Console dashboard, Super Admin screen 1.
 *
 * Thin by design: the figures come from a service, so the same numbers are
 * available to the /api/v1 endpoints the mobile apps will consume without
 * either surface reimplementing them.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, PlatformOverview $overview, PlatformNotifications $notifications): Response
    {
        return inertia('Gemini/Dashboard', [
            'kpis' => $overview->kpis(),
            'tiers' => $overview->mrrByTier(),
            'activity' => $overview->recentActivity(),

            // The bell's count (12 §2, item 41) — derived, like the centre itself.
            'unread' => $notifications->attention($request->user())['unread'],
        ]);
    }
}
