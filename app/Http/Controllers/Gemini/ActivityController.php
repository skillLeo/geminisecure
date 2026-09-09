<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Gemini\PlatformOverview;
use Inertia\Response;

/**
 * The full platform activity feed — board screen super-admin-03.
 *
 * Separate from DashboardController because it is a different screen with a
 * different board, and because that controller is invokable: bolting a second
 * action onto it would mean converting it and touching a screen that already
 * passes its diff at 0.71%.
 *
 * This is NOT the audit log. The audit log is the standing record of who did
 * what, append-only, with its own module and permission. This is the platform's
 * recent history in four kinds of event, and the dashboard's own panel shows
 * the first three rows of exactly this list.
 */
class ActivityController extends Controller
{
    public function __invoke(PlatformOverview $overview): Response
    {
        return inertia('Gemini/Activity/Index', [
            'events' => $overview->activityFeed(),
        ]);
    }
}
