# Reports become decision support (charts + ML + LLM)

Evolves the Reports module from audit tables into a **decision-support analytics
workspace** under Analytics & AI. Reports now answer *what's happening?*, *what
changed?* and *why?* — with charts, machine-learning signals and an on-demand LLM
narrative — on a single page, no navigation. Builds on the
[2026-06-28-07 reports module](./2026-06-28-07-reports-module.md); see the updated
[reports module doc](../modules/reports.md).

## Highlights

- **One workspace, no more cards.** A report rail on the left, the selected report
  inline on the right. Switching reports and changing filters are partial Inertia visits
  (`only: ['active']`) — the rail never reloads and the URL stays reproducible. The
  category-card hub and the separate report page are gone.
- **Decision-making views.** Every report now ships charts derived from its whole result
  set (status/type donuts, headcount and pipeline bars, movement mix, …), drawn with the
  dashboard's hand-rolled SVG primitives.
- **ML signals.** Workforce/attendance reports carry the latest **persisted** attrition,
  promotion-readiness and performance-forecast run summaries as chips linking to their
  analytics surfaces — available even when the live inference service is down.
- **AI insights (LLM).** On demand, Gemini reads a compact digest of the report — totals,
  chart aggregates, ML signals, a row sample — and answers in structured form: a
  headline, what's happening, what changed, why (grounded in the ML signals), and 2–4
  recommended actions. One model call per request; quota/overload degrade to a retryable
  message.
- **Moved to Analytics & AI.** The sidebar entry now sits with the predictive surfaces,
  not System.

## Backend

- **`Report` contract** gains `charts(rows, params)`; `BuildsReport` adds `donut()` /
  `bars()` / `barsFromCounts()` helpers and a no-op default. All seven reports implement
  charts.
- **`MlSignals`** reads the latest `AttritionRiskRun` / `PromotionReadinessRun` /
  `PerformanceForecastRun` summaries and exposes them per report group.
- **`ReportInsights`** wraps `GeminiClient`: builds the digest, calls the model once,
  parses strict JSON, and returns an `available`/`reason`/`retryable` result. Reuses the
  assistant's cost discipline and rate-limit handling.
- **`ReportController`** merges the old hub + runner into one `index` (catalogue + active
  report with charts/signals/pagination), adds `POST {report}/insights`, and keeps
  `export`. The old `show` route is removed.

## Frontend

- **`pages/reports/index.tsx`** — the workspace (rail + inline detail), reusing the
  existing filters/table/pagination.
- **`features/reports/`** — new `api.ts` (insights fetch), `components/report-rail.tsx`,
  `report-charts.tsx` (reusing the dashboard charts), `report-signals.tsx`, and the
  `insights-panel.tsx` decision-support centrepiece. `show.tsx` removed.

## Notes

- No migrations; no ERD change. Verified: Pint, web `tsc` / ESLint / Prettier /
  `npm run build`. Every report's charts and the ML signals were smoke-tested in Tinker
  against the seeded tenant, and a live insights generation returned correct structured
  decision support that referenced the ML signals. The Pest suite still can't run locally
  (no `pdo_sqlite`).
