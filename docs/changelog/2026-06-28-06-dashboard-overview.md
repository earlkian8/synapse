# Home dashboard: a real overview

Replaces the placeholder dashboard with a **permission-aware overview** of the active
organisation — headline numbers, hand-drawn charts, a consolidated action queue, and
the recent activity feed. No new persistence: it composes the existing per-module
statistics and reads tenant-scoped data through the usual scope. See the
[dashboard module](../modules/dashboard.md).

## Highlights

- **A pulse, not a placeholder.** A deep-navy hero band (the brand's "synapse" node
  motif, continuous with the workspace picker) carries the greeting and a live strip:
  active staff, in today, leave to review, open roles.
- **Insight over decoration.** Workforce composition (donut), a 14-day attendance trend
  (area), recruitment funnel + headcount by department (bars), upcoming events, and the
  activity feed — all hand-drawn SVG, matching the app's existing chart idiom (no chart
  dependency).
- **"Needs your attention."** One queue across modules — pending leave, attendance
  approvals, overdue onboarding tasks, flagged clearance, upcoming interviews — showing
  only rows the viewer can act on and that actually need action. Empty → "all caught up".
- **Right-sized per viewer.** Every block is gated on the viewer's permissions
  server-side; a regular employee gets a small quick-actions panel instead of figures
  they aren't entitled to.

## Backend

- **`DashboardController`** (`GET /dashboard`) — replaces the old `Route::inertia`
  placeholder; renders the same `dashboard` page with the overview payload.
- **`DashboardOverview`** (`app/Queries/`) — composes the payload, reusing
  `EmployeeStatistics`, `LeaveStatistics`, `AttendanceStatistics`,
  `RecruitmentStatistics`, `OnboardingStatistics`, `OffboardingStatistics`, and
  `DepartmentStatistics`, and adding workforce composition, the busiest departments, the
  14-day attendance trend, the attention queue, upcoming events, and recent activity.
  Each block is `null` unless the viewer holds the matching `*.view` permission.

## Frontend

- **`pages/dashboard.tsx`** — renders whatever blocks are present into a CSS-multicolumn
  masonry with a staggered, reduced-motion-aware reveal, plus a quick-actions fallback
  for employees without management access.
- **`features/dashboard/`** — `types.ts`, `lib.ts` (greeting, relative time, palette),
  `components/charts.tsx` (`Donut`, `TrendArea`, `BarList`), `components/dashboard-hero.tsx`,
  and `components/panels.tsx` (one `SectionCard` per section, each linking to its module).

## Notes

- No migrations; no ERD change. Verified: Pint, web `tsc` / ESLint / Prettier /
  `npm run build`. Payload smoke-tested in Tinker against the seeded `dev@synapse.com`
  (all blocks populate; the attendance trend shows the real weekday/weekend rhythm). The
  Pest suite still can't run locally (no `pdo_sqlite`).
