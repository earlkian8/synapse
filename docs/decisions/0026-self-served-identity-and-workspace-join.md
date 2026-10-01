# 0026 — Self-served identity: people register themselves and join a company

- **Status:** Accepted
- **Date:** 2026-08-10
- **Supersedes:** hire-time account provisioning from
  [0006 — Recruitment ATS & hire bridge](./0006-recruitment-ats-and-hire-bridge.md)
  and [0020 — Mobile companion app](./0020-mobile-companion-app.md)
- **Related:** [0023 — Identity & organisation membership](./0023-identity-and-organization-membership.md),
  [0004 — Employee ↔ User](./0004-employee-user-separation.md),
  [0005 — Multi-tenancy](./0005-multi-tenancy.md),
  [0002 — RBAC](./0002-rbac-authorization.md),
  [Employees module](../modules/employees.md),
  [Mobile app module](../modules/mobile-app.md),
  [Multi-tenancy module](../modules/multi-tenancy.md)

## Context

[ADR 0023](./0023-identity-and-organization-membership.md) made a `User` a global
identity that can belong to many organisations. But it left the *creation* of that
identity where ADR 0006 had put it: **the employer made your account for you.**
Hiring an applicant ran `EmployeeAccountProvisioner`, which invented a password,
emailed it in clear text, and linked the new login to the roster line. HR could also
rotate anybody's password from the employee directory.

For a multi-tenant HR product that is the wrong shape, in four separate ways:

1. **The employer owned the credential.** HR generated, transmitted and could reset
   the password of a person's account — an account that, under ADR 0023, is the same
   identity they use at *other* employers. One company's HR could therefore take over
   an identity that another company also trusts.
2. **The identity was keyed to a work address.** Provisioning used
   `employees.email`, so the person's login was whatever address payroll had on file.
   People leave; the address dies; the identity dies with it.
3. **Passwords in email.** A temporary password in an inbox is a credential sitting
   in plain text, indefinitely, in a place neither party controls.
4. **There was no way in that HR didn't initiate.** Anyone missed by the import — or
   hired before the app existed — simply had no route to their own records.

Google Classroom solves the same shape of problem — an institution with a roster, and
people who own their own accounts — with two ideas: a **class code** anybody can use
to join, and **direct invitations** for people the teacher names. That is the model
we adopt, with one deliberate divergence noted below.

## Decision

**The ERP owns employment. The person owns identity. A join record bridges them.**

`Employee` remains the per-organisation employment record and is unchanged.
Creating one no longer creates a login of any kind. Instead there are exactly two
ways an identity comes to occupy a roster line:

### 1. Invitation — HR names the person

`employee_invitations` is a claim ticket for one specific `Employee`. It carries one
grant in two forms: a **link token** (emailed; stored only as its sha256) and a short
**retypeable code**. Either redeems it. `App\Support\EmployeeInvitations` is the only
class that issues, redeems, or withdraws one; the ERP controller and the mobile API
both call it.

- **Re-inviting supersedes.** Issuing a new invitation revokes the outstanding one, so
  exactly one code is ever live and a forwarded old email is inert.
- **Possession is the authorisation.** Redeeming deliberately does *not* require the
  claimant's email to match the invited address — people register with the address
  they actually read, which is frequently not the one payroll holds. What the secret
  proves is that HR handed it to them. *Discovery* is stricter: listing "what is
  waiting for me?" matches the caller's own mailbox, because that asks for nothing but
  a login.
- **Expiry is evaluated, never swept.** `expires_at` (14 days) is checked on every
  lookup, so an invitation lapses correctly whether or not any job ran.

### 2. Join code — the person walks up

