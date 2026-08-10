<?php

namespace App\Notifications;

use App\Support\EmployeeInvitations;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Hands somebody the claim ticket for their roster line (ADR 0026).
 *
 * Addressed to the invitation itself rather than to a user, because at this point
 * there may be no account behind the address at all — that is rather the point of
 * sending it. Purely transactional, so like the notifications it replaced it goes
 * to mail only, never the in-app feed, and ignores notification preferences.
 *
 * Delivered synchronously so a bare `php artisan serve` (no queue worker) still
 * sends it; with the default `MAIL_MAILER=log` it lands in storage/logs, which is
 * how the flow is demonstrated without SMTP.
 *
 * Note what this mail does *not* contain: no password, no account. It carries a
 * link and a code, both of which are useless without an account the recipient
 * creates themselves. See {@see EmployeeInvitations}.
 */
class EmployeeInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $employeeName,
        public string $organizationName,
        public string $code,
        public string $url,
        // CarbonInterface, not Carbon: this app casts datetimes to CarbonImmutable.
        public ?CarbonInterface $expiresAt = null,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Force mail onto the synchronous connection (see the class docblock).
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
            ->subject("You've been invited to join {$this->organizationName} on SYNAPSE")
            ->greeting("Hello {$this->employeeName},")
            ->line("{$this->organizationName} has invited you to use the SYNAPSE app, where you can clock in, file leave, and see your own records.")
            ->action('Accept the invitation', $this->url)
            ->line('Already have the app? Sign in — or create your account — and enter this code:')
            ->line("**{$this->code}**");

        if ($this->expiresAt !== null) {
            $message->line('This invitation expires on '.$this->expiresAt->toFormattedDayDateString().'.');
        }

        return $message
            ->line("If you weren't expecting this, you can ignore this email.")
            ->salutation('— The SYNAPSE Team');
    }
}
