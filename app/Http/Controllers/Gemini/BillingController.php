<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Gemini\BillingOverview;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Billing and subscriptions — Super Admin board screen 32.
 *
 * Gemini Security billing its client estates. Resident dues are a different
 * ledger in a different database and never appear here.
 *
 * Thin by design: every figure comes from BillingOverview, so the /api/v1
 * endpoints can serve the same numbers without either surface reimplementing
 * the money rules.
 */
class BillingController extends Controller
{
    public function index(Request $request, BillingOverview $overview): Response
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        // The search runs here, against the database, not in the browser
        // against a page of rows already sent: a term that matches an invoice
        // from two years ago has to be able to find it.
        $search = trim($request->string('q')->toString());

        return inertia('Gemini/Billing/Index', [
            'kpis' => $overview->kpis(),
            'rows' => $overview->invoiceRows($search),
            'search' => $search,
        ]);
    }
}
