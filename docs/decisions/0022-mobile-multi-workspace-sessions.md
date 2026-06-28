# 0022 — Mobile multi-workspace sessions (employees of more than one company)

- **Status:** Accepted
- **Date:** 2026-06-28
- **Related:** [0005 — Multi-tenancy](./0005-multi-tenancy.md),
  [0010 — Attendance & mobile API](./0010-attendance-and-mobile-api.md),
  [0020 — Mobile companion app](./0020-mobile-companion-app.md),
  [Mobile app module](../modules/mobile-app.md)

## Context

A person can be employed by two companies that both run SYNAPSE. Under
[ADR 0005](./0005-multi-tenancy.md) each tenant is an `organizations` row and
**one user belongs to exactly one organisation** (`users.organization_id` is
non-null, `users.email` is globally unique). So that person is necessarily *two
distinct accounts* in two tenants — with two different emails, since the same
email cannot exist twice (and `EmployeeAccountProvisioner` would collide on the
global uniqueness mobile login depends on). ADR 0005 deliberately **deferred**
users-in-many-organisations (M:N): "it complicates login and every query for a
need we do not have yet."

That backend model is fine. The gap was entirely **on the phone**: the mobile app
([ADR 0020](./0020-mobile-companion-app.md)) stored a *single* Sanctum token in
SecureStore (`synapse.token`). A two-company employee had to fully sign out and
re-type credentials to move between companies, and the app never showed which
company they were even in — the auth payload omitted the organisation.

## Decision

Support **multiple signed-in sessions in the mobile client** and let the user
switch between them in a tap. **No change to the server's tenancy model.**

The key observation: a Sanctum token already *is* a complete tenant binding
(token → user → organisation, resolved by `SetCurrentOrganization`). So holding
*N* tokens means holding *N* company contexts. We never need M:N users.

- **Per-account sessions, persisted.** The app keeps a list of sessions, each
  `{ id, token, user }` where `id` is the account's (globally unique) user id.
  Tokens (the secret) stay in SecureStore; the cached user profiles live in
  AsyncStorage; the two join by id (`mobile/lib/sessions.ts`). A one-time
  migration folds any pre-existing single `synapse.token` into the new store.
- **One active session drives the client.** The `api` client sends the active
  session's token. `switchTo(id)` re-points that token and swaps the visible user
  with **no re-authentication**; `signIn` adds (or re-authenticates) a session and
  activates it; `signOut(id?)` revokes that one token server-side and falls back to
  another workspace, or to the login screen when it was the last
  (`mobile/lib/auth.tsx`).
- **Switching refetches in place.** The active workspace id is published to a tiny
  external store (`mobile/lib/active-workspace.ts`) that `useQuery` subscribes to,
  so every tenant-scoped screen reloads against the new company's token with no
  per-screen wiring and without resetting navigation.
- **The organisation is now in the auth payload.** `POST /api/auth/login` and
  `GET /api/me` return `organization { id, name, logo, initials }` so each saved
  workspace can be labelled and branded. This is the **only** backend change.
- **Companies read as squares, people as circles.** The workspace switcher and the
  company chip render the organisation as a rounded square (initials fallback),
  deliberately distinct from the circular person avatars used everywhere else; the
  active workspace is ringed and tinted in teal.

## Alternatives considered

- **M:N users-in-many-organisations on the backend** (the ADR 0005 deferral).
  Still the wrong trade for this need: it would force a tenant disambiguator at
  login (email is no longer a unique key), touch the global scope and every query,
  and rewrite identity — to solve what is fundamentally a *client* problem.
  Rejected.
- **One account, switch organisation server-side.** Contradicts "one user ⇒ one
  organisation" and re-introduces the same M:N cost. Rejected.
- **Remount the navigation tree on switch** (key the router by active id). Simple,
  but resets navigation to the splash bridge and leaned on fragile redirect
  behaviour. Replaced by the in-place refetch store. Rejected.

## Consequences

- **Positive:** a two-company employee uses one app for both and switches with one
  tap; single-account users see no change; the server's tenancy model and ADR 0005
  are untouched; isolation still holds because each token resolves exactly one
  tenant. New screens inherit switch-aware refetching for free via `useQuery`.
- **Negative / watch-outs:**
  - The same person still needs **two different emails** (one per company) — the
    global email-uniqueness constraint is unchanged and intentional.
  - Re-authenticating an already-saved workspace orphans its previous token
    server-side (we can't revoke a token we no longer hold in plaintext); it
    expires on its own and is harmless.
  - Switching while on a deep, id-specific detail screen would refetch that id
    under the new tenant; in practice the switcher is only reached from the Home
    and Profile tabs, so this is not hit.