Every `Organization` has a rotatable `join_code` (Classroom's class code) and a
`join_code_enabled` switch. `App\Support\WorkspaceJoin` resolves an entered code:

- **The code-holder's registered email matches exactly one unclaimed roster line** →
  admit them immediately and bind that line. This is the ordinary case and it feels
  instant, which is the point.
- **Anything else** → an `organization_join_requests` row that HR reviews on the new
  **App Access** screen, where they must nominate *which* employee record the person
  is before approving.

**This is the deliberate divergence from Classroom.** In Classroom a leaked class code
costs you a stranger in a lesson. Here it would cost you a stranger holding somebody's
201 file, salary and government numbers. So a code alone never admits an unknown
person — it buys them a place in a queue. An ambiguous email (two roster lines sharing
an address) matches *nothing* rather than guessing, for the same reason.

### Consequences for identity and sessions

- **"No organisation" is a valid session state.** `POST /api/auth/register` creates an
  identity with no membership and returns a real token with `organization: null` and
  `needs_workspace: true`. Login no longer errors for an unaffiliated account. The
  mobile app routes that state to `app/join.tsx` rather than treating it as a failure.
- **`App\Support\MobileSession` mints every session.** There are now four ways one
  begins — login, register, switch company, join a company — and they must agree on
  the token's bound organisation and the payload's shape, so they share one builder.
- **`/me` reports what the token can do**, not what the user could do. A token minted
  before its holder joined anywhere stays unbound; the payload still lists every
  membership and the client binds one via `/auth/switch`.
- **`OrganizationProvisioner::admit()` is the single admission path.** Both routes in
  converge on it: membership, the organisation's baseline `staff` role (resolved by
  `organization_id`, never by whichever tenant happens to be bound), and the
  `employee.user_id` link.

### Removed, and not to be re-added

`EmployeeAccountProvisioner`, `EmployeeCredentialsNotification`,
`EmployeeAccountController`, and `POST employees/{employee}/reset-password`. **HR
cannot set or reset anybody's password.** Forgotten passwords go through Fortify's
existing forgot-password flow, which proves control of the mailbox. The separate
system Users module keeps its own admin-managed logins; that is a different concern
and is untouched here.

`ApplicantHirer::hire()`'s `$sendCredentials` flag became `$sendInvitation`. Hiring is
a workforce fact that must stand on its own, so a refused invitation (no address on
the application) is reported rather than unwinding the employment.

### Tenancy escapes

Both support classes read past `OrganizationScope` with `withoutGlobalScopes()`, on
purpose and *only* there: an invitation or a join code is issued **inside** a tenant
and answered from **outside** one, by somebody who is not yet a member of it. Writes
still set `organization_id` explicitly, and activity logs are written inside
`Tenancy::runFor()` so an acceptance lands in the right workforce's audit trail rather
than in whichever company the caller's token happened to be bound to.

## Alternatives considered

- **Keep hire-time provisioning, add invitations alongside.** Rejected: it leaves the
  employer holding a credential for a cross-tenant identity, which is the actual
  defect. Half-migrating would also leave two code paths that drift.
- **Instant, Classroom-exact join.** Rejected for the reason above — the blast radius
  of a leaked code is an HR file, not a lesson.
- **Always moderate every join.** Rejected as the default: it makes every new hire wait
  on HR even when the roster already holds their exact address, which is precisely the
  case the system can answer by itself.
- **Let mobile create a company.** Rejected: the app is the employee companion and has
  no admin surface to run a tenant afterwards. Company creation stays on web
  registration, which already provisions the org, its roles, and its owner.

## Consequences

- HR gains a real answer to "who can actually sign in?" — the **App Access** screen at
  `/employees/access`, plus a derived `app_access` (`active` / `invited` / `none`) on
  every employee row. It is derived on read, because the `invited` state can lapse on
  its own and no writer would be watching.
- A new `employees.invite` permission (HR Manager by default, back-filled by migration)
  gates inviting and reviewing join requests.
- Codes use Crockford base32 (no I, L, O, U) and normalise typed input by folding those
  letters onto the digits they resemble, so a code read aloud or off a whiteboard
  survives the trip.
- People can now be reached before they exist as users, so
  `EmployeeInvitationNotification` is addressed to the invitation rather than to a
  `User`.
- 35 new tests cover both routes in, the supersede/expiry/revoke rules, the tenancy
  escapes, and the admission guards.
