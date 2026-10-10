# 0074 — Training: employees join programs, and programs meet in sessions with attendance

- **Status:** Accepted
- **Date:** 2026-10-11
- **Related:**
  - [0013 — Training & Development](./0013-training-and-development.md) (the module this extends);
  - [0036 — Attendance judged in local time](./0036-attendance-judged-in-local-time-on-shift-anchored-dates.md)
    (the organisation's wall clock, which sessions are on);
  - [0070 — Events answered by invitees](./0070-events-answered-by-invitees-repeating-rooms-reminders-and-a-calendar-feed.md)
    (the calendar feed sessions join, and the one-way-in navigation);
  - [0072 — Appraisal reviews and acknowledgement](./0072-appraisal-reviews-and-acknowledgement-by-the-employee.md)
    (the precedent for a `participate` permission and `ModuleNav` sections);
  - [0075 — Certificates with expiry, and budgets with costs](./0075-training-certificates-with-expiry-and-budgets-with-costs.md)
    (built alongside);
  - module doc: [Training & Development](../modules/training.md).

## Context

Training had one user: HR. An employee could not see the training on offer, could
not put themselves forward for it, and could not see their own enrollments — the
module needed `training.view`, which only HR held. A program was a date window and
nothing more: there was no record of when it met, or of who actually came.

## Decision

### Taking part is its own permission

**`training.participate`** — "Browse training, join or ask to join a program & follow
your own sessions and certificates (self-service)" — is given to the built-in Staff,
Department Head and HR Manager roles, back-filled with `PermissionSyncer::grant()` as
`performance.participate` was. Running training stays `training.view` and
`training.manage`.

### A program says who may join it

`training_programs.enrollment_mode`:

- **closed** (the default, and every existing program) — HR enrolls people;
- **open** — an employee takes a seat at once, while seats last;
- **approval** — an employee asks, and HR approves or declines.

Nobody joins a program that is over or archived, and only active employees join.

### A request to join is an enrollment row

A request is a `training_enrollments` row with status **`requested`**, decided to
**`enrolled`** or **`declined`**, with `decided_by`, `decided_at` and a
`decision_note` the person is shown. We chose this over a separate requests table
because the one-row-per-person-per-program rule already holds (a request and an
enrollment can never both exist), and HR's enroll can simply take a request in.

Every reader of the roster had to learn the difference:

- `TrainingEnrollment::ROSTER_STATUSES` (enrolled, completed, dropped) is the roster;
  `SEATED_STATUSES` (enrolled, completed) hold a seat. The `active` scope now means
  seated; `onRoster` and `pending` are new.
- Requests take no seat, are not in `analytics()`, the roster table, the exports or
  the counts, and are never graded or bulk-changed: `TrainingWorkflow::grade()`
  refuses one in words, and `bulk()` skips them.
- HR's **Enroll** turns a pending or declined request into an enrollment on the same
  row.

`TrainingSelfService` is the one path for joining, withdrawing and deciding, for the
screens and the assistant. Seats are counted under a lock on the program row, in
`join()`, `approve()` and `TrainingWorkflow::enroll()`, so two people cannot take the
last seat at once.

**Leaving** is the person's own while it costs nobody anything: a request can be
withdrawn at any time; a seat can be given up while the program is upcoming and no
attendance has been taken. After that, HR takes them off the roster.

**Who is told:** whoever holds `training.manage` when someone asks to join; the person
when HR approves, declines, or enrolls them.

### Sessions on the wall clock

`training_sessions` holds a program's sittings: `session_date`, local `starts_at` /
`ends_at` times, an optional title, location and notes. They are stored as a date and
wall-clock times, not instants, because a session is "Tuesday 9 to 12 in Manila" and
attendance is per day; `TrainingSession::startsAt()` turns them into instants on the
organisation's clock when a calendar needs them.

`TrainingSessions` holds the rules:

- a session falls inside the program's dates (an open side takes any date) and ends
  after it starts;
- adding one can repeat it daily, or on weekdays, up to a date — at most 60 at a time
  and 200 per program;
- a program's dates cannot move off a session it has (`TrainingWorkflow::update()`
  refuses, beside the date field).

A refusal is a `TrainingException` with the field it belongs to, so a form keeps what
was typed and shows the message beside that field.

### Attendance per session

`training_attendances` holds one row per session and enrollment: **present**, **late**,
**absent** or **excused**, with who marked it. Attendance is taken from the session's
day on, and only for people holding a seat. A person's rate is attended (present or
late) over counted (every mark but excused). There is no self check-in: attendance
is HR's record.

### Where it shows

- **My training** (`/training/me`) — sessions coming up as an agenda (each with an
  `.ics` download), the person's programs with attendance and score, requests waiting
  or declined with HR's note, and their certificates (ADR 0075).
- **Browse** (`/training/catalog`) — programs open to sign-ups, with seats left, the
  sessions and the certificate, and where the person stands on each.
- A program's page gains underline tabs: **Roster** (requests waiting above it, an
  attendance column), **Sessions** (schedule, edit, cancel, take attendance) and
  **Costs** (ADR 0075).
- **Schedule** (`/training/schedule`) — every session in a month, Monday first; a
  held session with no attendance is flagged, and programs running with no session
  yet are listed. On a phone it reads as an agenda.
- The calendar feed of ADR 0070 also carries the sessions of the programs a person
  holds a seat on. `EventCalendar` writes them, so there is still one place a
  `VCALENDAR` is written.

### One way in

One sidebar entry, **Training & Development**, for `training.view` *or*
`training.participate`. `TrainingNav` (`ModuleNav`) groups **My training · Browse** ‖
**Programs (requests waiting) · Schedule · Certificates · Budget**. `/training` sends
someone who only takes part to `/training/me`. The Staff dashboard gains **My
training**.

## Consequences

- Staff can open the module for the first time; everything they see is their own.
  Costs and budgets are never shown to them.
- A roster figure means the roster: anything counting enrollments goes through
  `onRoster` / `active`, or it counts requests too.
- Sessions do not book rooms. A training room is still free text; making
  `RoomBooking` aware of sessions is listed in `not-yet-built.md`.
- A program's derived status is still judged on the server's date, while sessions are
  on the organisation's clock — the existing rule, unchanged here.
- The assistant gains `find_my_training`, `find_open_trainings`, `join_training`† and
  `leave_training`† for participants, and `find_training_requests`,
  `decide_training_request`†, `find_training_sessions`, `schedule_training_session`
  and `record_training_attendance` for HR († waits for Confirm).
- Not built: rooms for sessions, session reminders, waitlists, and training on the
  mobile app.
