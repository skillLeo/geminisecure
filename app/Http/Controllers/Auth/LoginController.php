<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

/**
 * Sign-in for both consoles.
 *
 * There is deliberately no `store`-adjacent registration action. Accounts are
 * issued by invitation and no public registration endpoint exists anywhere in
 * this application.
 */
class LoginController extends Controller
{
    public function create(): Response
    {
        return inertia('Auth/Login', [
            /*
             * Quick-login buttons, local only.
             *
             * A closure, so the roles are not even queried outside local, and
             * an empty array reaches the page rather than a null the component
             * has to guard against.
             */
            'quickLoginRoles' => fn () => app()->isLocal()
                ? Role::query()
                    ->orderBy('console')
                    ->orderBy('sort')
                    ->get()
                    ->map(fn (Role $role) => [
                        'name' => $role->name,
                        'label' => $role->label,
                        'console' => $role->console->value,
                        'scope' => $role->scope_default->label(),
                    ])
                    ->all()
                : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            /*
             * One message for both a wrong password and an unknown address.
             * Distinguishing them turns the login form into an oracle for
             * which addresses hold accounts on the platform.
             */
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        $user = Auth::user();

        if ($user->status !== 'active') {
            Auth::logout();
            $request->session()->invalidate();

            throw ValidationException::withMessages([
                'email' => $user->status === 'invited'
                    ? 'This invitation has not been accepted yet.'
                    : 'This account is suspended.',
            ]);
        }

        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('gemini.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
