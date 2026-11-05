# The attrition model reads withdrawal, an emotional factor built from behaviour

The attrition model now reads an emotional factor. The survey asked nothing about
feelings, so SYNAPSE builds **behavioural indicators of emotional states** from what is
recorded. Absences and late arrivals, read together as **withdrawal**, are a sign of
disengagement. Every indicator was tested on splits no choice was made on, and
withdrawal is the one that improved prediction, so it is now a model input. The others
are shown with the reason the score does not use them. See
[ADR 0101](../decisions/0101-the-attrition-model-reads-withdrawal.md).

## Highlights

- **A better model, on every honest check.** ROC-AUC on 30 unseen splits went from 0.698
  to 0.715, ahead on 25 of the 30. Nested CV went from 0.671 to 0.678, PR-AUC from 0.418 to
  0.436 and the Brier score from 0.222 to 0.217. The Random Forest gains from withdrawal too
  (0.664 → 0.677), so the gain is not specific to the SVM.
- **Withdrawal is one reason.** The breakdown's ledger shows it as one step, tagged
  *Emotional indicator*: "High withdrawal: 7 days absent, late 6 times · typical low". It
  replaces two separate absence and lateness reasons that the model no longer reads apart.
- **Emotional factors in every breakdown.** The Reasons tab lists four indicators for each
  person: withdrawal, career stagnation, workload strain and effort–reward imbalance. Each
  shows a level meter, what it was read from, whether the score reads it (with its
  points), and how often people at that level left in the survey. For example, 52% of the
  27 people at *High* withdrawal left, against 28% overall.
- **What was tested, not only what was kept.** Workload strain and effort–reward imbalance
  rest on overtime, whose leave rate is flat, and both made the score less accurate (−0.035
  and −0.022). Career stagnation added nothing beyond the promotion inputs, and in this
  survey people never promoted left less often. Pay against peers also cost accuracy. The
  page and the notebook say so.
- **A next step for withdrawal.** "Ask what is behind the absences and late arrivals". It
  explains that the two together indicate withdrawal, which often comes before a
  resignation, and that this is a sign to ask about, not a verdict.

## Model (`model/`)

- `synapse_ml/attrition/indicators.py` (new): each indicator's definition, theory, levels and
  the leave rates by level (`profile`). It also holds per-record readings and
  `CandidateIndicators`, the in-fold model-input forms the notebook compares.
- `features.py`: `INDICATORS = {"withdrawal": [absences, lates]}`; the preprocessor gains a
  `withdrawal` branch (impute → band → standardise → equal-weight mean, stock
  scikit-learn only). `indicators=False` restores the previous layout. New `FEATURE_GROUPS`.
- `model.py`: the served SVM reads withdrawal, with `C = 3`. `pipeline(add=…)` puts
  candidates in front.
- `survey.py`: the free-text department answer (Q3) is grouped into `department_group`
  (production, sales, operations, public sector, HR/admin, other; groups under 10 merged).
  It is for audit and the pay-against-peers test, not a model input.
- `evaluation.py`: `UNSEEN_SEEDS`, `per_seed_roc_auc` and `paired`, for split-by-split
  before/after comparisons.
- `explain.py`: Shapley players can stand for several inputs (`groups`), revealed together.
- `evidence.py`: leave rates by indicator level (`indicators`). `write()` is called by the
  notebook, so `evidence.json` is written beside the model it describes.
- Notebook 01: new §2 indicator levels, new §5 before/after for every indicator, §7 nested CV
  for both designs and with the design chosen inside the folds; `metrics.json` gains
  `unseen_roc_auc`, `previous_model`, `indicator_ablation`, `nested_design_choice`.
- Service: each attrition factor carries `group` (and `components` for withdrawal), each
  result carries `indicators`, and the reference carries `indicators` definitions. An
  organisation's own model stores indicator evidence from its own records.

## Backend (`server/`)

- `PredictionExplanation` stores `indicators` in each attrition score's `explanation`. No
  migration is needed.
- `PredictionWording` labels the withdrawal reason and words a reading for the assistant.
  `get_attrition_risk` states withdrawal, career stagnation and workload strain, never
  effort–reward imbalance, which is read from pay.

## Frontend (`server/resources/js/features/attrition-risk/`)

- New `BehaviouralIndicators` panel ("Emotional factors") in the Reasons tab.
- `explain.ts`: withdrawal label, detail and reason wording, plus `GROUP_LABELS`; the ledger
  tags emotional reasons. `brief.ts`: a withdrawal next step. The survey evidence chart
  reads indicator levels. The input grid notes that absences and lateness are read
  together as withdrawal.

## Notes

- Runs from before this change keep their two attendance reasons and show no *Emotional
  factors* section. Run a new assessment to see them.
- The model must be retrained (execute `notebooks/01_attrition_model.ipynb`) for the service
  to read withdrawal. An older artifact still serves and is explained per input.
- These indicators indicate a feeling; they do not measure it. A future survey should add
  direct measures (satisfaction, stress or engagement scales).
- The Docker image was not built or run.
