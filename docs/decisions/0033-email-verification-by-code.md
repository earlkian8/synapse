# 0033 — Confirming an email address with a code, not a link

- **Status:** Accepted
- **Date:** 2026-09-10
- **Related:** [0023 — Identity & organisation membership](./0023-identity-and-organization-membership.md)
  (the email *is* the identity key, which is why confirming it matters),
  [0026 — Self-served identity & workspace join](./0026-self-served-identity-and-workspace-join.md)
  (people create their own accounts, so this is the first thing they do),
  [0032 — Guided company setup](./0032-guided-company-setup.md) (what they reach
  immediately afterwards).

## Context

Registration sent the standard framework mail: a **"Verify email address" button**
on a signed, expiring URL. It works, and it has four problems that all land on the
person who just signed up.

- **It moves them to a different tab, and often a different device.** The address is
  frequently read on a phone while the browser sits on a laptop. Clicking there
  signs them in *there*, on the wrong machine, and the tab they were actually using
  sits on the prompt forever.
- **The link is the credential.** Anything that can read the message can spend it —
  including the link-scanning and "safe browsing" prefetchers that corporate mail
  gateways run, which have been known to consume single-use URLs before the
  recipient sees them.
- **Mail clients rewrite links.** A button whose href has been re-aimed through a
  tracking domain is indistinguishable, to the reader, from one that has not.
- **There is nothing to do in the tab that asked.** The screen's only control was
  "Resend verification email", which is not the action anybody wants.

## Decision

**An address is confirmed by typing a six-digit code into the screen that asked for
it.** The mail carries the code and nothing clickable at all.

- **Storage.** The code is hashed on `users`, exactly like a password reset token,
  with its own expiry. The column is readable by anything that can read the row, and
  possession of the code is what verifies the address — so it is treated as the
  credential it is, and cleared the moment it is spent.
- **Lifetime.** Ten minutes (`auth.verification.code_expire`), against the hour a
  link got. A code is typed on the screen that asked for it, so there is no reason
  for one to outlive the sitting it was issued in.
- **Not reusing `JoinCode`.** Both are short codes people retype, but a join code is
  Crockford base32 because it is read aloud or off a printed slip, and it trades
  keyspace for characters nobody can misread. This one is typed off the screen
  beside it, and is **numeric** so the phone keyboard comes up as a keypad and
  `autocomplete="one-time-code"` can fill it. Its safety comes from being
  short-lived, single-use and rate-limited, not from its length.
- **Rate limiting is what makes six digits safe.** The verify endpoint is
  `throttle:6,1` per caller. Hashing with bcrypt makes each attempt cost real time
  on top of that.
- **One method issues them.** `User::sendEmailVerificationNotification()` is
  overridden, so both ways an address gets confirmed — the `Registered` listener at
  sign-up and Fortify's resend endpoint — go through the same code, and neither can
  drift from the other. Admin-created users and email changes in User Management get
  a code for the same reason.
- **No verify button on the screen either.** The code *is* the action, so the form
  posts itself the moment the sixth digit lands. The only button sends a new code,
  which is the one thing typing cannot do.

**Fortify's signed-link route stays registered.** Nothing generates a signed URL any
more, so it is inert — but leaving it means a verification mail already sitting in
somebody's inbox when this shipped still works. Removing it would have meant
unpicking Fortify's route registration to delete a route that cannot be reached
without a signature only the app can produce.

## Consequences

- Sign-up finishes in one tab, on one device.
- The mail is safe to prefetch, scan and rewrite: there is nothing in it to click.
- A code that leaks is useful for ten minutes, to somebody who also has the session
  — the link was useful for an hour to anybody at all.
- Two more columns on `users`, and a credential to remember to clear. Both are
  handled in one place (`EmailVerificationCode`).
- Copy across the app that said "link" now says "code": the verification screen, the
  User Management tests, and the unverified-address prompt on the profile page,
  which now points at the verification screen rather than only offering a resend.
- The mobile API's `register` endpoint creates identities without firing `Registered`
  and so never sent verification mail; that is unchanged and out of scope here.

## Alternatives considered

- **Keep the link and add a code.** Two credentials for one address, both live at
  once, and the weaker property of the two (a link anything can spend) still holds.
- **A magic link that signs you in.** Solves nothing about the wrong-device problem
  and makes the link more valuable, not less.
- **Reuse `JoinCode`'s alphabet.** Rejected above: a base32 code cannot be typed on a
  numeric keypad and cannot be autofilled as a one-time code.
