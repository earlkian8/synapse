# Database: work schedule & holiday tables

The tables behind the [Work Schedule & Holidays module](../modules/work-schedule-holidays.md).
Both are tenant-scoped — a non-null `organization_id` FK (ADR 0005), omitted below for
brevity. `holiday_work_location` (ADR 0078) is a pivot between two tenant-scoped tables
and carries no `organization_id` of its own.

## `work_schedules`

A shift pattern employees are assigned to (read by Attendance). Created with the org
foundation; this module **added soft deletes** so a schedule can be archived rather than
hard-deleted.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in the UI. |
| `name` | string | |
| `start_time` | time, nullable | Stored `HH:MM:SS`; surfaced as `HH:MM`. |
| `end_time` | time, nullable | An end before the start is an overnight shift. |
| `work_days` | json, nullable | Short day names, e.g. `["Mon","Tue",…]`. Empty ⇒ Mon–Fri. |
| `grace_minutes` | smallint | Lateness grace. Default 0. |
| `required_hours` | decimal(5,2) | Expected daily hours. Default 8. |
| timestamps + `deleted_at` | | Soft delete (added by this module). |

Permanent deletion is blocked while employees are assigned; `Employee::workSchedule` is
`withTrashed` so an employee on an archived schedule still resolves it.

## `holidays`

The organisation's holiday calendar (read by Leave).

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | Addressed by hashid in the UI. |
| `name` | string | |
| `date` | date | Indexed. For a `yearly` holiday only the month/day matter. For a movable one (`easter`, `weekday`) the model stores its **next** occurrence on save; the rule decides every year. |
| `type` | string | `regular` / `special_non_working` / `special_working`. Indexed. The first two are non-working. |
| `is_recurring` | boolean | True for any repeating rule (kept in step with `recurrence` on save). Default false. |
| `recurrence` | string(16) | `none` / `yearly` / `easter` / `weekday` (ADR 0078). Default `none`; the migration set `yearly` wherever `is_recurring` was true. |
| `rule` | json, nullable | `{"offset": -2}` (days from Easter Sunday, −70…+70) or `{"month": 8, "weekday": 1, "week": -1}` (ISO weekday; week 1–4, or −1 for the last). Null unless movable. |
| timestamps + `deleted_at` | | Soft delete. |

## `holiday_work_location`

The sites a **local** holiday is kept at (ADR 0078). A holiday with no rows here is kept
everywhere; otherwise only by the people whose base site (primary work location) is one
of them.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint (PK) | |
| `holiday_id` | FK → holidays | `cascadeOnDelete`. |
| `work_location_id` | FK → work_locations | `cascadeOnDelete`. Indexed. |
| timestamps | | |

Unique `(holiday_id, work_location_id)`.

`App\Support\HolidayCalendar` reads the **non-working** types (`regular` +
`special_non_working`) and places each repeating row on the queried year(s) by its rule,
for a person's site; `LeaveCalculator` uses that set so a holiday is not charged as a leave
day.
