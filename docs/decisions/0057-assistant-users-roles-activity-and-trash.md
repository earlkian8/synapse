# 0057 — Users, roles, the audit trail and the trash bin join the assistant; nobody hands out access they do not hold

- **Status:** Accepted
- **Date:** 2026-09-29
- **Extends:**
  - [0023 — Identity and organisation membership](./0023-identity-and-organization-membership.md);
  - [0049 — The assistant assumes it will be prompt-injected](./0049-assistant-prompt-injection-defences.md);
  - [0051 — A user is picked from the workspace's members](./0051-assistant-offboarding-reports-and-workspace-members.md).
- **Related:** [User Management](../modules/user-management.md#the-assistant),
  [Roles & Permissions](../modules/roles-permissions.md#the-assistant),
  [Activity Logs](../modules/activity-logs.md#the-assistant),
  [Trash Bin](../modules/trash-bin.md#the-assistant).

## Context

Four System screens were left without an assistant capability: **Users**, **Roles &
Permissions**, **Activity Logs** and the **Trash Bin**. They are the most
consequential in the app. They decide who can sign in and what everyone can do.

Before building on them, their rules were audited, the same way ADR 0051 audited
Offboarding. It found more than inline validation:

- **Any company could take over any account on the instance.**
  - A user is an identity shared across workspaces (ADR 0023).
  - "Add user" links an existing email into the workspace without the holder's
    consent.
  - Password reset, email change, deactivate, archive and permanent delete all acted
    on that shared identity.
  - So a newly registered company could add someone's address, reset the password,
    and sign in as them everywhere, including as HR Manager of their real employer.
  - Short of that, it could lock them out of every workspace they belong to.
- **`roles.assign` and `roles.update` were the whole system.** Whoever held them could:
  - give themselves the HR Manager role, which bypasses every gate, by the user form,
    the bulk action or the CSV importer;
  - add any permission to their own role.

  Anyone with `users.reset-password` could also reset the HR Manager's password.
- **"Deactivate" did not stop anyone on the web.** Only the mobile API login checked
  `is_active`. The web login, an open session and a passkey sign-in all ignored it.
- **The Trash Bin reached every company's archived accounts.** `User` has no tenant
  scope. The bin listed, counted, restored and purged archived users instance-wide,
  and "Empty trash" would have deleted other companies' accounts.
- **The Roles screen's figures counted every company.** "Assigned users" and
  "unassigned users" were counted across the instance.
- **A role key was unique across the instance.** It is stored per organisation, so a
  second company could not create an "auditor" role once any other had one, and the
  error told it one existed.
- **Deleting audit entries left no trace.** Deleting an entry, a selection or the
  whole log recorded nothing about who did it.

## Decision

### 1. An account shared with another workspace is its holder's

`Support\Users\UserAccounts` holds every write to an account: create, update,
activate/deactivate, reset password, resend verification, archive, restore, permanent
delete and the bulk actions. The Users screen, the Trash Bin and the assistant all use
it. Refusals are `UserAccountException`, shown as they are.

- **If an account also belongs to another workspace:**
  - this workspace manages only its membership and roles;
  - name, email, phone, photo, password and active state are refused;
  - archiving *removes the person from this workspace*:
    - this workspace's roles are detached, by id, because a bare `detach()` would
      clear their roles everywhere;
    - the membership is dropped;
    - the phone sessions bound to this workspace end;
    - their sign-in lands in another workspace next time;
    - the account, and every other workspace, stays;
  - permanent delete is refused.
- **Nobody changes the account of someone with more access than they have.**
  `GrantRules::outranks()` compares permission sets, and an HR Manager outranks
  everyone except another HR Manager. This covers the sign-in, the status, archiving
  and deleting.
- Nobody deactivates, archives or deletes their own account, including through the
  edit form's active switch, which the status guard did not cover.

The Users list shows each row as `shared_account` / `manageable`. The form locks what
can't change and says why. The row menu offers only what would be allowed, and names
the shared-account archive "Remove from workspace".

### 2. You can only give what you hold

`Support\Roles\GrantRules` is the one rule. `Support\Roles\RoleWorkflow` holds every
role write: create, update, delete and bulk delete, and give, take and plan a role set.

- **A permission is added to a role only by someone who holds it.** Permissions the
  role already has may stay or go.
- **A role is given only by someone who holds everything it grants.**
- **The HR Manager role:**
  - it is given or taken only by an HR Manager;
  - the workspace's last active HR Manager can't lose it.
- It applies to:
  - the role editor, where permissions you lack are disabled unless already granted;
  - the Users form's role picker, where roles you can't give are shown locked, so
    saving never drops one silently;
  - the bulk "assign role";
  - the CSV importer, which refuses the row;
  - the assistant.
- Two roles may not share a label in any case, and the key is unique **within the
  workspace** (`TenantRule::unique`).

### 3. The walls that were missing

- **Deactivation takes effect everywhere:**
  - `Fortify::authenticateUsing()` refuses an inactive account after the password
    matches, in the phone app's words, so it never tells a stranger which addresses
    are deactivated;
  - the new `EnsureAccountIsActive` middleware, on the web and API groups, ends a
    deactivated account's session or token on its next request;
  - deactivating, archiving or resetting a password revokes the account's phone
    tokens now.
- **The Trash Bin is this workspace's:**
  - `TrashRegistry::trashed($type)` confines users by membership;
  - `Support\Trash\TrashBin` is the workflow the screen and the assistant share;
  - an account goes through `UserAccounts`, so the bin can't delete what the Users
    screen would refuse, and emptying it skips those accounts.
- **`RoleStatistics`** counts this workspace's members.
- **Deleting audit entries is audited.** One entry, a selection or clearing the log
  each leave an entry naming who did it and how many went.

### 4. Four assistant modules

| Module | Reads | Writes | Waits for Confirm |
| --- | --- | --- | --- |
| **Users** (`users.view`) | `find_users` by name, status, role, or what they can do ("who can approve leave?"); `get_user`: roles, access by group, last sign-in, linked employee, shared / outranks | `create_user`, `update_user` (name, phone), `resend_user_verification` | `create_user`, `change_user_email`, `set_user_active`, `give_user_role`, `take_user_role`, `archive_user` |
| **Roles** (`roles.view`) | `find_roles` (optionally by a permission they grant), `get_role` (permissions by group, holders), `find_permissions` (which permission does what, and which roles grant it) | `create_role` (from permissions or a copy), `update_role` (label, description) | `grant_role_permissions`, `revoke_role_permissions`, `delete_role` |
| **Activity logs** (`activity-logs.view`) | `find_activity` (words, who, event, area, dates), `activity_summary` | none | none |
| **Trash** (a type's own view permission) | `find_trash` | none | `restore_from_trash`, `delete_from_trash` (one record) |

- **Permissions are named the way people say them.** `PermissionRegistry::lookup()`
  resolves them in this order:
  1. a key;
  2. a label;
  3. a whole group;
  4. the one permission whose key or label holds every word ("approve leave").

  Anything else is refused as unknown or ambiguous.
- **Each card says what changes hands.** For example: "Jon Doe would gain 2
  permissions: …", "2 people hold the Clerk role and would lose …", "Their account
  belongs to another workspace too: they would be removed from this one…". The
  create card does **not** say whether an address already has an account: it would
  answer that for any address without anything being done.
- **Never through the assistant:**
  - **Passwords.** A password would be sent to the model and kept in the
    conversation. The screen resets them.
  - **Deleting or clearing the audit trail.** The assistant reads untrusted text
    every turn, and an audit trail a prompt-injected model could erase is not one.
  - Emptying the trash, photos, import and export.
- **Personal request data stays out of the model.** The activity module doesn't pass
  on IP addresses, browsers or change payloads: they answer nothing a person asks
  there and are personal data.

## Consequences

- **The takeover and escalation paths are closed on every surface**, with a test
  pinning each one (`AccountGuardsTest`, the Trash and Roles tests).
- **Behaviour changes on the screens:**
  - **Shared accounts:** an HR admin can no longer edit a person's name or email, or
    deactivate them, when their account belongs to another workspace too. Archiving
    such a person removes them from this workspace instead, and the toast says so.
    Seeded demo accounts that belong to both demo workspaces are shared in this sense.
  - **Grants:** a custom role holding `roles.*` can hand out only what it holds.
  - **Bulk actions:** they skip the accounts their single-row action would refuse, and
    the toast says how many and why.
- **Activity-log deletions now leave one entry each.** The trail is never truly empty
  after a clear.
- **New event:** `removed` (a shared account taken out of a workspace), with its own
  filter and badge.
- **The tool budget grows** by four modules. Each is offered only with its own
  permission, and a read-only user sees only the reads.

## Alternatives considered

- **Per-membership active state and names** (moving `is_active` and the profile onto
  `organization_user`). This is the complete model, but it is a schema change across
  login, the mobile app and every user list. Refusing the change, and archiving by
  removal, closes the hole now without migrating anyone.
- **Asking the holder before linking an existing account** (an invitation instead of
  "add user"). This is the right product flow and is left open. With identity changes
  refused, being added without consent no longer gives anyone power over the account.
- **Letting the assistant set passwords behind a Confirm.** The confirmation card
  would show the password, and so would the conversation history and the model
  provider. A reset link is the better shape and is left for later.
- **An assistant tool to clear the audit trail behind a Confirm.** It was refused
  outright for the reason above. The screen keeps it, now recorded.
