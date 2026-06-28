# Dashboard

The home dashboard (`/dashboard`) is the landing surface once a workspace is chosen.
It is a single, **permission-aware overview** of the active organisation — headline
numbers, hand-drawn charts, a consolidated action queue, and the recent activity feed
— not a per-module report. Everything is tenant-scoped through the usual
`OrganizationScope`; nothing here is new persistence.

## Backend

| Piece | File | Role |
| --- | --- | --- |
| `DashboardController` | `app/Http/Controllers/DashboardController.php` | Thin: renders the `dashboard` page with the overview payload. |
| `DashboardOverview` | `app/Queries/DashboardOverview.php` | Composes the payload for the signed-in user. |

`DashboardOverview::for(User)` **reuses the per-module `*Statistics` classes**
(`EmployeeStatistics`, `LeaveStatistics`, `AttendanceStatistics`,
`RecruitmentStatistics`, `OnboardingStatistics`, `OffboardingStatistics`,
`DepartmentStatistics`) so each module's headline numbers have one source of truth,
and adds only the cross-cutting shape the overview needs:

- **workforce** — composition by employment type, the busiest departments, headcount.
- **attendance** — today's board plus a 14-day present-count trend (`attendance_records`
  grouped by `work_date`, weekends filled as zero so the weekly cadence shows).
- **attention** — a consolidated queue of items the viewer may act on *and* that
  currently need it (pending leave, attendance approvals, overdue onboarding tasks,
  flagged clearance, upcoming interviews); empty rows are dropped.
- **events** / **activity** — the next few events and the latest audit-trail entries.

**Permission gating is server-side.** Each block is computed only when the viewer holds
the relevant `*.view` permission and is otherwise returned as `null`; management actions
in the attention queue are additionally gated on the matching `*.manage` permission. A
regular employee therefore receives an empty overview rather than figures they can't
see — the front-end shows them a small quick-actions panel instead.

## Frontend

`resources/js/pages/dashboard.tsx` + the `features/dashboard/` folder
(`types.ts`, `lib.ts`, `components/`). The page renders whatever blocks are present into
a CSS-multicolumn masonry, with a staggered reveal that respects
`prefers-reduced-motion`.

- **Hero** (`dashboard-hero.tsx`) — the signature surface: a deep-navy band with the
  brand's faint "synapse" node field and a live pulse strip (active staff, in today,
  leave to review, open roles). The one bold element; every panel around it stays light.
- **Charts** (`components/charts.tsx`) — pure inline SVG, matching the app's existing
  `Sparkline` / `TrajectoryChart` idiom (no chart dependency): a `Donut` (workforce
  composition), a `TrendArea` (attendance), and a `BarList` (recruitment funnel,
  headcount by department). All draw in on mount.
- **Panels** (`components/panels.tsx`) — each section composes a chart with its numbers
  inside a shared `SectionCard`, links through to the owning module, and has its own
  empty state.

## Extending it

Add a block by giving `DashboardOverview` a new permission-gated key (reuse the module's
`*Statistics` class if one exists), then render a panel for it and push it into the
`panels` array in priority order. Keep the masonry happy: panels are self-contained and
make no assumptions about their neighbours.
