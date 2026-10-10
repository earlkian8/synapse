# Events self-service and peer recognition — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the stuck offline punch, then give employees self-service RSVP, recurring events, a
calendar feed, room booking and automatic reminders, plus nominations, points, rewards and kudos —
on the web ERP and the mobile app.

**Architecture:** Each module keeps one workflow class that screens, the mobile API and the assistant
all call (ADR 0050). Recurrence is materialised as ordinary `events` rows grouped by `event_series`.
Points are an append-only ledger. Every new table is tenant-scoped (`BelongsToOrganization`).

**Tech Stack:** Laravel 12 + Pest on Postgres; Inertia v3 + React 19 + Tailwind v4; Expo 57 / RN 0.86.

**Spec:** `docs/superpowers/specs/2026-10-10-events-and-recognition-design.md`

## Global Constraints

- No mutating git; commit text goes to `docs/commit-messages/`. Every change gets a changelog entry.
- Never migrate or seed the DB named in `server/.env`. Tests run on `synapse_test`, serially.
- Never call Gemini. Assistant tests use `fakeAssistantModel()`.
- Times are the organisation's wall clock (`OrganizationClock`). Dates are immutable app-wide.
- Notifying tools in the assistant are Confirm tools (ADR 0050).
- Driver-aware SQL; no Postgres-only constructs that break SQLite.
- Limits from the spec:
  - recurrence: ≤ 100 occurrences, ≤ 2 years, interval 1–4;
  - reminder choices: 10/30/60/120/1440/2880;
  - kudos: 1–500 chars; defaults 10 points and 5 per month;
  - nomination reason: 20–1000 chars;
  - award points: 0–10 000.

## Review Focus

1. Two quick taps on *Redeem* (or two devices) must not spend the same points twice → a test calls
   `redeem` twice when the balance covers one only.
2. Another tenant's event, nomination, reward or feed token must 404, never leak → tests call
   `Tenancy::forget()` before cross-tenant requests.
3. A room booked by an archived event must be free again; restoring that event must re-check the
   clash → a test archives, books the slot, then restores.
4. A "this and following" edit that moves an occurrence across midnight must keep each later
   occurrence on its own shifted date → a test moves Mon 23:30 to Tue 00:30.
5. A punch send that never answers must not wedge the queue → a browser RED/GREEN check against a
   hanging server.

---

### Task 1: Punch queue cannot wedge, and *Send* reports

**Files:** Modify `mobile/lib/api.ts`, `mobile/features/attendance/punch-queue.ts`,
`mobile/features/attendance/punch-queue-runner.tsx`, `mobile/app/(tabs)/clock.tsx`.

**Interfaces:**
- Produces:
  - `request(method, path, body?, { timeoutMs? })`, default 30 000;
  - `attendanceApi.punch` uses 60 000 when a photo is attached;
  - `punchQueue.flush(): Promise<FlushResult>`, where
    `FlushResult = { sent: number; refused: RefusedPunch[]; waiting: number; offline: boolean }`;
  - `punchQueue.onRefused(listener): () => void`.

- [ ] Reproduce RED on the web build with a server that accepts and never answers.
- [ ] Implement the deadline (`AbortController`; abort → `ApiError(0, 'The server took too long to answer.')`).
- [ ] Replace the `sending` boolean with a shared `inFlight` promise; publish refusals to listeners.
- [ ] The runner subscribes to refusals; *Send* shows a spinner and toasts the outcome.
- [ ] GREEN in the browser; run mobile `tsc` and `expo lint`.

### Task 2: Events schema, permissions and models

**Files:**
- Create migration `2026_10_10_000000_create_event_self_service_tables.php`:
  - `rooms` and `event_series`;
  - `calendar_feeds`;
  - `events.series_id`, `room_id`, `reminder_minutes` and `reminder_sent_at`;
  - `event_attendees.responded_at`;
  - grants `events.respond` to the staff, department-head and hr-manager roles.
- Create models `Room`, `EventSeries`, `CalendarFeed`.
- Modify `Event`, `EventAttendee`, `PermissionRegistry`, `OrganizationProvisioner`
  (Staff and Department Head get `events.respond`) and `DataExportCatalogue` (the events dataset
  adds `rooms`, `event_series`, `calendar_feeds` — the last one excluded as a secret).

**Interfaces:**
- `Event`:
  - `room(): BelongsTo`, `series(): BelongsTo`;
  - `scopeLive`, meaning not trashed (the default);
  - `scopeOverlapping(Builder, CarbonInterface $start, CarbonInterface $end)`.
- `Room::scopeActive`.
- `CalendarFeed::issueFor(User): CalendarFeed`, `CalendarFeed::findByToken(string): ?CalendarFeed`
  (no tenant scope), `plainToken(): string`.

