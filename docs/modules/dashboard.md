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

## The assistant

`App\Services\Assistant\Modules\DashboardModule` puts the dashboard in the chat
assistant. It is read-only ([ADR 0049](../decisions/0049-assistant-prompt-injection-defences.md)).

- **Every figure comes from `DashboardOverview`**, so a number in chat is the number on
  the screen, and each block is gated by the same permission the dashboard uses. The
  module is offered only to someone the dashboard shows at least one block to, so a
  regular employee does not get an empty capability.
- **Retrieval:** a question about the workspace that names nobody ("how are we doing
  today?", "what needs my attention?", "catch me up") reads the dashboard before the
  model is called. That brief carries:
  - the visible blocks;
  - the action queue;
  - the next events;
  - for those with `activity-logs.view`, the latest audit entries.

  The trigger words are matched as whole words in the user's own message.
- **Tools:**
  - `get_workspace_overview` — one block, or all;
  - `get_attention_queue`;
  - `get_recent_activity` (`activity-logs.view`) — filtered by area, capped at 15;
  - `get_attendance_trend` (`attendance.view`).

  Events are listed by the Events module's own `find_events` (see
  [Events](./events.md#the-assistant)). The dashboard used to carry a
  `list_upcoming_events` of its own; two tools for one job cost tokens on every turn
  and gave the model a choice it did not need.
- **Event times in the brief are the office's wall clock**, not UTC.

