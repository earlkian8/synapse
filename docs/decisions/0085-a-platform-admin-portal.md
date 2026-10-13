# 0085 — A platform admin portal, with its own admins, over its own API

- **Status:** Accepted
- **Date:** 2026-10-13
- **Related:**
  - [0023 — One identity, many organisations](./0023-identity-and-organization-membership.md)
    (what an ERP account is, and why it is not an admin);
  - [0005 — Multi-tenancy](./0005-multi-tenancy.md) (the tenant scope the portal stands outside);
  - [0084 — The ERP has no front page](./0084-the-erp-has-no-front-page.md) (the other static
    site beside the ERP, `landing/`);
  - module doc: [Admin portal](../modules/admin-portal.md); tables:
    [admin portal tables](../database/admin-portal-tables.md).

## Context

Everything in the ERP happens inside one organisation. An HR Manager is the most anybody
can be, and only in their own company. Nobody could see SYNAPSE as a whole: how many
companies registered, which ones never finished setup, who signs in, or how big each
one is. Answering those questions meant opening a database console.

Whoever answers them can read every organisation, so the people who do it are the
platform's most privileged users. Giving them an ERP role would put that power inside
the tenant model it has to stand above. It would also mean an HR Manager's password, or
a stolen phone with the app signed in, was one role change away from every company.

## Decision

A separate **admin portal** (`admin/`), a static React app like `landing/`, signs in
against a JSON API in the ERP at `/api/admin`.

**Admins are their own identity.** They live in `admins`, not `users`, with their own
`admin` guard (Sanctum, provider `admins`). An admin belongs to no organisation and
holds no ERP role. An ERP account cannot sign in to the portal, and an admin cannot sign
in to the ERP. Two roles: a **super admin** also manages admin accounts and reads the
admin activity log; an **admin** monitors.

**The API is mounted on its own middleware group, `admin-api`.** It is not the `api`
group, which binds an ERP tenant. The group has no session, no cookies and no tenant,
and every response carries `Cache-Control: no-store`. On top of `auth:admin`,
`admin.session` insists the caller is an `Admin` holding a real portal token with the
`admin` ability. Sanctum hands back a session user before it looks at a token, so the
guard alone would not be enough. The mobile API's `sanctum` guard is pinned to the
`users` provider at the same time. Left unset, Sanctum accepts any model's token, and a
portal token would have passed as a phone session.

**Signing in always takes two steps, and only the second grants anything.**

1. The password, checked in constant time whether or not the email exists (a dummy hash
   stands in), and refused with one message for both. Only somebody with the right
   password learns that an account is deactivated. The answer is a **challenge**: a
   random 64-character reference, kept server-side (hashed) for 5 minutes and good for 5
   wrong codes.
2. A TOTP code (RFC 6238). **Two-factor is mandatory.** An admin who has not enrolled is
   given a fresh secret with the challenge, held encrypted inside it. The secret is
   written to the account only when a good code confirms it, so an abandoned enrolment
   leaves nothing behind. Each code works once: the matched time slot is remembered per
   admin and the next code must be newer.

The session is a bearer token that ends **8 hours** after sign-in and after **30 minutes**
with no request (`ADMIN_SESSION_LIFETIME`, `ADMIN_IDLE_TIMEOUT`). Sanctum's own expiry
covers the first. A token-authentication callback covers the second, checking
`last_used_at` before Sanctum moves it. The portal keeps the token in `sessionStorage`
(never a cookie), so it dies with the tab. It warns two minutes before the idle limit
and makes no background requests, so an unattended tab cannot keep a session alive.

**Admins never choose another admin's password.** A new account, and a reset, gets a
24-character one-time password, shown once to the super admin who issued it. Its holder
must replace it before anything else opens (`admin.password-current` holds every other
route with `reason: password_change_required`). Passwords need 12 characters with both
cases, a number and a symbol, plus a breach check in production. Changing one ends every
other session.

**Two rules keep the portal governable.** Nobody acts on their own role, status,
password reset, authenticator or deletion through admin management. And there is always
an active super admin: demoting, deactivating or deleting the last one is refused under
a row lock, so two super admins cannot remove each other at once. Deleting needs the
account to be deactivated first. Deactivating, resetting a password and resetting an
authenticator all end the target's sessions immediately.

**What the portal monitors, read-only:** an overview, every organisation (setup state,
members, employees, last activity, its HR Managers and newest members), and every ERP
account (status, workspaces with roles, two-factor, app sessions). It never returns a
join code, employer numbers, password hashes, TOTP secrets or tokens. Changing an ERP
account stays with each organisation's HR, who know the person.

**Admin activity is logged in `admin_activity_logs`**, append-only, with no route that
edits or deletes an entry. Every sign-in, sign-out, failed password or code, password
change, ended session and admin-account change is recorded with the IP and browser. The
actor's name and email are copied onto each row, so the trail still reads after an
account is deleted.

**The first admin is made at the console:** `php artisan admin:create <email>` prompts for
the password (hidden, never an argument). `php artisan admin:reset-two-factor <email>`
is the break-glass path for a lost phone when no other super admin is available.

## Consequences

- The ERP gains a second identity type. Anything that authenticates (guards, Sanctum,
  the Gate) has to keep the two apart. The Gate's callbacks are typed to `User`, so
  portal authorisation is middleware (`admin.super`), never `can:`.
- The portal is another static site to deploy, built with `VITE_ERP_URL` pointing at
  the ERP. Header-only protections (`frame-ancestors`, HSTS) are the host's job; the
  build injects a Content-Security-Policy meta tag that allows scripts only from the
  portal and requests only to the ERP.
- There is no password-reset email for admins: a super admin issues a one-time password.
  A portal with a single super admin who loses their password or phone needs the
  console.
- The monitoring is deliberately small (three screens). New screens follow the same
  pattern: a read-only query under `app/Queries/Admin`, a resource that leaves secrets
  out, and the tenant scope lifted explicitly.
