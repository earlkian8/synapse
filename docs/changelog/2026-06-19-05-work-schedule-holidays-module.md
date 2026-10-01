# Work Schedule & Holidays module

Adds the **Work Schedule & Holidays** Company-Setup screen (`/setup/schedule`): manage the
shift patterns employees follow and the organisation's holiday calendar. Follows the
two-config-surface shape of KPI setup (one index controller + a resource controller per
catalogue). Wires the existing sidebar placeholder (which 404'd). Teaches **Leave** to not
charge a holiday as a leave day — the integration the `LeaveCalculator` TODO pointed at. See
[module doc](../modules/work-schedule-holidays.md) and
[schema](../database/work-schedule-holidays-tables.md).

## Highlights

- **Work schedules, now manageable.** The existing `work_schedules` table gains soft deletes
  + a hashid and a full CRUD UI: name, hours, a Mon–Sun working-days picker, lateness grace
  and required hours, with the assigned-employee count per row. Archived schedules are kept
  (assigned employees still resolve them); permanent deletion is blocked while employees are
  assigned.
- **Holiday calendar.** A new `holidays` catalogue: a named date with a type (regular /
  special non-working / special working) and an optional yearly recurrence.
- **Leave is holiday-aware.** A leave request no longer counts a non-working holiday as a
  chargeable day — recurring holidays are honoured every year from a single row.

## Backend

- **Migration** `…_create_holidays_and_schedule_soft_deletes`: creates `holidays` (name, date,
  type, is_recurring, soft-deletes) and adds `deleted_at` to `work_schedules`. Tenant-scoped.
- **Models** `Holiday` (HasHashid, SoftDeletes, `TYPES` / `NON_WORKING_TYPES`,
  `nonWorking` / `chronological` scopes, `fallsOn()` recurrence helper) and `WorkSchedule`
  (now SoftDeletes + HasHashid); `Employee::workSchedule` is now `withTrashed`.
- **Controllers** `Setup\ScheduleSetupController` (index) + `WorkScheduleController` +
  `HolidayController` (store / update / destroy / restore / forceDelete — schedule force-delete
  blocked when employees are assigned). Thin, FormRequest-validated, activity-logged.
- **Requests / Resources** `WorkScheduleRequest` + `HolidayRequest`; `WorkScheduleResource`
  (+ `employees_count`) + `HolidayResource`.
- **Routes** under `setup/schedule/…` in `routes/setup.php`; permissions `setup.schedule.view`
  / `setup.schedule.manage` added to `PermissionRegistry`; built-in HR Manager granted both.
- **Integration** new `App\Support\HolidayCalendar::datesInRange()` (non-working holidays,
  recurrence-expanded); `LeaveCalculator::workingDays/chargeableDays` gain an optional holiday
  set (backward-compatible); wired in `LeaveRequestController` (file / edit) and the assistant
  `LeaveModule`.
- **Seeder** `HolidaySeeder` (PH statutory calendar — fixed recurring holidays + the movable
  National Heroes Day); wired into `DatabaseSeeder` and `MockSeeder`. Factory `HolidayFactory`.

## Frontend

- **Feature** `features/schedule-config` (types, routes, constants with day / holiday-type
  meta + formatters, a work-schedule form with a day-toggle picker, a holiday form, a holiday
  type badge).
- **Page** `pages/setup/schedule.tsx` — two archive-aware config sections (Work schedules,
  Holidays) mirroring the KPI setup page.
- **Sidebar** Work Schedule & Holidays placeholder gated by `setup.schedule.view`.

## Notes

- Verified: `php -l`, Pint, `tsc`, ESLint (project-wide), Prettier and `vite build` all green
  (the `setup/schedule` chunk emitted). Migration applied on Postgres; a tinker run confirmed
  the permission sync + HR-Manager grant, the `HolidaySeeder` (14 holidays), the **recurrence
  projection** (a 2026 row resolving for 2027), and that `LeaveCalculator` drops non-working
  holidays from a range (Dec 24–28 → 1 chargeable day instead of 3). Pest was **not** run
  locally (no `pdo_sqlite`).
- Out of scope this cut: per-employee schedule overrides, rotating shifts, holiday **pay**
  rules, movable-feast auto-calculation, local holidays, and the attendance `holiday`-status
  wiring (a deliberate follow-up).
