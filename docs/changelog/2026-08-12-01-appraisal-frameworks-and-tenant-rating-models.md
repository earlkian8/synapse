# Performance stops being a number out of five

Performance Management shipped with one shape: a single global list of weighted
KPI criteria, everybody scored against all of them, and the result reduced to
`3.87 / 5.00`. That is a demo. No two companies review a warehouse picker, an
account executive and an engineering manager the same way, appraisals are written
in weighted sections rather than flat lists, and the artefact a promotion case
actually carries is a **word** — "Outstanding", "Exceeds Expectations", "A",
"Exceptional Leader" — not a decimal somebody else chose the range for.

The module now conducts appraisals against a **tenant-defined framework** and
reports the result in that tenant's **own rating model**. See
[ADR 0028](../decisions/0028-appraisal-frameworks-and-tenant-rating-models.md).

## Highlights

- **Appraisal frameworks.** Weighted sections ("Goals 60%, Capability 30%, How
  we work 10%"), the weighted criteria inside them, and an eligibility rule
  deciding who each framework reviews — everyone, or chosen departments,
  positions or employment types. The narrowest match wins; the tenant's default
  breaks ties.
- **Rating scales as records.** A numeric range with a step, a 0–100 percentage,
  or ordered named levels each carrying a **behavioural anchor**. Defined once
  per tenant and reused, instead of re-described on every criterion.
- **The rating model is the tenant's.** Ordered outcome bands — label, the
  attainment they start at, a description, a semantic tone — decide what a result
  is *called*. Nothing in the app hard-codes "out of 5" any more.
- **Two-level scoring.** Each line is read on its own scale and weighted **within
  its section**; the sections are then weighted against each other. The result is
  **attainment on 0–100**, the figure that stays comparable when frameworks and
  cycles disagree about everything else.
- **Launch a cycle in one action.** Open appraisals for everyone active, or for
  chosen departments, seeding each person from the framework that covers them.
  Idempotent — anyone already appraised is skipped, so it is safe to re-run as
  people join.
- **A cycle you can actually read.** The overview scopes to one review cycle and
  shows coverage against headcount, the **result spread** across the tenant's own
  bands, and **per-department calibration** as a deviation from the cycle average.
  Rating inflation is invisible one scorecard at a time.

## Backend

- New tables: `rating_scales`, `review_templates`, `review_template_items`. New
  models `RatingScale`, `ReviewTemplate`, `ReviewTemplateItem`, with factories.
- `PerformanceScorer` rebuilt around a `ScoreResult` value object: attainment on
  0–100, the 1–5 projection, the per-section breakdown, and the band. `overall()`
  keeps its old narrow contract (a `?float` on 1–5) because the ML forecast,
  attrition and promotion mappers and the awards nominator all read it — and a
  flat, unsectioned 1–5 card still scores **exactly** as it did, which the unit
  tests pin.
- New support classes: `RatingModel` (band resolution and normalisation),
  `RatingScales` (the one place a raw score is read against any scale),
  `TemplateResolver` (specificity-ordered eligibility), `EvaluationOpener` (the
  **only** path that seeds a scorecard — single open, bulk launch and the seeder
  all go through it), `PerformanceCalibration` (distribution + department rows).
- `PerformanceCycleController` launches a cycle; the overview is cycle-scoped
  (`?period=`), defaulting to the open cycle rather than the newest.
- `Setup\RatingScaleController` and `Setup\ReviewTemplateController` round out
  `/setup/kpi`; a framework saves whole (items replaced in one transaction) and
  its cross-references are validated before anything is written.
- Ratings are checked against **their own line's snapshot scale** on the way in —
  a level scale accepts only the values it defines, so 2.5 on a four-level scale
  is refused rather than quietly stored.
- The inline scale columns on `kpi_criteria` are folded into `rating_scales`, so
  a scale is defined in exactly one place.

## Frontend

- **`/performance` rebuilt** around the cycle: a cycle selector with its window
  and status, four stat tiles led by coverage, the result-spread card, the
  department calibration table, and an appraisal list where every row carries the
  rating in the company's words plus a miniature of the ladder it sits on.
- **The scorecard rebuilt**: identity and framework facts, the result led with in
  whatever way the framework asks for, then the **rating ladder** — this module's
  one picture — drawing the whole model with the result standing on it. Below,
  one card per weighted section showing its weight, its running attainment and
  how much of it is rated. Named levels show their anchor as you pick them; goal
  attainment gets a slider and an exact field. The action bar is sticky, so
  *Submit* is never below the fold on a long card.
- **`/setup/kpi` rebuilt** as a four-tab surface (Frameworks, Rating scales,
  Criteria, Review cycles). The framework editor draws the rating ladder **live**
  as the bands are written, so the configuration is legible while it is being
  made. Everything opens as a centred modal; the last two edge sheets in the
  module are gone.
- New: `RatingLadder`, `BandChip`, `BandDistribution`, `CalibrationTable`,
  `ResultSummary`, `SectionCard`, `RatingControl`, `EvaluationTable`,
  `OpenAppraisalModal`, `LaunchCycleModal`, `FrameworkModal`, `RatingScaleModal`,
  `CriterionModal`, `PeriodModal`.
- The employee profile's appraisal list now shows the band and the framework
  rather than a bare `x / 5.00`.
- Sidebar: Company Setup → **Performance Framework** (was "KPI & Evaluation
  Criteria").

## Migration

`…_create_appraisal_frameworks` migrates existing tenants rather than resetting
them: the scales their criteria already used become `rating_scales` rows, their
criteria become one "Standard Appraisal" framework, every score line gains a
section, and every past appraisal's 1–5 overall is read back onto 0–100 and
stamped with a band. Nothing needs re-scoring by hand.

## Notes

- **Rounding drift on migrated rows.** A backfilled `overall_percent` is derived
  from a stored 1–5 value already rounded to two decimals, so it can sit up to
  ~0.13 points away from a fresh computation. It does not move a result across a
  band boundary in practice, and no historical appraisal is re-derived.
- The seeder now builds two frameworks that deliberately **disagree** — a
  three-section individual-contributor review on a five-band model, and a
  leadership review on a four-band one, mixing percentage goal attainment with
  competency levels. That is the shape the module has to survive, so that is what
  the demo data is.
- **537 tests, 537 passing** (up from 474): 28 unit tests across the scorer, the
  rating model and the scale reader, and 35 feature tests covering opening,
  snapshotting, two-level scoring, scale enforcement, the lifecycle, cycle
  launches, the export and the whole configuration surface.
- Deliberately out of scope: self / peer / 360 review, goal libraries with
  mid-cycle check-ins, forced distribution, calibration *sessions*, and an
  assistant capability. Each is a module, not a field.
