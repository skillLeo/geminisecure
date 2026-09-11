<?php

declare(strict_types=1);

namespace App\Http\Controllers\Estate;

use App\Http\Controllers\Controller;
use App\Services\Estate\Governance;
use App\Services\Estate\Notices;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Estate notices — board community-admin-32.
 *
 * POSTING IS `create`, NOT `approve`, AND THAT IS DELIBERATE. Publishing a
 * meeting is `approve` because it commits four hundred and fifty households to a
 * date they will arrange their Saturday around; a notice tells them something
 * and asks nothing of them. The two are not the same weight, and gating a gate
 * closure behind the President would mean the Property Manager who closed the
 * gate could not say so.
 *
 * IT SITS UNDER GOVERNANCE BECAUSE THE BOARD PUTS IT THERE. Board 32's own
 * sub-navigation is Notices / Meetings / Elections and its URL is
 * /governance/notices, so a notice is a governance act in this estate's own
 * language even though its author is often the Property Manager.
 */
class NoticesController extends Controller
{
    public function index(Request $request, Notices $notices, Governance $governance): Response
    {
        return inertia('Estate/Governance/Notices', [
            'estate' => ['name' => (string) tenant()->name],
            ...$notices->board(),
            'canPost' => $request->user()->can('estate.governance.create'),

            // Board 32's "Elections" tab leads where board 36's does — the latest
            // election this estate has held — by the same rule, asked once.
            'electionYear' => $governance->electionYear(),
            'reasons' => [
                'post' => 'Posting a notice tells every household on the estate something, so it needs '.
                    'Governance create access, which this role does not hold.',
            ],
        ]);
    }

    public function store(Request $request, Notices $notices): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:general,urgent'],
            'title' => ['required', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:4000'],
            'audience_scope' => ['required', 'string', 'in:estate,phase'],
            'audience_phase' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $notices->post($data, $request->user());
        } catch (DomainException $e) {
            return back()->withErrors(['notice' => $e->getMessage()]);
        }

        return back()->with('flash', 'Notice posted.');
    }
}
