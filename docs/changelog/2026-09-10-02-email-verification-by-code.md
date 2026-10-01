# Confirming your email address is a code you type, not a button you click

Registration sent the standard framework mail: a **"Verify email address" button** on a
signed URL. It works, and every one of its problems lands on the person who just signed
up. The address is usually read on a phone while the browser sits on a laptop, so
clicking signs them in on the wrong device and leaves the tab they were actually using
stuck on the prompt. The link *is* the credential, so anything that can read the message
can spend it — including the link-scanning prefetchers corporate mail gateways run. And
the screen that asked had nothing to do on it but "Resend verification email."

Now the mail carries a six-digit code and nothing clickable at all, and the screen that
asked for it is where you finish.

## Highlights

- **The code is the action.** No verify button: the form posts itself the moment the
  sixth digit lands. The only button on the screen sends a new code, which is the one
  thing typing cannot do.
- **A rejected code clears the boxes and puts the caret back in the first one**, so the
  next attempt is a straight retype rather than a select-all-and-delete.
- **The mail has no URL in it.** Nothing to prefetch, nothing to rewrite, nothing to
  consume before the recipient sees it. The code is in the subject line too, so it can
  be read off a notification without opening anything.
- **Ten minutes, single-use, rate-limited.** A code is typed on the screen that asked
  for it, so it has no reason to outlive that sitting — against the hour a link got. It
  is cleared the moment it is spent, and the endpoint allows six attempts a minute.
- **Stored hashed**, exactly like a password reset token, and hidden from the client.
- **One method issues them**, so sign-up, the resend endpoint, admin-created users and
  an email change in User Management all behave identically.

## Backend

- **New** `Support\EmailVerificationCode` — issue, match, clear, and the lifetime. The
  one place that knows a code is a credential.
- **New** `Notifications\VerifyEmailCodeNotification` — the message. Mail only,
  delivered synchronously so a bare `php artisan serve` still sends it.
- **New** `Auth\VerifyEmailCodeController` + `VerifyEmailCodeRequest`, on
  `POST /email/verify` (`verification.code`), `auth` + `throttle:6,1`. The caller is
  signed in but unverified, so the account being confirmed is always their own — there
  is no id or hash in the request to trust, which is the main thing a code buys over a
  link.
- `User::sendEmailVerificationNotification()` is overridden; `AppServiceProvider`'s
  link-branding closure is gone with it.
- **Migration** adds `users.email_verification_code` (hashed) and
  `email_verification_code_expires_at`. Neither is fillable; the code is added to the
  model's `#[Hidden]` set so it cannot reach the client.
- `auth.verification.code_expire` (10 minutes) — the config block the app had been
  reading a default out of.

## Frontend

- `pages/auth/verify-email.tsx` is now the OTP screen: the same six-slot input the 2FA
  challenge uses, `autocomplete="one-time-code"`, auto-submitting on completion, and the
  address it was sent to stated on screen.
- The completed code is passed into the payload with `transform` rather than read back
  out of `useForm` state — `setData` only lands on the next render, so the submit that
  fires on the sixth digit would otherwise post five of them.
- `pages/settings/profile.tsx`'s unverified-address prompt points at the verification
  screen instead of only offering a resend.

## Notes

- Pest: **700 tests, 700 passed** — 14 new ones covering what the account is sent (a
  code, no action URL), the code being hashed and never reaching the client, spending
  it, refusing a wrong / expired / another account's / malformed one, the throttle,
  resending invalidating the one before, and an already-verified account being sent on
  rather than shown an error. Three User Management tests were updated from the link
  notification to the code one. Pint, tsc, ESLint, Prettier and `npm run build` are green.
- Walked it in Chromium over CDP: registered a real account, read the code out of the
  log mailer, confirmed the message contains no action button and no verification link,
  then typed a wrong code (refused, cleared, refocused) and the right one — which
  verified and carried straight on to the setup wizard. Light and dark, 1440px and
  390px. The throwaway tenant was removed afterwards.
- Fortify's signed-link route stays registered but is now unreachable — nothing
  generates a signed URL. It is left in place so a verification mail already in
  somebody's inbox when this ships still works.
