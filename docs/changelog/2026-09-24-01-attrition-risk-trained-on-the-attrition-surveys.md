# Attrition Risk, trained on the attrition surveys

Attrition Risk stops being a browser-side demo. The two attrition survey exports are
merged and cleaned into one dataset, a Random Forest is trained on it with an honest,
repeated evaluation, the inference service serves it, and the `/analytics/attrition`
page scores every active employee from their real employment, pay, promotion and
attendance records — persisted, permission-gated and activity-logged like the other two
predictive surfaces. See
[ADR 0043](../decisions/0043-attrition-risk-trained-on-the-attrition-surveys.md)
(supersedes [ADR 0030](../decisions/0030-attrition-risk-frontend-only.md)).

## Highlights

- **A servable model.** Every one of the survey's eight predictors has an ERP answer,
  and the model bands exact ERP figures with the survey's own edges — so 4.2 years and
  3 absences in 90 days score exactly like the answers "3 to 5 years" and "3 to 5 days".
- **Honest numbers.** 155 usable responses. Cross-validated ROC-AUC **0.63 ± 0.04**
  (PR-AUC 0.45 vs a 0.32 base rate), permutation test **p = 0.03**; Random Forest beat
  Logistic Regression (0.54), Gradient Boosting (0.60) and matched Extra Trees (0.63) on
  identical folds. It does **not** transfer between the two survey populations
  (0.36–0.39), which is stated on the page and in the ADR rather than hidden.
- **A "why" for a forest.** The service explains non-linear classifiers by occlusion —
  each input's effect if it were typical — so HR sees what drives each person's score,
  and only recorded facts can ever be offered as a reason.

## Model (`model/`) — restructured

The flat folder (loose modules, datasets beside the code, a builder at the root) becomes a
conventional layout. The trained models are unchanged — the attrition artifact retrained
from the new code scores 500 probe rows bit-identically, and every `metrics.json` matches.

```
api/            FastAPI inference service (python -m api, unchanged)
synapse_ml/     the library notebooks, tests and service share
  paths.py        every directory, defined once
  runs.py         logged runs + artifact persistence   (was synapse_ml.py)
  tabular.py      generic helpers for the older notebooks
  attrition/      survey.py · features.py · model.py · evaluation.py
notebooks/      the narrative only; logic lives in synapse_ml/
scripts/        build_notebooks.py                       (was at the root)
tests/          pytest
data/           raw/ · processed/ · archive/  — git-ignored; data/README.md tracked
pyproject.toml  tool config (replaces pytest.ini)
```

- **`synapse_ml.attrition`** — `survey` merges both exports, keeps consenting respondents
  and only *resigned voluntarily* vs *still employed* (retirement / contract end /
  dismissal excluded), drops double-submissions and reads "None" as zero rather than
  missing; `features` is the serving contract (units, band edges, labels, the shared
  banding preprocessor built from stock scikit-learn/NumPy callables); `model` holds the
  candidates and the chosen forest's hyperparameters; `evaluation` the repeated grouped
  CV, permutation test, cross-survey check and held-out importance that used to be
  defined inside notebook cells.
- **`notebooks/01_attrition_model.ipynb`** — cleaning report, rate-by-band EDA, a
  five-model comparison, a permutation test, a cross-survey check, out-of-fold
  curves/calibration/tiers, permutation importance, an ERP-parity assertion, and the
  persisted `attrition_model.joblib` / `metrics.json` / `feature_contract.json`. Notebooks
  are committed without outputs, like 02 and 03.
- **Data** — every dataset moves under `data/` and stays out of git: the promotion set for
  size, the surveys because they are people's responses. `data/README.md` says what goes
  where. The two datasets no model uses any more (`attrition-v2.csv`,
  `employee_attrition_dataset_10000.csv`) are parked in `data/archive/`.
- **`scripts/build_notebooks.py`** has a `main()` and no longer rewrites an unchanged
  notebook (nbformat's random cell ids made every regeneration a diff).
- **Inference service** — `attrition` classifier slot; labels and the artifacts path come
  from the library instead of being restated; explanations split into small functions:
  logit terms for the linear model, occlusion (what-if-typical, two-point floor) for any
  other classifier; `/health` surfaces `cv_roc_auc` / `cv_pr_auc` / `permutation_p_value`.
- **Tests (new, 49)** — `test_attrition_survey.py` (cleaning rules),
  `test_attrition_features.py` (band edges, vocabulary order, ERP/survey encoding parity)
  and `test_api_attrition.py` (the trained artifact through FastAPI — health,
  partial/empty records, ERP parity, extreme values, explanation hygiene, failure modes).
  `pytest` pinned in `requirements.txt`. The code passes ruff (pyflakes, pycodestyle,
  isort, bugbear, pyupgrade, simplify).
- `.gitignore` rewritten in sections; `venv/` and `.venv/` are ignored explicitly rather
  than by the interpreter's own marker file.
- The stale June IBM-dataset artifact in `artifacts/attrition/` (recorded as removed by
  ADR 0030, but still on disk) was taken out of the serving path before the new model
  was trained.

## Backend (`server/`)

- **Migration** `2026_09_24_000000_create_attrition_risk_tables` — `attrition_risk_runs`
  / `attrition_risk_scores`, plus `analytics.attrition.view` / `.manage` synced and
  granted to existing HR Managers (both) and Department Heads (view).
- `AttritionRiskRun` / `AttritionRiskScore` models, `Employee::attritionRiskScores()`,
  two resources, `AttritionRiskController` / `AttritionRiskRunController`, and gated
  routes under `/analytics/attrition`.
- `App\Support\Ml\AttritionRiskAssessor` (gather → map → score → persist, confidence =
  grounded inputs ÷ 8) and `AttritionFeatureMapper` (90-day absences, late arrivals and
  overtime via tenant-scoped `withCount` / `withSum`; no attendance features at all for
  an untracked employee).
- The page's `service.connected` means the service has the **attrition model loaded**,
  not merely that it answers.
- `PermissionRegistry`, `OrganizationProvisioner` (Department Head view), `MlSignals`
  (attrition chip back on Reports), `ReportInsights` prompt, and `MlClient`'s model union.

## Frontend (`server/resources/js/`)

- `pages/analytics/attrition.tsx` reads Inertia props again (run, history, service,
  `can.manage`) instead of the local mock store; `mock-engine.ts` and `DemoBanner` are
  deleted, `ServiceBanner` and `routes.ts` return, `api.ts` posts to the server.
- The detail dialog shows *what moves this score* (a risk-palette `FactorList`: rose
  raises, emerald lowers) and all eight inputs, with "Not on record" where a value was
  estimated. Sidebar entry gated on `analytics.attrition.view`.
- Model graduation: attrition's provenance names the survey, its stored scores satisfy
  `outcome_linkage` (stage `collecting`), and its field list moves the inputs actually
  used into **Used now**.

## Notes

- Verified: Pest on the Postgres harness **1013/1013** (7,176 assertions), including
  the new `tests/Feature/Analytics/AttritionRiskTest.php` (21), which fakes the service
  only at the HTTP boundary. `pytest` 49/49; ruff clean. Pint `passed`, `php -l` clean, `tsc`, ESLint,
  Prettier and `vite build` green.
- End to end against the development database: the real FastAPI service scored all 24
  active employees of the demo company through `AttritionRiskAssessor` (all eight inputs
  grounded, factors on every score, Reports chip produced) inside a rolled-back
  transaction, so nothing was written.
- The raw survey CSVs and the merged dataset are small and contain no names; whether to
  commit them or git-ignore them like the earlier datasets is left to the maintainer.
