# 0094 — Training in the app

- **Status:** Accepted
- **Date:** 2026-10-18
- **Related:**
  - [0074 — Employees join programs; sessions with attendance](./0074-training-self-enrolment-sessions-and-attendance.md)
    (the rules this puts on the phone; its "not built: training on the mobile app");
  - [0075 — Certificates with expiry, and budgets with costs](./0075-training-certificates-with-expiry-and-budgets-with-costs.md)
    (the certificates the phone shows);
  - [0077 — An exit's papers are generated as PDFs](./0077-an-exits-papers-are-generated-as-pdfs.md)
    (the short-lived signed link this reuses for a calendar file);
  - [0081 — Search finds attendance days, and the app searches one's own](./0081-search-finds-attendance-days-and-the-app-searches-ones-own.md);
  - [0083 — My schedule in the app](./0083-my-schedule-in-the-app.md) (the week sessions now sit on);
  - [0088 — The app opens its modules from Services](./0088-the-app-opens-its-modules-from-services.md)
    (the catalogue and the drawn tiles a new module joins);
  - module docs: [Mobile app](../modules/mobile-app.md), [Training & Development](../modules/training.md).

## Context

ADR 0074 let employees browse training, join or ask to join a program, follow their
sessions and see their certificates, but only on the web. Most staff use the phone. In
the app they could not see what was on offer or when their next session was, and could
not show a certificate. My schedule showed shifts, holidays and leave, but not the
afternoon spent at a training session.

## Decision

### One reading of "my training", shared by the web and the app

`App\Queries\MyTraining` reads everything the person taking part sees: their
enrollments (requests included), the sessions coming up on programs they hold a seat
on, their certificates, the programs open to sign-ups, and one program with their own
place on it. The web's `MyTrainingController` and the app's `Api\TrainingController`
are both thin callers, so the phone and the browser cannot disagree. Every change still
goes through `TrainingSelfService`, so the rules and the words of a refusal are the
web's. A change made from the app is logged with "via the app".

A program is shown to a participant as `MyTraining::listing()`: what it is, when it runs,
its seats, who can join it, and its certificate. It never includes the budget, the costs,
the request count or HR's AI notes. Browse on the web used the HR program resource,
and so sent `budget` and `ai_insights` to every participant's browser even though the
screen hid them. It now uses the same participant listing.

Browse is ordered **soonest first**, with undated programs last. That is the order
someone choosing what to join reads in. It used to be newest-first, which put next
month's programs above next week's.

### The API

All routes are behind `auth:sanctum` and `can:training.participate`, and all are self-scoped.

| Route | What |
|---|---|
| `GET /api/training` | My training: enrollments, sessions coming up (120 days), certificates |
| `GET /api/training/catalog` | Browse |
| `GET /api/training/programs/{hashid}` | One program, its sessions with my mark, my enrollment, and `join` (`join` · `ask` · `full` · null) |
| `POST …/programs/{hashid}/join` | Join, or ask to join (201) |
| `DELETE /api/training/enrollments/{id}` | Withdraw a request, or leave before the program starts |
| `POST /api/training/sessions/{id}/calendar-link` | A 10-minute signed link to the session's `.ics` |
| `GET /api/training/certificates/{id}` | One of my certificates, with its standing |

A program is visible when it takes sign-ups or the person is on it (or asked). A
program HR fills alone is a 404 to anyone not on it, as is someone else's enrollment,
session or certificate. A refusal is a 422 with the rule's own sentence. `/me` gains
`can_train`.

### A session reaches the phone's calendar through a signed link

The phone's browser carries no token. As with the Certificate of Employment (ADR 0077),
the app asks for a link signed for this session and this person that works for 10
minutes. `GET /api/documents/training-session/{session}/{user}` checks the signature,
binds the session's own company, and serves the calendar file only while the person
still holds a seat. It is served inline, so the phone offers to add the event rather
than only saving a file. Nothing new is written: `EventCalendar` writes the file, as it
does for the web's download and the feed.

### Sessions sit on My schedule

`MySchedule` gives each day a `training` list: the sessions of programs the person holds
a seat on, with the program and its link. A session is set on the organisation's wall
clock (ADR 0074). A person whose site keeps its own clock (ADR 0079) sees it at their own
hour, and on their own date when the difference crosses midnight. The week's totals gain
`training`. Training adds to a day and excuses no work.

### Search finds one's own training

`MySearch` gains a **Training** group: one's own programs by name or provider (with
where one stands, "Asked to join" for a request) and one's own certificates by name
(with their standing, renewed included). "Training" or "certificates" alone lists them.

### The screens

A new family, **Learning**, joins Services with its own hue, jade (`#2E9D57`), far
enough from Time's teal to tell apart in a grid. It has three new drawings on the
32-unit grid: a mortarboard (**My training**), books on a shelf (**Browse**) and a
sealed certificate (**Certificates**). A new module never gets a tab (ADR 0088).

- **My training** (`app/training/index.tsx`) — the next session on the screen's one
  navy card, with *Add to calendar*; then *Coming up* (an agenda by day), *Programs*
  (grouped by where one stands; the header names the status, so only the mixed
  *Finished* group carries a pill) and *Certificates*. `?tab=certificates` is the
  Certificates service.
- **Browse** (`app/training/browse.tsx`) — a card a program: its first day as a
  calendar leaf, the dates and sessions, the seats as one capsule a seat (a bar past
  30), who can join, the certificate, and one's standing. It has a search field once
  there are more than three programs, and an *All · Open to join · On approval* filter
  when both kinds are on offer.
- **A program** (`app/training/[id].tsx`) — what it is; *Your place* (a request and
  when it was made, HR's note on a declined one, attendance as figures and one square
  a held session in its mark's colour, the score, the certificate); every session with
  one's mark, or *Add to calendar* ahead of it; and the one action that applies:
  *Join program* or *Ask to join* (confirmed in a sheet), *Every seat is taken*,
  *Withdraw request* or *Leave program* (confirmed by a system alert). After a refusal
  the screen reloads, because seats may have changed.
- **A certificate** (`app/training/certificate/[id].tsx`) — the certificate drawn as
  the document it stands for: an engraved frame in jade, the holder's name in the
  system's serif (Georgia on iOS, the platform serif elsewhere; nothing is
  downloaded), and a gold seal over the frame's corner that comes down onto the sheet
  once as the screen opens. A lapsed or renewed certificate wears a stamp. Under it are
  the standing in words, the details, and *Share* as text.

The search field sits in the page's keyboard-aware scroll view (ADR 0065), so the list
stays reachable with the keyboard up. Each list refetches quietly when the person comes
back to it, so a program just joined or left is already shown as such.

## Consequences

- Staff can take part in training from the phone. HR still runs it from the web: there
  is no approving, grading or attendance-taking in the app.
- The web's Browse no longer sends a program's budget or AI notes to participants.
- My schedule's week payload gains `days[].training` and `totals.training`. An older
  app ignores both.
- A certificate's appearance in the app is the app's own drawing. The printable page is
  still the web's (`/training/certificates/{id}`), which needs a browser session. A PDF
  for the phone is not built.
- Not built: notifications pushed to the phone (the app has no push channel), a note
  to HR when asking to join, and session reminders.
