# 0003 — Notification delivery & channels

- **Status:** Accepted (amended 2026-06-14)
- **Date:** 2026-06-10
- **Supersedes:** —
- **Related:** [Notifications module](../modules/notifications.md), [0002 — RBAC](./0002-rbac-authorization.md)

> **Amendment (2026-06-14) — synchronous delivery.** Email and push are no longer
> queued: `viaConnections()` now pins **every** channel to the `sync` connection,
> so delivery never depends on a running queue worker. In practice the app is
> often served locally with a bare `php artisan serve` (no `queue:work`), and a
> queued email that silently never sends is worse than a marginally slower
> request — the same reasoning already applied to the verification email. Web
> push is delivered through a best-effort wrapper, `SafeWebPushChannel`, so a
> platform that can't sign VAPID (e.g. a local PHP with `OPENSSL_CONF` unset /
> no `gmp`) logs a warning instead of failing the send. Points 2–3 and the
> consequences below are updated accordingly.

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
   `mail` and/or `SafeWebPushChannel` based on the recipient's `email_notifications`
   / `push_notifications` flags (and, for push, an existing subscription).

3. **Synchronous, worker-independent delivery.** The notification stays
   `ShouldQueue`, but `viaConnections()` pins **every** channel — `database`,
   `mail`, and the web-push channel — to the `sync` connection, so all three are
   delivered inline on the request. Nothing waits on `php artisan queue:work`.
   (Originally email/push were queued; see the 2026-06-14 amendment for why that
   was reversed.) The trade-off — a broadcast to "everyone" runs N sends inline —
   is acceptable at this scale and can be revisited with a real worker later.

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
  - Delivery is synchronous, so a large broadcast runs N email/push sends inline
    on the request. Fine at the current scale; revisit (re-queue with a running
    worker) if "everyone" broadcasts grow large or SMTP gets slow.
  - Web push needs **HTTPS** (or localhost) and per-device permission; the VAPID
    keys in `.env` must be stable (rotating them invalidates subscriptions).
  - Signing VAPID needs working EC crypto. A local PHP with `OPENSSL_CONF` unset
    or no `gmp` extension can't, so push **won't actually deliver there** —
    `SafeWebPushChannel` degrades it to a logged warning (point in-app + email
    still work). Set `OPENSSL_CONF` to a valid `openssl.cnf` / enable `gmp` to
    deliver push locally; the deployed (Linux) environment is unaffected.
  - The shared bell payload costs ~2 light queries per Inertia response.
