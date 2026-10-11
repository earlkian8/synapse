# 0078 — Holidays that move, and holidays kept only at some sites

- **Status:** Accepted
- **Date:** 2026-10-11
- **Related:**
  - [0036 — Attendance judged in local time on shift-anchored dates](./0036-attendance-judged-in-local-time-on-shift-anchored-dates.md);
  - [0041 — Attendance days close themselves](./0041-attendance-days-close-themselves.md)
    (the recompute a holiday change queues);
  - [0079 — Shape fences and a clock per site](./0079-shape-fences-and-a-clock-per-site.md)
    (the site a person is based at);
  - module doc: [Work Schedule & Holidays](../modules/work-schedule-holidays.md).

## Context

A holiday was a date, repeating every year on that date or not at all. Holy Week
(Maundy Thursday, Good Friday, Black Saturday) moves with Easter, and National
Heroes Day is the last Monday of August. HR had to type each year's dates, and the
setup wizard computed them once, for the current year only.

A holiday also applied to everybody. City and provincial holidays (a charter day, a
fiesta) are kept only by the people working there. A company with a Cebu office had
to choose between giving Manila a day off and marking Cebu absent.

## Decision

### A holiday repeats by one rule

`holidays.recurrence` takes one of four values, with the rule's numbers in
`holidays.rule` (JSON):

| `recurrence` | `rule` | Example |
|---|---|---|
| `none` | — | a one-off date |
| `yearly` | — | Christmas, every Dec 25 |
| `easter` | `{offset}`: days from Easter Sunday, −70…+70 | Good Friday is −2 |
| `weekday` | `{month, weekday 1–7, week 1–4 or −1 for last}` | Heroes Day: 8, 1, −1 |

Existing rows were back-filled: `is_recurring` true became `yearly`. `is_recurring`
stays, true for any repeating rule, so code that only asks "does it repeat" keeps
working.

`App\Support\MovableDates` works out Easter (the anonymous Gregorian computus) and
the n-th weekday. `Holiday::occurrenceIn($year)` and `fallsOn($date)` answer for
any year.

A movable holiday has no date typed in. The model stores its **next** occurrence in
`date` on save, so lists that sort by date still work.

**Add Holy Week** on the holidays screen calls `HolidayWorkflow::adoptMovable` and
puts Maundy Thursday, Good Friday, Black Saturday and National Heroes Day on the
calendar as rules. One already there by name, typed in for one year, is turned into
its rule rather than doubled. The wizard and the seeder create them as rules too.

### A holiday may be kept only at some sites

The pivot `holiday_work_location` holds the sites. **No sites means everywhere.**
Otherwise a holiday applies to the people whose base site is one of them. Their base
site is their primary work location (`WorkLocation::primaryFor`), and somebody with
no site keeps only the company-wide holidays.

`HolidayCalendar::datesInRange`, `inRange` and `on` take an optional site.
`HolidayCalendar::siteOf($employee)` finds it. For many people across a range,
`HolidayCalendar::forEmployees()` returns a `HolidayMap`, which answers
`on($date, $employeeId)` from one query. Where two holidays share a day, the day-off
one wins.

Every place that asks whether a day is a holiday now asks for the person:

- leave's chargeable days, on the screen, in the app and in chat;
- the attendance engine: punch, close, recompute and re-apply;
- the roster and the attendance boards;
- reminders.

A roster cell shows a local holiday by name.

### A change re-judges the days it touches

A holiday change, including only its sites, queues a recompute for every date it
fell on before and falls on now, in every year attendance has been recorded through
next year (`AttendanceInputs::holidayChanged`). Holy Week lands on a different date
each year.

## Consequences

- Holy Week and Heroes Day are right every year without anyone typing them.
- Local holidays follow the person's base site, not where they punched. Somebody
  based in Manila who visits Cebu on its charter day works an ordinary day.
- The app gains **Holidays**: the year ahead as the person keeps it, local ones
  marked with their site.
- The assistant's `add_holiday` and `update_holiday` take the rule (`repeats`,
  `easter_offset`, `month` / `weekday` / `week`) and the sites (`sites`,
  `everywhere`). A movable holiday's date is refused rather than silently ignored.
- Not built: holiday pay rules, and holidays proclaimed each year on dates no rule
  gives (Eid'l Fitr and Eid'l Adha follow the lunar calendar and a proclamation;
  they are still entered as dates). No local holidays are seeded.
