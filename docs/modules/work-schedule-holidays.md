# Work Schedule & Holidays

Two related Company-Setup catalogues on one screen (`/setup/schedule`): the **shift
templates** employees are assigned to, and the organisation's **holiday calendar**. Data model is ERD §2 (`WORK_SCHEDULE`, `HOLIDAY`); everything is
tenant-scoped (ADR 0005). It follows the two-config-surface shape of
[KPI & Evaluation Criteria](./performance.md) — one index controller plus a resource
controller per catalogue.

> Status: **Active** · Route prefix: `/setup/schedule`
> Sidebar: Company Setup → Work Schedule & Holidays (gated by `setup.schedule.view`)

Who works which of these templates on which date — the **shift roster** — is its own
Company Setup page beside this one (`/setup/roster`, Company Setup → Shift Roster, gated
by `setup.roster.view`); see [Attendance → Shift Roster](./attendance.md#shift-roster).

## Surfaces

A single page with two sections, each a card list with archive / restore /
permanently-delete (mirrors the KPI setup page):

- **Work schedules** — a shift **template** (ADR 0037): a name, how the day is judged
  (**fixed** / **flexible** / **hours only**), a **cycle** (7 for a week, anything else
  a rotation with an anchor date), lateness **grace**, and **one row per day of that
  cycle** — worked or a rest day, one or more stretches of hours (a second makes it a
  split shift; an end at or before its start crosses midnight), the day's required
  minutes, and, for a flexible schedule, the **core hours** everyone must be present
  for. The editor previews the **next 14 days** from the pattern on screen before it is
  saved. Each row shows how many employees are assigned, whether it rotates, and which
  one is the **company default**. A schedule can also name the **attendance policy** its
  days are judged by (ADR 0038); a policy that sets its own grace overrides the
  schedule's.
- **Holidays** — a named **date** with a **type** (regular / special non-working /
  special working) and an optional **yearly recurrence** (repeats on the same
  month/day). Recurring entries show a "Yearly" marker; the date renders as
  `Jan 1 · yearly` or `Jan 1, 2026`.

## Data model

- **`work_schedules`** — already created with the org foundation (read by Attendance).
  This module adds **soft deletes** + a hashid so a schedule can be **archived** (assigned
  employees keep it — every relation to it is `withTrashed`) and a UI to manage them;
  permanent deletion is blocked while employees are assigned. ADR 0037 adds `type`,
  `cycle_length_days`, `cycle_anchor_date` and `weekly_required_minutes`.
- **`work_schedule_days`** *(ADR 0037)* — one row per day of a template's cycle. See the
  [scheduling schema doc](../database/scheduling-tables.md). `start_time`, `end_time` and
  `work_days` remain as a **read-only summary** of the pattern, kept true by
  `SchedulePatternWriter` for the screens that still read them.
- **`organizations.default_work_schedule_id`** — the company's default hours, chosen with
  the star on a schedule's row.
- **`holidays`** *(new)* — `name`, `date`, `type` (`regular` / `special_non_working` /
  `special_working`), `is_recurring`, soft-deletes + hashid. See the
  [schema doc](../database/work-schedule-holidays-tables.md).

## Backend

- Controllers (`app/Http/Controllers/Setup/`): `ScheduleSetupController` (index — renders
  both catalogues + their archived sets), `WorkScheduleController` and `HolidayController`
  (store / update / destroy / restore / forceDelete). Thin, FormRequest-validated,
  activity-logged (`logName: 'company-setup'`). Both are addressed by **hashid**; restore /
  force-delete take the hashid as a string (so trashed rows resolve).
- Requests `WorkScheduleRequest` (type, cycle — an anchor date is required unless the
  cycle is a week — grace bounds, and a `days` array validated per day: segments as `H:i`
  pairs, required minutes, and core hours that a **flexible working day must state**) +
  `SetDefaultScheduleRequest` + `HolidayRequest`. Resources `WorkScheduleResource`
  (+ `employees_count`, the day pattern, and the legacy summary trimmed to `HH:MM`) +
  `HolidayResource` (+ derived `month`/`day`).
- `App\Support\Attendance\SchedulePatternWriter` is the only writer of a template's
  cycle; it also refreshes the legacy summary columns.
- **`App\Support\Setup\WorkScheduleWorkflow`** and **`HolidayWorkflow`** hold every
  write, with the audit lines. The screen and the assistant both call them:
  - a schedule and its pattern are written in one transaction;
  - permanently deleting a schedule people are on throws `WorkScheduleException`,
    which the screen shows as a warning toast;
  - the pattern checks that span the form (one row per cycle day, a working day,
    flexible core hours) are `WorkScheduleRequest::validatePattern()`, so a caller
    with no request holds a pattern to the same rules.
- `routes/setup.php` under the `schedule/…` prefix; every route gated.

### Integration — holidays make leave holiday-aware

`App\Support\HolidayCalendar::datesInRange()` resolves the **non-working** holiday dates
(`regular` + `special_non_working`) in a range, **expanding yearly-recurring holidays onto
whichever year(s) the range spans** (so one New-Year row is honoured every year).
`App\Support\LeaveCalculator` now takes an optional holiday set, so a leave request is **not
charged on a holiday** (it stays a pure date utility — the DB lookup lives in
`HolidayCalendar`). Wired into `LeaveRequestController` (file / edit) and the assistant's
`LeaveModule`. `special_working` holidays remain ordinary working days.

### Integration — attendance judges by the resolved shift and the holiday, frozen per day

[Attendance](./attendance.md) never reads a template directly. It asks
`ShiftResolver` which shift applies to a person on a date (ADR 0037) and **freezes the
answer onto the day** (ADR 0036): the resolved segments become instants in the
organisation's zone (an end at or before its start ends the next morning), and the day's
type, grace, required minutes, core window and holiday go into the record's `rules`
snapshot. `HolidayCalendar::on()` / `inRange()` return the holiday on a date of **any**
type (a non-working one wins when two share a date). A `regular` or
`special_non_working` holiday nobody worked is recorded as `holiday`; a `special_working`
holiday is an ordinary working day.

> **Editing a schedule or adding a holiday does not change days already recorded.** HR
> re-applies the current schedule from the attendance board when a past day should be
> judged by the change.

## The assistant

`App\Services\Assistant\Modules\SchedulesModule` puts the calendar and the templates in
the chat assistant ([ADR 0053](../decisions/0053-assistant-company-profile-schedules-and-attendance-policies.md)).

- **Reads** (`setup.schedule.view`):
  - `find_holidays`: the next twelve months by default, or a year, or a range of up to
    two years. A yearly holiday is placed on each year the range spans; archived ones
    on request;
  - `find_work_schedules`;
  - `get_work_schedule`: the pattern day by day, grace, weekly target, the policy it
    names, how many are assigned and working it today, and which departments default
    to it.
- **Writes** (`setup.schedule.manage`), through the workflows and the screen's rules:
  - `add_holiday` / `update_holiday`:
    - dates are `YYYY-MM-DD`;
    - a holiday of the same name on the same date is refused;
    - two holidays of one name are told apart by `on`;
    - a date already passed gets a note that recorded days keep their judgement;
  - `restore_holiday`, `restore_work_schedule`;
  - `create_work_schedule`: a weekly schedule with the same hours on each working day,
    defaulting like the editor (480 required minutes; hours-only days keep 08:00–17:00).
    A name another schedule has is refused;
  - **`update_work_schedule`, `set_default_schedule`, `archive_work_schedule` and
    `archive_holiday` always wait for Confirm.** Their cards say how many people work
    the schedule today, whom the company default reaches, or which date becomes a
    working day.
- **Never flattened:** a rotation, a split shift, different hours on different days, or
  start/end limits cannot be described by one set of hours. Their hours are refused and
  pointed to this screen; a rename, grace or policy change still works.
- **Screen-only:** permanent deletion.
- **Retrieval:** "when is the next holiday?", "what are our working hours?" carry the
  next holidays and the schedules before the model is called.

## Permissions

`setup.schedule.view` (see the catalogues) and `setup.schedule.manage` (create / edit /
archive), added to `PermissionRegistry` under **Company Setup**. Built-in **HR Manager** gets
both; Super Admin / Administrator via the all-permissions grant. The sidebar item is gated on
`setup.schedule.view`.

## Seeding

`HolidaySeeder` (in `DatabaseSeeder`) seeds the Philippine statutory calendar:
fixed regular and special non-working holidays as recurring entries, plus the movable National
Heroes Day (last Monday of August). Work schedules (Day / Night Shift) are seeded by
`OrganizationSeeder`. Idempotent.

## Out of scope (this cut)

Half-day/holiday **pay** rules, movable-feast auto-calculation (Holy Week), and
region-specific local holidays. How lateness, overtime and breaks are *judged* beyond the
three schedule types — rounding, overtime thresholds, auto-deducted breaks, night
differential — is the [attendance policy](./attendance-policies.md) (ADR 0038), set on
its own Company Setup screen.

Per-employee schedule overrides and rotating shift patterns were out of scope until
ADR 0037; both now exist (the roster and a template's cycle).
