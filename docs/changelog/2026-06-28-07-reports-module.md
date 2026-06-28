# Reports module (auditing)

Adds a **Reports** module — the system's auditing surface: parameterised, exportable
views over the active organisation's records, for reconciliation and compliance. It
introduces no new persistence; every report reads the same tenant-scoped data the rest
of the app does and reuses the canonical query classes, so the figures reconcile with
their source modules exactly. See the [reports module](../modules/reports.md).

## Highlights

- **Seven reports, one runner.** Employee Masterlist, Headcount Summary, Workforce
  Movement, Attendance Summary, Leave Ledger, Recruitment Pipeline, and the Audit Trail
  — each with its own filters, columns, totals and CSV export, all driven by one
  registry-backed contract and rendered by a single runner.
- **Faithful exports.** A report's `rows()` is the single source for the on-screen table,
  the totals *and* the CSV, so a download can never disagree with what was viewed. The
  export carries the active filters and streams the full (unpaginated) set.
- **Accuracy by reuse.** Attendance Summary is built on the canonical
  `AttendanceMonthlyReport`; Workforce Movement reads hires from `date_hired` and exits
  from completed offboarding cases; the Audit Trail reads `ActivityLog`. No figure is
  recomputed where a source of truth already exists.
- **Permission-aware availability.** The hub lists only the reports the viewer may run;
  the runner and export re-authorise against each report's own permission (404 unknown /
  403 forbidden). The sidebar entry is gated on the union of report permissions.
- **Built for an ERP.** A dense, bordered, sticky-headed register with right-aligned
  tabular numbers and tone-coded status badges; an inline filter toolbar (selects, date
  range, month, debounced search), a summary strip, server-side pagination, CSV export
  and a clean print view with a self-describing masthead.

## Backend

- **`App\Support\Reports`** — `Report` interface, `BuildsReport` trait (shared filter
  scaffolding + default summary), `ReportRegistry`, and seven report classes under
  `Reports/`.
- **`ReportController`** (`reports.index` / `reports.show` / `reports.export`) — resolves
  and re-authorises each report, normalises and validates the query string into the
  report's declared filters (bad dates fall back; a backwards range is re-ordered),
  paginates the slice for display, and streams the full set as CSV.
- **`routes/reports.php`** — `auth`+`verified`; wired into `web.php`.

## Frontend

- **`pages/reports/index.tsx`** — the grouped report catalogue.
- **`pages/reports/show.tsx`** + **`features/reports/`** — the runner: a declarative filter
  bar, the dense `ReportTable`, a summary strip, `ReportPagination`, CSV export and print.
- **Sidebar** — the existing "Reports" entry moves into the System group (alongside the
  audit tooling) and is gated on the union of report permissions.

## Notes

- No migrations; no ERD change. Verified: Pint, web `tsc` / ESLint / Prettier /
  `npm run build`. Every report was smoke-tested in Tinker against the seeded
  `dev@synapse.com` — all seven run, their summaries reconcile with the source modules
  (e.g. 24 active employees across masterlist and headcount; 94% average attendance), and
  the CSV export round-trips correctly. The Pest suite still can't run locally
  (no `pdo_sqlite`).
