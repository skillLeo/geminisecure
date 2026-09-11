<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitationSent;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Issuing accounts. Ruled (12 §2, Wave 1): an invitation names inviter,
 * tenant, role, and expires in 14 days. Without it no committee can be
 * onboarded.
 *
 * THERE IS NO OTHER WAY IN. The platform has no registration route, and this
 * is why it does not need one: an account exists because somebody holding
 * Settings create on an estate — or, for Gemini staff, a platform role — asked
 * for it by name, and the person asked chose a password on a link only they
 * received. Every step is audited against the estate.
 *
 * ONE PERSON, ONE ESTATE. A committee member lives inside exactly one estate
 * (`User::canAccessEstate`), so an address that already holds an account
 * anywhere on the platform cannot be invited to a second estate: the refusal
 * says so rather than quietly making a user who could reach two communities.
 *
 * THE TOKEN IS SHOWN TO NOBODY BUT ITS RECIPIENT. It is the credential until
 * it is accepted; the users screen shows that an invitation is pending, who
 * sent it and when it lapses, and never the link.
 */
class Invitations
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Invite somebody to hold a role on an estate.
     *
     * @param  string|null  $tenantId  the estate, or null for Gemini staff
     */
    public function invite(string $email, Role $role, ?string $tenantId, User $by): Invitation
    {
        $email = strtolower(trim($email));

        if (User::query()->where('email', $email)->exists()) {
            throw new DomainException(
                'That address already holds an account on this platform. A person belongs to one console and '.
                'one estate; change their role from Users & roles rather than inviting them again.'
            );
        }

        if ($tenantId !== null && $role->console !== Console::Estate) {
            throw new DomainException('Only an estate role can be held on an estate. '.$role->label.' is a Gemini role.');
        }

        if ($tenantId === null && $role->console !== Console::Gemini) {
            throw new DomainException('An estate role has to be invited to an estate.');
        }

        $pending = Invitation::query()
            ->where('email', $email)
            ->where('tenant_id', $tenantId)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($pending !== null) {
            throw new DomainException(sprintf(
                'An invitation to %s is already open until %s. Resend it rather than sending a second one.',
                $email,
                $pending->expires_at->format('M j'),
            ));
        }

        return DB::connection('mysql')->transaction(function () use ($email, $role, $tenantId, $by): Invitation {
            $invitation = Invitation::create([
                'email' => $email,
                'token' => Str::random(64),
                'invited_by' => $by->id,
                'role_id' => $role->id,
                'tenant_id' => $tenantId,
                'expires_at' => now()->addDays(Invitation::DAYS),
            ]);

            $this->audit->record(
                action: 'user.invited',
                entityType: 'Invitation',
                entityId: (string) $invitation->id,
                after: ['email' => $email, 'role' => $role->name, 'expires_at' => $invitation->expires_at->toIso8601String()],
                tenantId: $tenantId,
            );

            Notification::route('mail', $email)->notify(new InvitationSent($invitation));

            return $invitation;
        });
    }

    /**
     * Send it again, with a fresh token and a fresh fortnight.
     *
     * The old link stops working the moment this runs: one open credential per
     * invitation, and a resend that left two live links behind would double
     * the number of emails that could open the account.
     */
    public function resend(Invitation $invitation, User $by): Invitation
    {
        if ($invitation->accepted_at !== null) {
            throw new DomainException('This invitation has been accepted. The account exists; there is nothing to resend.');
        }

        $invitation->forceFill([
            'token' => Str::random(64),
            'expires_at' => now()->addDays(Invitation::DAYS),
        ])->save();

        $this->audit->record(
            action: 'user.invitation_resent',
            entityType: 'Invitation',
            entityId: (string) $invitation->id,
            after: ['email' => $invitation->email, 'expires_at' => $invitation->expires_at->toIso8601String()],
            tenantId: $invitation->tenant_id,
        );

        Notification::route('mail', $invitation->email)->notify(new InvitationSent($invitation));

        return $invitation;
    }

    /**
     * Withdraw it. The row goes: an unaccepted invitation is not a record of
     * anything, and the audit entry is what remembers it was sent.
     */
    public function revoke(Invitation $invitation, User $by): void
    {
        if ($invitation->accepted_at !== null) {
            throw new DomainException('This invitation has been accepted. Suspend the account from Users & roles instead.');
        }

        $this->audit->record(
            action: 'user.invitation_revoked',
            entityType: 'Invitation',
            entityId: (string) $invitation->id,
            before: ['email' => $invitation->email, 'role' => $invitation->role->name],
            tenantId: $invitation->tenant_id,
        );

        $invitation->delete();
    }

    /** The invitation this token opens, or null when it opens nothing. */
    public function find(string $token): ?Invitation
    {
        return Invitation::query()->with(['inviter', 'role'])->where('token', $token)->first();
    }

    /**
     * Accept: the account comes into existence here and nowhere else.
     *
     * Active from the first moment, because the person chose the password
     * themselves on the link only they received; there is no second
     * confirmation to wait for. The assignment binds them to the estate and
     * the role decides what they may do there.
     */
    public function accept(Invitation $invitation, string $name, string $password): User
    {
        if (! $invitation->isPending()) {
            throw new DomainException(
                $invitation->accepted_at !== null
                    ? 'This invitation has already been accepted. Sign in with the password you chose.'
                    : 'This invitation has expired. Ask whoever invited you to send it again.'
            );
        }

        if (User::query()->where('email', $invitation->email)->exists()) {
            throw new DomainException('An account already exists for this address. Sign in, or ask for a password reset.');
        }

        return DB::connection('mysql')->transaction(function () use ($invitation, $name, $password): User {
            $role = $invitation->role;

            $user = User::create([
                'name' => trim($name),
                'email' => $invitation->email,
                'password' => Hash::make($password),
                'console' => $role->console->value,
                'status' => 'active',
            ]);

            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles([$role->name]);

            if ($invitation->tenant_id !== null) {
                EstateAssignment::create([
                    'user_id' => $user->id,
                    'tenant_id' => $invitation->tenant_id,
                    'role_id' => $role->id,
                    'is_active' => true,
                ]);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->audit->record(
                action: 'user.invitation_accepted',
                entityType: 'User',
                entityId: (string) $user->id,
                after: ['email' => $user->email, 'role' => $role->name],
                tenantId: $invitation->tenant_id,
            );

            return $user;
        });
    }
}
