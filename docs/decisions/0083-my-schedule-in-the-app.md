# 0083 — My schedule in the app

- **Status:** Accepted
- **Date:** 2026-10-12
- **Related:**
  - [0037 — Schedules are templates, assignments are dated, and a resolver decides the day's shift](./0037-schedules-are-templates-assignments-are-dated-a-resolver-decides-the-day.md)
    (the resolver and the roster this reads);
  - [0078 — Holidays that move, and holidays kept only at some sites](./0078-holidays-that-move-and-holidays-kept-at-some-sites.md);
  - [0079 — Fences drawn as shapes, and a site that keeps its own clock](./0079-shape-fences-and-a-clock-per-site.md);
  - [0082 — The app resets a forgotten password and edits one's own account](./0082-the-app-resets-a-forgotten-password-and-edits-ones-own-account.md)
    (built alongside);
  - module docs: [Mobile app](../modules/mobile-app.md),
    [Work schedule & holidays](../modules/work-schedule-holidays.md).

## Context

The app showed one shift: today's, on the Clock tab and the Home card. Nobody could see
from the phone whether they work on Saturday, whether HR moved Thursday to the late
shift, or which days next week a holiday or their own leave takes off. The roster that
answers all of that is in Company Setup on the web, behind `setup.roster.view`, which
Staff do not hold, and it shows everyone.

## Decision

### One person's week, from the roster's own sources

`GET /api/schedule?week=YYYY-MM-DD` returns the signed-in person's week, Monday to
Sunday. The week is the one containing the date, and this week when the date is left
out. Like the app's other reads it is self-scoped: no permission beyond a session, and
403 for an account with no employee record. Nobody else's week can be asked for.

`App\Queries\MySchedule` builds it from what the roster board uses:

- `ShiftResolver::forMany()`, so each day's shift follows the same precedence (roster
  override, dated assignment, the employee's, the department's, the site's, the
  company's, the fallback). It is read on the person's clock: their base site's when it
  keeps its own zone, the company's otherwise.
- `HolidayCalendar::forEmployees()`, so a holiday kept only at their site shows, and
  one kept at another site does not.
- **Their own leave**, approved or waiting, on the days it would be worked. A rest day
  inside a leave range does not show it. An approved request wins a day it shares with
  a waiting one.

Each day gives its hours (segments, an hours-only day's minutes, a flexible day's core
hours), the schedule's name, whether **HR changed it for that date**, the holiday, the
leave, and whether work is **due**. Work is due on a working day that no day-off
holiday or whole day of approved leave excuses. Approved half a day, it is due for half
the hours. Waiting leave excuses nothing yet. The week totals the due shifts and hours.

### What it leaves out

Where in the precedence chain a shift came from, and the **reason** HR wrote on an
override, are not sent. A changed day says *Changed*. HR's note is written for the
roster, not for the employee, and may say more than they should read.

### The screen

`app/schedule/index.tsx`, **My schedule**, opens from Profile, from the shift row on
the Clock tab, and from Search. It follows the Attendance tab:

- a week switcher with *Today* and chevrons (the chevrons are now one `StepButton` in
  the UI kit, shared with Attendance's month);
- swiping sideways turns the week;
- the week's shift count and hours under the dates;
- one inset grouped list of the seven days, each with its date block, its hours or what
  it is instead (a holiday's name, the leave type, *Rest day*), and a pill for what sets
  it apart (*Holiday*, *On leave*, *Half day*, *Pending*, *Changed*).

Today's date sits on a filled disc. A day that has started opens its attendance record,
so the plan and what happened are one tap apart. Times are on the person's clock, with a
note when their site keeps its own.

## Consequences

- Employees see their coming weeks, and what HR changed, without asking.
- The phone and the roster cannot disagree. Both ask the same resolver and calendar.
- Not built: a whole month at a glance, a team's schedule, and asking HR to change a
  shift. The roster stays HR's to set
  ([ADR 0042](./0042-no-attendance-requests-or-periods-the-roster-is-setup.md)).
