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
The ERP never issues or knows anybody's password. A forgotten one is reset through
Fortify's reset link, which the app's **Forgot password?** asks for
(`POST /api/auth/forgot-password`, [ADR 0082](../decisions/0082-the-app-resets-a-forgotten-password-and-edits-ones-own-account.md)):
it answers the same whatever the address, and the link opens the web's reset page.

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
  **Forgot password?** under the password field opens `app/(auth)/forgot-password.tsx`
  (the typed address carried over): one field, then *Check your email* with the three
  steps and *Send it again* once the broker's minute is up. When the server refuses the
  phone's token (a new password, a reset, HR), the app signs out and sign-in says why
  (ADR 0082).
- **Join a company** (`app/join.tsx`) — where an account with no employer lands.
  Invitations addressed to them are listed unprompted and joined in one tap; below
  that, a single code field takes either an invitation code or the company join
  code (it tries the more specific one first). Pending requests are shown so nobody
  asks twice. Company creation is deliberately absent — that lives on the web app.
- **Home** — the date and a greeting as the large title, the workspace chip, and
  today on a navy card: where the day stands, hours worked against the shift, and the
  next punch (tapping it opens Clock). Then shortcuts (file leave, records, awards —
  plus **Events** and **Recognition** when the person may answer invitations or take
  part), an **Up next** card with the next invitation and one-tap answers, requests
  awaiting approval, a snapping carousel of leave balances, and the latest award. A
  **search** button in the large title opens Search.
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
  enters it instead. Only one send runs at a time: `flush()` shares the attempt in
  flight, so **Send now** pressed during a background send waits for it and reports
  its outcome (sent, still unreachable, or refused). Every request has a deadline
  (`lib/api.ts`: 30 s, 60 s for a punch with a selfie), because React Native's Android
  HTTP client sets none — before, one connection that never answered left the queue
  "sending" for good and **Send now** did nothing. A resend is harmless: the same client id returns
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
  sign-out behind a system alert; and the employee's recognitions. The Profile list is
  also the hub for **My events**, **Recognition** and **Points & rewards** (shown by
  permission), **My schedule** (with an employee record), **Holidays**, **Resignation**
  (with `offboarding.resign`) and a **Help** section (*Take the tour*, *Search*) — the
  five tabs stay as they are. The employee record is read-only and says HR keeps it.
  An **Account** section edits the person's own sign-in account
  ([ADR 0082](../decisions/0082-the-app-resets-a-forgotten-password-and-edits-ones-own-account.md)):
  **Name and email** (`account/details`, a form sheet; a new address asks for the current
  password and is confirmed by a mailed code) and **Change password** (`account/password`,
  which signs the app out on the person's other phones, and offers the reset link for a
  forgotten current one).
- **My schedule** ([ADR 0083](../decisions/0083-my-schedule-in-the-app.md)) —
  `app/schedule/index.tsx`, from Profile, the Clock tab's shift row and Search: one's own
  week, Monday to Sunday, as the roster has it. A week switcher (and a sideways swipe),
  the week's shifts and hours, and the seven days: hours (or the holiday, the leave,
  *Rest day*) and a pill for *Holiday*, *On leave*, *Half day*, *Pending* or *Changed*. A
  day that has started opens its attendance record.
- **Resignation** ([ADR 0076](../decisions/0076-employees-resign-and-leavers-answer-an-exit-interview.md),
  [ADR 0077](../decisions/0077-an-exits-papers-are-generated-as-pdfs.md)) —
  `app/resignation/index.tsx` shows the same thing as My resignation on the web
  (`MyExit`): with nothing under way, *Resign*; a waiting resignation with *Withdraw*
  (confirmed by a system alert); a declined one with HR's note; once the exit starts, a
  hero with the last day and clearance progress, the clearance item by item (a flag
  with its reason), the exit interview and *Get my certificate*, which opens a
  10-minute signed link in the browser. `resignation/new` files one (the notice is
  counted as the day is picked, with a note under 30 days); `resignation/interview` is
  the exit-interview survey: the reason, the seven ratings in one card with tappable
  1–5 scores, the yes / maybe / no questions and the open answers.
- **Holidays** ([ADR 0078](../decisions/0078-holidays-that-move-and-holidays-kept-at-some-sites.md))
  — `app/holidays/index.tsx`: the year ahead as the person keeps it, by month, each
  with its weekday, type, whether it is a day off, how it repeats, and a local one's
  site.
- **Search** ([ADR 0081](../decisions/0081-search-finds-attendance-days-and-the-app-searches-ones-own.md))
  — `app/search.tsx`: the app's screens matched on the phone, and the person's own
  leave, days, invitations, awards and holidays from the server, grouped; a result
  opens its screen.
- **The tour** ([ADR 0080](../decisions/0080-tour-versions-per-stop-runs-and-a-tour-in-the-app.md))
  — `features/tour/tab-tour.tsx`: a spotlight over the tab bar with a card above it
  (Home, Attendance, Clock, Leave, Profile), offered once after sign-in and replayed
  from Profile. Each run is recorded.
