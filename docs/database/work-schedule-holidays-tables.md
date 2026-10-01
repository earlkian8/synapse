# Database: work schedule & holiday tables

The tables behind the [Work Schedule & Holidays module](../modules/work-schedule-holidays.md).
Both are tenant-scoped — a non-null `organization_id` FK (ADR 0005), omitted below for
brevity.

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
| `date` | date | Indexed. For a recurring holiday only the month/day matter. |
| `type` | string | `regular` / `special_non_working` / `special_working`. Indexed. The first two are non-working. |
| `is_recurring` | boolean | Repeats every year on the same month/day. Default false. |
| timestamps + `deleted_at` | | Soft delete. |

`App\Support\HolidayCalendar` reads the **non-working** types (`regular` +
`special_non_working`) and expands recurring rows onto the queried year(s); `LeaveCalculator`
uses that set so a holiday is not charged as a leave day.
