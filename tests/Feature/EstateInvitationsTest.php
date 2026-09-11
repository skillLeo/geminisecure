<?php

declare(strict_types=1);

use App\Models\EstateAssignment;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\InvitationSent;
use App\Services\Invitations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Support\FacilitiesFixture;

/*
|--------------------------------------------------------------------------
| Inviting a committee member — boards 22 and 24 (12 §2, Wave 1)
|--------------------------------------------------------------------------
|
| "Names inviter, tenant, role, 14-day expiry. Without it no committee can be
| onboarded." An invitation is the only way an account comes into existence,
| so what is under test is the whole road: who may send one, what it carries,
| that the link is never shown to the sender, that accepting it creates an
| active account bound to this estate and this role and nothing wider, and
| that a lapsed or used link creates nothing.
|
*/

beforeEach(function () {
    FacilitiesFixture::boot();
    FacilitiesFixture::platform();

    DB::connection('mysql')->beginTransaction();

    $this->withoutVite();
    Notification::fake();
});

afterEach(function () {
    DB::connection('mysql')->rollBack();
});

it('lets the Community Super Admin invite, and refuses the officers who read the screen', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $president = FacilitiesFixture::viewer(Role::PRESIDENT);

    $this->actingAs($president)
        ->get(FacilitiesFixture::url('/settings/users'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Estate/Settings/Users')
            ->where('canInvite', false)
            ->has('roles', 7)
            ->where('invitations', []));

    $this->actingAs($president)
        ->post(FacilitiesFixture::url('/settings/users/invitations'), ['email' => 'new@phoenixpark.org', 'role' => Role::SECRETARY])
        ->assertForbidden();

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/users/invitations'), ['email' => 'New@PhoenixPark.org', 'role' => Role::SECRETARY])
        ->assertRedirect(FacilitiesFixture::url('/settings/users'));

    $invitation = Invitation::query()->where('email', 'new@phoenixpark.org')->firstOrFail();

    // Inviter, tenant, role, fourteen days — and lowercased, so the address
    // matches the one they will sign in with.
    expect($invitation->invited_by)->toBe($admin->id)
        ->and($invitation->tenant_id)->toBe(FacilitiesFixture::ESTATE)
        ->and($invitation->role->name)->toBe(Role::SECRETARY)
        ->and($invitation->expires_at->diffInDays(now()->addDays(14), true))->toBeLessThan(1)
        ->and(strlen($invitation->token))->toBe(64);

    Notification::assertSentOnDemand(InvitationSent::class, fn (InvitationSent $mail, array $channels, $notifiable): bool => $notifiable->routes['mail'] === 'new@phoenixpark.org'
        && str_contains($mail->url(), '/invitations/'.$invitation->token));

    // The screen lists it, with who sent it and when it lapses — and never
    // the token, which is the credential until it is accepted.
    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/settings/users'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('invitations', 1)
            ->where('invitations.0.email', 'new@phoenixpark.org')
            ->where('invitations.0.role_label', 'Secretary')
            ->where('invitations.0.invited_by', $admin->name)
            ->where('invitations.0.expired', false)
            ->missing('invitations.0.token'));

    expect(DB::connection('mysql')->table('audit_log')->where('action', 'user.invited')->where('tenant_id', FacilitiesFixture::ESTATE)->exists())->toBeTrue();
});

it('refuses an address that already holds an account, and a second open invitation to the same one', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $existing = FacilitiesFixture::viewer(Role::TREASURER);

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/users/invitations'), ['email' => $existing->email, 'role' => Role::SECRETARY])
        ->assertSessionHasErrors('email');

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/users/invitations'), ['email' => 'twice@phoenixpark.org', 'role' => Role::SECRETARY])
        ->assertSessionHasNoErrors();

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/users/invitations'), ['email' => 'twice@phoenixpark.org', 'role' => Role::PRESIDENT])
        ->assertSessionHasErrors('email');

    expect(Invitation::query()->where('email', 'twice@phoenixpark.org')->count())->toBe(1);

    // And a Gemini role cannot be invited onto an estate at all.
    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/users/invitations'), ['email' => 'staff@geminisecurity.test', 'role' => Role::DIRECTOR])
        ->assertSessionHasErrors('role');
});

