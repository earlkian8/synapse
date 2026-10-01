<?php

namespace App\Notifications;

use App\Support\WorkspaceJoin;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells somebody who asked to join a company with its code how it went
 * (see {@see WorkspaceJoin}).
 *
 * Mail only: the recipient is, by definition, either not yet a member of the
 * organisation or has just this second become one, so the in-app feed — which is
 * tenant-scoped — is the wrong place to reach them.
 */
class JoinRequestDecisionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $organizationName,
        public bool $approved,
        public ?string $reason = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Force mail onto the synchronous connection so a queue-less demo still sends.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['mail' => 'sync'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->greeting('Hello '.($notifiable->first_name ?? 'there').',');

        if ($this->approved) {
            return $message
                ->subject("You're in — welcome to {$this->organizationName}")
                ->line("Your request to join {$this->organizationName} has been approved, and your record is linked.")
                ->line('Open the SYNAPSE app to clock in, file leave, and see your own records.')
                ->salutation('— The SYNAPSE Team');
        }

        $message
            ->subject("About your request to join {$this->organizationName}")
            ->line("Your request to join {$this->organizationName} wasn't approved.");

        if (filled($this->reason)) {
            $message->line("**Reason:** {$this->reason}");
        }

        return $message
            ->line('If you think this is a mistake, get in touch with their HR team.')
            ->salutation('— The SYNAPSE Team');
    }
}
