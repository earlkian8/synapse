# 2026-06-16 — Attendance workspace: tabs + exceptions

The attendance board was a single day's roster as a card grid or list, with the
present/late/absent statuses spent as the page's primary tabs. That answers "who's in
right now" but nothing deeper. This reworks `/attendance` into a **three-tab workspace**
over the same roster — a richer daily log, a weekly matrix and a monthly report — adds an
**exceptions panel**, and demotes status from the tab bar to a filter. It also seeds ~6
weeks of demo punches so the new surfaces actually have history
to show. No schema changes — everything derives from the existing two-table model.

## Highlights

- **Today's Log** — the daily table, enhanced: avatar + name, time in / out, computed
  hours, a **status pill** and an **anomaly flag** (late by N, missing time-out, left
  early, unscheduled absence), **sortable** by every column. Filterable by department,
  status and search.
- **Weekly View** — a matrix: rows are employees, columns Mon–Sun, each cell a colour-
  coded status tile (with the arrival time and any lateness). The one place a grid earns
  its keep — comparing a week across people. Clicking a cell jumps to that day's log.
- **Monthly Report** — one summary row per employee: present days, late count, absences,
  overtime hours and an **attendance-rate %** (banded green/amber/rose), each with an
  inline **sparkline** of the month's worked-hours rhythm.
- **Exceptions panel** — the day's problems pulled out of the table into a short,
  actionable list grouped by kind (missing time-out · late over 30m · unscheduled
  absence). Each item opens the person's day to resolve it. "All clear" when clean.

## Backend

- New queries in `app/Queries`: **`AttendanceRangeQuery`** (the shared per-employee /
  per-day matrix across any date range — saved record or synthesised day_off / on_leave /
  absent, future days left status-less), consumed by **`AttendanceWeeklyQuery`** (Mon–Sun
  grid) and **`AttendanceMonthlyReport`** (per-employee rollup + worked-minutes trend).
- `AttendanceController@index` gains a `tab` (`today` | `weekly` | `monthly`); the weekly
  and monthly datasets are tab-gated closures, so they stay cheap on the daily tab and are
  fetched on demand via Inertia partial reloads when the tab changes.
- **`AttendanceSeeder`** (registered in `DatabaseSeeder`): ~6 weeks of punches per
  employee with per-person punctuality profiles, written through the canonical
  `AttendanceClock` / `AttendanceCalculator` so seeded totals and statuses match a live
  punch. Idempotent.

## Frontend

- New components under `features/attendance/components/`: `attendance-view-tabs`,
  `today-log-table` (sortable), `exceptions-panel`, `weekly-grid`, `monthly-report-table`,
  and a small inline `sparkline`.
- `attendance-toolbar` reworked: a **period-aware** stepper (day / week / month) and a
  **status filter** (Select) on the daily tab, replacing the status tab bar and the
  board/list toggle. `use-attendance-filters` gains `setTab` + `goToDay` and scopes each
  partial reload to the active tab's prop. Types extended (`AttendanceTab`, `WeeklyView`,
  `MonthlyReport`, `GridEmployee`, …); `constants` adds `recordAnomalies`, `STATUS_TILE`.
- Removed the now-unused `attendance-card` and `attendance-row` (the card grid / list).

## Docs

- [Attendance module](../modules/attendance.md) updated for the workspace tabs, heatmap
  and exceptions.

## Notes

- Verified: `php -l` + `vendor/bin/pint` clean on all changed PHP; a tinker smoke test ran
  the weekly and monthly queries against Postgres (7-day matrix × roster; monthly rollup
  with the trend series) and the seeder produced a healthy status spread (present / late /
  undertime / incomplete + synthesised absences); `tsc`, ESLint, Prettier and
  `npm run build` all green. The Pest suite can't run on this machine (no `pdo_sqlite`).
- The daily table is intentionally kept — it's still the right tool for "what happened
  today, per person"; the heatmap, tabs and exceptions add the depth and the glance.
