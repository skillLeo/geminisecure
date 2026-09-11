<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\ReportController;
use Illuminate\Support\Facades\Route;

/*
| Cross-tenant reports — board screens super-admin-36 to 40, and 07.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
|
| Every figure reachable from here is an AGGREGATE derived from central data.
| No route in this module may fan out across estate databases: a cross-tenant
| report that opens each estate in turn is a tenant-isolation breach wearing a
| report's clothes.
*/

Route::middleware('can:gemini.cross_tenant_reports.view')->group(function () {
    Route::get('reports', [ReportController::class, 'index'])->name('gemini.cross_tenant_reports');

    Route::get('reports/mrr', [ReportController::class, 'mrr'])
        ->name('gemini.cross_tenant_reports.mrr');

    Route::get('reports/revenue', [ReportController::class, 'revenue'])
        ->name('gemini.cross_tenant_reports.revenue');

    /*
     * The two exports the boards draw (12 §1). Their own verb, because taking
     * the platform's commercial position away as a file is a different act from
     * reading it — and each writes an audit entry naming the clients in it.
     */
    Route::get('reports/mrr/export', [ReportController::class, 'exportMrr'])
        ->middleware('can:gemini.cross_tenant_reports.export')
        ->name('gemini.cross_tenant_reports.mrr.export');

    Route::get('reports/revenue/export', [ReportController::class, 'exportRevenue'])
        ->middleware('can:gemini.cross_tenant_reports.export')
        ->name('gemini.cross_tenant_reports.revenue.export');

    Route::get('reports/churn', [ReportController::class, 'churn'])
        ->name('gemini.cross_tenant_reports.churn');

    Route::get('reports/utilisation', [ReportController::class, 'utilisation'])
        ->name('gemini.cross_tenant_reports.utilisation');

    /*
     * Client health reads the central adoption roll-up, which is what lets it
     * obey the rule at the head of this file. Each side writes the capabilities
     * it owns; nothing in this route opens an estate database to find out.
     */
    Route::get('reports/client-health', [ReportController::class, 'clientHealth'])
        ->name('gemini.cross_tenant_reports.client_health');
});

/*
| Every route here is a GET, including the two exports.
|
| A cross-tenant export puts every client's commercial position into a file that
| leaves the platform, and this system cannot recall it. What it does — 12 §1,
| and the two routes above — is record that it left: the actor, the scope, the
| row count, the moment, and the clients named in the file.
*/
