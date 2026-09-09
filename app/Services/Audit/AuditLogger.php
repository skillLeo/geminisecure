<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Models\AuditEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes audit entries. The only sanctioned way to do so.
 *
 * The actor's name and role are denormalised onto the row rather than joined
 * at read time, because the log has to read correctly years later, after the
 * account has been renamed, re-roled or deleted. A join that returns null tells
 * a reviewer nothing about who did the thing.
 */
class AuditLogger
{
    public function record(
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $tenantId = null,
    ): AuditEntry {
        $actor = Auth::user();

        return AuditEntry::create([
            // Falls back to the current tenant so an action taken inside an
            // estate is attributed to it without every caller remembering.
            'tenant_id' => $tenantId ?? tenant()?->getTenantKey(),
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_role' => $actor?->roles->first()?->label,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'ip' => Request::ip(),
            'user_agent' => str(Request::userAgent() ?? '')->limit(250)->value(),
        ]);
    }
}
