<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The single notification type that powers the whole module.
 *
 * Every in-app, email, and web-push notification flows through this class. It is
 * queued so email and web-push delivery never block the request — but the
 * `database` channel is forced onto the synchronous connection (see
 * {@see self::viaConnections()}) so the in-app bell updates instantly.
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
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /**
     * Keep the in-app write synchronous (instant bell update) while email and
     * web-push fan out on the queue.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
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
