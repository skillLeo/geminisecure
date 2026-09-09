<?php

declare(strict_types=1);

namespace App\Http\Controllers\Gemini;

use App\Enums\AccessScope;
use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * Access and audit log, Super Admin screen 41.
 */
class AuditLogController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $query = AuditEntry::query()->with('estate')->latest('created_at');

        // A site-scoped role sees entries for its estates, plus platform-level
        // entries that concern no single estate.
        if ($user->widestScope() === AccessScope::AssignedSites) {
            $ids = $user->accessibleEstateIds();
            $query->where(fn ($q) => $q->whereIn('tenant_id', $ids)->orWhereNull('tenant_id'));
        }

        return inertia('Gemini/Audit/Index', [
            'entries' => $query->limit(200)->get()->map(fn (AuditEntry $entry) => [
                'id' => $entry->id,
                'timestamp' => $entry->created_at->format('Y-m-d H:i:s'),
                'actor' => $entry->actor_name ?? 'System',
                'actor_role' => $entry->actor_role,
                'action' => $entry->action,
                'estate' => $entry->estate->name ?? 'Platform',
                'entity' => $entry->entity_type
                    ? trim("{$entry->entity_type} {$entry->entity_id}")
                    : null,
            ]),
        ]);
    }
}
