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
| **Channels** | In-app (always), **email** (Brevo SMTP), **web push** (VAPID). Email & push are queued so they never block the request. |
| **Preferences** | Each user toggles email and push; web push additionally requires a per-device opt-in (browser permission + subscription). |
| **Auto-notifications** | Emitted by other modules — e.g. a new user gets a welcome; granting a role notifies that user. |

---

## 2. How a notification is emitted

One façade — `App\Support\Notifier` (mirrors `ActivityLogger`) — is the single
entry point. It resolves an audience and fans a `SystemNotification` out to it,
returning the recipient count.

```php
Notifier::toUser($user, 'Welcome to NEXO', 'Your account is ready.', url: '/dashboard', level: 'success');
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
| `database` | always | Powers the bell + centre. Written **synchronously** (see below). |
| `mail` | `email_notifications` is on **and** the user has an email | Rendered as a `MailMessage`; delivered via Brevo SMTP. |
| `WebPushChannel` | `push_notifications` is on **and** ≥1 push subscription exists | Encrypted payload sent to the browser's push service. |

`SystemNotification` implements `ShouldQueue`, so email and web push run on the
queue. But `viaConnections()` pins the `database` channel to the `sync`
connection, so **the in-app row is written immediately** — the bell updates even
if no queue worker is running. Email/push wait for the worker (`php artisan
queue:work`, already part of `composer dev`).

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
   `subscriptions.store`. The server then delivers via `WebPushChannel`.

> Web push requires HTTPS (or `localhost`) and a user gesture to grant
> permission. It is independent per browser/device — the user opts in on each.

---

## 9. Email setup

Email uses SMTP (Brevo) configured in `.env` (`MAIL_MAILER=smtp`,
`MAIL_HOST=smtp-relay.brevo.com`, …). Because `SystemNotification` is queued,
emails are sent by the queue worker, not during the web request.

---

## 10. Testing

`tests/Feature/Notification/NotificationTest.php` covers the centre (render, own
scope, unread filter), read/delete/clear (incl. cross-user denial), broadcast
(permission gate, to-all, to-role isolation, validation), the channel/preference
matrix (`via()`), preference updates, push subscription store/destroy, and the
welcome auto-notification on user creation.

(The Feature suite needs `pdo_sqlite` / CI to execute locally — the channel logic
and the full emit→store chain were additionally verified against live Postgres.)
