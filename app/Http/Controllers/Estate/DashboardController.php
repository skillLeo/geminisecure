<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Enums\Console as ConsoleEnum;
use App\Http\Controllers\Controller;
use App\Models\Estate\Charge;
use App\Models\Estate\Household;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use App\Services\Navigation\ConsoleNavigation;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Estate Console landing screen.
 *
 * The Estate Console proper is Phase 5. Until then this is a real page rather
 * than a bare string: it uses the console shell, generates its navigation from
 * the role matrix like every other screen, and states plainly which modules
 * this role will get and which are not built yet.
 *
 * A placeholder that looks broken is indistinguishable from a broken page, and
 * costs a reader time working out which it is.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, ConsoleNavigation $navigation): Response
    {
        $tenant = tenant();

        return inertia('Estate/Dashboard', [
            'estate' => [
                'id' => $tenant->getTenantKey(),
                'name' => $tenant->name,
                'status' => $tenant->status,
            ],

            /*
             * Real counts from this estate's own database. Even a placeholder
             * should show true figures: a screen full of invented numbers
             * teaches the reader to distrust every number after it.
             */
            'counts' => [
                'units' => Unit::query()->count(),
                'households' => Household::query()->count(),
                'residents' => Resident::query()->count(),
                'outstanding_charges' => Charge::query()->where('status', 'outstanding')->count(),
            ],

            // What this role WILL see, generated from the matrix exactly as the
            // real console will, so the role model can be checked now.
            'modules' => $navigation->for($request->user(), ConsoleEnum::Estate),
        ]);
    }
}
