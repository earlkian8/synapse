# 2026-06-10 — Notifications module (in-app, email & web push)

A system-wide notification layer delivering on three channels from one façade,
with per-user channel preferences and permission-gated broadcasting.

## Summary

- New **Notifications** module at `/system/notifications` (centre + header bell).
- One façade — `Notifier::toUser / toRole / toAll` — emits a `SystemNotification`
  on **in-app**, **email** (Brevo SMTP), and **web push** (VAPID) channels.
- **Compose / broadcast** to everyone, a role, or one person (gated by
  `notifications.send`).
- Per-user **preferences** (email + push) and **per-device** push opt-in.
- First **auto-notifications**: welcome on user creation, notice on role grant.

## Backend

- Migrations: `…_create_notifications_table`, `…_create_push_subscriptions_table`
  (published), `…_add_notification_preferences_to_users_table`
  (`email_notifications`, `push_notifications`).
- `App\Notifications\SystemNotification` — `ShouldQueue`; `via()` honours
  preferences; `viaConnections()` pins `database` to `sync` (instant bell, async
  email/push); `toArray()` / `toMail()` / `toWebPush()`.
- `App\Support\Notifier` — `toUser` / `toRole` / `toAll`, returns recipient count.
- `NotificationController` (index, broadcast `store`, read, readAll, destroy,
  clear), `NotificationPreferenceController`, `PushSubscriptionController`;
  `SendNotificationRequest`; `NotificationResource`.
- Routes live in `routes/system.php` under the `system` group
  (`system.notifications.*`); broadcast gated by `can:notifications.send`, all
  other actions scoped to the owner.
- `PermissionRegistry` gains a **Notifications** group (`notifications.send`),
  seeded to Super Admin / Administrator / HR Manager.
- `User` uses `HasPushSubscriptions`; new fillable/casts for the preference flags.
- `HandleInertiaRequests` shares a compact `notifications` prop (recent 8 +
  unread count) for the header bell.
- `UserController` emits a welcome notification on create and a role-grant notice
  on update — installed `laravel-notification-channels/webpush`.

## Frontend

- `pages/system/notifications/index.tsx` — centre: filter (all/unread), mark
  read, delete, clear, pagination, preferences panel, compose.
- `components/notifications-dropdown.tsx` — rewritten to use the shared payload,
  mark-read, and a 30s poll (was static mock data).
- `features/notifications/` — types, routes, constants (level styles), and
  components (item, compose sheet, preferences, confirm dialog).
- `hooks/use-web-push.ts` + `public/sw.js` — service-worker registration, VAPID
  subscribe/unsubscribe, and OS-level push display + click-to-open.
- Sidebar gains a **Notifications** item under System; shared page-props type
  extended with `notifications`.

## Config

- `.env`: `MAIL_*` set to Brevo SMTP; `VAPID_SUBJECT` / `VAPID_PUBLIC_KEY` /
  `VAPID_PRIVATE_KEY` generated via `php artisan webpush:vapid`.

## Tests

- `tests/Feature/Notification/NotificationTest.php` — centre, ownership scoping,
  read/delete/clear, broadcast (gate, all, role isolation, validation), the
  channel/preference matrix, preference updates, push subscription store/destroy,
  and the welcome auto-notification.

## Verification

`tsc`, ESLint, Prettier and Pint clean; `npm run build` succeeds (`notifications`
chunk 26 kB). Unit suite green (9). The emit→store chain and the `via()` channel
selection were verified against live Postgres; mail + VAPID config confirmed to
resolve. (Feature suite needs `pdo_sqlite` / CI to run locally.)

## ⚠️ Notes

- Email and web push are **queued** — run `php artisan queue:work` (already in
  `composer dev`) for them to send; in-app is synchronous regardless.
- Web push needs **HTTPS or localhost** and per-device permission.
