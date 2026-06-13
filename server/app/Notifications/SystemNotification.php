<?php

namespace App\Notifications;

use App\Notifications\Channels\SafeWebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The single notification type that powers the whole module.
 *
 * Every in-app, email, and web-push notification flows through this class. All
 * channels are delivered on the synchronous connection (see
 * {@see self::viaConnections()}) so delivery never depends on a running queue
 * worker — in local dev the app is often served with a bare `php artisan serve`
 * (no `queue:work`), and a silently-queued email that never sends is worse than
 * a slightly slower request. Web push is best-effort via {@see SafeWebPushChannel}.
 */
class SystemNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $level  info | success | warning | error
     */
    public function __construct(
        public string $title,
        public string $body,
        public ?string $url = null,
        public string $level = 'info',
        public string $category = 'general',
        public ?string $actor = null,
    ) {}

    /**
     * The channels this notification is delivered on, honouring the recipient's
     * per-channel preferences. In-app (database) is always on.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (($notifiable->email_notifications ?? false) && ! empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        if (($notifiable->push_notifications ?? false) && $this->hasPushSubscriptions($notifiable)) {
            $channels[] = SafeWebPushChannel::class;
        }

        return $channels;
    }

    /**
     * Force every channel onto the synchronous connection so delivery never
     * waits on a queue worker (see the class docblock). The notification stays
     * {@see ShouldQueue} so the per-channel routing still flows through here.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return [
            'database' => 'sync',
            'mail' => 'sync',
            SafeWebPushChannel::class => 'sync',
        ];
    }

    /**
     * The payload stored in the `notifications` table and read by the frontend.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'level' => $this->level,
            'category' => $this->category,
            'actor' => $this->actor,
        ];
    }

    /**
     * Render the email for the mail channel.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting('Hello '.($notifiable->first_name ?? 'there').',')
            ->line($this->body);

        if ($this->url) {
            $mail->action('View in NEXO', url($this->url));
        }

        return $mail->salutation('— The NEXO Team');
    }

    /**
     * Render the web-push payload for the desktop/browser channel.
     */
    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->icon('/favicon.ico')
            ->badge('/favicon.ico')
            ->body($this->body)
            ->tag($notification->id)
            ->data(['url' => $this->url, 'id' => $notification->id])
            ->options(['TTL' => 3600]);
    }

    /**
     * Whether the notifiable has at least one registered push subscription.
     */
    private function hasPushSubscriptions(object $notifiable): bool
    {
        return method_exists($notifiable, 'pushSubscriptions')
            && $notifiable->pushSubscriptions()->exists();
    }
}
