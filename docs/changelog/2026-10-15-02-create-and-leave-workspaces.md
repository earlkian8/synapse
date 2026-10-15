# Create a workspace, leave one, and no company left without an HR Manager

One sign-in could belong to several companies, but the only way to make a company was
the register form, and that refuses an email that already has an account. An HR officer
working for two employers could not add the second company. Leaving was also
incomplete: only HR could remove you, and an HR Manager could delete their account and
leave a company with nobody to run it.

See [ADR 0089](../decisions/0089-people-start-and-leave-their-own-workspaces.md).

## Highlights

- **Create workspace.** In the sidebar switcher (under your companies), at the end of the
  workspace picker, and in **Settings → Workspaces**. Enter the company name; you become
  its HR Manager and it opens on the setup wizard. The switcher now shows for everybody,
  including people with one company, so the option is always one click away.
- **Leave a workspace.** **Settings → Workspaces** lists every company with your role
  there. **Leave** removes your roles and access there. Your account, your other
  companies and the company's employee record of you stay.
- **Nobody walks out and leaves a company without an HR Manager.** The only active HR
  Manager of a company other people still work in cannot leave it, or delete their
  account. The row says why and links to User Management. A company with nobody else in
  it can be left after typing its name.
- **Removal is told.** Someone HR removes from a workspace gets a notification. If they
  were working in it at that moment, the next page says "You no longer have access to …"
  and opens the picker.
- **A finished exit shows the leaver's access.** Once an exit is completed, the case says
  the leaver can still sign in (they need it for the exit interview and the certificate),
  with **Remove access**.
- **No workspace, no company pages.** Somebody in no company is sent to the picker, which
  shows **Create a workspace** and how to join an employer's.

## Backend

- `App\Support\Workspaces\Workspaces`: `create()`, `leave()`, `whyNotLeave()`,
  `isAlone()`, `remove()` and `heldTogetherBy()`. `WorkspaceException` carries the
  refusals.
- `OrganizationProvisioner::found()` creates a company with its owner. Registration
  (`CreateNewUser`) and Create workspace both use it.
- `POST /workspaces` (`workspaces.store`, throttled 10 an hour, `StoreWorkspaceRequest`
  refuses a name you already use). `GET /settings/workspaces` (`workspaces.manage`) and
  `DELETE /settings/workspaces/{organization}` (`workspaces.leave`,
  `LeaveWorkspaceRequest`).
- `App\Queries\MyWorkspaces` builds the picker's and Settings' cards (the picker's code
  moved out of `WorkspaceController`).
- `RequireWorkspace` middleware (web, after `SetCurrentOrganization`). With no tenant
  bound, it redirects (303) to `/workspaces`, or answers 403 for JSON. Settings, sign-out,
  workspace screens and public pages stay open. `SetCurrentOrganization` flags a chosen
  workspace that is no longer the person's.
- `UserAccounts`: the shared-account removal goes through `Workspaces::remove()` and
  notifies the person. `whyNotArchive()` is new and also used by the exit case page.
- `ProfileController@destroy` refuses deleting the account while you hold a company
  together. The profile page receives `heldTogether`.
- `OffboardingCaseController@show` sends `access` (the leaver's account here, whether it
  is shared, and whether the viewer may remove it).
- Help Center: *Working in more than one company* covers creating, leaving and having no
  company. The assistant's system guide entry lists the new tasks.

## Frontend

- `features/workspaces/`: `CreateWorkspaceDialog`, `LeaveWorkspaceDialog`, routes and
  types.
- `pages/settings/workspaces.tsx` and a **Workspaces** entry in the Settings nav.
- `workspace-switcher.tsx`: *Create workspace*, "1 workspace" (not "1 workspaces"),
  *Your workspace* as the label with one company. `app-sidebar.tsx` always renders the
  switcher, and the unused `company-logo.tsx` is removed.
- `pages/workspaces.tsx`: a *Create a workspace* row and an empty state.
- `delete-user.tsx`: names the companies that block deletion instead of offering the
  button.
- `features/offboarding/components/case-access-notice.tsx` on completed exit cases.
- The tour's one-company workspace stop mentions creating another.

## Notes

- No migration. Memberships, roles and tokens already had the columns needed.
- Not built: deleting a workspace. Uploads are not stored per company, so its files would
  be left behind; it needs its own decision. A workspace left by its only member stays
  in the database, unreachable.
- The mobile app is unchanged. It still cannot create companies, and a phone signed in to
  a company you left loses that session.
- Tests: `tests/Feature/Tenancy/WorkspacesTest.php` (19). Checked in Chromium at 1440 and
  390 px, light and dark: create from the switcher, the wizard landing, Settings →
  Workspaces with the blocked HR Manager row, leaving alone (typed name), leaving as
  staff into the empty picker, the dashboard bouncing to it, and *Remove access* on a
  completed exit.
