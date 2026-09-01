# 2026-06-14 — Notification delivery: reliable email, fault-tolerant push

Two notification bugs surfaced in local development: manually-sent notifications
(broadcast, by role, or to a single user) never emailed their recipients, and web
push raised a hard error. Both are fixed, and delivery no longer depends on a
running queue worker.

## Highlights

- **Email actually sends now.** Notifications were `ShouldQueue` with only the
  in-app channel pinned to `sync`, so email rode the `database` queue connection
  and was delivered **only by a queue worker**. Run with a bare `php artisan
  serve` (no `queue:work`) and the mail job just sat in the `jobs` table — no
  email ever went out. Every channel is now pinned to the `sync` connection, so
  email (and push) are delivered inline on the request, worker or not. This
  mirrors the earlier decision to send the verification email synchronously.
- **Web push degrades gracefully instead of erroring.** Signing a VAPID token
  needs working EC (prime256v1) crypto. A local PHP with `OPENSSL_CONF` unset and
  no `gmp` extension can't do it, so `WebPush::flush()` threw "Unable to create
  the key" — taking the whole notification (and the originating request) down with
  it. Push is now sent through a best-effort wrapper that logs a warning and lets
  the in-app + email channels through.

## Backend

- `app/Notifications/Channels/SafeWebPushChannel.php` — **new.** Composes the
  package `WebPushChannel` (composition, not inheritance, so the package's
  contextual container bindings still resolve) and swallows any delivery
  `Throwable`, logging `Web push delivery skipped: …`.
- `app/Notifications/SystemNotification.php` — `via()` now returns
  `SafeWebPushChannel` for the push channel; `viaConnections()` pins `database`,
  `mail`, **and** the web-push channel to `sync`.

## Docs

- ADR [`0003-notification-channels`](../decisions/0003-notification-channels.md)
  amended (2026-06-14): synchronous, worker-independent delivery; push best-effort.
- [`docs/modules/notifications.md`](../modules/notifications.md) — channel table,
  delivery model, and email-setup notes updated.

## Tests

- `tests/Feature/Notification/NotificationTest.php` — the push-channel assertion
  now expects `SafeWebPushChannel`; added a test pinning the `viaConnections()`
  contract (every channel `sync`).

## Notes / environment

- Push still **won't deliver on this local machine** until its PHP can sign VAPID:
  set `OPENSSL_CONF` to a valid `openssl.cnf` and/or enable the `gmp` extension.
  The deployed Linux environment is unaffected. The app no longer errors either
  way — push just no-ops with a logged warning there.
- Brevo SMTP STARTTLS from local was verified working, so synchronous email
  delivery has a working transport; if mail still doesn't arrive, check the
  `.env` SMTP credentials and Brevo sender/domain verification.
- `MAIL_PASSWORD` (Brevo SMTP) and the Gemini API key have appeared in plaintext
  in working sessions — rotate them.
