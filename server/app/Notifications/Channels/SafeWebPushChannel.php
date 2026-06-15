<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use NotificationChannels\WebPush\WebPushChannel;
use Throwable;

/**
 * A best-effort wrapper around the package {@see WebPushChannel}.
 *
 * Web-push delivery signs a VAPID JWT with EC (prime256v1) crypto. Some PHP
 * builds can't do that — e.g. a local Windows install with OPENSSL_CONF unset
 * and the `gmp` extension missing — and `WebPush::flush()` then throws
 * "Unable to create the key". Push is auxiliary: the in-app bell and email are
 * the source of truth, so a signing/transport failure must never abort the
 * notification (taking the email + database channels down with it) or bubble a
 * 500 to the request that triggered it. We log it and move on.
 *
 * The real channel is composed (not extended) so it keeps the package's
 * contextual container bindings — they are scoped to {@see WebPushChannel}
 * exactly and would not resolve for a subclass.
 */
class SafeWebPushChannel
{
    public function __construct(private WebPushChannel $channel) {}

    /**
     * Send the notification, swallowing any delivery failure.
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        try {
            $this->channel->send($notifiable, $notification);
        } catch (Throwable $e) {
            Log::warning('Web push delivery skipped: '.$e->getMessage(), [
                'notifiable' => $notifiable->getKey() ?? null,
            ]);
        }
    }
}
