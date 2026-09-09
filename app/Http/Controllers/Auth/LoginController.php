<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\ConsoleHome;
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
 * this application, and tests/Feature/AuthenticationTest.php asserts that
 * absence so adding one breaks a test rather than passing review unnoticed.
 */
class LoginController extends Controller
{
    public function create(Request $request): Response
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

            /*
             * The quick-login bypass bounces back here with a reason when it
             * cannot send a role anywhere - an estate role with no estate to
             * be assigned to. Without this the redirect lands on a sign-in
             * page that says nothing, which reads as the button being broken.
             */
            'quickLoginNotice' => fn (): ?string => $this->quickLoginNotice($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        /*
         * No "remember me".
         *
         * The approved board draws no such control, and this console holds
         * data for every client estate on the platform: a long-lived cookie on
         * an unattended machine is exactly the risk the two-factor copy on the
         * card is about. Sessions here end with the session.
         */
        if (! Auth::attempt($credentials)) {
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

        /*
         * The user's OWN console, not the Gemini one.
         *
         * This used to send every successful sign-in to gemini.dashboard, so
         * an estate committee member signing in through this form landed on
         * the Gemini dashboard and was refused by the permission gate — a 403
         * that reads as a broken login. The same fault was fixed in the local
         * quick-login bypass and left here, where it mattered far more.
         *
         * intended() is still honoured first: someone who was deep-linked into
         * a page and bounced to sign in should return to that page.
         */
        return redirect()->intended(ConsoleHome::for($user));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Why the local-only quick sign-in sent the visitor back here, if it did.
     *
     * Matched against a fixed set rather than echoed: the value arrives in the
     * query string, and a query string is the visitor's to write.
     */
    private function quickLoginNotice(Request $request): ?string
    {
        if (! app()->isLocal()) {
            return null;
        }

        return match ($request->query('quicklogin') ?? $request->query('reason')) {
            'no-estate' => 'That role belongs to the Estate Console, and there is no estate to sign it in to.',
            default => null,
        };
    }
}
