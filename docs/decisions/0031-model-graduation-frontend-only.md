# 0031 — Model graduation panels, embedded per surface

- **Status:** Accepted
- **Date:** 2026-09-02
- **Related:** [Promotion Readiness](../modules/promotion-readiness.md),
  [Performance Forecast](../modules/performance-forecast.md),
  [Attrition Risk](../modules/attrition-risk.md),
  [0017 — Predictive Analytics & ML inference](./0017-predictive-analytics-and-ml-inference.md),
  [0028 — Appraisal frameworks and tenant rating models](./0028-appraisal-frameworks-and-tenant-rating-models.md),
  [0030 — Attrition Risk becomes a frontend-only demo surface](./0030-attrition-risk-frontend-only.md).

## Context

Promotion Readiness and Performance Forecast are served by models trained on
`employee_promotion_prediction.csv` — a general public dataset whose `city_tier`
column (`Tier1` / `Tier2` / `Tier3`) is the Indian metropolitan classification.
The dataset is not Philippine, and the deployment target is a Philippine
institution. The objection follows: the models do not describe the workforce they
are scoring. Attrition Risk is further out still — it has no model at all
(ADR 0030).

The obvious fix — let each organisation retrain on its own records — does not
survive arithmetic. A model of this kind needs roughly 10–20 recorded outcomes
per input; the promotion model uses 12 inputs, so it needs on the order of 120
promotions. An organisation of ~50 people generates perhaps 7 a year. The
performance regressor needs consecutive-cycle comparisons, and the demo tenant
has two review cycles in total. Attrition needs recorded departures, which a
stable organisation produces slowest of all.

The failure mode matters more than the delay. A model fitted to fourteen examples
still returns probabilities, still ranks employees, still renders a confident
dashboard. Nothing in the UI would look wrong. Shipping a retrain button that
works on thin data would launder noise into something HR acts on.

## Decision

**Ship the lifecycle and the gate; do not ship retraining. Put each surface's gate
inside that surface, not in a page of its own.**

An earlier draft of this work was a standalone `/analytics/model-graduation`
page with its own sidebar entry. That was wrong: graduation is not a workspace
someone visits, it is a property *of* a prediction. The question it answers —
*whose workforce does this score actually describe?* — is only asked while
looking at the scores, so the answer belongs beside them.

Each of the three analytics surfaces therefore embeds a **`ModelProvenance`
panel** directly beneath its own header:

- **Collapsed**, it states the provenance in one line and carries the stage badge
  and an `n of m met` count. That is the whole message for a reader who wants
  nothing more.
- **Expanded**, it shows the three-stage lifecycle (`provisional` → `collecting`
  → `graduated`) with the retraining gate drawn closed, the single requirement
  furthest from satisfied, and the full requirement ledger. Each requirement opens
  a drill-down carrying its statistical justification.

**Requirements are per surface, because the surfaces learn different things:**

| | Promotion Readiness | Performance Forecast | Attrition Risk |
|---|---|---|---|
| Learns from | who was promoted | this cycle's rating vs. the last | who left |
| Headline requirement | 120 promotions on record | 200 cycle-to-cycle comparisons | 80 departures on record |
| Distinctive requirement | outcome balance across promoted / not promoted | 30 people with three or more appraisals — two points cannot show a trajectory | every departure carrying a reason, since a resignation and a redundancy are opposite events |
| Current stage | `collecting` | `collecting` | `provisional` |

Attrition's stage is the honest outlier. Its scores are generated in the browser
and never stored, so the prediction-to-outcome link is at zero — and that, not
elapsed time, is what blocks it. Waiting does not move that requirement, which is
why it reads `provisional` where the other two read `collecting`.

**Two rules keep the panel truthful:**

- **The blocker is chosen among requirements that can be acted on directly.**
  Held-out rows, outcome balance and framework stability are *derived* — they rise
  as records accumulate — so naming one as the blocker would point at something
  nobody can act on. They are flagged `derived` and excluded from that selection.
- **The number and the sentence explaining it always describe the same
  requirement.** Each actionable requirement carries its own `outlook`, and the
  panel renders the blocker's own.

Following ADR 0030, the whole thing is **frontend-only**: no controller, no
migration, no permission, no retraining job, no route and no sidebar entry.
Counts are fabricated by `features/model-graduation/mock-engine.ts` and persist
per surface to `localStorage`, read through `useSyncExternalStore` so the SSR pass
and first client render agree.

**Only the counts are simulated; the thresholds are real.** The panel says so, and
is in effect a specification of the gate an implementation would have to enforce.

## Consequences

- **The dataset-provenance objection has an answer that is not a claim of
  accuracy.** Each surface states which data it came from, why a local model is
  not yet possible, and what would have to be true — rather than overstating what
  the current models know.
- **The copy stays out of model internals.** Which algorithm runs and how it
  tested remain hidden, consistent with the surfaces' own `ServiceBanner` and the
  decision to stop showing HR users the model behind the predictions. The
  statistical reasoning lives one click into a drill-down, for the reader who
  wants it.
- **`framework_stability` documents a real tension in our own design.** ADR 0028
  made appraisal frameworks configurable per tenant, which is right for the
  product but means scores are not automatically comparable across cycles. The
  requirement names that cost rather than hiding it.
- **No backend surface area is added** — no tables, permissions or queue workers,
  none of which would earn their keep while the gate cannot open.
- **If retraining is ever built**, these panels are its precondition: the
  prediction-to-outcome link is the requirement that must hold from day one,
  because history cannot be reconstructed after the fact.

## Alternatives considered

- **A standalone Model Graduation page** (the first draft). Rejected: it separated
  a claim about a prediction from the prediction itself, and added a nav entry for
  something nobody would visit deliberately.
- **Ship a working retrain button.** Rejected: on current data it produces a model
  indistinguishable from noise, presented with a real model's confidence.
- **Say nothing and keep the general dataset quietly.** Rejected: the provenance
  is a genuine limitation, and a system that hides it cannot be defended.
- **Threshold recalibration instead** (fit the decision threshold and tier
  cut-offs to the tenant's own base rate). Viable on tens of examples rather than
  hundreds, and a better near-term answer than retraining — but it changes the
  *serving* path rather than provenance, so it belongs in its own ADR.
