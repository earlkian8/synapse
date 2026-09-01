# Attrition Risk

A **frontend-only demo** alongside the two real Predictive Workforce Analytics
surfaces, [Promotion Readiness](./promotion-readiness.md) and
[Performance Forecast](./performance-forecast.md). It shows what a flight-risk
view could look like — a fabricated roster scored 0–100 into **Stable / At watch /
High risk** tiers — entirely in the browser. There is **no server, database, or
trained model behind it.** See
[ADR 0030](../decisions/0030-attrition-risk-frontend-only.md) for why (it
supersedes the original design in
[ADR 0021](../decisions/0021-attrition-risk.md)).

> Status: **Active (demo)** · Route: `/analytics/attrition` (`Route::inertia`, no
> controller, no permission — open to any authenticated user)
> Sidebar: Analytics & AI → Attrition Risk

## What it does

- **`/analytics/attrition`** — the same surface the real design called for:
  headline metric cards (assessed, high risk, at watch, average risk), a cohort
  **risk-distribution bar**, and a **ranked roster** with search, tier filter, a
  history selector across past runs, and a per-employee **detail dialog** (score,
  tier, simulated probability, confidence, and the synthetic signals behind it).
  "Run assessment" and "Delete" both work — they just don't touch a server.

## How it works — `features/attrition-risk/mock-engine.ts`

1. **A stable roster** (~46 fabricated employees: name, department, position, and
   a baseline tenure/income/performance/overtime profile) is generated once from a
   fixed PRNG seed, so it's the same roster on every page load.
2. **"Run assessment"** scores that roster with a small, self-consistent synthetic
   logistic formula — overtime, a long stretch since the last promotion, low
   performance and thin training push risk up; tenure, recent training and higher
   pay pull it down — jittered per run so repeat assessments vary. This is
   explicitly **not** a fitted model; it makes no predictive claim.
3. The run (header stats + one score per employee, mirroring the original
   `RiskRun` / `RiskScore` shape) is written to **`localStorage`**
   (`synapse:attrition-risk:runs`, capped at 10). The page seeds one run on first
   visit so it's never empty.
4. **History and delete** read/write that same local store — no network call, no
   loading state beyond a short simulated delay kept for UX continuity with the
   original "Assessing…" spinner.

A `DemoBanner` on the page says plainly that the data is simulated and generated
in the browser; the detail dialog's copy was reworded ("simulated probability",
"simulated confidence") so nothing implies a live model or real HR data.

## Where these scores come from (model graduation)

The page embeds a **`ModelProvenance`** panel directly beneath its header, stating
in one line that there is no trained model behind this surface at all. Expanded, it shows the
three-stage lifecycle (`provisional` → `collecting` → `graduated`) with the
retraining gate drawn closed, the requirement furthest from satisfied, and the
full requirement ledger — each row opening a drill-down with the statistical
justification for its threshold.

Attrition is the honest outlier of the three. Because scores are generated in the
browser and never stored, the prediction-to-outcome link sits at **zero** — and
that, rather than elapsed time, is what blocks it. Waiting does not move that
requirement, so this surface reads `provisional` where the other two read
`collecting`. Its headline requirement is **80 recorded departures**, which a
stable organisation produces slowest of all.

The panel is **frontend-only**: counts are fabricated in the browser and persisted
to `localStorage`, and no retraining runs behind it. Only the counts are
simulated — the thresholds and their reasoning are real. Shared implementation
lives in `resources/js/features/model-graduation/`; see
[ADR 0031](../decisions/0031-model-graduation-frontend-only.md).

## What was removed

Everything server-side: `AttritionRiskController` / `AttritionRiskRunController`,
`AttritionRiskRun` / `AttritionRiskScore` models and resources,
`AttritionRiskAssessor` / `AttritionFeatureMapper`, the
`attrition_risk_runs` / `attrition_risk_scores` migration, the
`analytics.attrition.view` / `analytics.attrition.manage` permissions, the
attrition entry in `MlSignals` (Reports' ML signal chips), the `attrition` slot in
the FastAPI registry, the trained model artifact, both datasets, the training
notebook, and the attrition logs. Full accounting in
[ADR 0030](../decisions/0030-attrition-risk-frontend-only.md).

## Permissions

None. The route carries only `auth` + `verified` (like Dashboard and Reports) —
there is nothing to authorize against a fabricated, per-browser demo.
