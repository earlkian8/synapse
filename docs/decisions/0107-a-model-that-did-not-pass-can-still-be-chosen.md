# 0107 — A model that did not pass can still be chosen

- **Status:** Accepted
- **Date:** 2026-11-17
- **Amends:** [0046 — Model graduation](./0046-model-graduation-trains-on-the-organisations-own-records.md)
  ("stored only when it passes")
- **Related:** [0106 — AI explains each prediction in plain words](./0106-ai-explains-each-prediction-in-plain-words.md)
  (the reading that informs the choice); module doc: [Model graduation](../modules/model-graduation.md).

## Context

Under ADR 0046 a model trained on the organisation's own records was stored, and could
be switched to, only when it passed its check. It had to come out ahead of the general
model, and of knowing nothing, in at least 90 % of 1,000 re-samples of the
organisation's people. A model that fell short was discarded, and the organisation was
told to wait for more history.

The organisation, not the system, is accountable for whose model scores its people.
It may have reasons the check cannot see. Its model may be close behind and learn from
signals only its records hold, or it may prefer a model of its own people to one learned
from a survey of other workplaces. Discarding the model took that decision away.

## Decision

**The check recommends; it no longer decides.**

- **The service keeps a model whatever the verdict.** `synapse_ml.local.training.train`
  fits the model on every example after the check, passed or not, and `/train` stores it
  with `verdict` in its `metrics.json`. A model that cannot be fitted at all is still not
  stored. Example: a promotion model whose calibration would run backwards. The
  calibration guard stays.
- **The verdict and the lifecycle are separate.** `local_models.passed` holds the
  verdict. `status` keeps the lifecycle: `ready` means stored and waiting for a decision,
  passed or not, and `failed` now means only that nothing could be stored. Existing rows
  are backfilled (`passed = status <> 'failed'`). The newest stored model is the one on
  offer.
- **Choosing a model that did not pass is deliberate.**
  - `activate` refuses it unless the request carries `despite_check`
    (`ActivateLocalModelRequest`).
  - The confirmation says plainly what was not shown, and the button stays disabled until
    "I understand it didn't pass its check, and I want … to use it anyway" is ticked.
  - The activity log ends "though it did not pass its check".
  - Through the assistant, the switch is held for confirmation as before. Its consequence
    begins "This model did NOT pass its check", so confirming is the acknowledgement.
- **Keeping the current model is a recorded choice too.** **Keep the general model**
  (or *your model in use*) retires the model on offer through
  `POST …/graduation/{localModel}/dismiss`, logged as `dismissed`. The page then stops
  asking. A model that passed gets the same choice beside *Switch to this model*.
- **The words follow.**
  - The model in use says when it did not pass: "It didn't pass its check against the
    general model, and was chosen anyway."
  - The stage copy says "chosen by your organisation" instead of "which proved more
    accurate".
  - The explainer says the model is *recommended* only if it proves better.
  - Runs scored by such a model tell the AI reading (ADR 0106) so, and it names it as a
    caveat.

## Consequences

- An organisation can use its own model as soon as its records meet the requirements,
  and the decision, the warning and who chose it are on record.
- Scores from a model that did not pass can look as confident as any other. The panel,
  the confirmation, the strip and the AI reading all say so, but the page cannot stop a
  reader from over-trusting them.
- More models are stored per organisation under `artifacts/local/`: one per training
  attempt that could be fitted, as before for passing ones. Retired models stay on disk,
  as they did.
- The requirements gate is unchanged: below the minimums the service still refuses to
  train, because the check itself would be meaningless.
