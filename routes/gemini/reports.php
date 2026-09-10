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

    Route::get('reports/churn', [ReportController::class, 'churn'])
        ->name('gemini.cross_tenant_reports.churn');

    Route::get('reports/utilisation', [ReportController::class, 'utilisation'])
        ->name('gemini.cross_tenant_reports.utilisation');
});

/*
| Every route here is a GET, and there is no export route among them.
|
| A cross-tenant export puts every client's commercial position into a file
| that leaves the platform. It wants a recorded reason, an audit entry and a
| retention rule before it wants a route, so the controls are inert and say so.
*/
