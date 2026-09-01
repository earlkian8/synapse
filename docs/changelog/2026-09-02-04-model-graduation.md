# Model Graduation: say where the model came from, and refuse to retrain early

Adds a new Analytics surface, **Model Graduation**, that makes the provenance of
the two predictive models explicit — they are trained on a general public dataset
whose `city_tier` column is the Indian metropolitan classification, not on the
deploying organisation's records — and tracks the seven conditions under which a
locally trained model would become possible. It refuses to retrain until every
one of them holds, and says why.

Frontend-only, following the Attrition Risk precedent: no controller, no
migration, no permission, no retraining job. See
[ADR 0031](../decisions/0031-model-graduation-frontend-only.md) and the
[module doc](../modules/model-graduation.md).

## Highlights

- **The refusal leads.** The verdict panel opens with "Retraining locked" and a
  `2/7` count rather than with progress, then names the **single requirement
  furthest from satisfied** and projects when it would be met at the observed rate
  — currently promotion outcomes at 14 of 120, around 2042. A closed gate is the
  system working, so the palette stays neutral and teal rather than reaching for a
  destructive red.
- **A lifecycle rail** — Provisional → Collecting → Graduated — with the gate
  drawn closed on the connector into the final stage. Order carries real meaning
  here (a model cannot graduate before it has collected), so it is a genuine
  sequence rather than decorative numbering.
- **Every threshold is defended.** Each of the seven requirements opens a dialog
  giving its statistical justification and where the count comes from. "120
  promotions" is not a magic number on screen — it is 10–20 recorded outcomes per
  input across the promotion model's 12 inputs.
- **One requirement documents a tension in our own design.** Appraisal frameworks
  are configurable per tenant (ADR 0028), which is right for the product but means
  scores are not automatically comparable across cycles — so "consecutive cycles on
  one appraisal form" is a requirement rather than an assumption.
- **A now/after comparison** of the provisional and local models, so the payoff of
  graduating is concrete: the difference is not accuracy, it is whose workforce the
  predictions describe.

## Frontend

- **New feature folder** `resources/js/features/model-graduation/` — `types.ts`,
  `constants.ts` (stage/status/group vocabularies, progress and date formatters),
  `mock-engine.ts`, `api.ts`, and five components (`demo-banner`, `stage-rail`,
  `gate-verdict`, `model-summary`, `requirement-ledger`, `requirement-dialog`).
- **`mock-engine.ts`** derives all seven requirements from three counters, so every
  number on the page stays consistent with every other — held-out rows follow from
  review cycles, outcome balance follows from promotion count. Checks persist to
  `localStorage` (`synapse:model-graduation:checks`, capped at 10) and are read
  through `useSyncExternalStore`, matching the Attrition Risk demo: the server
  snapshot returns the empty array so the SSR pass and first client render agree
  and no hydration mismatch is possible.
- **`stage-rail.tsx`** renders one rail that runs vertically on mobile and
  horizontally from `md` up, with the gate badge centred on its connector in both
  orientations.
- **New page** `resources/js/pages/analytics/model-graduation.tsx`.
- **Sidebar** — a new Analytics & AI entry using `Milestone` (`GraduationCap` was
  already taken by Training & Development).

## Backend

- **`routes/analytics.php`** — one line:
  `Route::inertia('model-graduation', 'analytics/model-graduation')`, ungated like
  Attrition Risk. The file's header comment now names both frontend-only surfaces.

## Notes

- **Only the counts are simulated.** The demo banner separates the two: record
  counts are generated in the browser, while the thresholds and the reasoning for
  each are the real ones. The page is a specification of the gate a real
  implementation would enforce.
- **Promotion Readiness and Performance Forecast are untouched** — same
  controllers, permissions and FastAPI inference service.
- Verified in a real browser (Chromium, compiled assets) in light and dark themes
  at 1440px and 390px, plus the requirement dialog; no console errors.