- [ ] Tests: the migration back-fills the permission; `PermissionRegistry` lists it.

### Task 3: EventWorkflow — RSVP, rooms, reminders, recurrence, scopes

**Files:** Modify `app/Support/Events/EventWorkflow.php`; create `app/Support/Events/Recurrence.php`
(occurrence generator) and `app/Support/Events/RoomBooking.php` (availability + clash check).

**Interfaces:**
- `Recurrence::occurrences(CarbonImmutable $localStart, int $durationMinutes, array $rule): list<array{starts_at, ends_at}>`.
  The rule shape is `{frequency, interval, weekdays?, until?, count?}`. It throws `EventException`
  past the limits.
- `RoomBooking::clashes(Room $room, $start, $end, ?array $exceptEventIds = null): Collection<Event>`
  and `RoomBooking::availability($start, $end, ?Event $except): list<array{room, free, clash?}>`.
- `EventWorkflow`:
  - `schedule(array $data, User $organizer, string $channel = ''): Event` — `$data` may carry
    `repeat` (rule) and `room_id` / `reminder_minutes`; it returns the first occurrence;
  - `update(Event, array, string $channel = '', string $scope = 'this'): Event`;
  - `archive(Event, string $channel = '', string $scope = 'this'): int`;
  - `invite(Event, iterable, ?User, string $channel = '', string $scope = 'this'): int`;
  - `respondAsInvitee(Event, Employee, string $response, string $scope = 'this'): int`;
  - `sendDueReminders(): int`.

- [ ] Tests (`tests/Feature/Events/EventSelfServiceTest.php`, `EventRecurrenceTest.php`, `RoomBookingTest.php`):
  - weekly Mon/Wed × 4 creates 8 occurrences at 09:00 Manila;
  - monthly from Jan 31 clamps;
  - 101 occurrences are refused;
  - "following" shifts by delta across midnight (Review Focus 4);
  - a room clash is refused naming the event;
  - archive frees the room, and a restore that clashes is refused (RF 3);
  - an invitee answers, a past event is refused, a non-invitee gets 403;
  - "following" answers every later invitation;
  - the reminder goes to non-decliners once, and moving the start re-arms it.

### Task 4: Events controllers, routes, feed, command

**Files:**
- Create:
  - `Events/MyEventsController` (index, respond, ics);
  - `Events/RoomController` (index, store, update, destroy, restore, availability);
  - `Events/CalendarFeedController` (show (public), link, reset);
  - `Api/EventController`, `Api/CalendarFeedController`;
  - `Console/Commands/RemindEvents`;
  - `app/Support/Events/EventCalendar.php`;
  - requests `Events/RoomRequest`, `Events/RespondRequest`.
- Modify:
  - `EventRequest` — repeat/room/reminder/scope rules;
  - `EventManagementController`, `EventAttendeeController`, `EventController` — rooms, series in the
    resource, can flags;
  - `EventIcsController` — uses `EventCalendar`;
  - `EventResource`;
  - `routes/events.php`, `routes/api.php`, `routes/web.php` (feed), `routes/console.php`
    (`events:remind` every five minutes);
  - `MobileSession` — `can_respond_events`, `can_recognize`.

- [ ] Tests:
  - the feed serves the invitee's events, hides declined and archived ones, a reset token
    404s, and a cross-tenant token sees only its own tenant;
  - the API respond and list endpoints work;
  - the routes refuse users without the permission.

### Task 5: Events web screens

**Files:**
- Create:
  - `pages/events/me.tsx`, `pages/events/rooms.tsx`;
  - `features/events/components/`: `respond-buttons.tsx`, `subscribe-dialog.tsx`,
    `scope-dialog.tsx`, `room-select.tsx`, `repeat-fields.tsx`, `room-form-sheet.tsx`.
- Modify `event-form-sheet.tsx` (repeat, room, reminder), `pages/events/show.tsx` (series, room,
  scopes), `event-table.tsx` (repeat icon, room), `types.ts`, `routes.ts`, `app-navigation.ts`
  (one Events & Meetings entry for viewers and invitees — spec §6), `dashboard.tsx` `PersonalStart`.

- [ ] tsc, ESLint, Prettier and the build; check in the browser as HR and as Staff.

### Task 6: Recognition schema, permissions and models

**Files:**
- Create migration `2026_10_10_010000_create_recognition_tables.php`:
  - `award_nominations`, `point_transactions`, `rewards`, `reward_redemptions`, `kudos`;
  - `award_types.points` and `accepts_nominations`;
  - `organizations.kudos_points` and `kudos_monthly_limit`;
  - grants `awards.participate`.
- Create models `AwardNomination`, `PointTransaction`, `Reward`, `RewardRedemption`, `Kudos`.
- Modify `AwardType`, `Organization`, `PermissionRegistry`, `OrganizationProvisioner` and
  `DataExportCatalogue` (the awards dataset adds the five tables).

