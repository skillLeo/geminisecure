<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dev;

use App\Enums\Console;
use App\Http\Controllers\Controller;
use App\Models\EstateAssignment;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ConsoleHome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * One-click sign-in as any role. LOCAL DEVELOPMENT ONLY.
 *
 * This is an authentication bypass. It exists so the 13 roles can be compared
 * without maintaining 13 sets of credentials, and it must never be reachable
 * anywhere but a developer's machine.
 *
 * THREE INDEPENDENT GUARDS, deliberately redundant:
 *
 *   1. routes/web.php registers these routes only when app()->isLocal()
 *   2. every action calls assertLocal() again, so the controller is inert even
 *      if a route were registered by mistake
 *   3. the accounts it creates are marked with a recognisable email domain and
 *      an unusable random password, so they cannot be signed into by hand and
 *      are trivial to find and delete
 *
 * Guard 2 matters because guard 1 is a single line in a route file, and a
 * single line is exactly the kind of thing that gets moved during a refactor.
 */
class QuickLoginController extends Controller
{
    /** Recognisable, and never a real address. */
    private const DEMO_DOMAIN = 'quicklogin.local';

    public function __invoke(string $roleName): RedirectResponse
    {
        $this->assertLocal();

        $role = Role::where('name', $roleName)->first();

        if ($role === null) {
            abort(404);
        }

        $user = $this->demoUserFor($role);

        Auth::guard('web')->login($user);
        session()->regenerate();

        // The same answer the real sign-in form uses. This logic lived here
        // first and was not copied into LoginController, which is how the real
        // form kept sending estate users to the Gemini dashboard long after
        // the bypass stopped doing it.
        return redirect(ConsoleHome::for($user));
    }

    /**
     * A demo account holding exactly this role.
     *
     * Re-roled rather than duplicated, so repeated use does not fill the users
     * table with near-identical rows.
     */
    private function demoUserFor(Role $role): User
    {
        $email = Str::of($role->name)->replace('.', '-')->append('@'.self::DEMO_DOMAIN)->value();

        $user = User::firstOrNew(['email' => $email]);

        if (! $user->exists) {
            // Random and never disclosed: this account is reachable only
            // through this local-only route, never through the sign-in form.
            $user->password = Str::password(32);
        }

        $user->forceFill([
            'name' => $role->label.' (demo)',
            'console' => $role->console->value,
            'status' => 'active',
        ])->save();

        $user->syncRoles([$role->name]);

        /*
         * Estate roles need somewhere to be. Without an assignment,
         * canAccessEstate() correctly refuses them everything, and the button
         * would appear broken rather than demonstrating the role.
         */
        if ($role->console === Console::Estate) {
            /*
             * Phoenix Park by preference, and it matters. It is the estate every
             * board is drawn from — 450 units, six months of dues, Lot 47 owing
             * J$12,400 — and the fidelity harness signs in through this route.
             * Landed in Ocean View, which is mid-onboarding and has no dues
             * history by design, every money screen would measure an empty
             * state against a populated board.
             */
            $estate = Tenant::find('phoenixpark') ?? Tenant::estates()->first();

            if ($estate !== null) {
                EstateAssignment::updateOrCreate(
                    ['user_id' => $user->id, 'tenant_id' => $estate->getTenantKey()],
                    ['role_id' => $role->id, 'is_active' => true],
                );
            }
        }

        return $user->fresh();
    }

    /**
     * Refuse to run anywhere but local.
     *
     * A RuntimeException rather than abort(404): if this is ever reached off a
     * developer machine, that is a deployment fault worth a stack trace and an
     * alert, not a quiet not-found.
     */
    private function assertLocal(): void
    {
        if (! app()->isLocal() || app()->environment('production')) {
            throw new RuntimeException(
                'Quick login is a local development bypass and must never run outside APP_ENV=local. '
                .'Reaching this line in any other environment is a deployment fault.'
            );
        }
    }
}
