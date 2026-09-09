<?php

declare(strict_types=1);

use App\Http\Controllers\Gemini\AuditLogController;
use Illuminate\Support\Facades\Route;

/*
| Access and audit log — board screen super-admin-41.
|
| Loaded by routes/web.php inside the shared auth group. Do not add `auth`
| here; do add the module's own `can:` permission to every route.
|
| READ ONLY, permanently. The audit log is append-only, so this module has no
| update or delete route and must never grow one — not even a soft delete.
*/

Route::get('audit', AuditLogController::class)
    ->middleware('can:gemini.access_audit_log.view')
    ->name('gemini.access_audit_log');
