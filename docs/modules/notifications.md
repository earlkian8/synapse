# Module: Notifications

> Status: **Active** · Route prefix: `/system/notifications` · Sidebar: System → Notifications

The system-wide notification layer. Delivers alerts on three channels — **in-app**
(the header bell + a personal centre), **email**, and **web push** (desktop
notifications) — from one call site. Any module can notify a person, a whole
role, or everyone, and each recipient controls which channels reach them.

---

## 1. Feature overview

| Area | Capabilities |
| --- | --- |
| **In-app centre** | `/notifications` page: filter (all / unread), mark one/all read, delete one, clear all, pagination. |
| **Header bell** | Live-ish unread badge + recent list, shared on every page and polled every 30s. Click marks read and follows the link. |
| **Compose / broadcast** | Permitted users send a notification to **everyone**, **a role**, or **one person**, with a title, body, importance level, and optional deep link. |
| **Channels** | In-app (always), **email** (Brevo SMTP), **web push** (VAPID). All delivered synchronously — no queue worker required. |
| **Preferences** | Each user toggles email and push; web push additionally requires a per-device opt-in (browser permission + subscription). |
| **Auto-notifications** | Emitted by other modules — e.g. a new user gets a welcome; granting a role notifies that user. |

---

## 2. How a notification is emitted

One façade — `App\Support\Notifier` (mirrors `ActivityLogger`) — is the single
entry point. It resolves an audience and fans a `SystemNotification` out to it,
returning the recipient count.

```php
Notifier::toUser($user, 'Welcome to SYNAPSE', 'Your account is ready.', url: '/dashboard', level: 'success');
Notifier::toRole('hr-manager', 'Policy update', 'The 2026 leave policy is live.');
Notifier::toAll('Maintenance tonight', 'The system will be down at 10pm.', level: 'warning');
```

`toRole` and `toAll` target **active** users only. Every call funnels through
`SystemNotification` — the one notification class for the whole app.

---

## 3. Channels (`App\Notifications\SystemNotification`)

`via()` decides the channels per recipient, honouring their preferences:

| Channel | Sent when | Notes |
| --- | --- | --- |
| `database` | always | Powers the bell + centre. Written **synchronously**. |
| `mail` | `email_notifications` is on **and** the user has an email | Rendered as a `MailMessage`; delivered via Brevo SMTP. |
| `SafeWebPushChannel` | `push_notifications` is on **and** ≥1 push subscription exists | Encrypted payload sent to the browser's push service, best-effort (see below). |

`SystemNotification` implements `ShouldQueue`, but `viaConnections()` pins
**every** channel — `database`, `mail`, and web push — to the `sync` connection,
so all three are delivered **inline on the request**. Delivery never waits on a
queue worker, so email arrives whether the app is run with `composer dev` or a
bare `php artisan serve`. (Trade-off: a broadcast to "everyone" runs N sends
inline; acceptable at this scale.)

Web push is wrapped by `App\Notifications\Channels\SafeWebPushChannel`: signing a
VAPID token needs working EC crypto, and a PHP build that can't do it (e.g. a
local Windows install with `OPENSSL_CONF` unset or no `gmp` extension) would
otherwise throw and abort the whole notification. The wrapper catches that,
**logs a warning, and lets the in-app + email channels through**. Push therefore
won't deliver on such a machine until `OPENSSL_CONF`/`gmp` is fixed, but it never
breaks sending.

---

## 4. Routes

Registered in [`routes/system.php`](../../server/routes/system.php) under the
`system` group (`['auth', 'verified']`), name prefix `system.notifications.*`.
Notifications are **personal**, so only composing to others is permission-gated.

| Method | URI | Name | Permission |
| --- | --- | --- | --- |
| GET | `/system/notifications` | `index` | — (own) |
| POST | `/system/notifications` | `store` (broadcast) | `notifications.send` |
| POST | `/system/notifications/read-all` | `read-all` | — (own) |
| DELETE | `/system/notifications/clear` | `clear` | — (own) |
| PATCH | `/system/notifications/{id}/read` | `read` | — (own) |
| DELETE | `/system/notifications/{id}` | `destroy` | — (own) |
| PUT | `/system/notifications/preferences` | `preferences` | — (own) |
| POST | `/system/notifications/subscriptions` | `subscriptions.store` | — (own) |
| DELETE | `/system/notifications/subscriptions` | `subscriptions.destroy` | — (own) |

