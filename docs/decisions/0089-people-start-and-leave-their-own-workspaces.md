# 0089 — People start and leave their own workspaces

- **Status:** Accepted
- **Date:** 2026-10-15
- **Related:**
  - [0023 — Identity & organisation membership](./0023-identity-and-organization-membership.md)
    (one sign-in, many companies — this adds the ways in and out);
  - [0026 — Self-served identity](./0026-self-served-identity-and-workspace-join.md)
    (joining a company by invitation or code; "no company" is a valid state);
  - [0057 — Users, roles, activity and trash](./0057-assistant-users-roles-activity-and-trash.md)
    (a shared account is removed from a workspace, not archived);
  - [0076 — Employees resign](./0076-employees-resign-and-leavers-answer-an-exit-interview.md)
    (a leaver keeps their sign-in for the exit interview and certificate);
  - [Multi-tenancy module](../modules/multi-tenancy.md).

## Context

A user can belong to several companies (ADR 0023), but the only way to *create* one was
the register form. An HR officer who works for two employers, and uses SYNAPSE for both,
had no way to add the second company to the account they already had. The register form
refuses an email that already has an account. The seeded owner having two workspaces
made it look possible; it was only seed data.

The way *out* of a company had gaps too:

1. **Nobody could leave.** A person could only be removed by the company's HR, from the
   Users screen. Somebody who resigned had to wait for that.
2. **A company could lose its last HR Manager.** The role guard stops anybody *taking the
   role away* from the last HR Manager. But the HR Manager could still delete their own
   account, and the company was left with nobody able to run it.
3. **No company meant no tenant.** A person with no membership (they registered in the
   app, ADR 0026) could sign in on the web. With no tenant bound, the tenant scope does
   nothing, and the dashboard ran with no company.
4. **Removal was silent.** Someone removed while working in that company was quietly put
   into another one on their next click. Someone who wasn't working in it at the time
   never found out.

## Decision

**Starting, leaving and being removed from a company are all handled by one class,
`App\Support\Workspaces\Workspaces`, with one rule: nobody leaves a company that other
people still work in without an HR Manager.**

### Starting one

- **Create workspace** is offered wherever a person's workspaces are listed: the sidebar
  switcher (under the list), the workspace picker (the last row), and Settings →
  Workspaces. It asks for the company name only.
- `OrganizationProvisioner::found()` creates the organisation, its roles and join code,
  the membership, and the HR Manager role for its creator. Registration uses it too, so
  an owner is made only one way. The new membership is the sign-in default only if it is
  the person's first.
- Anyone signed in and verified may create one, not only HR Managers. A workspace is a
  company of one's own, so creating one gives nobody access to anything else.
- The session switches to the new workspace and opens on the setup wizard. Creation is
  throttled (10 an hour), and a person cannot have two workspaces with the same name.

### Leaving one

- **Settings → Workspaces** lists every company with the person's role there. Each row has
  **Leave**. Leaving removes that workspace's roles, the membership and the phone
  sessions bound to it. The account, the other workspaces and the employee record stay.
  The employee record is the company's history, not the person's access.
- **The rule.** `whyNotLeave()` refuses when the person is the workspace's only *active*
  HR Manager and at least one other active member exists. Instead of a dead button, the
  row says why and links to User Management (or offers to switch to that workspace
  first). Deleting one's account follows the same rule (`heldTogetherBy()`): the Delete
  section names the companies and offers no button.
- **Alone in it.** A workspace nobody else is in may be left, but then nobody can open
  it again. So the dialog says so and asks for the workspace's name to be typed.
- People who look after accounts in that workspace (`users.view`) are notified, and the
  departure is logged there.

### Removed by the company

- This still happens on the Users screen ("Remove from workspace", ADR 0057). It now runs
  through `Workspaces::remove()`, and the removed person is **notified**. Notifications
  belong to the account, not to one company, so they see it in whichever workspace they
  are working in.
- **A completed exit shows the leaver's access.** The leaver keeps their sign-in on
  purpose (exit interview, certificate). So once an exit is completed, the case page
  says "*Name* can still sign in to this workspace", with **Remove access** for anyone
  who may archive users. It uses the same action as the Users screen.

### No workspace, or one taken away

- `RequireWorkspace` (web middleware, after `SetCurrentOrganization`) sends anybody with
  no workspace bound to `/workspaces`. Their own settings, signing out and the workspace
  screens stay open. JSON callers get a 403.
- `/workspaces` with no memberships shows an empty state: **Create a workspace**, and how
  to join an employer's (an invitation or a join code, in the app).
- When the session's workspace is no longer the person's, the toast says "You no longer
  have access to *Name*", and the person goes to the picker. The page they asked for
  would belong to a company they can no longer see.

## Alternatives considered

- **Register again for the second company.** This is ruled out by ADR 0023: one identity
  per person, and the email is unique.
- **Let only HR Managers create workspaces.** Roles belong to a company, but creating one
  is about the person, not the company they are in. Someone whose only role is Staff
  somewhere may still run a small business of their own.
- **End the leaver's access automatically when the exit completes.** The leaver still
  needs it for the exit interview and the certificate (ADR 0076). The case page now
  shows HR when access is still open instead.
- **Delete a workspace.** Not built. Every table's `organization_id` cascades, so removing
  the database rows would be simple, but uploads are not kept in per-company folders, so
  the company's files would be left behind in the bucket. It can't be undone, and it
  should start with a data export. It needs its own decision. Until then, a workspace
  nobody else is in may be left.
- **A platform admin hands over a company whose only HR Manager is gone.** The admin
  portal is read-only by design (ADR 0085). The rule above prevents this case from
  arising through the app. Recovering from one that already happened (a lost password
  with no second HR Manager) is support work outside the app.

## Consequences

- One sign-in can run any number of companies. Each company is still isolated by the
  tenant scope; nothing about it changed.
- No company is left with people but no HR Manager through the app.
- A web session never runs with no tenant bound, which closes the case where the tenant
  scope did nothing.
- Workspaces left by their only member stay in the database, unreachable. They show in
  the admin portal's list of organisations.
- The mobile app is unchanged: it never creates companies (ADR 0026), and a phone signed
  in to a company the person left is signed out by its revoked token.
