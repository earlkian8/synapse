# 0070 — Events: answered by invitees, with repeats, rooms, reminders and a calendar feed

- **Status:** Accepted
- **Date:** 2026-10-10
- **Related:**
  - [0015 — Events & meetings](./0015-events-and-meetings.md) (the module this extends);
  - [0036 — Attendance judged in local time](./0036-attendance-judged-in-local-time-on-shift-anchored-dates.md)
    (the organisation's wall clock, which repeats are generated on);
  - [0050 — Assistant: training, awards and events](./0050-assistant-training-awards-and-events.md)
    (the events tools this widens);
  - [0071 — Recognition: kudos, nominations, points and rewards](./0071-recognition-kudos-nominations-points-and-rewards.md)
    (built alongside; it shares the navigation decision below);
  - module doc: [Events & Meetings](../modules/events.md).

## Context

Events had one user: HR. An invitee could not answer an invitation — HR set each reply
by hand from the attendees table — and the notification an invitee got linked to
`/events/{hashid}`, a page Staff could not open (403). There was no way to repeat an
event, no rooms (location was free text, so two meetings could claim the boardroom), no
calendar sync beyond a one-off `.ics` download, and reminders went out only when
someone pressed *Remind pending*.

## Decision

### Invitees answer for themselves

A new permission, **`events.respond`** — "See and answer your own invitations" — is
granted to the built-in Staff, Department Head and HR Manager roles. The migration
back-fills every organisation's built-in roles; custom roles are left to their owners.

An invitee answers **accepted**, **tentative** or **declined** (never back to
"invited"), only while the event is neither over nor archived; `responded_at` is
stamped. On a repeating event the answer covers this date or this and every later date
they are invited to. `EventWorkflow::respondAsInvitee()` is the one path for the web
page, the mobile API and the assistant; HR's own reply select keeps `respond()`.

Notifications now link to `/events/me?event=<hashid>`, a page every invitee can open,
and the page scrolls to that event.

### Repeats are materialised occurrences

`event_series` holds the rule — `frequency` (daily, weekly, monthly), `interval` (1–4),
`weekdays` for weekly (ISO 1–7), and either `until` or `count`. Each occurrence is an
ordinary `events` row with `series_id`, so answers, reminders, rooms, the feed, search
and the assistant work per date with no special cases. We chose this over an RRULE
expanded at read time because every other part of the module — and the HR reports —
reads `events` rows.

- Dates are generated on the organisation's wall clock: every occurrence starts at the
  same local time and lasts as long as the first. Monthly repeats keep the first date's
  day and clamp to the month's last day (31 Jan → 28 Feb → 31 Mar).
- Limits: at most 100 occurrences and an end no more than two years after the start;
  over either, the request is refused in words.
- Editing, inviting to and archiving an occurrence take a **scope**: *this event* or
  *this and the following events*. "Following" applies the content fields and moves each
  later date by the same shift in whole days, at the new time of day, so each stays on
  its own day.

### Rooms are booked by scheduling in them

`rooms` (name, location, capacity, description, active, soft deletes) and
`events.room_id`. A room needs an end time. Two live events cannot hold one room in
overlapping windows; `RoomBooking::assertBookable()` checks this in a transaction with
the room row locked, for every date of a series, and refuses with the event that holds
it ("Boardroom is taken then by “Q3 review” (Mar 4, 9:00 AM)."). A retired or archived
room takes no new bookings; an event that already holds one keeps it. Capacity below
the invite count is a warning, not a refusal.

There is no separate booking object: a room is booked by an event. A standalone
"reserve the room" with no event was not asked for and would duplicate the event form.

### Reminders run on a schedule

`events.reminder_minutes` (10, 30, 60, 120, 1440 or 2880; the form defaults to one hour)
and `events.reminder_sent_at`. `events:remind` runs every five minutes, finds live
events whose reminder is due and not sent, notifies every invitee who has not declined
and has an active account, and stamps the event. Moving the start clears the stamp, so
the reminder follows the new time. *Remind pending* stays: it nudges people who have not
answered.

### Calendar sync is a subscription feed

`calendar_feeds` holds one feed per user per organisation: a sha-256 `token_hash` to
look it up and the token encrypted so the link can be shown again. `GET
/api/calendar/{token}.ics` is public, throttled (60/min) and sessionless; it binds the
feed's tenant and serves the user's invitations (not declined, not archived) and the
events they organise, from 60 days back to 400 ahead. Each event has a stable `UID` and a `SEQUENCE`, so a calendar
app replaces it after an edit and drops it after archiving. *Reset link* issues a new
token and the old one stops working. `EventCalendar` builds every `VCALENDAR`.

Writing back from Google or Outlook is **not built**: it needs an OAuth app per
provider, and stays in `not-yet-built.md`.

### One way in: a section of the module, not a second sidebar entry

The first cut put "My Events" in the sidebar's Main group. That gave anyone who both
manages and attends two entries for one subject — and on `/events/me` both lit up.
Instead, **Events & Meetings** is one entry under Workforce, shown to whoever holds
`events.view` *or* `events.respond`. The module's sections — **All events**, **Rooms**
and **My invitations** — are a segmented control in the page header, the pattern Leave's
Requests / Balances already used (now the shared `ModuleNav`), each shown to whoever may
open it. Someone who only answers invitations lands on `/events/me` from `/events`.
Main is the Dashboard only.

## Consequences

- Staff can open the module for the first time; everything they see is their own.
- Every reply, reminder and feed reads plain `events` rows. A series is a label on
  them, so changing the rule after the fact means editing the following dates.
- The feed is a bearer link. It shows only the holder's own invitations and events, and it can be
  reset; the help page says so.
- The mobile app answers through the same workflow (`/api/events`), and the assistant
  gains `find_my_invitations`, `respond_to_event` and `find_rooms`, plus room, reminder,
  repeat and scope on the scheduling tools.
