<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcomes a new hire and hands them the temporary password for the SYNAPSE
 * mobile app. Unlike {@see SystemNotification} this is purely transactional —
 * mail only (never the in-app feed), and always sent regardless of the
 * recipient's notification preferences. Delivered synchronously so a bare
 * `php artisan serve` (no queue worker) still sends it; with the default
 * `MAIL_MAILER=log` it lands in storage/logs for a no-SMTP demo.
 */
class EmployeeCredentialsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $email,
        public string $temporaryPassword,
        public string $organizationName,
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
        return (new MailMessage)
            ->subject("Welcome to {$this->organizationName} — your SYNAPSE app login")
            ->greeting('Hello '.($notifiable->first_name ?? 'there').',')
            ->line('An account has been created for you so you can clock in, file leave, and view your records from the SYNAPSE mobile app.')
            ->line('**Email:** '.$this->email)
            ->line('**Temporary password:** '.$this->temporaryPassword)
            ->line('Please sign in and change your password as soon as you can.')
            ->salutation('— The SYNAPSE Team');
    }
}