it('creates an active account bound to this estate and this role when the link is accepted, and signs them in', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $invitation = app(Invitations::class)->invite('accept@phoenixpark.org', Role::named(Role::SECRETARY), FacilitiesFixture::ESTATE, $admin);

    $this->get(FacilitiesFixture::url('/invitations/'.$invitation->token))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Auth/AcceptInvitation')
            ->where('door.key', 'estate')
            ->where('invitation.email', 'accept@phoenixpark.org')
            ->where('invitation.role', 'Secretary')
            ->where('invitation.inviter', $admin->name)
            ->where('invitation.where', 'Phoenix Park')
            ->where('refusal', null));

    $this->post(FacilitiesFixture::url('/invitations/'.$invitation->token), [
        'name' => 'Delroy Samuels',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertRedirect();

    $user = User::query()->where('email', 'accept@phoenixpark.org')->firstOrFail();

    expect($user->status)->toBe('active')
        ->and($user->console->value)->toBe('estate')
        ->and($user->hasRole(Role::SECRETARY))->toBeTrue()
        ->and($user->can('estate.governance.update'))->toBeTrue()
        ->and($user->can('estate.dues_ledger.view'))->toBeFalse()
        ->and(EstateAssignment::query()->where('user_id', $user->id)->where('tenant_id', FacilitiesFixture::ESTATE)->where('is_active', true)->exists())->toBeTrue()
        ->and($user->canAccessEstate(FacilitiesFixture::ESTATE))->toBeTrue()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);

    // Used once: the same link now opens the refusal, and no second account.
    auth()->logout();
    $this->flushSession();

    $this->get(FacilitiesFixture::url('/invitations/'.$invitation->token))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('refusal', fn ($r) => str_contains((string) $r, 'already been accepted')));

    expect(User::query()->where('email', 'accept@phoenixpark.org')->count())->toBe(1);
});

it('lapses after fourteen days, and a resend issues a fresh link that the old one no longer opens', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $invitation = app(Invitations::class)->invite('late@phoenixpark.org', Role::named(Role::SECRETARY), FacilitiesFixture::ESTATE, $admin);
    $oldToken = $invitation->token;

    $this->travel(15)->days();

    $this->get(FacilitiesFixture::url('/invitations/'.$oldToken))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('refusal', fn ($r) => str_contains((string) $r, 'expired')));

    $this->post(FacilitiesFixture::url('/invitations/'.$oldToken), [
        'name' => 'Too Late',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertSessionHasErrors('name');

    expect(User::query()->where('email', 'late@phoenixpark.org')->exists())->toBeFalse();

    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/users/invitations/'.$invitation->id.'/resend'))
        ->assertRedirect(FacilitiesFixture::url('/settings/users'));

    $resent = $invitation->fresh();

    expect($resent->token)->not->toBe($oldToken)
        ->and($resent->isPending())->toBeTrue();

    // As a guest again: the acceptance door is a guest's, and a signed-in
    // admin is bounced away from it.
    auth()->logout();
    $this->flushSession();

    // The old link is dead; the new one opens.
    $this->get(FacilitiesFixture::url('/invitations/'.$oldToken))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitation', null));

    $this->get(FacilitiesFixture::url('/invitations/'.$resent->token))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('refusal', null));

    // Withdrawn: gone from the screen, and the link opens nothing.
    $this->actingAs($admin)
        ->post(FacilitiesFixture::url('/settings/users/invitations/'.$resent->id.'/revoke'))
        ->assertRedirect(FacilitiesFixture::url('/settings/users'));

    expect(Invitation::query()->whereKey($resent->id)->exists())->toBeFalse();

    auth()->logout();
    $this->flushSession();

    $this->get(FacilitiesFixture::url('/invitations/'.$resent->token))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitation', null));
});

it('links board 24\'s Invite user to board 22 for the one role that may invite', function () {
    $admin = FacilitiesFixture::viewer(Role::COMMUNITY_SUPER_ADMIN);
    $vp = FacilitiesFixture::viewer(Role::VICE_PRESIDENT);

    $this->actingAs($admin)
        ->get(FacilitiesFixture::url('/settings/roles'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canInvite', true)
            ->where('inviteHref', fn ($href) => str_ends_with((string) $href, '/settings/users?invite=1')));

    $this->actingAs($vp)
        ->get(FacilitiesFixture::url('/settings/roles'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canInvite', false)->has('inviteReason'));
});
