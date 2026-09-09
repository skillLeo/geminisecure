<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Http\Controllers\Controller;
use App\Services\Gemini\CrossTenantReports;
use Inertia\Response;

/**
 * Cross-tenant reports, Super Admin screens 36 to 40.
 */
class ReportController extends Controller
{
    public function index(CrossTenantReports $reports): Response
    {
        return inertia('Gemini/Reports/Index', $reports->catalogue());
    }
}
