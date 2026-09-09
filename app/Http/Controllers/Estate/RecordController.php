<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Models\Estate\Charge;
use App\Models\Estate\Household;
use App\Models\Estate\Journal;
use App\Models\Estate\Resident;
use App\Models\Estate\Unit;
use Illuminate\Http\JsonResponse;

/**
 * Read endpoints for estate records, addressed by id.
 *
 * These exist primarily so the Phase 1 gate has something to probe: an
 * authenticated committee member of one estate requesting a known record id
 * belonging to another. Any 200 fails the build.
 *
 * The lookups are deliberately naive — no ownership filter, no scope clause.
 * That is the point. If tenant isolation depended on a WHERE clause here, an
 * engineer would eventually forget one. Isolation comes from the connection
 * these models resolve through, which authenticates as the current estate's
 * own MySQL user and holds no grant on any other estate.
 */
class RecordController extends Controller
{
    /*
     * Route parameters always arrive as strings. Under strict_types an `int`
     * hint here raises a TypeError and surfaces as a 500 — which in an
     * isolation test reads as a denial and quietly passes. The routes
     * constrain {id} to digits, so the cast is safe.
     */
    public function resident(string $id): JsonResponse
    {
        return response()->json(Resident::findOrFail((int) $id));
    }

    public function household(string $id): JsonResponse
    {
        return response()->json(Household::findOrFail((int) $id));
    }

    public function unit(string $id): JsonResponse
    {
        return response()->json(Unit::findOrFail((int) $id));
    }

    public function charge(string $id): JsonResponse
    {
        return response()->json(Charge::findOrFail((int) $id));
    }

    public function journal(string $id): JsonResponse
    {
        return response()->json(Journal::findOrFail((int) $id));
    }
}