"own" actions are scoped through `$request->user()->notifications()`, so a user
can never read or delete another person's notifications.

The literal routes are declared **before** the `{notification}` wildcard, and the
wildcard is pinned with `whereUuid('notification')`. Without both,
`DELETE …/notifications/subscriptions` matched the wildcard — "delete the
notification with id `subscriptions`" — which compared a plain string against a
`uuid` column, aborted the surrounding Postgres transaction, and took the rest of
the request with it. Push notifications could be enabled but never disabled.

---

## 5. Data model

- **`notifications`** — Laravel's standard table (UUID id, morphable
  `notifiable`, JSON `data`, `read_at`). Rich fields (title, body, url, level,
  category, actor) live in `data`; `read_at` drives the unread badge.
- **`push_subscriptions`** — from `laravel-notification-channels/webpush`
  (endpoint, keys), morphed to the user.
- **`users.email_notifications` / `users.push_notifications`** — per-channel
  opt-in booleans (default `true`).

See the [schema reference](../database/notifications-tables.md).

---

## 6. Backend architecture

```
app/
├── Notifications/SystemNotification.php   # the one notification: via()/toArray()/toMail()/toWebPush()
├── Support/Notifier.php                   # façade: toUser / toRole / toAll
├── Http/Controllers/Notification/
│   ├── NotificationController.php          # index, store(broadcast), read, readAll, destroy, clear
│   ├── NotificationPreferenceController.php
│   └── PushSubscriptionController.php
├── Http/Requests/Notification/SendNotificationRequest.php
└── Http/Resources/NotificationResource.php # flattens the JSON payload for the UI
```

`HandleInertiaRequests` shares a compact `notifications` prop (`items` = latest 8,
`unread` = count) on every response, so the header bell renders everywhere without
a dedicated fetch.

`notifications.send` is part of the [permission registry](./roles-permissions.md)
and is granted to Super Admin, Administrator, and HR Manager by the seeder.

---

## 7. Frontend architecture

```
resources/js/
├── pages/system/notifications/index.tsx     # the centre (filter, list, prefs, compose)
├── components/notifications-dropdown.tsx     # header bell (shared data + 30s poll)
├── hooks/use-web-push.ts                     # SW registration + subscribe/unsubscribe
└── features/notifications/
    ├── types.ts · routes.ts · constants.ts   # level styles/icons
    └── components/
        ├── notification-item.tsx
        ├── notification-compose-sheet.tsx     # audience (all/role/user) + message
        ├── notification-preferences.tsx       # email/push toggles + per-device enable
        └── confirm-dialog.tsx
```

`public/sw.js` is the service worker that shows the OS notification on a `push`
event and focuses/open the deep link on click.

---

## 8. Web push setup

1. The package is installed and its config/migration published.
2. VAPID keys were generated with `php artisan webpush:vapid` (stored in `.env`
   as `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY`, plus `VAPID_SUBJECT`). The public
   key is exposed to the page so the browser can subscribe.
3. On the notifications page, **Enable** registers `sw.js`, requests the browser
   permission, subscribes via the Push API, and POSTs the subscription to
   `subscriptions.store`. The server then delivers via `SafeWebPushChannel`.

> Web push requires HTTPS (or `localhost`) and a user gesture to grant
> permission. It is independent per browser/device — the user opts in on each.

---

## 9. Email setup

Email uses SMTP (Brevo) configured in `.env` (`MAIL_MAILER=smtp`,
`MAIL_HOST=smtp-relay.brevo.com`, …). Email is delivered **synchronously during
the web request** (no queue worker needed) — so if a notification's recipients
have `email_notifications` on and a valid address, the message goes out
immediately. If mail isn't arriving, check the `.env` SMTP credentials and the
Brevo sender/domain verification, not the queue.

---

## 10. Testing

`tests/Feature/Notification/NotificationTest.php` covers the centre (render, own
scope, unread filter), read/delete/clear (incl. cross-user denial), broadcast
(permission gate, to-all, to-role isolation, validation), the channel/preference
matrix (`via()`), preference updates, push subscription store/destroy, and the
welcome auto-notification on user creation.

(The Feature suite needs `pdo_sqlite` / CI to execute locally — the channel logic
and the full emit→store chain were additionally verified against live Postgres.)
