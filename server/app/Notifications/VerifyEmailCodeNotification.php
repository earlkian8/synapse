<?php

namespace App\Notifications;

use App\Support\EmailVerificationCode;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Carries the one-time code that confirms a new account's email address.
 *
 * Replaces the framework's link-based {@see VerifyEmail}:
 * the message has no button and no URL at all, because the person is already
 * sitting on the screen that asked for it. Nothing here is clickable, which also
 * means nothing here can be re-aimed by a mail client rewriting links.
 *
 * Purely transactional — mail only, never the in-app feed, and it ignores
 * notification preferences: an account that cannot confirm its address cannot be
 * used. Delivered synchronously so a bare `php artisan serve` (no queue worker)
 * still sends it, the same arrangement {@see EmployeeInvitationNotification} uses.
 */
class VerifyEmailCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $code) {}

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
        $app = config('app.name');
        $minutes = EmailVerificationCode::lifetime();

        return (new MailMessage)
            ->subject("Your {$app} verification code: {$this->code}")
            ->greeting("Welcome to {$app}!")
            ->line('Enter this code on the verification screen to activate your account:')
            // Spaced out so the digits are read in pairs rather than as one number,
            // and bolded so the code is the thing the eye lands on.
            ->line('# '.trim(chunk_split($this->code, 2, ' ')))
            ->line("The code expires in {$minutes} minutes. If you didn't create an account, you can ignore this email — nothing happens until the code is used.")
            ->salutation("— The {$app} Team");
    }
}
