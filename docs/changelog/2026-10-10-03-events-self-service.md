# Events: invitees answer for themselves, with repeats, rooms, reminders and a calendar feed

Until now only HR could use Events. Invitees couldn't answer, and the link in their
notification led to a page they couldn't open. There were no repeats, no rooms and no
calendar sync, and reminders went out only when someone pressed *Remind*. See
[ADR 0070](../decisions/0070-events-answered-by-invitees-repeating-rooms-reminders-and-a-calendar-feed.md).

## Highlights

- **My invitations** (`/events/me`, web and mobile). Each invitee answers **Going,
  Maybe or Not going** themselves. For a repeating event, the answer can cover every
  later date too. Each event downloads to a calendar. **Subscribe** gives a private
  feed that Google, Outlook or Apple Calendar follows (with *Reset link*).
- **Repeating events.** Daily, weekly on chosen weekdays, or monthly, every 1–4,
  ending after a count or on a date (at most 100 dates, two years). Editing, inviting
  or archiving one date asks: *this event* or *this and the following*.
- **Rooms.** Rooms are booked by scheduling an event in them. The form shows which are
  free, and a clash names the event that holds the room. **Rooms** shows each room's
  week.
- **Automatic reminders.** Choose 10 minutes to 2 days before the start (default 1
  hour). Everyone who hasn't declined is reminded once. *Remind pending* still nudges
  people who haven't answered.
- **One way in.** Events & Meetings is a single sidebar entry for anyone who can view
  events or answer invitations. Its sections — **All events · Rooms · My invitations**
  — sit in the page header, the way Leave's do, and each shows only to people who may
  open it. Someone who only answers invitations lands on their own.

## Server

- New permission `events.respond`, back-filled to the built-in Staff, Department
  Head and HR Manager roles. Migration `2026_10_10_000000_create_event_self_service_tables`:
  - adds `rooms`, `event_series` and `calendar_feeds`;
  - adds `events.series_id`, `room_id`, `reminder_minutes` and `reminder_sent_at`;
  - adds `event_attendees.responded_at`.
- `EventWorkflow` (rewritten):
  - repeats and scopes on schedule, update, invite and archive;
  - `respondAsInvitee()`;
  - `sendDueReminders()`;
  - room checks through `RoomBooking`;
  - a moved start clears the sent reminder.
- `Recurrence`, `RoomBooking` and `EventCalendar` (every `VCALENDAR`, with `UID`,
  `SEQUENCE` and line folding).
- `events:remind`, scheduled every five minutes.
- Controllers:
  - `MyEventsController` and `RoomController`;
  - the public feed `GET /api/calendar/{token}.ics`, throttled;
  - mobile `GET/POST /api/events…` and `/api/calendar-feed`.
- `EventController@index` sends someone with only `events.respond` to `/events/me`.
- Notifications link to `/events/me?event=<hashid>`.
- The assistant gains `find_rooms`, `find_my_invitations` and `respond_to_event`. Its
  scheduling tools take room, reminder, repeat and scope.
- `SystemGuide` gains *Rooms* and *My invitations*. The router knows rooms and
  invitations.
- Data Export covers rooms and series, but not feeds, because a feed holds a bearer
  token.
- The seeder adds rooms and a Mon/Wed/Fri stand-up.

## Frontend

- Pages: `events/me.tsx` (an agenda by day with Coming up / Past 30 days tabs) and
  `events/rooms.tsx` (the week grid).
- The event form gains room, reminder and repeat fields. The scope dialog serves edit,
  invite and archive. The table shows the room and the repeat.
- The shared `components/module-nav.tsx` is the header section control, now used by
  Leave as well. `EventsNav` is built on it. On a narrow screen, it scrolls the
  current section into view.
- Sidebar: "My Events" left Main. Events & Meetings uses `permissionAny`.

## Mobile

- `app/events/index.tsx` and `app/events/[id].tsx` (answer one date or every later
  date, subscribe the calendar).
- Home gains an **Up next** card and an Events shortcut. Profile gains *My events*.

## Docs

- `modules/events.md`, `modules/mobile-app.md` and `modules/notifications.md`.
- `database/events-tables.md` and ERD §9.
- Help: *Events and meetings* is rewritten, plus a new *Answering your invitations*.
- `not-yet-built.md` keeps only write-back from Google/Outlook and room reservations
  without an event.

## Notes

- **Verified:**
  - tested as HR and as Staff in headless Chromium, at desktop and phone width;
  - the mobile screens in the Expo web build;
  - Pest (count in the commit message), Pint, tsc, ESLint, Prettier and the build.
- Calendar apps refresh a subscription on their own schedule; Google Calendar can
  take hours. The help page says so.
