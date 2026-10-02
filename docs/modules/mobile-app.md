# Mobile app (employee companion)

The employee-facing companion to the web ERP, living in `mobile/` (Expo SDK 57 +
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
- **Home** — the date and a greeting as the large title, the workspace chip, and
  today on a navy card: where the day stands, hours worked against the shift, and the
  next punch (tapping it opens Clock). Then shortcuts (file leave, records, awards),
  requests awaiting approval, a snapping carousel of leave balances, and the latest
  award.
- **Clock (DTR, the hero)** — a clock face: a ring that fills as the shift is worked,
  around the live time and the running time on the clock, then a state-driven primary
  button (Time In → Break → Time Out) driven by the server's `allowed` /
  `next_expected`, today's in/out/worked, and the shift. Confirming a punch is a sheet
  (location, optional selfie), and a recorded punch lands with a full-screen check. Captures real GPS (`expo-location`) and an optional selfie
  (`expo-image-picker`), submitted as multipart to `POST /attendance/punch`
  through the canonical `AttendanceClock`. Live worked-hours counter + status chip.
  **Punching offline** (ADR 0040): a punch that cannot reach the server — or any punch
  while others are still waiting — is **queued** on the phone
  (`features/attendance/punch-queue.ts`, in AsyncStorage) with the phone's time and a
  client id, and the button moves on as if it had been recorded. A banner says how many
  are waiting, with **Send now**, and the Clock tab carries a badge.
  `PunchQueueRunner` (mounted in the tab layout) sends the queue oldest first every 30
  seconds and whenever the app returns to the foreground; a punch the server refuses
  (older than the policy's offline window, say) is dropped with a toast saying why — HR
  enters it instead. A resend is harmless: the same client id returns
  `duplicate`. The day screen shows each punch's site and distance, and whether it was
  sent offline.
- **Attendance** — a month switcher (swipe the calendar sideways to turn the month), a
  summary card (a bar of on-time/late/absent/on-leave days, hours rendered, late and
  overtime) from `GET /attendance/summary`, a calendar whose recorded days sit on a wash
  of their status colour, a list view, and a per-day sheet with the punch timeline.
- **Leave** — balances per type with bars, a file-leave form sheet (type → dates →
  half-day → reason; iOS's compact date picker inline, Android's calendar dialog) with
  server-computed days and inline 422 errors, history filtered by a segmented control,
  and a detail screen whose cancel is confirmed by a system alert.
- **Profile + Awards** — the 201 profile as inset grouped lists (government IDs
  masked, salary omitted), the workspace, appearance (light, dark, match phone), and
  sign-out behind a system alert; and the employee's recognitions.

## API (all behind `auth:sanctum`, self-scoped)

| Method & path | Purpose |
|---|---|
| `POST /api/auth/register` (public, throttled) | Create an identity; returns a session with `organization: null`, `needs_workspace: true` |
| `POST /api/auth/login`, `GET /api/me`, `POST /api/auth/logout` | Token session; payload includes the token's `organization` (may be `null`), whose `timezone` is the clock every punch time is shown on, whatever zone the phone is in (ADR 0036) |
| `POST /api/auth/switch` | Re-issue the token bound to another company the identity belongs to |
| `POST /api/workspaces/preview` · `POST /api/workspaces/join` | Look up / redeem a company join code (throttled) |
| `GET /api/invitations` · `POST /api/invitations/preview` · `POST /api/invitations/accept` · `DELETE /api/invitations/{id}` | Invitations addressed to this identity |
| `GET /api/attendance/today` · `POST /api/attendance/punch` · `GET /api/attendance/records` · `GET /api/attendance/summary` | DTR + metrics. A punch may carry `client_id` (idempotency), `punched_at` (the phone's time; marks it offline, judged against the policy's offline window) and `sent_at` (to measure clock skew) |
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

Against a deployed server ([Deployment](../deployment.md)), set
`EXPO_PUBLIC_API_URL=https://<your-domain>/api` in `mobile/.env` instead.

## Conventions

- `lib/api.ts` — the single fetch client (base URL + Bearer token + 422 parsing).
- `lib/auth.tsx` — one identity, one org-bound token in SecureStore, re-hydrated from
  `/me` on boot. `login` / `switchTo` / `logout` / `refresh`; `switchTo` swaps in a
  token bound to the chosen company. `lib/active-workspace.ts` republishes the active
  org id so `lib/use-query.ts` refetches on a switch.
- `theme/` — design tokens, light + dark ([ADR 0064](../decisions/0064-the-mobile-app-is-designed-as-an-ios-app.md)).
  **The structure is iOS's, the colours are the brand's.** Content sits on the grouped
  background (`#F2F2F7` by day, true black at night) in cards with continuous corners
  and no edge (`squircle`), and the dark scheme is iOS's (`#1C1C1E` cards, `#2C2C2E`
  raised). Navy `#0F2044` is the brand's weight: the one `hero` surface on a screen and
  the `primary` action (teal at night, where navy on black disappears). Teal `#0ABFBF`
  is the `tint`: links, selection, switches, progress, the punch button. Status tones are
  Apple's system colours. Every reading colour clears WCAG AA on page and card;
  `textTertiary` is for glyphs and placeholders only (3:1). Type is the HIG scale set in
  Inter (`fonts`, `typography`), a point under Apple's sizes with Inter's own tracking
  curve. `theme/color.ts` carries the colour maths: sRGB ⇄ OKLCH, WCAG contrast, and
  `readableOn()`, which walks a colour's lightness until it can legibly carry text on a
  given surface. Status tones and the colours HR picks for leave and award types are
  rendered through `readable()` from `useTheme()` rather than painted raw. `FixedScheme`
  pins one subtree to a scheme — `EntryScreen` uses it. The chosen appearance is handed
  to the platform (`Appearance.setColorScheme`) so the keyboard, alerts and date picker
  match.
- `lib/motion.ts` — the motion vocabulary: springs by job (`press`, `snappy`, `gentle`,
  `pop`), `enter(index)` (a 12pt rise with a 40ms stagger), and `fadeIn`. Reanimated
  follows the phone's Reduce Motion setting.
- `components/ui/` — the shared kit:
  - `page.tsx`: `Page`, every screen. A large title that collapses into a frosted
    navigation bar as it scrolls under it, pull to refresh, `back`, `left`/`right` bar
    items (`BarButton`, `BarTextButton`), `modal` for sheets, and `tabInset` to clear the
    floating tab bar. Its scroll view is `KeyboardScroll`.
  - `keyboard-scroll.tsx` + `lib/keyboard.ts`: keyboard handling on React Native's own
    `Keyboard` events ([ADR 0065](../decisions/0065-keyboard-handling-on-the-core-keyboard-api.md)).
    When the keyboard rises, the page makes room under the content and scrolls the
    focused field (and, with `anchor`, the form's button) above it. Everything is
    measured relative to the scroll view, so it is right on iOS, on Android in Expo Go
    (window resized, status bar left out of `measureInWindow`) and in edge-to-edge
    builds. Dragging the page dismisses the keyboard. `Input` reveals itself on focus.
  - `tab-bar.tsx`: the floating capsule tab bar (Liquid Glass on iOS 26, blur before it,
    a fill on Android) with a sliding selection pill and the queued-punch badge;
    `useTabBarInset()`.
  - `list.tsx`: `ListSection` / `ListRow`, inset grouped lists (headers, footers, icon
    wells, values, chevrons, checkmarks, highlight on press, `raised` inside sheets).
  - `text.tsx` (`AppText`: HIG variants, `tone`, `weight`, `numeric`; maps any
    `fontWeight` to its Inter file), `icon.tsx` (`Icon`: one name → an SF Symbol on iOS
    and a Material Symbol on Android, via `expo-symbols`), `touchable.tsx` (the spring
    press and haptics behind everything pressable), `material.tsx` (glass/blur/fill).
  - `button.tsx` (iOS button styles: `primary`, `tint`, `tinted`, `gray`, `plain`,
    `destructive`), `input.tsx` (filled field, animated focus edge, `secureToggle`,
    `ref` for focus chaining), `sheet.tsx` (springs up, drag down to dismiss, rides
    above the keyboard), `toast.tsx` (a frosted banner dropped from the top; flick it away),
    `segmented.tsx` (sliding thumb), plus `Card`, `Pill`, `Avatar`, `Skeleton`,
    `EmptyState`/`ErrorState`, `ProgressBar`, `Section`, `ToneWell`.
  - `entry-screen.tsx`: `EntryScreen`, the ground of the entry screens (cold-start
    splash, sign-in, register, workspace picker). It is white and light-scheme whatever
    the phone is set to, keyboard-aware (`keyboardAnchor` lifts the form's button with
    the focused field), with dark status-bar icons. `BrandLockup` is
    the mark over the navy wordmark, and `entryColors` gives the light palette to a
    screen reading colours outside the ground.
  - `logo.tsx`: the SYNAPSE mark in two colourways. About 60% of the artwork is deep
    navy and vanishes on a dark ground, so `Logo` takes the original on light surfaces
    and a reversed one (white figure, teal network) on dark; the entry screens pass
    `surface="light"`. The mark is landscape (about 4:3), so it is sized by width and
    never squeezed into a square. The app, splash and adaptive icons in `assets/images/`
    are generated from the same artwork, each at the padding its slot wants. The splash
    icon is the original colourway on a white splash; the launcher icon keeps its navy
    tile.
- `features/<module>/` — `api.ts` + components, mirroring the web feature folders.
