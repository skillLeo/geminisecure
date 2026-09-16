<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Enums\AccessScope;
use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Who holds a Gemini Console account — board 42's "Platform admins" tab (12 §2,
 * item 43: build the missing section).
 *
 * THE PLATFORM'S OWN STAFF, ISSUED BY INVITATION. Accounts come into existence
 * only through `Invitations` — the same fourteen-day link, naming inviter and
 * role, that an estate uses — so this screen lists them, sends and withdraws
 * invitations, and changes an existing person's role, sites or standing.
 *
 * WHAT IT CANNOT DO IS WHAT MAKES IT SAFE. Nobody changes their own role or
 * suspends themselves (a slip would lock the platform's only administrator
 * out), the last active Director cannot be demoted or suspended, and a Gemini
 * account is never given an estate role. What a role may reach is the role
 * matrix's, which stays a read: this moves people between roles and never
 * changes what a role can do.
 *
 * A SITE-SCOPED ROLE NEEDS SITES. A Head of Security scoped to assigned sites
 * sees nothing until sites are assigned, so the sites are set here with the
 * role, and cleared when a person moves to a role that covers every client.
 */
final class PlatformAdmins
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array<string, mixed>
     */
    public function board(User $viewer): array
    {
        $users = User::query()
            ->with(['roles', 'assignments'])
            ->where('console', Console::Gemini->value)
            ->orderBy('name')
            ->get();

        $clients = Tenant::estates()->sortBy('name')->mapWithKeys(static fn (Tenant $t): array => [(string) $t->getTenantKey() => (string) $t->name]);

        $directors = $this->activeDirectorIds();

        return [
            'people' => $users->map(function (User $user) use ($viewer, $clients, $directors): array {
                /** @var Role|null $role */
                $role = $user->roles->first();
                $scoped = $role !== null && $role->scope_default === AccessScope::AssignedSites;
                $sites = $user->assignments->where('is_active', true)->pluck('tenant_id')->map(static fn ($id): string => (string) $id)->values()->all();

                return [
                    'id' => $user->id,
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                    'role' => $role?->name,
                    'role_label' => (string) ($role->label ?? $role->name ?? 'No role'),
                    'scoped' => $scoped,
                    'sites' => $sites,
                    'site_names' => $scoped
                        ? (count($sites) === 0 ? 'No sites assigned — sees nothing yet' : implode(', ', array_map(static fn (string $id): string => $clients[$id] ?? $id, $sites)))
                        : 'Every client',
                    'status' => (string) $user->status,
                    'is_self' => $user->is($viewer),
                    'reason' => match (true) {
                        $user->is($viewer) => 'This is your own account. Changing your own role or suspending yourself is done by another administrator, so a slip cannot lock you out.',
                        $directors === [$user->id] => 'This is the only active Director. Make somebody else a Director before changing this account.',
                        default => null,
                    },
                ];
            })->all(),
            'invitations' => Invitation::query()
                ->with(['inviter', 'role'])
                ->whereNull('tenant_id')
                ->whereNull('accepted_at')
                ->orderByDesc('id')
                ->get()
                ->map(static fn (Invitation $invitation): array => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => (string) ($invitation->role->label ?? $invitation->role->name),
                    'invited_by' => (string) ($invitation->inviter->name ?? 'Not recorded'),
                    'expires' => $invitation->expires_at->format('M j, Y'),
                    'expired' => ! $invitation->isPending(),
                ])
                ->all(),
            'roles' => Role::query()
                ->where('console', Console::Gemini->value)
                ->orderBy('sort')
                ->get()
                ->map(static fn (Role $role): array => [
                    'name' => $role->name,
                    'label' => (string) ($role->label ?? $role->name),
                    'scoped' => $role->scope_default === AccessScope::AssignedSites,
                ])
                ->all(),
            'clients' => $clients->map(static fn (string $name, string $id): array => ['id' => $id, 'name' => $name])->values()->all(),
        ];
    }

    /**
     * Move a person to a role, set their sites, and set their standing.
     *
     * @param  list<string>  $siteIds
     */
    public function manage(User $target, string $roleName, string $status, array $siteIds, User $by): void
    {
        if ($target->console !== Console::Gemini) {
            throw new DomainException('That account belongs to an estate. It is managed from that estate\'s own Users & roles.');
        }

        if ($target->is($by)) {
            throw new DomainException('You cannot change your own role or suspend yourself. Another administrator does that, so a slip cannot lock you out.');
        }

        if (! in_array($status, ['active', 'suspended'], true)) {
            throw new DomainException('An account is active or suspended. Nothing deletes a person from the platform\'s history.');
        }

        $role = Role::query()->where('name', $roleName)->where('console', Console::Gemini->value)->first();

        if ($role === null) {
            throw new DomainException('Choose one of the Gemini Console\'s roles. A platform account never holds an estate role.');
        }

        $wasDirector = $target->hasRole(Role::DIRECTOR) && $target->status === 'active';
        $staysDirector = $role->name === Role::DIRECTOR && $status === 'active';

        if ($wasDirector && ! $staysDirector && $this->activeDirectorIds() === [$target->id]) {
            throw new DomainException('This is the only active Director. Make somebody else a Director first — otherwise nobody could change this back.');
        }

        $scoped = $role->scope_default === AccessScope::AssignedSites;
        $sites = $scoped
            ? array_values(array_intersect(array_unique(array_map('strval', $siteIds)), Tenant::estates()->map(static fn (Tenant $t): string => (string) $t->getTenantKey())->all()))
            : [];

        $before = [
            'role' => $target->roles->first()?->name,
            'status' => $target->status,
            'sites' => $target->assignments()->where('is_active', true)->pluck('tenant_id')->all(),
        ];

        DB::connection('mysql')->transaction(function () use ($target, $role, $status, $scoped, $sites): void {
            $target->syncRoles([$role->name]);
            $target->forceFill(['status' => $status])->save();

            // Sites mean something only to a scoped role. Everyone else covers
            // every client, and a stale assignment row would be a scope nobody
            // can see on the screen.
            EstateAssignment::query()
                ->where('user_id', $target->id)
                ->when($scoped, static fn ($query) => $query->whereNotIn('tenant_id', $sites))
                ->update(['is_active' => false]);

            foreach ($sites as $tenantId) {
                EstateAssignment::query()->updateOrCreate(
                    ['user_id' => $target->id, 'tenant_id' => $tenantId],
                    ['role_id' => $role->id, 'is_active' => true],
                );
            }
        });

        $this->audit->record(
            action: 'platform.user_managed',
            entityType: 'User',
            entityId: (string) $target->id,
            before: $before,
            after: ['role' => $role->name, 'status' => $status, 'sites' => $sites, 'changed_by' => $by->name],
        );
    }

    /** @return list<int> */
    private function activeDirectorIds(): array
    {
        return User::query()
            ->where('console', Console::Gemini->value)
            ->where('status', 'active')
            ->whereHas('roles', static fn ($query) => $query->where('name', Role::DIRECTOR))
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
