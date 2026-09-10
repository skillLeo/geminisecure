<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Console;
use App\Models\User;
use App\Services\Navigation\ConsoleNavigation;
use App\Support\EstateNavigation;
use App\Support\ScreenState;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every page.
     *
     * Navigation is generated here rather than per controller, so no screen
     * can accidentally ship without it or, worse, ship with a hand-written
     * list that has drifted from the role matrix.
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $user ? $this->presentUser($user) : null,
            ],

            'nav' => fn () => $user
                ? app(ConsoleNavigation::class)->for($user, $user->console)
                : [],

            /*
             * The Estate Console's own sidebar, and only inside an estate.
             *
             * Shared rather than passed per controller for the same reason the
             * Gemini nav is: forgetting it on one screen is how a console ends
             * up with a sidebar that is right on nine screens and empty on the
             * tenth. Which item is CURRENT is the screen's business and arrives
             * as a prop on the layout, so this carries only the items and the
             * permissions that filter them.
             */
            'estateNav' => fn (): array => $user !== null && tenancy()->initialized
                ? app(EstateNavigation::class)->forViewer($user, tenantKey: (string) tenant()?->getTenantKey())
                : [],

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],

            /*
             * Which of the six states to force, in local development only.
             *
             * Shared rather than passed per controller because it applies to
             * every screen and because forgetting it on one screen is exactly
             * how the other five states go unreviewed. Null in every other
             * environment — ScreenState asserts that itself, and this is the
             * second place it is checked.
             */
            'screenState' => fn (): ?string => ScreenState::forced($request),
        ];
    }

    /**
     * Only what the interface actually renders.
     *
     * Deliberately not the whole model: a User carries mfa_secret, a password
     * hash and a status, none of which belong in a JSON payload that ships to
     * the browser on every page.
     *
     * @return array{id: int, name: string, email: string, console: string, role_label: string, is_gemini_staff: bool}
     */
    private function presentUser(User $user): array
    {
        $role = $user->roles->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'console' => $user->console->value,

            // `??` covers both halves — a user with no role at all, and a role
            // row whose label was never set. `?->` on the left of it is
            // redundant: null-coalescing already suppresses the null access.
            'role_label' => $role->label ?? 'No role assigned',
            'is_gemini_staff' => $user->console === Console::Gemini,
        ];
    }
}
