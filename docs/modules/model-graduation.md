# Model Graduation

A **frontend-only** surface that makes the provenance of the two real predictive
models explicit — [Promotion Readiness](./promotion-readiness.md) and
[Performance Forecast](./performance-forecast.md) are trained on a general public
dataset, not on the deploying organisation's own records — and tracks the
conditions under which they could honestly be retrained on local data. It refuses
to retrain until every condition holds. There is **no server, database, retraining
job or per-tenant model behind it.** See
[ADR 0031](../decisions/0031-model-graduation-frontend-only.md) for why.

> Status: **Active (demo)** · Route: `/analytics/model-graduation`
> (`Route::inertia`, no controller, no permission — open to any authenticated user)
> Sidebar: Analytics & AI → Model Graduation

## What it does

- **`/analytics/model-graduation`** — a **stage rail** (Provisional → Collecting →
  Graduated) with the retraining gate drawn closed on the connector into the final
  stage; a **verdict panel** leading with "Retraining locked" and naming the single
  requirement furthest from satisfied, with a straight-line projection of when it
  would be met; a **now/after comparison** of the provisional and local models; and
  a **requirement ledger** grouped into three categories, each row opening a dialog
  that gives the threshold's statistical justification and where the count comes
  from.
- **"Re-check readiness"** produces a new dated check, advancing the counters
  slightly so the mechanism is visible; the history selector switches between past
  checks and "Delete" removes one. None of it touches a server.

## The three stages

| Stage | Meaning |
|---|---|
| `provisional` | Scoring with the model trained on the general public dataset. Every prediction is labelled provisional. |
| `collecting` | Still scoring provisionally, but recording each prediction against what actually happened — the prerequisite for any future retrain. |
| `graduated` | Retrained on this organisation's own records. Predictions describe this workforce rather than a borrowed one. |

The stage is derived, not stored: all requirements met → `graduated`; otherwise
the prediction-to-outcome linkage requirement decides `collecting` vs
`provisional`.

## The seven requirements

**Enough data**

| Requirement | Threshold | Why |
|---|---|---|
| Promotion outcomes on record | 120 | A logistic model needs roughly 10–20 recorded outcomes per input. The promotion model sends 12 inputs. |
| Completed review cycles | 4 | Three consecutive pairs for a trend, plus one cycle held back for testing. |
| Rows reserved for testing | 25 | A fifth of the data, never trained on. Below ~25 rows the test swings on one or two people. |

**Trustworthy data**

| Requirement | Threshold | Why |
|---|---|---|
| Employees in the smaller outcome group | 30 | When one outcome is rare the model scores best by always predicting the common one. |
| Consecutive cycles on one appraisal form | 3 | Frameworks are configurable per tenant (ADR 0028), so a 4.0 on one form is not the same fact as a 4.0 on another. |
| Predictions matched to what happened | all | Must hold from day one — history cannot be reconstructed after the fact. |

**System readiness**

| Requirement | Threshold | Why |
|---|---|---|
| Per-organisation model storage | configured | A model trained on one organisation's history is that organisation's data. |

## How it works — `features/model-graduation/mock-engine.ts`

1. **Counters** (promotion outcomes, review cycles, framework stability) start
   from a fixed baseline representing a ~42-person organisation with two years of
   records.
2. **Requirements are derived from those counters**, so every number on the page
   stays consistent with every other — held-out rows follow from review cycles,
   outcome balance follows from promotion count.
3. **The binding requirement** is whichever unmet requirement has the lowest
   completion ratio; the projection extrapolates the observed promotion rate.
4. **"Re-check readiness"** advances the counters a little (promotions +0–2,
   cycles rarely) and writes a new check to **`localStorage`**
   (`synapse:model-graduation:checks`, capped at 10), read through
   `useSyncExternalStore` so the SSR pass and first client render agree.

A `DemoBanner` states plainly that the **record counts are simulated and no
retraining runs behind the page**, while the **thresholds and their reasoning are
real** — the page specifies the gate an implementation would have to enforce.

## Files

| Concern | Location |
|---|---|
| Page | `resources/js/pages/analytics/model-graduation.tsx` |
| Types / constants | `resources/js/features/model-graduation/{types,constants}.ts` |
| Simulated checks | `resources/js/features/model-graduation/mock-engine.ts` |
| Action wrappers | `resources/js/features/model-graduation/api.ts` |
| Components | `resources/js/features/model-graduation/components/` |
| Route | `routes/analytics.php` |

## Notes

- **No backend.** No controller, model, migration, permission or queue worker —
  deliberately, since the gate can never open on current data (ADR 0031).
- **The palette avoids destructive colours.** A closed gate is the system working
  correctly, so the locked state stays neutral/teal; emerald marks met
  requirements and amber marks partial ones.
- **Promotion Readiness and Performance Forecast are unaffected** — they keep
  their controllers, permissions and the FastAPI inference service.
