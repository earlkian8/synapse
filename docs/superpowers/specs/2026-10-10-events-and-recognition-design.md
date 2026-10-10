# Events self-service and peer recognition — design

- **Date:** 2026-10-10
- **Asked for:** "Events: employees can't RSVP themselves. There are no recurring events,
  no calendar sync, no room booking, and reminders only go out when someone clicks
  Remind (both mobile and ERP). Awards: there's no nominate-and-approve workflow. The
  'Nominations' page is an AI-ranked shortlist with citation drafting. There are also
  no points or rewards and no peer-to-peer kudos (both mobile and ERP)." Plus: a queued
  Time Out punch on the phone that does nothing when *Send* is pressed.
- **Revised (same day):** where the screens live. The first cut put "My Events" and
  "Recognition" in the sidebar's Main group, beside the existing Workforce modules.
  On review that gave HR two entries per subject, so each module now has one sidebar
  entry and a section nav (§6).
- **Path:** architectural (new tables, new permissions, new screens on two clients).
  The user said "go all out, implement", so this spec records the decisions made on
  their behalf rather than waiting at each gate.

## 0. The stuck punch (bug)

**Root cause.** React Native on Android builds its HTTP client with no timeouts
(`OkHttpClientProvider.kt`: connect, read and write all `0`), and `lib/api.ts` sets
none. A queued send that goes out on a connection that dies never settles, so
`punchQueue.flush()` never reaches `finally { sending = false }`. Every later flush —
the *Send* button, the 30-second retry, the return to the foreground — sees
`sending === true` and returns `[]` at once. Nothing reaches the server (the local DB
has the 06:05 clock-in and no clock-out; the server log has no punch error). *Send*
also threw its result away, so even an ordinary failure showed nothing.

**Fix.**
1. `lib/api.ts`: every request carries an `AbortController` deadline (30 s by default,
   60 s for a punch with a selfie). A timeout becomes `ApiError(0, …)`, which the queue
   already treats as "not sent yet".
2. `punch-queue.ts`: `flush()` shares one in-flight run (`inFlight` promise) instead
   of a boolean, so pressing *Send* during a background attempt waits for that attempt
   and reports its outcome. It returns `{ sent, refused, waiting, offline }`. Refusals
   are published to subscribers (`onRefused`), so the runner remains the one place that
   announces them and a refusal is never toasted twice.