- **Site clocks** ([ADR 0079](../decisions/0079-shape-fences-and-a-clock-per-site.md))
  — `lib/clock-zone.ts`: times are shown on the clock the person's days are judged on
  (their base site's, else the company's); a day from the server carries its own
  `timezone`.
- **Events** ([ADR 0070](../decisions/0070-events-answered-by-invitees-repeating-rooms-reminders-and-a-calendar-feed.md))
  — `app/events/index.tsx` lists the person's invitations (upcoming / past) and
  `app/events/[id].tsx` shows one with its room, repeat and reminder, answers it (for
  this date or every later one), and subscribes the phone's calendar to the feed.
- **Recognition** ([ADR 0071](../decisions/0071-recognition-kudos-nominations-points-and-rewards.md))
  — `app/recognition/index.tsx` is the wall with the person's balance; kudos and
  nominating open as modals (`recognition/kudos`, `recognition/nominate`);
  `recognition/nominations` lists one's nominations; `app/rewards/index.tsx` holds the
  balance, the catalogue with redeem, one's requests and the points history.

## API (all behind `auth:sanctum`, self-scoped)

| Method & path | Purpose |
|---|---|
| `POST /api/auth/register` (public, throttled) | Create an identity; returns a session with `organization: null`, `needs_workspace: true` |
| `POST /api/auth/login`, `GET /api/me`, `POST /api/auth/logout` | Token session; payload includes the token's `organization` (may be `null`), whose `timezone` is the clock every punch time is shown on, whatever zone the phone is in (ADR 0036) — unless the person's base site keeps its own (`employee.timezone`, ADR 0079). It also says whether the person may resign (`can_resign`) and owes the app's tour (`tour`) |
| `POST /api/auth/switch` | Re-issue the token bound to another company the identity belongs to |
| `POST /api/workspaces/preview` · `POST /api/workspaces/join` | Look up / redeem a company join code (throttled) |
| `GET /api/invitations` · `POST /api/invitations/preview` · `POST /api/invitations/accept` · `DELETE /api/invitations/{id}` | Invitations addressed to this identity |
| `GET /api/attendance/today` · `POST /api/attendance/punch` · `GET /api/attendance/records` · `GET /api/attendance/summary` | DTR + metrics. A punch may carry `client_id` (idempotency), `punched_at` (the phone's time; marks it offline, judged against the policy's offline window) and `sent_at` (to measure clock skew) |
| `GET /api/profile` | Own 201 profile (masked IDs) |
| `POST /api/auth/forgot-password` (public, throttled) | Mail Fortify's reset link; the same answer for any address (503 if mail fails) |
| `GET` · `PATCH /api/account` · `PUT /api/account/password` | One's own sign-in account: name and email (a new address needs `current_password`), and the password (ends the other app sessions) |
| `GET /api/schedule?week=` | One's own week of shifts (`MySchedule`): hours, holiday, leave, whether HR changed the day, and the week's totals |
| `GET /api/awards` | Own recognitions |
| `GET /api/events` · `GET /api/events/{hashid}` · `POST /api/events/{hashid}/respond` | Own invitations and answering them (`events.respond`) |
| `GET /api/calendar-feed` · `POST /api/calendar-feed/reset` | The person's calendar-feed links |
| `GET /api/recognition` · `GET /api/colleagues` · `POST /api/kudos` | The wall, colleagues to pick, sending kudos (`awards.participate`) |
| `GET /api/nominations` · `GET /api/nominations/types` · `POST /api/nominations` · `DELETE /api/nominations/{id}` | One's nominations |
| `GET /api/points` · `GET /api/rewards` · `POST /api/rewards/{hashid}/redeem` · `PATCH /api/redemptions/{id}/cancel` | Points, rewards and redeeming |
| `GET /api/leave/types` · `GET /api/leave/balances` · `GET /api/leave/requests` · `POST /api/leave/requests` · `PATCH /api/leave/requests/{id}/cancel` | Self-service leave |
| `GET /api/holidays` | The holidays the person keeps over the next 365 days, local ones at their site included |
| `GET /api/search?q=` | The person's own records (`MySearch`), throttled by `search` |
| `GET /api/offboarding` · `POST /api/offboarding/resignation` · `PATCH /api/offboarding/resignation/{id}/withdraw` · `GET`/`POST /api/offboarding/exit-interview` · `POST /api/offboarding/certificate-link` | One's own exit (`offboarding.resign`) |
| `GET /api/documents/certificate/{case}/{user}` (public, **signed**, throttled) | The Certificate of Employment PDF the link above points at, for 10 minutes |
| `POST /api/tour/runs` · `PATCH /api/tour/runs/{id}` · `POST /api/tour/finish` | The app's tour: its runs and its answer |

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

- `lib/api.ts` — the single fetch client (base URL + Bearer token + 422 parsing). A 401
  to a request carrying the current token calls the auth layer's unauthorized handler,
  which signs the phone out (ADR 0082).
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
    `segmented.tsx` (sliding thumb), `step-button.tsx` (the chevron that steps a month or week), plus `Card`, `Pill`, `Avatar`, `Skeleton`,
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
