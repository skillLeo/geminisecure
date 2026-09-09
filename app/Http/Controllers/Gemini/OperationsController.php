<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Gemini\SecurityOperations;
use Illuminate\Http\Request;
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
    public function roster(Request $request, SecurityOperations $operations): Response
    {
        return inertia('Gemini/Operations/Roster', $operations->roster($request->user()));
    }
}