3. `clock.tsx`: *Send* shows progress and says what happened: sent, still unreachable
   (kept, retried automatically), or refused (via the runner's toast).

Proof: the web build against a server that accepts the connection and never answers
(RED: *Send* does nothing; GREEN: it times out, says so, and the next press sends).

## 1. Events

### 1.1 Self-service RSVP
- New permission **`events.respond`** — "See and answer your own invitations". Granted
  to the built-in Staff, Department Head and HR Manager roles (migration back-fills
  every organisation's built-in roles; custom roles are left to their owners).
- An invitee answers **accepted / tentative / declined** (never back to "invited"),
  only while the event is not over and not archived. `event_attendees.responded_at` is
  stamped. For an occurrence of a series the answer can cover **this one** or **this
  and every later one** they are invited to.
- `EventWorkflow::respondAsInvitee()` is the one path (web, mobile API, assistant).
  HR's inline response select keeps using `respond()`.
- Web: **`/events/me`** "My invitations" (a section of Events & Meetings, §6). Upcoming first, then past; answer
  buttons inline; "Add to calendar" per event; "Subscribe" for the feed.
- Notifications now link to `/events/me?event=<hashid>`, a page every invitee can open.
  Before, they linked to `/events/<hashid>`, which Staff could not open (403).

### 1.2 Recurring events
- **Materialised occurrences.** `event_series` holds the rule (`frequency`
  daily|weekly|monthly, `interval` 1–4, `weekdays` for weekly (ISO 1–7), `until` date or
  `count`). Each occurrence is an ordinary `events` row with `series_id`, so RSVP,
  reminders, rooms, the feed and the assistant work per occurrence with no special
  cases.
- Times are generated on the organisation's wall clock (ADR 0036): every occurrence
  starts at the same local time and lasts as long as the first one.
- Monthly repeats on the first date's day of the month and clamps to the month's
  last day (31 Jan → 28 Feb → 31 Mar).
- Limits: at most **100 occurrences** and an end no more than **2 years** after the
  first start. Over either limit, the request is refused in words.
- **Scopes** for edit, archive and invite on an occurrence: *this event* or *this and
  following*. "Following" applies title, kind, description, location, room and
  reminder; it moves every later occurrence by the same wall-clock shift as this one
  (days + minutes) and gives each the same duration.

### 1.3 Calendar sync (subscription feed)
- `calendar_feeds`: one per user per organisation, holding a sha-256 `token_hash`
  (lookup) and the `token` encrypted (so the link can be shown again), plus
  `last_used_at`.
- **`GET /calendar/{token}.ics`**: public, throttled (60/min), no session. It binds
  the feed's tenant and serves the user's invitations (not declined, not archived) and
  the events they organise, from 60 days back to 400 days ahead. Each event has a
  stable `UID` and a `SEQUENCE`, so a calendar app replaces it after an edit and drops
  it after archiving. Tentative replies are `STATUS:TENTATIVE`.
- The link is offered as `webcal://…` (Apple, Outlook) with the `https://` form to
  paste into Google Calendar. "Reset link" issues a new token; the old one stops
  working.
- `Support\Events\EventCalendar` builds every VCALENDAR (the single download and the
  feed); the controller's escaping moves there unchanged.
- **Not built:** writing back from Google/Outlook. That needs an OAuth app per
  provider. It stays in `not-yet-built.md`, reworded.

### 1.4 Rooms
- `rooms`: `name`, `location`, `capacity` (nullable), `description`, `is_active`, soft
  deletes. Managed **inside Events** at `/events/rooms` (`events.view` to see it,
  `events.manage` to change it). It is not a Company Setup screen, so the setup wizard
  is not widened.
- `events.room_id`. A room needs an **end time**. Two live (non-archived) events
  cannot hold the same room in overlapping windows (`start < other.end && end >
  other.start`). The check runs in a transaction with the room row locked. A retired
  or archived room cannot be newly booked (an event that already holds one keeps it).
- The form asks `GET /events/rooms/availability?starts_at&ends_at[&event]` and marks
  each room free or busy (with the clashing event's title). Capacity under the invite
  count is a warning, not a refusal.
- `/events/rooms`: each room's bookings for a chosen week, plus the catalogue.

### 1.5 Automatic reminders
- `events.reminder_minutes` (nullable; 10, 30, 60, 120, 1440 or 2880) and
  `events.reminder_sent_at`. The form defaults to **1 hour before**. "No reminder" is
  allowed.
- `events:remind` runs every five minutes. For each organisation it finds live
  events whose reminder is due (`starts_at − reminder_minutes ≤ now < starts_at`) and
  not yet sent. It notifies every invitee who has not declined and has an active
  account, then stamps `reminder_sent_at` on the event. Moving the start clears the
  stamp, so the reminder follows the new time.
- The manual *Remind pending* button stays: it nudges people who have not answered.

## 2. Recognition

### 2.1 Permission
**`awards.participate`** — "Give kudos, nominate colleagues & redeem rewards". Granted
to the built-in Staff, Department Head and HR Manager roles, back-filled the same way.
HR work stays `awards.manage`.

### 2.2 Nominate → approve
- `award_nominations`: `award_type_id`, `employee_id` (nominee), `nominated_by`
  (user), `nominator_employee_id`, `reason`, `status`
  (pending|approved|rejected|withdrawn), `reviewed_by`, `reviewed_at`, `review_note`,
  `employee_award_id`.
- `award_types.accepts_nominations` (default true): retired or "not by nomination"
  types are not offered.
- Rules (`NominationWorkflow`, `NominationException`):
  - the nominee must be an active colleague and not the nominator;
  - the type must be active and accept nominations;
  - the reason must be 20–1000 characters;
  - a nominator may have only one *pending* nomination per nominee and type;
  - the nominator may withdraw a nomination while it is pending;
  - a reviewer (`awards.manage`) cannot be the nominator or the nominee.
- **Approving gives the award through `AwardWorkflow::give()`**. The reviewer can edit
  the citation (it starts as the nominator's reason; the existing AI citation draft
  is offered) and the date (today by default). Its points follow (2.3).
  **Rejecting** takes an optional note.
- Notifications: holders of `awards.manage` (except the nominator and nominee) hear of
  a new nomination; the nominator hears the decision. The recipient of any award is now
  notified when it is given (before, nobody was told).

### 2.3 Points
- `point_transactions` is a ledger: `employee_id`, signed `amount`, `kind`
  (award|kudos|redemption|refund|adjustment), a nullable `subject` (morph), `note`,
  `created_by`. **Balance = sum.** Nothing is ever edited in place.
- `award_types.points` (0–10 000, default 0). Giving an award credits it. Changing an
  award's type posts the difference. Removing an award reverses it. A reversal may take
  a balance below zero; the ledger keeps the truth, and redeeming waits until the
  balance covers the cost.
- Kudos credit `organizations.kudos_points` (default 10, 0 disables) to the recipient,
  for at most `organizations.kudos_monthly_limit` kudos per sender per calendar month on
  the organisation's clock (default 5). Kudos past the limit still go out, with no
  points, and the sender is told before sending.
- HR (`awards.manage`) can post an **adjustment** with a required note.

### 2.4 Rewards
- `rewards`: `name`, `description`, `cost` (≥ 1), `stock` (nullable = unlimited),
  `is_active`, soft deletes. Managed at `/awards/rewards`.
- `reward_redemptions`: `reward_id`, `employee_id`, `cost` (as charged), `status`
  (pending|fulfilled|declined|cancelled), `note`, `response_note`, `handled_by`,
  `handled_at`.
- **Redeeming** locks the employee row and the reward row, checks the balance and the
  stock, debits the cost and decrements the stock — all in one transaction, so two
  taps cannot spend the same points twice. Cancelling (by the employee, while pending)
  or **declining** (HR) refunds and restocks. **Fulfilling** (HR) closes it. HR cannot
  handle their own redemption. The employee is notified either way.

### 2.5 Kudos and the wall
- `kudos`: `from_employee_id`, `to_employee_id`, `message` (1–500), `points` (as
  credited), soft deletes. Not to oneself. The recipient is notified. HR may remove a
  kudos; its points are reversed.
- **`/awards/wall`** (a section of Awards & Recognition, §6; `awards.participate`): one
  feed of kudos and awards, newest first, with the composer, my balance, and
  "Nominate". Beside it: **My points** (`/awards/points`) and **My nominations**
  (`/awards/my-nominations`). Awards appear on the wall with type, citation and
  date, so recognition is public to the workspace, as the brief asked.

### 2.6 HR screens
`/awards` gains sections (§6): **Awards · Nominations (pending count) · Rewards**, with
the AI shortlist as the second view of Nominations.
- `/awards/nominations` is now the review queue.
- The AI-ranked board moves to **`/awards/shortlist`**, renamed "Shortlist", and shows
  how many pending peer nominations each person has.
- `/awards/rewards` holds the catalogue, the redemption queue, the points settings
  and adjustments.
- Award Types setup gains *Points* and *Open to nominations*.

## 3. Mobile app
- Screens:
  - `events/index` — my invitations, with Upcoming / Past;
  - `events/[id]` — detail, answer (this / following), room;
  - `recognition/index` — the wall, with balance and actions;
  - `recognition/kudos` and `recognition/nominate` — modals;
  - `recognition/nominations` — mine;
  - `rewards/index` — balance, history, catalogue, redeem, my redemptions.
- Home: the shortcuts become *File leave · Events · Recognition*, and an **Up next**
  card shows the next invitation with one-tap answers.
- Profile lists Events, Recognition and Rewards. "Subscribe in Calendar" opens the
  `webcal://` link, or shares the https link when nothing handles webcal.
- API (Sanctum, self-scoped, the same workflows):
  - `GET events`, `GET events/{hashid}`, `POST events/{hashid}/respond`;
  - `GET calendar-feed`, `POST calendar-feed/reset`;
  - `GET recognition`, `POST kudos`, `GET colleagues`;
  - `GET nominations`, `POST nominations`, `DELETE nominations/{id}`, `GET nominations/types`;
  - `GET points`;
  - `GET rewards`, `POST rewards/{id}/redeem`, `GET redemptions`, `PATCH redemptions/{id}/cancel`.
- `me` gains `can_respond_events` and `can_recognize`.

## 4. Assistant
- **Events:**
  - `respond_to_event` — own RSVP, `events.respond`;
  - `find_rooms` — rooms and whether each is free in a window;
  - `schedule_event` and `update_event` gain `room`, `reminder_minutes` and `repeat` /
    `scope`. Everything still goes through `EventWorkflow`.
- **Awards:**
  - `find_nominations` (`awards.manage`);
  - `review_nomination` (Confirm);
  - `nominate_colleague` and `give_kudos` (Confirm — both notify someone; ADR 0050),
    under `awards.participate`;
  - `get_points` (own; anyone's with `awards.manage`);
  - `find_redemptions` and `handle_redemption` (Confirm).
- The SystemGuide and the Help Center get the new screens. Data Export covers the new
  tables.

## 5. Testing
- Pest feature tests per workflow: RSVP, recurrence, scopes, rooms, reminders
  command, the feed, nominations, points, redemptions, kudos, the API endpoints and
  tenancy isolation (with `Tenancy::forget()`), plus assistant tools with the stubbed
  model.
- Web screens are checked in headless Chromium. The mobile screens and the punch fix
  are checked in the Expo web build.
- Full suite, Pint, tsc, ESLint, Prettier, the Vite build, and the mobile tsc and lint.

## 6. Where the screens live (revised)
- **Main is the Dashboard only.** Each module has **one** sidebar entry under Workforce,
  shown to anyone who can view it *or* take part (`permissionAny`): Events & Meetings
  (`events.view`, `events.respond`) and Awards & Recognition (`awards.view`,
  `awards.participate`).
- The landing page sends someone who only takes part to their own section:
  `/events` → `/events/me`, `/awards` → `/awards/wall`. Anyone else gets 403.
- **Sections** are a segmented control in the page header (Leave's Requests /
  Balances is the precedent), each item shown to whoever may open it, in two groups
  where both apply — what is yours, then what you manage:
  - Events: **All events · Rooms ‖ My invitations**;
  - Awards: **Wall · My points · My nominations ‖ Awards · Nominations · Rewards**.
- In-page views use the underline tabs (Attendance's precedent): My invitations'
  Coming up / Past 30 days, the wall's Everything / Kudos / Awards, and Nominations'
  From colleagues / AI shortlist.
- Recognition's web routes move under `/awards` (`awards.wall`, `awards.points`,
  `awards.my-nominations`, `awards.kudos.*`, `awards.rewards.redeem`,
  `awards.redemptions.cancel`), so one module is one URL prefix and one breadcrumb root.
- The mobile app keeps its own placement: five tabs is the limit, and Events and
  Recognition are reached from Home (shortcuts, Up next) and the Profile hub.