### Task 7: Recognition workflows

**Files:**
- Create in `app/Support/Recognition/`:
  - `PointsLedger`;
  - `NominationWorkflow`;
  - `KudosWorkflow`;
  - `RewardWorkflow`;
  - `RecognitionException`.
- Modify `AwardWorkflow`: post points on give, revise and remove; notify the recipient on give.

**Interfaces:**
- `PointsLedger`:
  - `balance(Employee): int`;
  - `post(Employee, int $amount, string $kind, ?Model $subject, ?string $note, ?User $by): PointTransaction`;
  - `kudosLeftThisMonth(Employee): int`.
- `NominationWorkflow`:
  - `nominate(Employee $nominee, AwardType, string $reason, User $by): AwardNomination`;
  - `withdraw(AwardNomination, User)`;
  - `approve(AwardNomination, User $reviewer, ?string $citation, ?string $awardedOn): EmployeeAward`;
  - `reject(AwardNomination, User, ?string $note)`.
- `KudosWorkflow`:
  - `send(Employee $from, Employee $to, string $message): Kudos`;
  - `remove(Kudos, User)`.
- `RewardWorkflow`:
  - `redeem(Employee, Reward, ?string $note): RewardRedemption`;
  - `cancel(RewardRedemption, Employee)`;
  - `fulfil(RewardRedemption, User, ?string $note)`;
  - `decline(RewardRedemption, User, ?string $note)`;
  - `adjust(Employee, int, string $note, User)`.

- [ ] Tests (`tests/Feature/Recognition/*`):
  - nominating yourself is refused;
  - a duplicate pending nomination is refused;
  - a type closed to nominations is refused;
  - approve creates the award and credits points, and the nominator and recipient are notified;
  - the reviewer cannot be the nominator;
  - kudos credits points up to the monthly limit, then zero;
  - removing an award or kudos reverses its points;
  - a double redeem is refused (RF 1);
  - out of stock is refused;
  - cancel or decline refunds and restocks;
  - HR cannot handle their own redemption;
  - tenant isolation.

### Task 8: Recognition controllers, routes and API

**Files:**
- Create:
  - `Recognition/RecognitionController` (wall, rewards, nominations — self-service), and
    `KudosController`, `NominationController` (self), `RedemptionController` (self);
  - `Awards/NominationReviewController`, `Awards/AwardShortlistController` (renamed board),
    `Awards/RewardController` (catalogue, redemptions, settings, adjust);
  - the `Api/Recognition*` controllers;
  - the requests.
- Modify `routes/awards.php` (shortlist, nominations queue, rewards, and — spec §6 — the
  participant routes under `/awards`),
  `routes/api.php`, `AwardTypeRequest`, `AwardTypeWorkflow`, the Award Types resource and page.

### Task 9: Recognition web screens

**Files:**
- Create `pages/awards/{wall,points,my-nominations}.tsx`, `pages/awards/{shortlist,rewards}.tsx`,
  the rewritten `pages/awards/nominations.tsx` (review queue), and `features/recognition/*`.
- Modify `pages/awards/index.tsx` (section nav), the award-types form (points, open to
  nominations), `app-navigation.ts`, `dashboard.tsx`.
- Revision (spec §6): a shared `components/module-nav.tsx` (Leave's segmented control,
  now used by Leave too), `EventsNav`, `AwardsNav` and `NominationViews`.

### Task 10: Mobile — events and recognition

**Files:**
- Create:
  - `app/events/{index,[id]}.tsx`;
  - `app/recognition/{index,kudos,nominate,nominations}.tsx`;
  - `app/rewards/index.tsx`;
  - `features/events/api.ts`, `features/recognition/api.ts`.
- Modify `app/_layout.tsx` (stack screens), `app/(tabs)/index.tsx` (shortcuts, Up next),
  `profile.tsx`, `types/api.ts`, `components/ui/icon.tsx` (new icons if needed).

- [ ] Check in the web build at 390×844.

### Task 11: Assistant, guide, help, docs, seed

**Files:**
- Modify:
  - `EventsModule`, `AwardsModule` (new tools per spec §4), `ToolRouter` keywords, `SystemGuide`
    (my-events, rooms, recognition), `HelpCenter` and new help articles;
  - `DatabaseSeeder` demo data;
  - docs: ADR 0070 + 0071, `modules/events.md`, `modules/awards.md`, `modules/mobile-app.md`,
    `modules/notifications.md`, `database/erd.md`, `not-yet-built.md`;
  - changelog entries 02–04 and commit messages.

- [ ] Assistant tests with a stubbed model; the full Pest suite; Pint; frontend checks.
