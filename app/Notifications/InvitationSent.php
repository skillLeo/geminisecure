<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Invitation;
use App\Models\Tenant;
use App\Support\ConsoleHome;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The invitation email.
 *
 * Sent to an address, not a user — there is no user yet; that is what an
 * invitation is. The link lands on the estate's own door, where the card names
 * the community the person is being asked to join, and says who asked, what
 * role, and that the link stands for fourteen days.
 */
class InvitationSent extends Notification
{
    public function __construct(public readonly Invitation $invitation) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invitation = $this->invitation->loadMissing(['inviter', 'role']);
        $estate = $invitation->tenant_id === null ? null : Tenant::query()->find($invitation->tenant_id);
        $where = $estate === null ? 'the Gemini Console' : (string) $estate->name;

        return (new MailMessage)
            ->subject('You have been invited to '.$where.' on GeminiSecure')
            ->greeting('Hello,')
            ->line(sprintf(
                '%s has invited you to %s as %s.',
                $invitation->inviter->name,
                $where,
                (string) ($invitation->role->label ?? $invitation->role->name),
            ))
            ->action('Accept the invitation', $this->url())
            ->line('The link stands for fourteen days, until '.$invitation->expires_at->format('F j, Y').'. Choose a password when you arrive.')
            ->line('If you were not expecting this, ignore it — no account exists until you accept.');
    }

    /** The acceptance page, on the estate's own door or the central one. */
    public function url(): string
    {
        if ($this->invitation->tenant_id !== null) {
            return ConsoleHome::estateUrl($this->invitation->tenant_id).'/invitations/'.$this->invitation->token;
        }

        return url('/invitations/'.$this->invitation->token);
    }
}
