<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Console;
use App\Models\User;
use App\Services\Navigation\ConsoleNavigation;
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

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * Only what the interface actually renders.
     *
     * Deliberately not the whole model: a User carries mfa_secret, a password
     * hash and a status, none of which belong in a JSON payload that ships to
     * the browser on every page.
     */
    private function presentUser(User $user): array
    {
        $role = $user->roles->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'console' => $user->console->value,
            'role_label' => $role?->label ?? 'No role assigned',
            'is_gemini_staff' => $user->console === Console::Gemini,
        ];
    }
}
