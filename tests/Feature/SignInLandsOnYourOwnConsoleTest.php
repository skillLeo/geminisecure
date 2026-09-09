<?php

declare(strict_types=1);

use App\Enums\Console;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\User;
use App\Support\ConsoleHome;

/*
|--------------------------------------------------------------------------
| Signing in lands you on YOUR console
|--------------------------------------------------------------------------
|
| The two consoles are not on the same URL. Sending an estate committee member
| to /dashboard lands them on the Gemini dashboard, where the permission gate
| correctly refuses them — a 403 that reads as a broken login and is in fact
| the gate working.
|
| That fault was fixed once, in the local quick-login bypass, and left in place
| on the real sign-in form where it mattered far more: EVERY estate user
| signing in through the actual login page hit it. Two copies of the same rule
| is how that happened, so ConsoleHome is now the only copy and these tests
| pin it.
|
*/

function userOnConsole(Console $console, string $roleName): User
{
    $role = Role::findOrCreate($roleName, 'web');

    $user = User::factory()->create([
        'console' => $console->value,
        'status' => 'active',
        'password' => bcrypt('correct-horse-battery-staple'),
    ]);

    $user->syncRoles([$role->name]);

    if ($console === Console::Estate) {
        EstateAssignment::updateOrCreate(
            ['user_id' => $user->id, 'tenant_id' => 'harbourview'],
            ['role_id' => $role->id, 'is_active' => true],
        );
    }

    return $user->fresh();
}

it('sends a Gemini user to the Gemini dashboard', function () {
    $user = userOnConsole(Console::Gemini, 'test.gemini_person');

    expect(ConsoleHome::for($user))->toBe(route('gemini.dashboard'));
});

it('sends an estate user to their own estate, not the Gemini dashboard', function () {
    $user = userOnConsole(Console::Estate, 'test.estate_person');

    $home = ConsoleHome::for($user);

    expect($home)->toContain('harbourview')
        ->and($home)->not->toContain('dashboard');
});

it('sends an estate user with no estate back to sign-in with a reason', function () {
    $role = Role::findOrCreate('test.estate_orphan', 'web');
    $user = User::factory()->create(['console' => Console::Estate->value, 'status' => 'active']);
    $user->syncRoles([$role->name]);

    // No assignment. Saying so beats bouncing them into a console they hold
    // no assignment for and letting the gate refuse them without explanation.
    expect(ConsoleHome::for($user->fresh()))->toContain('reason=no-estate');
});

it('redirects an estate user to their estate through the real sign-in form', function () {
    $user = userOnConsole(Console::Estate, 'test.estate_person');

    // The end-to-end version. The unit assertions above can pass while the
    // controller still hardcodes gemini.dashboard, which is exactly what
    // happened.
    $this->post('/login', [
        'email' => $user->email,
        'password' => 'correct-horse-battery-staple',
    ])->assertRedirectContains('harbourview');
});
