# Mobile app (employee companion)

The employee-facing companion to the web ERP, living in `mobile/` (Expo SDK 54 +
expo-router + TypeScript). It talks to the same backend over the
token-authenticated API in `server/routes/api.php` (Sanctum personal access
tokens), and mirrors the web brand so the two read as one product. See
[ADR 0020](../decisions/0020-mobile-companion-app.md).

## Who can sign in

**People create their own accounts** ([ADR 0026](../decisions/0026-self-served-identity-and-workspace-join.md)).
`POST /api/auth/register` (throttled) takes a name, any email address they choose,
and a password. It creates no employment and joins no company: the response is a
real session whose `organization` is `null` and whose `needs_workspace` is `true`.
The ERP never issues, knows, or can reset anybody's password — forgotten passwords
go through Fortify's web forgot-password flow.

Connecting that account to an employer is a second, separate step, with two routes:

- **An invitation.** HR invites a specific roster line; the email carries a link and
  an 8-character code. `GET /api/invitations` lists the ones addressed to the
  caller's mailbox; `POST /api/invitations/accept` redeems any valid code (holding
  one *is* the authorisation, so it need not match their address).
- **The company join code.** `POST /api/workspaces/preview` names the company behind
  a 7-character code before committing; `POST /api/workspaces/join` redeems it. If
  their registered email matches exactly one unclaimed roster line they are admitted
  on the spot; otherwise the request queues for HR and the response comes back
  `status: "pending"`.

Both admitting responses re-issue the Sanctum token **bound to the new company**.
Admission grants the `staff` role (`attendance.clock`, `attendance.request`,
`leave.request`); every read
endpoint is self-scoped and needs no further permission.

`App\Support\MobileSession` builds every session — login, register, switch, join — so
the four cannot drift. Note that `/me` reports what *this token* can do: a token
minted before its holder joined anywhere stays unbound, so the payload lists their
memberships and the client binds one with `/auth/switch`.

## Multiple companies

A person employed by more than one company signs in **once** — a user is a single
identity that can belong to many organisations ([ADR 0023](../decisions/0023-identity-and-organization-membership.md)).
`login` / `me` return the active `organization` plus the full `organizations` list;
`switchTo(organizationId)` calls `POST /api/auth/switch`, which mints a fresh Sanctum
token **bound to the chosen company** (and revokes the old one), and `lib/auth.tsx`
swaps it in — no re-entering credentials. The **workspace switcher** (company chip on
Home, Workspace card on Profile) lists the identity's organisations, rendering
companies as rounded squares — distinct from circular person avatars — with the active
one ringed in teal. Switching republishes the active id (`lib/active-workspace.ts`) so
every `useQuery` screen refetches against the new company's tenant context.

## Surfaces

- **Sign in / Create account** — `app/(auth)/login.tsx` and
  `app/(auth)/register.tsx`. Registration asks for nothing about work: the account
  is the person's own, and connecting it to a company is the next screen.
- **Join a company** (`app/join.tsx`) — where an account with no employer lands.
  Invitations addressed to them are listed unprompted and joined in one tap; below
  that, a single code field takes either an invitation code or the company join
  code (it tries the more specific one first). Pending requests are shown so nobody
  asks twice. Company creation is deliberately absent — that lives on the web app.
- **Home** — greeting, today's clock state, quick actions, leave-balance
  mini-cards, latest award, pending-request badge.
