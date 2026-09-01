# 0031 — Model Graduation as a frontend-only readiness gate

- **Status:** Accepted
- **Date:** 2026-09-02
- **Related:** [Model Graduation module](../modules/model-graduation.md),
  [0017 — Predictive Analytics & ML inference](./0017-predictive-analytics-and-ml-inference.md),
  [0018 — Performance Forecasting](./0018-performance-forecasting.md),
  [0030 — Attrition Risk becomes a frontend-only demo surface](./0030-attrition-risk-frontend-only.md)
  (the precedent for a demo surface with no backend).

## Context

Promotion Readiness and Performance Forecast are served by models trained on
`employee_promotion_prediction.csv` — a general public dataset whose `city_tier`
column (`Tier1` / `Tier2` / `Tier3`) is the Indian metropolitan classification.
The dataset is not Philippine, and the deployment target is a Philippine
institution. The obvious objection follows: the models do not describe the
workforce they are scoring.

The obvious fix — let each organisation retrain on its own records — does not
survive arithmetic. A logistic model needs roughly 10–20 recorded outcomes per
input; the promotion model sends 12 inputs, so it needs on the order of 120
promotions. An organisation of ~50 people generates perhaps 7 a year. That is
over a decade of accumulation before a first honest retrain, and the performance
regressor is worse off still: it needs consecutive-cycle pairs, and the demo
tenant has two review cycles in total.

The failure mode matters more than the delay. A model fitted to fourteen examples
still returns probabilities, still ranks employees, still renders a confident
dashboard. Nothing in the UI would look wrong. Shipping a retrain button that
works on thin data would launder noise into something HR acts on — the opposite
of what the predictive surfaces are for.

## Decision

**Ship the lifecycle and the gate; do not ship retraining.**

A new Analytics surface, **Model Graduation**, makes the model's provenance and
its path to legitimacy explicit:

- **Three stages.** `provisional` (scoring on the general dataset) →
  `collecting` (still provisional, but recording each prediction against what
  actually happened) → `graduated` (retrained on the organisation's own records).
- **Seven requirements**, grouped as *enough data* (promotion outcomes, completed
  review cycles, held-out test rows), *trustworthy data* (outcome balance,
  appraisal-framework stability, prediction-to-outcome linkage) and *system
  readiness* (per-organisation model storage). Each carries the statistical
  justification for its own threshold, surfaced in a drill-down dialog.
- **A binding constraint.** The requirement furthest from satisfied is named
  explicitly, with a straight-line projection of when it would be met at the
  observed rate — currently promotion outcomes, around 2042.
- **The refusal is the hero.** The verdict panel leads with "Retraining locked"
  rather than with progress, and uses the neutral/teal palette rather than a
  destructive red: a closed gate is the system behaving correctly, not an error.

Following ADR 0030, the surface is **frontend-only**: no controller, no
migration, no permission, no retraining job. The route is a bare
`Route::inertia('model-graduation', 'analytics/model-graduation')`, ungated like
Attrition Risk, Dashboard and Reports. Record counts are fabricated by
`features/model-graduation/mock-engine.ts` and checks persist to `localStorage`,
read through `useSyncExternalStore` so the SSR pass and first client render agree.

**The thresholds themselves are real.** Only the counts are simulated, and the
demo banner says exactly that. The page is a specification of the gate a real
implementation would have to enforce, rendered as a working surface.

## Consequences

- **The dataset-provenance objection has an answer that is not a claim of
  accuracy.** The system states which dataset it uses, why a local one is not yet
  possible, and what would have to be true — rather than overstating what the
  current models know.
- **`appraisal-framework stability` documents a real tension in our own design.**
  ADR 0028 made appraisal frameworks configurable per tenant, which is right for
  the product but means scores are not automatically comparable across cycles. The
  requirement names that cost instead of hiding it.
- **No backend surface area is added.** No tables, no permissions, no queue
  worker, no per-tenant artifact storage — none of which would earn their keep
  while the gate can never open.
- **If retraining is ever built**, this surface is its precondition: the
  prediction-to-outcome linkage requirement is the one that must hold from day
  one, because history cannot be reconstructed after the fact.

## Alternatives considered

- **Ship a working retrain button.** Rejected: on current data it produces a
  model indistinguishable from noise, presented with a real model's confidence.
- **Say nothing and keep the general dataset quietly.** Rejected: the provenance
  is a genuine limitation, and a system that hides it cannot be defended.
- **Threshold recalibration instead** (fit the decision threshold and tier
  cut-offs to the tenant's own base rate). Genuinely viable on tens of examples
  rather than hundreds, and a better near-term answer than retraining — but it is
  a change to the *serving* path, not to provenance, so it belongs in its own ADR.
