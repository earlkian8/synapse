# Database: notifications tables

The tables and columns behind the [Notifications module](../modules/notifications.md).

## `notifications`

Laravel's standard notification table (the `database` channel). One row per
recipient per notification.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | uuid (PK) | Notification id; also used as the web-push `tag`. |
| `type` | string | The notification class (`App\Notifications\SystemNotification`). |
| `notifiable_type` / `notifiable_id` | morph | The recipient (a `User`). |
| `data` | text (JSON) | Payload: `title`, `body`, `url`, `level`, `category`, `actor`. |
| `read_at` | timestamp, nullable | `null` = unread; drives the badge and the unread filter. |
| `created_at` / `updated_at` | timestamps | |

Migration: `2026_06_10_210000_create_notifications_table`.

Reads are always scoped through the recipient relation
(`$user->notifications()`), so a user only ever touches their own rows.

## `push_subscriptions`

Browser push endpoints, from `laravel-notification-channels/webpush`. A user may
have several (one per browser/device).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `subscribable_type` / `subscribable_id` | morph | The owning `User`. |
| `endpoint` | string (unique) | The push service URL for this device. |
| `public_key` | string, nullable | The subscription's `p256dh` key. |
| `auth_token` | string, nullable | The subscription's `auth` secret. |
| `content_encoding` | string, nullable | Encoding negotiated by the browser. |
| `created_at` / `updated_at` | timestamps | |

Migration: `2026_06_10_063440_create_push_subscriptions_table` (published from the
package). Managed via `User::updatePushSubscription()` /
`deletePushSubscription()` (the `HasPushSubscriptions` trait).

## `users` — added columns

Per-channel delivery preferences (in-app is always on).

| Column | Type | Default | Notes |
| --- | --- | --- | --- |
| `email_notifications` | boolean | `true` | Opt in/out of the email channel. |
| `push_notifications` | boolean | `true` | Opt in/out of the web-push channel. |

Migration: `2026_06_10_210100_add_notification_preferences_to_users_table`.
