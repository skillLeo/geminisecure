<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Services\Estate\Residents;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Estate structure — board screen community-admin-03.
 *
 * EVERY COUNT ON THIS SCREEN IS DERIVED FROM `units`. The unit total, the
 * occupied count, the vacancies and the occupancy bar are one GROUP BY over the
 * estate's own addresses; nothing on a phase row carries them. A stored unit
 * count would be free to say 92 about a phase somebody had added a lot to, and
 * this is precisely the screen a committee checks that arithmetic on.
 *
 * THE TWO TOPBAR CONTROLS DO NOTHING YET, AND EACH SAYS WHY. Adding a phase and
 * importing a unit list both bring addresses into existence, and an address is
 * what a household is filed at, what dues are billed to and what a guard admits
 * somebody to — none of which is a button's worth of consequence. They are drawn
 * inert with the reason on them rather than quietly working.
 */
class EstateStructureController extends Controller
{
    private const NO_ADD_PHASE_YET = 'Not built yet — a phase brings addresses into existence, and an address is what a household is filed at and what dues are billed to. It needs the blocks and the lot range decided together, which is a form and not a button.';

    private const NO_IMPORT_YET = 'Not built yet — importing a unit list creates hundreds of addresses at once, and a mis-parsed row becomes a lot that does not exist or a household filed at the wrong one. It needs a preview and a confirmation step before it needs a control.';

    /** The phase cards — board community-admin-03. */
    public function index(Request $request, Residents $residents): Response
    {
        return inertia('Estate/Structure/Index', [
            'estate' => ['name' => (string) tenant()->name],
            ...$residents->structureBoard(),

            /*
             * Both writes are `create` on estate structure, which the Treasurer
             * and the Admin Assistant do not hold at all — they cannot reach
             * this screen either, so the flag is about the President and the
             * Vice President, who may read the estate's layout and not change
             * it.
             */
            'canCreate' => $request->user()->can('estate.estate_structure.create'),
            'blockedReason' => 'Changing the estate\'s layout needs Estate structure create access. You are able to read this screen.',
            'reasons' => [
                'add' => self::NO_ADD_PHASE_YET,
                'import' => self::NO_IMPORT_YET,
            ],
        ]);
    }
}
