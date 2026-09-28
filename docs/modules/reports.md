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
| `ReportParameters` | `app/Support/Reports/ReportParameters.php` | Raw filter input → a report's declared, validated params (workspace and assistant). |
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
   promotion-readiness and performance-forecast run summaries ride along as chips
   linking to their analytics surfaces. Reading the stored runs (not the live
   inference service) keeps signals available even when the model API is offline.
   (Attrition Risk isn't included — it's a frontend-only demo with no persisted,
   real data to report on; see
   [ADR 0030](../decisions/0030-attrition-risk-frontend-only.md).)
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

### Filter resolution (`ReportParameters`)

The workspace and the assistant both turn raw input into a report's params through
`ReportParameters::resolve()`:

- a **daterange** takes `start` / `end` as real YYYY-MM-DD dates, ordered if backwards;
- a **month** takes YYYY-MM;
- a **select** takes one of its declared options, by value or by label;
- a **search** is trimmed and capped at 120 characters.

Before, a select passed any string straight into the report's query. It also returns
the problems it found. The workspace ignores them, so a stale URL still opens on the
defaults. The assistant refuses instead of silently widening a filter to "all".

### The AI read, hardened

The `ReportInsights` digest carries report rows, and some of those are typed by
strangers: an applicant's name comes from the public careers page. Every value is now
cleaned to one bounded line. The prompt has a security block that treats the digest
as data and reports steering attempts, and the answer's fields come back as plain text
whatever shape the model returned. Before, a non-string `headline` threw.

## The assistant

`App\Services\Assistant\Modules\ReportsModule` runs reports from the chat
([ADR 0051](../decisions/0051-assistant-offboarding-reports-and-workspace-members.md)).
It is read-only, and it is offered to anyone who may run at least one report.

- **`run_report`:**
  - the `report` argument is an enum of the keys this user may run, and the
    permission is checked again at run time;
  - the filters are declared as the union across those reports, and one a report does
    not have is refused ("Headcount Summary has no stage filter");
  - a run returns the report's own totals, chart aggregates, ML signals, up to 10
    rows and the `/reports?…` link that reopens exactly that run;
  - the model writes the analysis itself from that, with no second model call.
- **`list_reports`** describes each report's filters and their allowed values.
- **Retrieval:** "what's our turnover this year?" reads, before the model is called,
  which reports the user can run. Where they may see it, it also reads the last
  twelve months of Workforce Movement: totals, and separations by kind and by
  department.

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
