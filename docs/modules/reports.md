# Reports

The Reports module (`/reports`) is a **decision-support analytics workspace** under
Analytics & AI. It turns the data spread across modules into views that answer the
questions a manager actually has — *what's happening?*, *what changed?* and *why?* —
with charts, machine-learning signals and an on-demand LLM narrative, all exportable
for auditing. It adds **no new data**: every report reads the same tenant-scoped models
the rest of the app does (through the global `OrganizationScope`) and reuses the
canonical query classes, so a report's figures match their source module exactly.

## One workspace, one contract

The whole module is one page: a report rail on the left, the selected report rendered
inline on the right. Switching reports and changing filters are **partial Inertia
visits** (`only: ['active']`) — the rail never reloads and the URL stays a reproducible
snapshot of the view. There is no separate "report page" to navigate to.

A report is a single class implementing `App\Support\Reports\Report`. It owns its
filters, columns, rows, **charts** and summary — one source feeding the inline table,
the totals, the charts, the CSV export *and* the LLM digest, so they can never disagree.

| Piece | File | Role |
| --- | --- | --- |
| `Report` (interface) | `app/Support/Reports/Report.php` | The contract, incl. `charts()`. |
| `BuildsReport` (trait) | `app/Support/Reports/Concerns/BuildsReport.php` | Filter scaffolding + `donut()` / `bars()` chart helpers. |
| `ReportRegistry` | `app/Support/Reports/ReportRegistry.php` | Catalogue; filters to the viewer's permissions. |
| `MlSignals` | `app/Support/Reports/MlSignals.php` | Decision signals from the **persisted** ML runs. |
| `ReportInsights` | `app/Support/Reports/ReportInsights.php` | The LLM decision-support generator. |
| `ReportController` | `app/Http/Controllers/Report/ReportController.php` | Workspace, CSV `export`, AI `insights`. |

### The reports

Employee Masterlist · Headcount Summary · Workforce Movement (Workforce) · Attendance
Summary (Attendance) · Leave Ledger (Leave) · Recruitment Pipeline (Recruitment) ·
Audit Trail (System). Each is gated on an existing module `*.view` permission.

## Decision support

Three layers turn a table into a decision:

1. **Charts** — each report's `charts(rows, params)` returns donut/bar specs derived from
   the *whole* result set (not the page on screen); the runner draws them with the same
   hand-rolled SVG primitives as the dashboard.
2. **ML signals** (`MlSignals`) — for workforce/attendance reports, the latest **persisted**
   attrition, promotion-readiness and performance-forecast run summaries ride along as
   chips linking to their analytics surfaces. Reading the stored runs (not the live
   inference service) keeps signals available even when the model API is offline.
3. **AI insights** (`ReportInsights` → `GeminiClient`) — on demand, the LLM is handed a
   compact digest (totals, chart aggregates, ML signals, a row sample — never the full
   table) and answers in strict JSON: a headline, *what's happening*, *what changed*,
   *why* (leaning on the ML signals), and 2–4 recommended actions. One model call per
   request; quota/overload degrade to a friendly, retryable message rather than an error.

## Request flow

1. **Workspace** (`GET /reports`, optionally `?report=`) lists the reports the user may
   run and renders the active one inline: charts + signals over the whole set, a
   paginated slice for the table.
2. **Insights** (`POST /reports/{report}/insights`) re-resolves and **re-authorises** the
   report, re-runs it from the same filters server-side (never trusting client numbers),
   and returns the LLM result as JSON. The panel is remounted per report+filters, so a
   stale narrative is never shown against changed figures.
3. **Export** (`GET /reports/{report}/export`) streams the full result set as CSV,
   carrying the active filters so the file matches the screen.

Filters are declarative, so the runner renders any report without bespoke code, and every
control patches the URL and re-fetches.

## Adding a report

1. Create a class under `app/Support/Reports/Reports/` implementing `Report` (use
   `BuildsReport`). Reuse an existing `*Statistics`/query class for the figures; add
   `charts()` for its decision views.
2. Register it in `ReportRegistry::REPORTS`.
3. Point `permission()` at an existing module permission; the rail, route guard and
   sidebar gate follow from it. Add the permission to the sidebar's Reports `permissionAny`
   if it's new. ML signals attach automatically for the `Workforce`/`Attendance` groups
   (see `MlSignals::SIGNAL_GROUPS`).

The charts, CSV export, print and AI insights all work off the contract — nothing else
is needed.
