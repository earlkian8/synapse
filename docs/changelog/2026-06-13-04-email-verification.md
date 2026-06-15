# 2026-06-13 — Standards-based email verification (Brevo)

User Management no longer lets an admin fake-verify an email with a toggle.
Instead, every new or re-emailed account must **confirm ownership** via a link
sent to the address — the standard Laravel email-verification flow, enforced
app-wide, delivered through **Brevo**.

## Highlights

- **Real confirmation email** on user create and on email change — a branded,
  signed verification link, not an admin switch.
- **Enforced** — `User` now implements `MustVerifyEmail`, so the existing
  `verified` middleware bounces unverified users to the verification notice until
  they confirm. No admin bypass.
- **Resend** — per-row action + a button in the detail drawer (shown only for
  unverified accounts), gated by `users.update`.
- **No lockouts** — a backfill migration marks every pre-existing account as
  verified, so enforcement applies only to accounts created/changed from here on.

## Backend

- `User` implements `Illuminate\Contracts\Auth\MustVerifyEmail` (the verification
  trait already ships on the framework base model).
- Migration `…_backfill_email_verified_at_for_existing_users` sets
  `email_verified_at = now()` for all rows that were null at migration time.
- `AppServiceProvider::configureEmailVerification()` brands the email in place via
  `VerifyEmail::toMailUsing()` — the notification class stays `VerifyEmail`, so the
  framework flow, the `Registered`-event auto-send, and Fortify's resend endpoint
  all keep working.
- `UserController`:
  - `store` — new accounts start unverified and are sent a confirmation email.
  - `update` — a changed email resets verification and re-sends the link.
  - `resendVerification` — new endpoint, `POST {user}/resend-verification`
    (`can:users.update`).
  - `sendVerification()` helper sends **synchronously** (so delivery doesn't depend
    on a queue worker — a dropped email would lock the user out) and swallows +
    reports transport errors so a mail outage never breaks the create/update.
- `StoreUserRequest` / `UpdateUserRequest` — dropped the `email_verified` field.
- Route added in `routes/system.php`.

## Frontend

- `user-form-sheet` — removed the "Email verified" toggle; added an informational
  note that a verification link will be emailed (and that changing the email
  re-triggers it).
- `user-row-actions` + `user-detail-sheet` — a **Resend verification** action for
  unverified, non-archived accounts.
- `routes.ts` — `resendVerification(id)`; page wires the handler through the table
  and detail drawer.

## Mail / config

- Delivery uses the app's default mailer: **Brevo** over SMTP
  (`smtp-relay.brevo.com`), configured via `MAIL_*` in `.env`. `.env.example`
  updated with Brevo placeholders.

## Notes

- Registration now also requires verification (the `Registered` listener auto-sends
  the branded email); a freshly registered owner must confirm before using the app.
- Run `php artisan migrate` to apply the backfill, and restart `php artisan serve`
  to pick up the provider/model changes.
