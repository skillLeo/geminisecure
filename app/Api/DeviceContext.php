<?php

declare(strict_types=1);

namespace App\Api;

use App\Models\Guard;
use App\Models\ResidentAccount;
use Illuminate\Support\Carbon;

/**
 * Who is speaking on this request, resolved from the token and nothing else (13 D1).
 *
 * THE TOKEN DECIDES THE GUARD, THE RESIDENT AND THE ESTATE. Before this, three
 * endpoints took `tenant_id` and `guard_id` from the request body, so a guard's
 * handset could raise an alert in another estate's name or clock on a shift
 * that was not theirs. An endpoint now reads these from here; a body field that
 * names a different estate or guard is refused, not obeyed.
 */
final class DeviceContext
{
    public function __construct(
        public readonly string $app,
        public readonly string $tenantId,
        public readonly ?Guard $guard,
        public readonly ?ResidentAccount $resident,
        public readonly Carbon $serverTime,
        public readonly ?Carbon $deviceTime = null,
    ) {}

    public function withDeviceTime(?Carbon $deviceTime): self
    {
        return new self($this->app, $this->tenantId, $this->guard, $this->resident, $this->serverTime, $deviceTime);
    }

    /** Whether the handset's clock disagrees with the server's by more than the tolerance. */
    public function clockSkewed(): bool
    {
        return $this->deviceTime !== null
            && abs($this->deviceTime->getTimestamp() - $this->serverTime->getTimestamp()) > Catalogue::CLOCK_SKEW_SECONDS;
    }

    public function guardOrFail(): Guard
    {
        if ($this->guard === null) {
            throw ApiError::forbidden('guard_app_only', 'This is a Guard App endpoint.');
        }

        return $this->guard;
    }

    public function residentOrFail(): ResidentAccount
    {
        if ($this->resident === null) {
            throw ApiError::forbidden('resident_app_only', 'This is a Resident App endpoint.');
        }

        return $this->resident;
    }
}
