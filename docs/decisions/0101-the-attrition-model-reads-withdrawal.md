# 0101 — The attrition model reads withdrawal, a behavioural indicator of emotional disengagement

- **Status:** Accepted
- **Date:** 2026-11-05
- **Related:**
  - [0043 — Attrition risk trained on the attrition surveys](./0043-attrition-risk-trained-on-the-attrition-surveys.md)
    (the survey and its feature contract);
  - [0098 — The predictive screens say why](./0098-the-predictive-screens-say-why.md)
    (the Shapley explanations an indicator becomes one reason in);
  - [0099 — Each breakdown is a decision brief](./0099-each-breakdown-is-a-decision-brief.md)
    (the Reasons tab and next steps the indicators join);
  - module docs: [Attrition Risk](../modules/attrition-risk.md).

## Context

The attrition model read only structural and numeric facts: employment type, tenure,
pay, promotion history and three attendance counts. Reviewers of the capstone said it
lacked an emotional factor. Feelings drive resignations, and the model had nothing that
stood for one.

The survey the model learned from asked nothing about feelings, and there is no time to
run another. Two shortcuts were ruled out:

- **Merging emotional columns from an online dataset** (e.g. IBM HR Analytics). Our
  respondents are not in it, so a "merge" invents values for real people. The findings
  would describe another population, from another country, on other scales — and the
  IBM dataset is itself fictional.
- **Asking HR to enter mood or satisfaction scores.** Nobody records them today, and a
  model trained on none cannot read them.

What the survey and the ERP both hold is **behaviour**, and organisational research reads
some behaviour as a sign of how a person feels about their job.

## Decision

**Build theory-backed behavioural indicators from the recorded inputs, and keep only what
the data supports** (`model/synapse_ml/attrition/indicators.py`).

| Indicator | Built from | Reads as | Theory |
|---|---|---|---|
| Withdrawal | absences + late arrivals | emotional disengagement | withdrawal progression (Rosse, 1988; Hanisch & Hulin, 1990, 1991; Berry, Lelchook & Clark, 2012) |
| Career stagnation | time since promotion, ever promoted | career frustration | career plateau; Herzberg's two-factor theory |
| Workload strain | overtime | emotional exhaustion | Job Demands–Resources (Bakker & Demerouti, 2007) |
| Effort–reward imbalance | overtime against pay and time since promotion | feeling under-rewarded | Effort–Reward Imbalance (Siegrist, 1996) |
| Pay inequity (tested only) | pay against the department group's median | unfairness against peers | Equity theory (Adams, 1965) |

Each was added to the model **inside every training fold** (band means, spreads and
department medians from the training rows only) and scored on the 10 CV splits the
choices were made on and on **30 splits no choice was made on**, split for split against
the previous model (notebook §5, `metrics.json` → `indicator_ablation`):

| Design | CV splits | Unseen splits | vs previous, unseen |
|---|---|---|---|
| Previous model (absences and lateness apart, C = 1) | 0.695 | 0.698 | — |
| Absences and lateness apart, C = 3 | 0.698 | 0.696 | −0.002 (ahead 14/30) |
| **Withdrawal replaces the two counts (served)** | **0.715** | **0.715** | **+0.018 (ahead 25/30)** |
| + career stagnation | 0.713 | 0.714 | +0.017 (24/30) |
| + pay inequity | 0.681 | 0.695 | −0.003 (14/30) |
| + effort–reward imbalance | 0.674 | 0.675 | −0.022 (5/30) |
| + workload strain | 0.655 | 0.663 | −0.035 (6/30) |

Nested cross-validation, where the settings are chosen inside each training fold, gives
0.678 with withdrawal against 0.671 without. The Random Forest gains from it too (0.664
→ 0.677 on the unseen splits), so it is not an artefact of one estimator. PR-AUC rises
from 0.418 to 0.436, the Brier score falls from 0.222 to 0.217, and the permutation test
stays at p = 0.010.

**Withdrawal is a model input.** The pipeline builds it from the raw counts — impute,
band with the survey's edges, standardise each band, average with equal weights — in a
`withdrawal` branch of the shared preprocessor (`features.preprocessor`, stock
scikit-learn/NumPy only). The two counts move together (Spearman 0.63), and their top
bands are where the share who left climbs: 52% of the 27 people at a *High* withdrawal
level left, against 28% overall. The ERP keeps sending the two counts; it never computes
an indicator. The served SVM's `C` moves from 1 to 3 with it (with one input fewer a
tighter margin pays; at C = 3 *without* withdrawal there is no gain, so the gain is the
indicator's).

**The others are not inputs, and the data says why.**

- *Workload strain* and *effort–reward imbalance* rest on overtime, whose leave rate is
  flat across its bands (26–33%); as inputs they cost accuracy.
- *Career stagnation* adds nothing to the promotion inputs, and replacing them with it
  costs 0.02–0.04. In this survey people never promoted left **less** often (24%) than
  people who were (34%) — the opposite of what the theory predicts — so it is not
  presented as an emotional factor the score relies on.
- *Pay inequity* needs a department median. The survey's departments (Q3, free text,
  now grouped into `department_group` by `survey.department_group`) belong to many
  different employers, and pay was asked in five bands, so a peer median here is barely
  one. It is tested and documented, not shown.

**Explanations read an indicator as one reason.** `synapse_ml.explain.shapley` gains
`groups`: a player can stand for several inputs, revealed together. The registry reads
every preprocessor branch other than `num` and `cat` as one player, so withdrawal is one
factor (`value` = its level, `components` = the two counts, `group` = `emotional`) and
the reasons still add up exactly to the score. Every factor names its group:
structural, compensation, career or emotional.

**Every result carries its indicator readings** (`indicators`): each indicator the record
can be placed on, with its level, how the score reads it (`direct`, through its
`inputs`, or `none`), the points it is responsible for and the survey's leave rate at
that level (`evidence.json` → `indicators`, written by the notebook beside the model).
The response's `reference.indicators` states each indicator once: what it reads as, the
theory, its levels and, for an unread one, why. Laravel stores readings in the score's
`explanation` and definitions in the run's `reference` (`PredictionExplanation`); no
migration.

**The breakdown shows them.** The Reasons tab gains *Emotional factors*: every reading
with a level meter, what it was read from, whether the score reads it and its points, and
the leave rate at that level. Unread indicators are shown with their reason, so a reader
sees what was tested, not only what was kept. The withdrawal reason is tagged *Emotional
indicator* in the ledger and gets its own next step ("Ask what is behind the absences and
late arrivals"). The assistant states withdrawal, career stagnation and workload strain;
effort–reward imbalance is read from pay, which never leaves the page.

**The wording is careful.** Absences and lateness *indicate* withdrawal; they do not
establish it. The dialog says the indicators indicate a feeling and do not measure it,
and that the model never sees how anyone feels.

## Consequences

- The model is modestly better on every honest check: unseen splits, nested CV, another
  estimator. The data ceiling still holds (about 0.67–0.72); a direct measure of how
  people feel is what would move it, and a future survey should add one (satisfaction,
  stress or engagement scales). A periodic pulse check-in in the app would let an
  organisation's own model learn from one.
- A run scored before this change has no readings; its dialog shows no *Emotional
  factors* section, and its absences and lateness remain two reasons. An older model
  artifact still serves: the registry reads its branches, finds no indicator, and
  explains per input.
- An organisation's own model (model graduation) is the same class, so it reads
  withdrawal too, and its stored evidence carries indicator levels from its own records.
- The cross-survey check (train on one source, test on the other) is still weak; that
  limit is the population difference between the two survey sources, not the features.
