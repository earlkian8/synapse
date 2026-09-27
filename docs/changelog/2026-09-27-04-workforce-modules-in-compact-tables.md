# Workforce modules in compact tables

Onboarding's compact layout (header, stat tiles, toolbar, table, pagination) now
covers every Workforce module:

- Employees
- Attendance
- Leave Management
- Performance Management
- Training & Development
- Awards & Recognition
- Events & Meetings

Every list on those pages is now a table, including the ones that used cards,
feeds or plain lists. The pieces now live in one shared kit,
`components/data-table/`, instead of a copy in each module. See
[ADR 0047](../decisions/0047-workforce-list-pages-share-one-table-kit.md).

## The kit (`server/resources/js/components/data-table/`)

- **`page-header.tsx`:** `PageBody`, `PageHeader` (title, description, back arrow,
  leading avatar or icon, badges, actions) and `HeaderIcon`.
- **`stat-tiles.tsx`:** `StatTiles`, which puts icon, label and value on one line, with
  a fixed set of accents (`TILE_ACCENTS`).
- **`list-toolbar.tsx`:** `ListToolbar` (filters on the left, Reset once any filter is
  set, actions on the right) and `FilterSelect`.
- **`search-input.tsx`:** `SearchInput`. It waits for typing to stop on server
  searches; with `delay={0}` it filters on every keystroke.
- **`table-card.tsx`:**
  - `TableCard`: a card with an optional title, count and actions strip.
  - `DataTable`: compact rows.
  - `SortableHead`: keeps the uppercase style and sets `aria-sort`.
  - `EmptyTableRow`, `RowMenuTrigger`, and `rowOpens(target)`. `rowOpens` makes the
    whole row clickable and opens it on Enter, without catching clicks on the row's
    own links, buttons, inputs or menu items.
- **`table-pagination.tsx`:** `TablePagination` (the current page now stays visible in
  dark mode) and `useClientPagination(items, resetKey)` for lists the server sends
  whole.

## By module

- **Employees:**
  - The directory uses the kit, and Department and Position share a column.
  - A whole row opens the profile.
  - **App access** is three tables: people waiting to join, invitations sent, and
    people not invited yet (searchable and paged, with *Invite all*).
- **Attendance:**
  - **Today's Log** has an **exceptions table** above the daily log. It has one row
    per problem with a Resolve/View button, and collapses to an "All clear" line when
    there are none.
  - The daily log has a row menu (open the day; edit or add punches).
  - **Weekly View** is a real table. A weekday header opens that day, and the legend
    sits in the card's footer.
  - **Monthly Report** sorts on every count column.
  - Each tab pages 25 rows at a time. The toolbar holds the period stepper, search,
    status and department filters, and a single **Reset**.
  - **My Attendance** has stat tiles and a paged history table beside the clock.
- **Leave:**
  - The inbox is a requests table (employee, type, dates, days, status, filed). Status
    is now a filter that opens on Pending. Pending rows can still be approved or
    rejected in one click.
  - Balances are a table with one column per leave type (remaining of entitled, with a
    meter) and an **Adjust** button per row.
- **Performance:**
  - The review-cycle picker moved into the header, and the stats are tiles.
  - **Result spread** and **calibration by department** are compact tables.
  - The appraisals table sorts by employee, framework, result and status, and is paged.
  - The scorecard is **one table**. Each weighted section is a header row, followed by
    a row per criterion (weight within the section, rating, evidence). The facts about
    the appraisal (cycle, framework, evaluator) sit on one line under the name.
- **Training:**
  - The programs are a table, and archived programs are an "Archived (n)" status.
  - A program's page has five stat tiles (seats taken, completion rate, average score,
    at risk, dropped) above the roster table. The roster keeps search, the status
    filter, sorting and bulk actions, and gains paging. A row opens the enrollment.
- **Awards:**
  - The feed is a table.
  - The nomination board is two tables: award types, then the chosen award's ranked
    nominees (`?type=`). A nominee row expands into the breakdown of their score, and
    the front-runner's opens by default.
- **Events:**
  - Events are a table, and archived events are an "Archived (n)" status.
  - The event page has stat tiles and a searchable, paged attendees table. Its
    breadcrumbs now name the event.
- **Onboarding** moved onto the kit, and its own `search-input` and pagination copies
  were deleted.

## Files

- **New:**
  - `components/data-table/*`
  - `features/leave/components/{leave-requests-table,balances-table}.tsx`
  - `features/awards/components/{awards-table,nomination-types-table,nominees-table,nomination-parts}.tsx`
  - `features/performance/components/scorecard-table.tsx`
  - `features/attendance/components/exceptions-table.tsx`
- **Rewritten:**
  - the stats, tables and toolbars of each module;
  - every Workforce page under `pages/{employees,attendance,leave,performance,training,awards,events,onboarding}`.
- **Removed:**
  - Employees: `employees-pagination`
  - Onboarding: `onboarding-pagination`, `search-input`
  - Leave: `leave-request-row`, `employee-balance-card`
  - Awards: `nomination-card`
  - Events: `event-card`, `events-toolbar`, `hooks/use-events-view`
  - Training: `program-card`, `training-toolbar`, `hooks/use-programs-view`,
    `roster-analytics`
  - Performance: `section-card`, `score-row`
  - Attendance: `exceptions-panel`, which became `exceptions-table`
- **Server:** unchanged. No controller, route, resource or prop changed.

## Verification

- **Pest:** the full suite passes (1112 tests).
- **Real browser:** headless Chromium against a freshly seeded throwaway database.
  Every Workforce page was walked, checking:
  - row clicks and row menus;
  - search, filters, Reset, sorting and paging;
  - the attendance period stepper, tabs and weekday jump;
  - leave inline approve and the review modal;
  - balance adjust;
  - saving a scorecard;
  - the training bulk bar and the enroll and enrollment modals;
  - the give-recognition modal;
  - the nomination drill-down.

  Dark mode was checked too. At phone width no page scrolls sideways: wide tables
  scroll inside their card. There were no console errors besides the known
  seeded-avatar CSP blocks.
- tsc, ESLint, Prettier and the build pass.

## Notes

- `pest --parallel` fails 9 `ShiftRosterTest` cases, before and after this change.
  The helper `nineToFive()` they call is defined in `ShiftSchedulingTest.php`, so it
  only exists when both files load in one process. The standard (non-parallel) run is
  green.
- Users, Roles, Activity Logs, Recruitment and Reports still use their own copies of
  these pieces (ADR 0047, *Consequences*).
