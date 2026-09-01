# Each predictive surface says where its scores came from

Adds a **`ModelProvenance`** panel to Promotion Readiness, Performance Forecast
and Attrition Risk. Each states, beneath its own header, that its scores come from
a general workforce dataset rather than from this organisation's records — and
tracks the conditions under which a locally built model would become possible,
refusing to retrain until every one of them holds.

Frontend-only, following the Attrition Risk precedent: no controller, no
migration, no permission, no retraining job, no route. See
[ADR 0031](../decisions/0031-model-graduation-frontend-only.md).

## Highlights

- **The panel lives in each module, not in a page of its own.** Graduation is a
  property *of* a prediction, not a workspace someone visits — the question it
  answers is only asked while looking at the scores, so the answer sits beside
  them. Collapsed, it is one line of provenance, a stage badge and an `n of m met`
  count; expanded, it opens into the full lifecycle and ledger.
- **Requirements are per surface, because the surfaces learn different things.**
  Promotion needs **120 promotions on record**; Performance needs **200
  cycle-to-cycle comparisons** plus **30 people with three or more appraisals** (a
  trajectory needs three points — with two, every forecast is last cycle
  restated); Attrition needs **80 recorded departures**, each carrying a reason,
  since a resignation and a redundancy are opposite events.
- **Attrition reads `provisional` where the other two read `collecting`.** Its
  scores are generated in the browser and never stored, so its
  prediction-to-outcome link sits at zero — and that, not elapsed time, is what
  blocks it. Waiting does not move that requirement.
- **The blocker is always something you can act on.** Held-out rows and outcome
  balance rise on their own as records accumulate, so they are flagged `derived`
  and excluded from the "furthest from ready" selection — naming one would point
  at something nobody can act on.
- **The number and the sentence explaining it always match.** Each actionable
  requirement carries its own outlook, and the panel renders the blocker's own —
  so "Promotions on record 14 / 120" is explained by the promotions projection,
  never by a neighbouring requirement's.
- **Every threshold is defended one click away.** "120 promotions" is not a magic
  number on screen — the drill-down gives 10–20 recorded outcomes per input across
  the 12 the readiness score uses, and where the count comes from.
- **One requirement documents a tension in our own design.** Appraisal frameworks
  are configurable per organisation (ADR 0028), which is right for the product but
  means ratings are not automatically comparable across cycles — so "cycles on one
  appraisal form" is a requirement rather than an assumption.

## Frontend

- **New feature folder** `resources/js/features/model-graduation/` — `types.ts`,
  `constants.ts` (stage/status/group vocabularies and per-surface copy),
  `mock-engine.ts`, and four components: `model-provenance`, `stage-rail`,
  `requirement-ledger`, `requirement-dialog`.
- **`mock-engine.ts`** holds a separate requirement builder per surface, each
  derived from that surface's own counters so every number stays consistent with
  every other. Checks persist per surface to `localStorage`
  (`synapse:model-graduation:<surface>`) and are read through
  `useSyncExternalStore`; the subscribe and snapshot functions are bound once per
  surface and handed back from a map, since `useSyncExternalStore` resubscribes
  whenever the subscribe identity changes.
- **`stage-rail.tsx`** renders one rail that runs vertically on mobile and
  horizontally from `md` up, with the closed gate centred on its connector in both
  orientations.
- **Wired into** `pages/analytics/{promotion-readiness,performance-forecast,attrition}.tsx`,
  each immediately below the existing banner.

## Notes

- **The visible copy stays out of model internals** — which algorithm runs and how
  it tested remain hidden, consistent with each surface's `ServiceBanner`. The
  statistical reasoning is one click into the drill-down, for the reader who wants
  it.
- **Only the record counts are simulated.** The panel says so, and the thresholds
  and their reasoning are the real ones.
- **Promotion Readiness and Performance Forecast are otherwise untouched** — same
  controllers, permissions, props and FastAPI inference service.
- Verified in a real browser (Chromium, compiled assets) across all three surfaces
  in light and dark themes, collapsed and expanded. The only console errors are
  pre-existing: seeded avatars point at `randomuser.me`, which the app's own
  `img-src` CSP blocks.
