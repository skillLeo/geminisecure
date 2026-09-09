<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The permission levels drawn in both role access matrices.
 *
 * Legend, verbatim from the Estate matrix wireframe (06, lines 575-578):
 *   Full   Create, edit, approve
 *   View   Read-only
 *   Entry  Data entry, no approval
 *   —      No access
 */
enum AccessLevel: string
{
    case None = 'none';
    case View = 'view';
    case Entry = 'entry';
    case Full = 'full';

    /**
     * The verbs this level grants, before can_approve is applied.
     *
     * `Entry` may create and update but never delete, export or configure —
     * that is what "data entry, no approval" means in practice.
     *
     * @return list<PermissionVerb>
     */
    public function verbs(): array
    {
        return match ($this) {
            self::None => [],
            self::View => [PermissionVerb::View],
            self::Entry => [PermissionVerb::View, PermissionVerb::Create, PermissionVerb::Update],
            self::Full => [
                PermissionVerb::View,
                PermissionVerb::Create,
                PermissionVerb::Update,
                PermissionVerb::Delete,
                PermissionVerb::Export,
                PermissionVerb::Configure,
            ],
        };
    }

    /** Does this level put the module in the navigation at all? */
    public function isVisible(): bool
    {
        return $this !== self::None;
    }

    /** The pill label as drawn in the wireframe. */
    public function label(): string
    {
        return match ($this) {
            self::None => '—',
            self::View => 'View',
            self::Entry => 'Entry',
            self::Full => 'Full',
        };
    }
}
