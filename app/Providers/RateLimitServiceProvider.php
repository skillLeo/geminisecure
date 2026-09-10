<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * What each door will accept, and how fast.
 *
 * /api/v1 had no limit of any kind. Every endpoint is behind a token and an
 * ability, which stops a stranger — and stops nothing at all once a handset is
 * lost, cloned or left in a taxi. A credential that can raise an alert can
 * raise ten thousand, and the queue a dispatcher watches is the thing that
 * fills up.
 *
 * KEYED ON THE TOKEN, NOT THE IP. Every guard at one estate can sit behind one
 * mobile carrier NAT, so an IP limit would throttle a whole gate because one
 * handset misbehaved. The token is the device, which is the thing that can be
 * revoked and the thing whose behaviour is actually being described.
 *
 * THE PANIC PATH IS THE ONE THAT NEEDS THE MOST CARE, AND IT GETS THE MOST
 * ROOM. A limit that silences an alert is worse than the flood it prevents, so
 * the alert ceiling is set where no frightened person could reach it and only a
 * loop can: a human jabbing a panic button cannot beat two per second for a
 * minute, and thirty alerts from one device already means the queue knows.
 * Retries are cheaper still — they carry the same idempotency key and return
 * the row that already exists rather than making a second one.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * Alerts: generous, because this is the life-safety path.
     *
     * High enough that no person in trouble can hit it, low enough that a
     * runaway handset cannot bury a dispatcher's queue.
     */
    private const ALERTS_PER_MINUTE = 30;

    /**
     * Gate events: a busy main gate at shift change, doubled.
     *
     * An admission takes a guard several seconds of talking to somebody, so two
     * a second sustained is not a gate — it is a script.
     */
    private const GATE_EVENTS_PER_MINUTE = 120;

    /**
     * Clocking on and off: deliberately tight.
     *
     * A handset clocks on once a shift. Even allowing for a bad signal and a
     * queue of retries, twenty in a minute is already far more than the act can
     * honestly need.
     */
    private const SHIFT_CLOCK_PER_MINUTE = 20;

    /**
     * Sign-in attempts, per address and per account.
     *
     * Both, because they stop different attacks: per-IP stops one machine
     * working through a password list, and per-account stops a distributed
     * attempt at one known address. Five a minute leaves room for a person
     * mistyping and none for a program.
     */
    private const LOGIN_PER_MINUTE = 5;

    public function boot(): void
    {
        RateLimiter::for('api-alerts', fn (Request $request) => Limit::perMinute(self::ALERTS_PER_MINUTE)
            ->by($this->device($request)));

        RateLimiter::for('api-gate-events', fn (Request $request) => Limit::perMinute(self::GATE_EVENTS_PER_MINUTE)
            ->by($this->device($request)));

        RateLimiter::for('api-shift-clock', fn (Request $request) => Limit::perMinute(self::SHIFT_CLOCK_PER_MINUTE)
            ->by($this->device($request)));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(self::LOGIN_PER_MINUTE)->by($request->ip()),
            Limit::perMinute(self::LOGIN_PER_MINUTE)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);
    }

    /**
     * Which device is speaking, for limiting purposes.
     *
     * READ FROM THE BEARER HEADER, NOT FROM `$request->user()`, and that is not
     * a shortcut — it is the only thing that works. Laravel's middleware
     * priority puts `ThrottleRequests` BEFORE `Authenticate`, so a limiter that
     * asked for the authenticated user would find null on every request and
     * quietly fall back to the address. The limit would still appear to work,
     * and every handset at one estate would share one bucket: a single runaway
     * phone taking the panic button away from every guard behind the same
     * mobile carrier NAT. A test caught it; nothing else would have.
     *
     * HASHED, so a credential does not become a cache key. The raw token is
     * what a device presents; what is stored here only has to be unique per
     * device, and sha256 of it is.
     *
     * Falls back to the address when there is no token at all. Those requests
     * are refused a moment later by `auth:sanctum`, and a caller with no
     * credential must not be handed an unlimited bucket on the way to being
     * rejected.
     */
    private function device(Request $request): string
    {
        $bearer = $request->bearerToken();

        return $bearer === null || $bearer === ''
            ? 'ip:'.$request->ip()
            : 'token:'.hash('sha256', $bearer);
    }
}
