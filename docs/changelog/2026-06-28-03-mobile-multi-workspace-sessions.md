# Mobile multi-workspace sessions

Lets an employee of **more than one company** use SYNAPSE Mobile for all of them:
keep several accounts signed in at once and switch between companies in a tap — no
signing out, no re-typing credentials. Each company is still a separate account in
a separate tenant (one user ⇒ one organisation, ADR 0005); the app simply holds
several Sanctum tokens, each of which already *is* a complete tenant binding. See
[ADR 0022](../decisions/0022-mobile-multi-workspace-sessions.md) and the
[module doc](../modules/mobile-app.md).

## Highlights

- **Workspace switcher.** A bottom sheet lists every signed-in company — companies
  render as rounded **squares** (people stay circles), the active one ringed in
  teal — tap to switch, with a per-row sign-out and an **Add a company** action.
- **Add another company.** A focused modal signs in to a second/third workspace
  with its own credentials and makes it active; it guards against re-adding a
  workspace you already have.
- **Always know where you are.** A company chip on Home and a Workspace card on
  Profile show the active company; **Sign out** now signs out just that company,
  falling back to another workspace (or the login screen when it's the last).
- **Switching refreshes everything.** Every tenant-scoped screen refetches against
  the newly-active company's token automatically — no per-screen wiring.

## Backend

- **One additive change:** `Api\AuthController::userPayload()` now returns
  `organization { id, name, logo, initials }` on `POST /api/auth/login` and
  `GET /api/me`, so each saved workspace can be labelled and branded. No change to
  the tenancy model, routes, or any other endpoint.

## Frontend (mobile)

- **`lib/sessions.ts`** — multi-session storage: tokens in SecureStore, cached user
  profiles in AsyncStorage, joined by account id; one-time migration of the old
  single `synapse.token`.
- **`lib/auth.tsx`** — reworked from a single token to `sessions` / `activeId` with
  `signIn` (add + activate), `switchTo` (instant, no re-auth), `signOut(id?)`, and
  `refresh`; restores and revalidates the active session on boot.
- **`lib/active-workspace.ts`** + **`lib/use-query.ts`** — a tiny external store the
  query hook subscribes to, so a switch reloads all data in place without resetting
  navigation.
- **`features/workspaces/workspace-switcher.tsx`** — `WorkspaceSwitcher`,
  `WorkspaceChip`, and the `CompanyLogo` mark.
- **`app/add-account.tsx`** — the add-a-company modal (registered in the root Stack);
  Home and Profile gained the chip / Workspace card and open the switcher.
- **`types/api.ts`** — new `AuthOrganization`, added to `AuthUser`.

## Notes

- The same person still needs **two different emails** (one per company): the global
  email-uniqueness constraint is unchanged and intentional (ADR 0005).
- Verified: backend `php -l` clean; mobile `tsc --noEmit` and `expo lint` clean.
