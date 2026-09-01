# Make Attrition Risk a frontend-only demo surface

Removes the entire **Attrition Risk** backend — the trained model, its FastAPI
slot, the persisted `attrition_risk_runs` / `attrition_risk_scores` tables, the
Laravel controllers/assessor/mapper, the datasets and training notebook — and
rebuilds the `/analytics/attrition` page to generate its own illustrative roster
and scores entirely in the browser. Promotion Readiness and Performance Forecast
are untouched; they keep their full backend. See
[ADR 0030](../decisions/0030-attrition-risk-frontend-only.md) (supersedes
[ADR 0021](../decisions/0021-attrition-risk.md)).

## Removed

- **Laravel** — `AttritionRiskController`, `AttritionRiskRunController`,
  `AttritionRiskRun`, `AttritionRiskScore`, `AttritionRiskRunResource`,
  `AttritionRiskScoreResource`, `AttritionRiskAssessor`, `AttritionFeatureMapper`,
  the `attrition_risk_runs`/`attrition_risk_scores` migration, the
  `analytics.attrition.view`/`analytics.attrition.manage` permissions (and the
  Department Head role grant), `Employee::attritionRiskScores()`, and the
  attrition entry + method in `MlSignals` (Reports' ML signal chips).
- **FastAPI (`model/api`)** — the `attrition` slot in `registry.py`'s `_SPECS`.
  `main.py` still serves `promotion` and `performance` unchanged.
- **Model artifacts & data** — `model/artifacts/attrition/`, `attrition-v2.csv`,
  `employee_attrition_dataset_10000.csv` (both already git-ignored),
  `model/notebooks/01_attrition_model.ipynb`, its `build_attrition()` builder in
  `build_notebooks.py`, and the `attrition*.log` files. `model/README.md` and
  `MODEL-JUSTIFICATION.md` updated to describe two models, not three.
- **Docs** — `docs/database/attrition-risk-tables.md` deleted (the tables are
  gone); ERD §10 marked with a removal note (proposed `ATTRITION_PREDICTION`
  shape kept for reference); ADR 0021 kept as a tombstone pointing to ADR 0030.

## Rebuilt (frontend)

- **`routes/analytics.php`** — the three-route attrition group (`index`/`store`/
  `destroy`, each `can:`-gated) is replaced by one bare
  `Route::inertia('attrition', 'analytics/attrition')`. No controller, no props,
  **ungated** — any authenticated user can view it, same as Dashboard and Reports
  (added to `PageSmokeTest`'s `UNGATED` list).
- **New `features/attrition-risk/mock-engine.ts`** — a seeded PRNG fabricates a
  stable ~46-person roster once (same roster every reload); "run assessment"
  scores it with a small synthetic logistic formula (overtime, promotion cadence,
  performance, training, pay → risk) jittered per run, and persists runs to
  `localStorage` (capped at 10). `api.ts` wraps this with the original handler
  shape (`onStart`/`onFinish`/`onSuccess`) plus a short simulated delay so the
  existing spinner UX is unchanged.
- **`pages/analytics/attrition.tsx`** — no longer reads Inertia props; seeds one
  run on first visit from local state, and the history selector/delete just
  mutate that state instead of navigating. The "can manage" gate is gone (always
  interactive, since there's no real permission left to check).
- **Honesty pass** — `ServiceBanner` (FastAPI connectivity) replaced by a new
  `DemoBanner` stating the data is simulated and browser-generated; copy that
  implied a live model ("model probability of leaving", "grounded in real HR
  data", "imputed by the model") reworded to say "simulated". The now-unused
  `modelAlgorithm()` helper was dropped from `constants.ts`.
- **Deleted**: `service-banner.tsx`, the feature's `routes.ts` (no more backend
  endpoints to address), and the two Wayfinder-generated attrition action files
  (git-ignored; regenerated via `php artisan wayfinder:generate --with-form`,
  which also caught up unrelated stale generated files).

## Notes

- Verified: `php -l` on every touched PHP file; Pint `passed`. Full Pest suite
  against `staffa_test` (Postgres, no `pdo_sqlite` locally): **636/637**, the one
  failure (`UserManagementTest::it_stores_an_uploaded_profile_photo`) is a
  pre-existing local-environment gap (missing GD extension), unrelated to this
  change. `PageSmokeTest` (81 cases) green, including the new ungated behaviour.
- Frontend: `tsc --noEmit` clean project-wide, ESLint clean, Prettier clean,
  `vite build` succeeds (attrition chunk builds standalone, no dead imports).
- No data migration — following the ADR 0019 precedent, the migration file is
  deleted outright rather than reversed; any `attrition_risk_*` rows are gone on
  the next `migrate:fresh`.
