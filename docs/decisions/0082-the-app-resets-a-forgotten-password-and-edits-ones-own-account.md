# 0082 — The app resets a forgotten password and edits one's own account

- **Status:** Accepted
- **Date:** 2026-10-12
- **Related:**
  - [0023 — Identity and organisation membership](./0023-identity-and-organization-membership.md)
    (one account, many companies);
  - [0026 — Self-served identity and workspace join](./0026-self-served-identity-and-workspace-join.md)
    (the account is the person's own);
  - [0057 — Users, roles, the audit trail and the trash bin join the assistant](./0057-assistant-users-roles-activity-and-trash.md)
    (`UserAccounts`, HR's side of the same account);
  - [0083 — My schedule in the app](./0083-my-schedule-in-the-app.md) (built alongside);
  - module docs: [Mobile app](../modules/mobile-app.md),
    [User management](../modules/user-management.md).

## Context

The app's sign-in screen had no *Forgot password?*. Someone who forgot theirs had to
find the web sign-in, on a laptop or in the phone's browser, with no hint from the app
that they should.

The app's Profile was read-only. It showed the employee record — the 201 file HR keeps
in each company — and nothing of the person's own account. Their name, the email they
sign in with and their password could be changed only on the web's Settings.

Those are two different things, and the app had blurred them. The employee record is
HR's: its name, work email, position and government IDs are what the company has on
file, and an employee does not rewrite them. The account is the person's (ADR 0026): it
is the same in every company they belong to, and no company can change it once they
belong to another (ADR 0057).

App sessions are Sanctum tokens with no expiry. A token stayed valid after its password
changed, on the web or through a reset, until someone signed out on that phone. HR's
password reset already ended them (ADR 0057); the person's own change did not.

## Decision

### Forgot password? in the app

The sign-in screen gains **Forgot password?**, which opens a screen for the address one
signs in with (filled in from the sign-in form when typed there).

`POST /api/auth/forgot-password` (public, `throttle:6,1`) asks Fortify's own password
broker for a reset link. That is the same link the web's *Forgot password* sends. It opens
the web's reset page in the phone's browser, which works at phone width, and it is valid
for the broker's 60 minutes. The app does not set the password itself, so the reset page
and its rules stay in one place.

The answer is **the same whatever the address**: *"If an account uses that address, a
link to reset its password is on its way."* It does not change for an address with no
account, or when a link went out within the broker's minute. The endpoint is public and
must not tell a stranger which addresses have accounts. A mail outage is the one
exception: it answers 503 ("could not be sent just now") rather than claim a link went
out.

After sending, the screen says what to do next (open the link on this phone, choose a
password, sign in here). *Send it again* stays disabled for the broker's minute.

### One's own account, edited in the app

Profile gains an **Account** section: **Name and email** and **Change password**, each
a form sheet. Both use the web Settings rules:

- `GET` / `PATCH /api/account`: first, middle and last name and email (the web's
  `ProfileValidationRules`).
- `PUT /api/account/password`: current password, then the new one twice (the web's
  `PasswordUpdateRequest`, reused).

They work whether or not the session is bound to a company: the account belongs to the
person in every company.

**A new email address asks for the current password** in the app (`UpdateAccountRequest`).
This is one rule more than the web. An app session never ends on its own, so without
it whoever picked up an unlocked phone could move the account to their own address and
then reset the password from there.

The employee record stays read-only, and Profile now says so: *"Your employee record,
as HR keeps it. Ask them to correct anything that's wrong."* Editing the account changes
nothing on it. An employee asking HR to correct the record from the app is not built
(`not-yet-built.md`).

### One class for the person's own changes

`App\Support\Users\OwnAccount` is now how a person changes their own account. Three
places use it: the web's Settings → Profile and → Security, the app's account
endpoints, and Fortify's `ResetUserPassword`. Two rules hold whichever way in:

- **A new email address is confirmed again.** It is marked unconfirmed and a code is
  mailed to it, as when HR changes it. Before, the web's own change sent no code: the
  person had to ask for one on the confirmation screen. A mail failure is reported and
  the change still stands.
- **A new password ends the app's other sessions** and stamps `password_changed_at`.
  The phone that made the change keeps its own session. A change on the web, or through
  a reset link, ends them all. A forgotten password is often a lost phone, and the old
  password may be what signed it in.

### The app signs out when its session is refused

The server now revokes tokens in more cases, so the app has to notice. `lib/api.ts`
reports a **401 to a request that carried the current token**, and `lib/auth.tsx` signs
the phone out. The sign-in screen then says *"You were signed out on this phone, as
happens when your password changes."* Before, a revoked token left every screen failing
until the app was restarted. A 401 for an older token (a request still in flight across
a company switch) is ignored.

### Not in the activity log

The person's own account changes are not written to a company's activity log, as on the
web. The account is not a company record. An entry in the active company's log would
show one company the identity's changes made from another.

## Consequences

- Someone who forgot their password gets back in from the phone, through the same link
  and page as on the web.
- People correct their own name and sign-in email in the app. HR still owns the
  employee record, and the app says so.
- A changed or reset password ends the app sessions that should end, and the app signs
  out cleanly instead of failing screen by screen.
- The web's own email change now sends its code straight away.
- Not built: asking HR to correct the employee record from the app, changing the
  account photo from the app, and two-factor sign-in in the app.
