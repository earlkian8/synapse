# Self-served identity: people register themselves and join a company

The employer no longer makes your account. SYNAPSE now works the way Google Classroom
does — **you own your login, the company owns the roster, and a code joins the two.**
Hiring an applicant used to invent a password, email it in clear text, and hand the
employer the keys to an identity that person also uses at their *other* employers.
That is gone. The *why* is in
[ADR 0026](../decisions/0026-self-served-identity-and-workspace-join.md); the *how* is
in the updated [employees](../modules/employees.md),
[mobile app](../modules/mobile-app.md) and [multi-tenancy](../modules/multi-tenancy.md)
module docs.

## Highlights

- **You create your own account.** `POST /api/auth/register` in the mobile app takes a
  name, any email you actually read, and a password. It creates no employment and
  joins no company — "signed in, belonging nowhere" is now a legitimate state that
  routes to a join screen instead of an error.
- **Two ways in, and only two.** HR **invites** a specific roster line (a link plus an
  8-character code), or publishes the **company join code** and lets people come to
  it. Both converge on one admission path, so neither can drift from the other.
- **The join code is deliberately not Classroom-exact.** If your registered email
  matches exactly one unclaimed roster line you're in instantly; anyone else lands in
  a queue HR reviews. A leaked class code costs you a stranger in a lesson — a leaked
  join code would cost you a stranger holding somebody's 201 file. Two roster lines
  sharing an address match *nothing* rather than guessing.
- **HR can no longer set or reset anybody's password.** The reset-password action, the
  provisioner behind it, and the credentials email are deleted. Forgotten passwords go
  through the existing forgot-password flow, which proves control of the mailbox.
- **A real answer to "who can actually sign in?"** Every employee row now carries a
  derived `app_access` — `active`, `invited`, or `none` — and the new **App Access**
  screen turns that into work: people at the door, invitations outstanding, and the
  backlog nobody has been asked yet.
- **Codes survive being read aloud.** Crockford base32 (no I, L, O or U), and typed
  input folds those letters onto the digits they resemble — so a code copied off a
  whiteboard, lower-cased, with a stray dash, still resolves.

## Backend

- **`App\Support\EmployeeInvitations`** — the one way to issue, redeem, or withdraw an
  invitation. Re-inviting **supersedes** (the old code dies), expiry is *evaluated* on
  every lookup rather than swept, and acceptance re-reads under a row lock so two
  people racing one code can't both get through. Redemption takes any valid code
  (possession is the authorisation); *discovery* is scoped to the caller's own mailbox.
- **`App\Support\WorkspaceJoin`** — resolves a join code to admission or an
  `organization_join_requests` row, and handles approve / decline. Re-asking after a
  decline revives the same row, so the review screen lists people rather than attempts.
- **`App\Support\JoinCode`** — generation, uniqueness (checked across every tenant and
  past soft deletes), and self-healing normalisation.
- **`App\Support\MobileSession`** — builds every session the app is handed. There are
  now four ways one begins (login, register, switch, join) and they must agree on the
  token's bound organisation. `/me` reports what *this token* can do, not what the user
  could do.
- **`OrganizationProvisioner::admit()`** — the single place a person becomes staff of a
  company: membership, the org's baseline `staff` role (resolved by `organization_id`,
  never by the bound tenant), and the `employee.user_id` link.
- **Migration** — `employee_invitations`, `organization_join_requests`,
  `organizations.join_code` + `join_code_enabled` (back-filled for existing tenants),
  and the new `employees.invite` permission granted to existing owner roles.
- **Tenancy escapes are confined.** Both support classes read past `OrganizationScope`
  with `withoutGlobalScopes()` — a code is issued inside a tenant and answered from
  outside it — and write activity logs inside `Tenancy::runFor()` so an acceptance
  lands in the right workforce's audit trail.
- **Removed:** `EmployeeAccountProvisioner`, `EmployeeCredentialsNotification`,
  `EmployeeAccountController`, `POST employees/{employee}/reset-password`.
  `ApplicantHirer::hire()`'s `$sendCredentials` is now `$sendInvitation`, and a refused
  invitation is reported rather than unwinding the hire.

## Frontend (web)

- **App Access** (`/employees/access`) — ordered by who is waiting on whom: join
  requests first (somebody is blocked on HR right now), invitations next, the
  un-invited backlog last. Empty sections disappear rather than sitting there hollow.
- **The join code** is set large and letter-spaced, because it exists to be read across
  a desk or off a slide, with copy, rotate, and an on/off switch.
- **Approving is a decision, not a rubber stamp.** The picker keeps the requester's
  name and address on screen while HR chooses, and floats exact email matches to the
  top marked as such.
- **Employees index** gains an *App access* column and Invite / Resend / Revoke row and
  bulk actions in place of the removed password reset.
- **`/invite/{token}`** — the public landing page for the invitation email. The code,
  not a button, is the hero: the account doesn't exist yet and gets made on a phone, so
  the page's job is to prove the invitation is genuine and be readable while typing.

## Frontend (mobile)

- **`app/(auth)/register.tsx`** — asks nothing about work; the account is yours.
- **`app/join.tsx`** — invitations addressed to you are listed unprompted and joined in
  one tap. Below them, one code field takes either kind of code (it tries the more
  specific one first). Pending requests are shown so nobody asks twice. Creating a
  company is deliberately absent and says so.
- **`lib/auth.tsx`** gains `register`, `joinWithCode` and `acceptInvite`, and the root
  navigator routes `needs_workspace` to the join screen instead of the tab shell.

## Notes

- **Verification:** 35 new tests, all passing. The full suite goes from 396 → 431 tests
  and 381 → 416 passing, with **no new failures** — the 12 failures and 3 errors that
  remain were confirmed present on a clean checkout of `development` before this work
  and are untouched by it. Pint, `tsc`, ESLint, Prettier (on changed files) and
  `npm run build` are green on both apps.
- `UserFactory` gains an `unaffiliated()` state — the factory otherwise joins the bound
  tenant, which is exactly wrong for someone about to ask to join.
- The mobile project has **no Prettier config**; its style is hand-maintained at single
  quotes / 100 columns. Don't run bare `npx prettier` there.
