# 0047 — Workforce list pages share one table kit

- **Status:** Accepted
- **Date:** 2026-09-27
- **Related:** [Onboarding module](../modules/onboarding.md#surfaces) (the layout this
  generalises), and the Workforce module docs:
  [Employees](../modules/employees.md), [Attendance](../modules/attendance.md),
  [Leave](../modules/leave.md), [Performance](../modules/performance.md),
  [Training](../modules/training.md), [Awards](../modules/awards.md),
  [Events](../modules/events.md).

## Context

Each Workforce module had its own way of listing things. Employees used a table,
Leave used a queue of rows, Training and Events used cards grouped by status, Awards
used a feed, the nomination board used cards, and Performance used a plain list. The
header, stat tiles, toolbar, empty state and pagination were copied into every
feature folder (`employees-pagination`, `onboarding-pagination`, two `search-input`s,
…). The copies had drifted apart. Stat tiles came in three sizes. Some sortable
headers dropped the uppercase style. The current page in the pagination disappeared
in dark mode. Some rows could be clicked and others could not. Archived items sat in
a separate section with its own toggle on some pages and were missing on others.

The onboarding redesign (2026-09-27-03) settled on one compact layout: header, stat
tiles, toolbar, table, pagination. Every Workforce module was asked to follow it, in
table form.

## Decision

**Every Workforce list page is built from one kit, `components/data-table/`, and every
list on those pages is a table.**

1. **The kit** (import from `@/components/data-table`):
   - `PageBody` and `PageHeader`: title, description, optional back arrow, leading
     avatar or icon (`HeaderIcon`), badges and actions.
   - `StatTiles`: one-line tiles with a fixed set of accents (`TILE_ACCENTS`).
   - `ListToolbar`: filters on the left, Reset once any filter is set, the list's
     actions on the right. It is used with `SearchInput` and `FilterSelect`.
   - `TableCard` and `DataTable`: a card with an optional title strip, and compact
     row density applied through descendant classes.
   - `SortableHead`, `EmptyTableRow`, `RowMenuTrigger` and `rowOpens(target)`.
     `rowOpens` makes a whole row clickable and keyboard-openable (Enter), and it
     ignores clicks on links, buttons, inputs and menu items inside the row.
   - `TablePagination`, with `useClientPagination(items, resetKey)` for lists the
     server sends whole.
2. **Tables, including where cards were used before.** Training programs, events,
   awards, leave requests, leave balances, nomination types and nominees, the
   attendance exceptions and weekly grid, calibration and result spread, the
   appraisal scorecard, and event attendees and program rosters are all tables.
3. **Archived items are a status.** On Training and Events, archived items are one
   more option in the status filter ("Archived (n)"), not a separate section.
4. **The server is unchanged.** Lists that were already sent whole are paged in the
   browser (`useClientPagination`). Lists paged on the server (Employees, Onboarding)
   stay that way. No controller, route or prop changed.

## Consequences

- One fix now reaches every page. The uppercase sortable headers and the dark-mode
  current page are fixed once in the kit.
- Every row behaves the same way: the whole row opens the item, a menu sits at the
  end, and the name is still a real link where there is a page to open.
- Browser paging suits the sizes these lists reach (hundreds of rows). If a list
  grows past that, it should move to server paging like Employees, keeping the same
  `TablePagination`.
- The per-feature copies are gone. A new list page should compose the kit rather
  than add its own header, toolbar or pagination.
- Modules outside Workforce (Users, Roles, Activity Logs, Recruitment, Reports) still
  have their own copies. They can move to the kit when they are next redesigned.
