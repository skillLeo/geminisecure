<?php

declare(strict_types=1);

namespace App\Services\Simulation;

use App\Models\Guard;
use App\Services\Devices\DeviceEnrolment;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

/**
 * A borrowed handset: the way the web simulator speaks to /api/v1.
 *
 * THE SAME RULE THE CONSOLE SIMULATORS KEEP. Every /api/v1 endpoint sits behind
 * `auth:sanctum` and an ability, and the one way to prove the dispatch screens
 * work against those endpoints is to go THROUGH them — the middleware, the
 * ability check, the validation, the route binding — with a real bearer token.
 * `Sanctum::actingAs` would exercise the controllers and skip exactly the two
 * things most likely to be wrong: whether the route is behind the right
 * ability, and whether a token lacking it is refused.
 *
 * So this enrols a real handset for a real guard at the estate being simulated,
 * fires each request through the HTTP kernel with its token, and revokes the
 * handset afterwards. It never touches a table: a row the simulator wants to
 * exist is a row an endpoint has to be willing to create.
 *
 * `simulate:alerts` and `simulate:gate` carry the same three steps in a console
 * trait. They pass their gates and are left alone (09 §1); this is the service
 * form of the same discipline for a request that arrives over HTTP rather than
 * from a terminal.
 */
final class Handset
{
    private ?string $token = null;

    private ?Guard $guard = null;

    public function __construct(
        private readonly DeviceEnrolment $enrolment,
        private readonly HttpKernel $kernel,
    ) {}

    /**
     * Borrow a handset belonging to an active guard at this estate.
     *
     * False when the estate has no active guard, which is a real state — an
     * estate mid-onboarding has none — and not an error.
     */
    public function borrow(string $tenantId): bool
    {
        $guard = Guard::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();

        if ($guard === null) {
            return false;
        }

        $this->guard = $guard;
        $this->token = $this->enrolment->enrol($guard, 'Web simulator')['token'];

        return true;
    }

    /**
     * Hand it back.
     *
     * Always, including when a run fails part way: a simulator that left a
     * working token behind would be leaving a credential on the platform every
     * time somebody pressed a button, and the guard it borrowed would show as
     * carrying a handset they do not have — which `AdoptionRollup` counts as
     * Guard App coverage.
     */
    public function return(): void
    {
        if ($this->guard !== null) {
            $this->enrolment->revoke($this->guard);
        }

        $this->guard = null;
        $this->token = null;
    }

    /** The guard whose handset is speaking, if one is borrowed. */
    public function guard(): ?Guard
    {
        return $this->guard;
    }

    /**
     * One request through the real HTTP kernel, as the handset.
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function post(string $path, array $payload = []): array
    {
        $request = Request::create('http://localhost'.$path, 'POST', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ], (string) json_encode($payload));

        $request->headers->set('Content-Type', 'application/json');

        if ($this->token !== null) {
            $request->headers->set('Authorization', 'Bearer '.$this->token);
        }

        $response = $this->kernel->handle($request);
        $decoded = json_decode((string) $response->getContent(), true);

        return [
            'status' => $response->getStatusCode(),
            'body' => is_array($decoded) ? $decoded : [],
        ];
    }
}