- **Clock (DTR, the hero)** — live clock, today's shift, a state-driven primary
  button (Time In → Break → Time Out) driven by the server's `allowed` /
  `next_expected`. Captures real GPS (`expo-location`) and an optional selfie
  (`expo-image-picker`), submitted as multipart to `POST /attendance/punch`
  through the canonical `AttendanceClock`. Live worked-hours counter + status chip.
  **Punching offline** (ADR 0040): a punch that cannot reach the server — or any punch
  while others are still waiting — is **queued** on the phone
  (`features/attendance/punch-queue.ts`, in AsyncStorage) with the phone's time and a
  client id, and the button moves on as if it had been recorded. A banner says how many
  are waiting, with **Send now**, and the Clock tab carries a badge.
  `PunchQueueRunner` (mounted in the tab layout) sends the queue oldest first every 30
  seconds and whenever the app returns to the foreground; a punch the server refuses
  (older than the policy's offline window, say) is dropped with a toast telling the
  employee to ask for a correction. A resend is harmless: the same client id returns
  `duplicate`. The day screen shows each punch's site and distance, the device that sent
  it, and whether it was sent offline.
- **Attendance** — month calendar with status dots + legend, a metrics summary
  card (present/late/absent, hours rendered, late/OT minutes) from
  `GET /attendance/summary`, a list view, and a per-day punch-timeline detail. The day
  offers **Request a correction** unless its period is locked (ADR 0039), and the header's
  **Requests** opens **My requests**: what was asked and decided, each with its reason and
  the reviewer's note, and **Cancel** while pending. **Ask** (`app/attendance/request.tsx`)
  files a correction, overtime, official business or remote work — the same four kinds,
  validated by the same request as the web, and decided in the ERP.
- **Leave** — balances per type, a file-leave form (type → dates → half-day →
  reason) with server-computed days and inline 422 errors, history with status
  pills, and a detail screen with cancel.
- **Profile + Awards** — the 201 profile (government IDs masked, salary omitted)
  and the employee's recognitions.

## API (all behind `auth:sanctum`, self-scoped)

| Method & path | Purpose |
|---|---|
| `POST /api/auth/register` (public, throttled) | Create an identity; returns a session with `organization: null`, `needs_workspace: true` |
| `POST /api/auth/login`, `GET /api/me`, `POST /api/auth/logout` | Token session; payload includes the token's `organization` (may be `null`), whose `timezone` is the clock every punch time is shown on, whatever zone the phone is in (ADR 0036) |
| `POST /api/auth/switch` | Re-issue the token bound to another company the identity belongs to |
| `POST /api/workspaces/preview` · `POST /api/workspaces/join` | Look up / redeem a company join code (throttled) |
| `GET /api/invitations` · `POST /api/invitations/preview` · `POST /api/invitations/accept` · `DELETE /api/invitations/{id}` | Invitations addressed to this identity |
| `GET /api/attendance/today` · `POST /api/attendance/punch` · `GET /api/attendance/records` · `GET /api/attendance/summary` | DTR + metrics. A punch may carry `client_id` (idempotency), `punched_at` (the phone's time; marks it offline, judged against the policy's offline window) and `sent_at` (to measure clock skew) |
| `GET /api/attendance/requests` · `POST /api/attendance/requests` · `GET /api/attendance/requests/{id}` · `PATCH /api/attendance/requests/{id}/cancel` | Own attendance requests (ADR 0039); filing always for the token's own employee |
| `GET /api/profile` | Own 201 profile (masked IDs) |
| `GET /api/awards` | Own recognitions |
| `GET /api/leave/types` · `GET /api/leave/balances` · `GET /api/leave/requests` · `POST /api/leave/requests` · `PATCH /api/leave/requests/{id}/cancel` | Self-service leave |

Leave filing reuses `LeaveCalculator` + `HolidayCalendar` (days computed
server-side, never trusted from the client) and auto-approves types that don't
require approval — identical to the web `LeaveRequestController`.

## Running it

1. **Server:** `php artisan serve --host 0.0.0.0` (so a phone on the LAN can reach
   it). Seed first with `php artisan migrate:fresh --seed` — this links the demo
   account (`earlkian.dev@gmail.com` / `password`) to an employee.
2. **Point the app at your machine:** set `expo.extra.apiUrl` in
   `mobile/app.json` to `http://<your-LAN-IP>:8000/api`, or export
   `EXPO_PUBLIC_API_URL`. (Find your IP with `ipconfig`.)
3. **App:** in `mobile/`, `npx expo start`, then open in Expo Go on the phone.

## Conventions

- `lib/api.ts` — the single fetch client (base URL + Bearer token + 422 parsing).
- `lib/auth.tsx` — one identity, one org-bound token in SecureStore, re-hydrated from
  `/me` on boot. `login` / `switchTo` / `logout` / `refresh`; `switchTo` swaps in a
  token bound to the chosen company. `lib/active-workspace.ts` republishes the active
  org id so `lib/use-query.ts` refetches on a switch.
- `theme/` — design tokens, light + dark, kept in lock-step with the web app's
  `resources/css/app.css`. **White is the app's primary colour**, as it is in the ERP's
  signed-in shell: white page and cards separated by the ERP's own neutral hairlines
  (its `oklch()` greys converted to sRGB — the Tailwind `neutral` ramp), near-black
  `primary` for anything you press, and the brand teal `#0ABFBF` held back for one job,
  marking what is active. Navy `#0F2044` is the *pre-app* field only — sign-in,
  register, splash and the workspace picker — and appears nowhere behind the app itself.
  `theme/color.ts` carries the colour maths: sRGB ⇄ OKLCH, WCAG contrast, and
  `readableOn()`, which walks a colour's lightness until it can legibly carry text on a
  given surface. Status tones and the colours HR picks for leave and award types are
  rendered through `readable()` from `useTheme()` rather than painted raw, so a
  tenant-chosen colour can never land as 2.5:1 text on a white card. `FixedScheme` pins
  one subtree to a scheme, for the sign-in card that stays white whatever the phone's
  appearance setting says.
- `components/ui/` — the shared kit (Button, Card, Pill, Input, Sheet, Toast, …), plus
  `logo.tsx`: the SYNAPSE mark in two colourways. About 60% of the artwork is deep navy
  and vanishes on a dark ground, so `Logo` takes the original on light surfaces and a
  reversed one (white figure, teal network) on dark; `surface="dark"` forces the reversed
  mark on the splash and sign-in screens, which are navy whatever the phone is set to.
  The mark is landscape (about 4:3), so it is sized by width and never squeezed into a
  square. The app, splash and adaptive icons in `assets/images/` are generated from the
  same artwork, each at the padding its slot wants.
- `features/<module>/` — `api.ts` + components, mirroring the web feature folders.
