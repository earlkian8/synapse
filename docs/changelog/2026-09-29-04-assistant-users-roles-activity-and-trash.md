# Assistant: Users, Roles & Permissions, Activity Logs and the Trash Bin; nobody hands out access they do not hold

The assistant gains retrieval (RAG) and function calling for the four System screens:
- **users**;
- **roles & permissions**;
- the **activity log**;
- the **trash bin**.

These screens decide who can sign in and what everyone can do, so their rules were
audited first. The audit found ways to take over an account in another company, to
make yourself HR Manager, and to reach other companies' archived accounts. Those are
closed on every surface: the screens, the importer and the assistant. See
[ADR 0057](../decisions/0057-assistant-users-roles-activity-and-trash.md).

## Highlights

- **These now work in chat, each behind a Confirm that says what changes hands:**
  - "Who can approve leave?", "who hasn't verified their email?";
  - "give Jon the Leave Approver role", "deactivate Maria's account";
  - "add export employees to the Payroll role";
  - "who archived Maria last week?", "restore the Finance department".
- **Never through the assistant:** passwords, and deleting the audit trail.
- **An account shared with another workspace is its holder's.** Only its roles here
  can change, and archiving removes the person from this workspace.
- **You can only give what you hold.** This applies to permissions on a role, to
  roles on a person, and to the HR Manager role. The workspace always keeps one HR
  Manager.

## Security fixes

- **Account takeover across companies.** Any company could:
  1. add an existing email;
  2. reset the account's password or change its email;
  3. sign in as that person everywhere.

  It could also deactivate, archive or delete the account in every workspace. Now,
  when an account belongs to another workspace too, its details, sign-in and status
  are refused, and archiving removes it from this workspace only.
- **Privilege escalation through roles.** `roles.assign` could give its holder the HR
  Manager role (by form, bulk action or CSV import), and `roles.update` could add any
  permission to its holder's own role. `users.reset-password` could take over an HR
  Manager's account. Each is refused now: you can't change the account of someone
  with more access than you, or grant what you don't hold.
- **Deactivation did not stop anyone on the web.** Web login, open sessions and passkey
  sign-ins ignored `is_active`. The web login now refuses an inactive account, the new
  `EnsureAccountIsActive` middleware ends its session or token on the next request, and
  phone tokens are revoked at once.
- **The Trash Bin reached every company's archived accounts.** It listed, counted,
  restored and purged them, and "Empty trash" would have deleted them. It is confined
  to this workspace.
- **The Roles screen counted every company's users.**
- **A role key was unique across the instance.** It collided with, and revealed, other
  companies' roles. It is now unique per workspace.
- **Deleting audit entries left no trace.** A delete, a bulk delete or clearing the log
  now records who did it and what went.

## Backend

- **`Support\Users\UserAccounts`** (`UserAccountException`) holds every account write.
  `UserController`, `UserStatusController`, `UserPasswordController` and
  `UserBulkActionController` are thin callers, and so is the Trash Bin for accounts.
- **`Support\Roles\GrantRules`** is the one rule for grants: `grantable`, `beyond`,
  `whyNotGive`, `whyNotTake`, `outranks` and `lastHolder`.
- **`Support\Roles\RoleWorkflow`** (`RoleException`) holds every role write:
  create, update, delete, bulk delete, give, take, and plan/apply for the form.
  `RoleController` and `RoleBulkActionController` are thin. The CSV importer refuses
  a role its user couldn't give.
- **`Support\Trash\TrashBin`** (`TrashException`) holds restore, permanent delete, bulk
  and empty. `TrashRegistry::trashed()`, `find()` and `allows()` confine and authorise
  them.
- **`EnsureAccountIsActive`** runs on the web and API groups, alongside
  `Fortify::authenticateUsing()`.
- **The requests:**
  - `StoreRoleRequest::rulesFor()`, which uses `TenantRule::unique`, and
    `messagesFor()`;
  - `StoreUserRequest::rulesFor()`;
  - `UpdateUserRequest::rulesFor($user)`.
- **`PermissionRegistry::lookup()`** names a permission by key, label, group, or every
  word.
- **Assistant modules:**
  - **`UsersModule`**:
    - reads: `find_users`, `get_user`;
    - writes: `create_user`, `update_user`, `resend_user_verification`;
    - confirmed: `create_user`, `change_user_email`, `set_user_active`,
      `give_user_role`, `take_user_role`, `archive_user`.
  - **`RolesModule`**:
    - reads: `find_roles`, `get_role`, `find_permissions`;
    - writes: `create_role`, `update_role`;
    - confirmed: `grant_role_permissions`, `revoke_role_permissions`, `delete_role`.
  - **`ActivityLogsModule`** (read-only): `find_activity` and `activity_summary`.
    It never passes IP addresses or browsers to the model.
  - **`TrashModule`**:
    - reads: `find_trash`;
    - confirmed: `restore_from_trash`, `delete_from_trash`.
  - **Shared helpers on the base `Module`:** `resolveAccount()`, which finds any
    account of this workspace, archived ones included, and `resolveRole()`.
  - **New IMPERATIVES:** grant, revoke, activate, deactivate, resend, take,
    permanently, purge.
- **New activity event:** `removed` (a shared account taken out of a workspace).

## Frontend

- **Users:**
  - rows carry `shared_account` and `manageable`, and roles carry `givable`;
  - the edit form locks what can't change and says why;
  - roles the editor can't give are shown locked;
  - the row menu hides refused actions and offers "Remove from workspace" for a
    shared account, with its own confirmation.
- **Roles:** the permission matrix disables what the editor can't add. Already-granted
  permissions stay removable.
- **Activity Logs:** a "Removed" filter and badge.
- **Assistant:**
  - the chat button also appears for `users.view` and `roles.view`;
  - new suggestions: "Who hasn't verified their email yet?", "Which roles can approve
    leave?" and "What changed in the activity log this week?".

## Verification

- **Pest:** the full suite passes (1369 tests: the previous 1333 plus 36 new).
- Pint, tsc, ESLint, Prettier and the build pass.
- **Headless Chromium** against a freshly seeded throwaway database:
  - **Light:** the demo co-owner's account, which belongs to both demo workspaces,
    opened in the Users screen with the "also belongs to another workspace" notice. Its
    name and email fields were disabled, and its menu offered only "Remove from
    workspace".
  - **Dark:** a `give_user_role` was held through the real assistant. Its card read
    "Nina Navarro would gain 16 permissions: …". Confirming gave the role, toasted,
    and was audited as "Gave Nina Navarro the Department Head role via assistant".
  - The role editor showed every permission enabled for an HR Manager.
  - No console errors.

## Tests

- `UserManagement/AccountGuardsTest` (10): each takeover and escalation path above.
- `UserManagement/UsersAssistantTest` (9), including a held role confirmed end to end
  through the real orchestrator.
- `RolePermission/RolesAssistantTest` (7)
- `ActivityLog/ActivityLogsAssistantTest` (4)
- `Trash/TrashAssistantTest` (4)
- `Trash/TrashTest` (+2): another company's archived accounts are out of reach, and a
  higher-ranked account can't be purged.
- `ActivityLog/ActivityLogTest`: the deletion and clear tests now assert the entry
  they leave.
