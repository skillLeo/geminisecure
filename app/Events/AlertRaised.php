<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\DuressAlert;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A panic, duress, medical, fire or intrusion alert has been raised.
 *
 * Broadcast so a dispatcher sees it arrive without refreshing. This is the
 * life-safety path: a queue that only updates on page load means a panic alert
 * can sit unseen for as long as nobody happens to reload.
 *
 * WHAT THIS PAYLOAD DELIBERATELY OMITS
 * ------------------------------------
 * No monetary value of any kind, and nothing about a household's financial
 * standing. Invariant 2 constrains this event exactly as it constrains the
 * guard endpoints: a broadcast is just another way for a figure to escape, and
 * it would escape to every connected client at once.
 *
 * It also omits the raiser's precise location. The dispatcher opens the alert
 * to see that, which keeps a continuously-broadcast channel from carrying a
 * live position for everyone with the console open.
 */
class AlertRaised implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public DuressAlert $alert) {}

    /**
     * Per-estate channels, so a dispatcher restricted to assigned sites is not
     * simply trusted to ignore what arrives for estates they do not cover.
     *
     * A single global channel would push every estate's alerts to every
     * connected console and rely on the client to filter, which is not a
     * boundary at all.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel("estate.{$this->alert->tenant_id}.alerts"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'alert.raised';
    }

    /**
     * Enough to make the queue react, never enough to act on alone.
     *
     * The dispatcher opens the alert for detail; this exists so the row appears
     * and the count moves the instant it happens.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->alert->id,
            'kind' => $this->alert->kind,
            'kind_label' => $this->alert->kindLabel(),
            'estate' => $this->alert->tenant_id,
            'is_urgent' => in_array($this->alert->kind, ['panic', 'duress'], true),
            'server_time' => $this->alert->server_time->toIso8601String(),
            'is_simulated' => $this->alert->is_simulated,
        ];
    }
}
