# 0003 — Notification delivery & channels

- **Status:** Accepted
- **Date:** 2026-06-10
- **Supersedes:** —
- **Related:** [Notifications module](../modules/notifications.md), [0002 — RBAC](./0002-rbac-authorization.md)

## Context

The ERP needs to notify people about events across modules (account changes,
approvals, announcements). Three reach levels were required: **in-app** (a bell +
centre), **email**, and **desktop/web push** like consumer apps. Each user must be
able to opt out per channel, and broadcasting must be controllable (to a person, a
role, or everyone). Delivery must not slow down the originating request.

## Decision

Build on **Laravel's native notification system** with one notification class and
a thin façade.

1. **One notification, one façade.** A single `SystemNotification` carries every
   message; `App\Support\Notifier` (`toUser` / `toRole` / `toAll`) is the only
   call site other modules use — mirroring `ActivityLogger`.

2. **Three channels, preference-gated.** `via()` returns `database` always, plus
   `mail` and/or `WebPushChannel` based on the recipient's `email_notifications`
   / `push_notifications` flags (and, for push, an existing subscription).

3. **Instant in-app, async email/push.** The notification is `ShouldQueue`, but
   `viaConnections()` pins `database` to the `sync` connection. The bell updates
   synchronously while email and push are queued — so the in-app experience never
   depends on a running worker, and a broadcast to "everyone" never blocks the
   request on N emails.

4. **In-app store = Laravel's `notifications` table.** Rich fields live in the
   JSON `data` column; `read_at` drives unread state. No custom table — the
   personal centre only needs "my rows, newest first, filter unread", all of
   which the standard schema serves directly.

5. **Web push via `laravel-notification-channels/webpush`.** Encrypted Web Push
   (VAPID + aes128gcm) is impractical to hand-roll; the package provides the
   channel, the `push_subscriptions` table, and key generation. A service worker
   (`public/sw.js`) renders the OS notification.

6. **Frontend mirrors, never owns.** The header bell reads a shared
   `notifications` prop (recent + unread) and polls every 30s; all state changes
   go through gated/owned endpoints.

## Alternatives considered

- **A bespoke notifications table + hand-rolled delivery.** Rejected — more code,
  and hand-rolling Web Push encryption is error-prone. The standard table already
  fits the personal-centre access pattern.
- **Real-time via WebSockets (Pusher/Reverb).** Rejected for now — adds
  infrastructure. Polling + web push cover "near-live bell" and "desktop alert"
  without a socket server. Can be added later behind the same `Notifier`.
- **Queue everything (incl. in-app).** Rejected — the bell would silently lag (or
  never update) without a worker. Pinning `database` to `sync` avoids that.
- **Send email inline (no queue).** Rejected — broadcasts would block the request
  on SMTP for every recipient.

## Consequences

- **Positive:** one vocabulary for all notifications; channels honour user
  choice; in-app is instant and worker-independent; email/push scale off the
  request path; a clean migration path to WebSockets later.
- **Negative / watch-outs:**
  - Email and push require a **queue worker** (`php artisan queue:work`); without
    one those channels sit in the `jobs` table.
  - Web push needs **HTTPS** (or localhost) and per-device permission; the VAPID
    keys in `.env` must be stable (rotating them invalidates subscriptions).
  - The shared bell payload costs ~2 light queries per Inertia response.
