# Reports

The Reports module (`/reports`) is the system's **auditing surface**: parameterised,
exportable views over the active organisation's records, for reconciliation and
compliance. It adds **no new data** — every report reads the same tenant-scoped models
the rest of the app does (through the global `OrganizationScope`) and reuses the
canonical query classes where one already exists, so a report's figures match their
source module exactly.

## Design: one contract, many reports, one runner

A report is a single class implementing `App\Support\Reports\Report`. That one class
owns its filters, its columns, and the exact rows behind them, which is what guarantees
the on-screen table, the totals, and the CSV export can never disagree — they all come
from the same `rows()` call.

| Piece | File | Role |
| --- | --- | --- |
| `Report` (interface) | `app/Support/Reports/Report.php` | The contract: `key/name/group/permission/filters/columns/rows/summary`. |
| `BuildsReport` (trait) | `app/Support/Reports/Concerns/BuildsReport.php` | Shared filter scaffolding (department/select/date-range/month/search) + a default summary. |
| `ReportRegistry` | `app/Support/Reports/ReportRegistry.php` | The catalogue; resolves reports via the container and filters them to the viewer's permissions. |
| Reports | `app/Support/Reports/Reports/*` | One class per report. |
| `ReportController` | `app/Http/Controllers/Report/ReportController.php` | Hub, runner (`show`) and CSV `export`. |

`rows()` returns **display-ready scalars** (dates formatted, enums labelled). That single
shape feeds both the paginated table and the export, so a download is always faithful to
what the auditor saw on screen.

### The reports

| Report | Group | Permission | Reuses |
| --- | --- | --- | --- |
| Employee Masterlist | Workforce | `employees.view` | `Employee` + `scopeSearch` |
| Headcount Summary | Workforce | `employees.view` | `Employee` / `Department` |
| Workforce Movement | Workforce | `employees.view` | `Employee` hires + completed `OffboardingCase` exits |
| Attendance Summary | Attendance | `attendance.view` | `AttendanceMonthlyReport` (the canonical monthly report) |
| Leave Ledger | Leave | `leave.view` | `LeaveRequest` |
| Recruitment Pipeline | Recruitment | `recruitment.view` | `JobApplication` |
| Audit Trail | System | `activity-logs.view` | `ActivityLog` (date-bounded, capped) |

## Request flow

1. **Hub** (`GET /reports`) lists only the reports the user may run (`ReportRegistry::forUser`).
2. **Runner** (`GET /reports/{report}`) resolves the report, **re-authorises** against its
   own permission (404 unknown, 403 forbidden), normalises the query string into the
   report's declared filters (invalid dates fall back to defaults; a backwards range is
   re-ordered), runs `rows()`, computes the summary over the **whole** set, then paginates
   the slice for display.
3. **Export** (`GET /reports/{report}/export`) runs the *same* report with the *same*
   normalised params and streams the full result set as CSV — carrying the active filters
   in the query string, so the file matches the screen.

Filters are declarative, so the runner UI (`resources/js/pages/reports/show.tsx` +
`features/reports/`) renders any report without bespoke code: selects, a date range, a
month picker and a debounced search, each patching the URL and re-fetching. Every control
is reflected in the URL, so a report view is a shareable, reproducible snapshot.

## Adding a report

1. Create a class under `app/Support/Reports/Reports/` implementing `Report` (use
   `BuildsReport` for the common filters). Reuse an existing `*Statistics` / query class
   if the figures already live somewhere — don't recompute them.
2. Register it in `ReportRegistry::REPORTS` (order = hub order).
3. Point `permission()` at an existing module permission; the hub, the route guard and the
   sidebar gate all follow from it. If the new report's permission isn't already in the
   sidebar's Reports `permissionAny`, add it there too.

Nothing else is needed — the runner, pagination, CSV export and print all work off the
contract.
