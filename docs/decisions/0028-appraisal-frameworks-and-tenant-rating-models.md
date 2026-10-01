# 28. Appraisal frameworks: the rating model belongs to the tenant

Date: 2026-08-12

## Status

Accepted. Supersedes the scoring half of
[ADR 0012](0012-performance-management.md) (performance management); bounded by
[ADR 0005](0005-multi-tenancy.md) (row-level tenancy). The ML surfaces that read
`overall_score` — [ADR 0017](0017-predictive-analytics-and-ml-inference.md),
[ADR 0018](0018-performance-forecasting.md),
[ADR 0021](0021-attrition-risk.md) — are unchanged by design.

## Context

Performance Management shipped with one shape: a single global list of weighted
KPI criteria, every employee scored against all of them, and the result reduced
to a number out of five. A later change let each criterion carry its own scale,
which helped the *inputs* — but the output was still `3.87 / 5.00`, and the
criteria list was still one list for the whole company.

That is a demo, not a product. In a multi-tenant HR ERP:

- **Companies do not review everyone the same way.** A warehouse picker, an
  account executive and an engineering manager are measured on different things.
  One global criteria list forces the union of every population's criteria onto
  every person, which makes half of any scorecard noise.
- **An appraisal is written in weighted sections**, not as a flat list —
  "Goals 60%, Competencies 30%, Values 10%". Flattening the sections into a
  single weighted average makes the section weights unexpressible: an evaluator
  cannot see what a section is worth, and HR cannot rebalance goals against
  behaviour without hand-editing every criterion's weight.
- **"5 out of 5" is not a rating anyone reports.** Companies report
  "Outstanding", "Exceeds Expectations", "A", "Band 3", "Exceptional Leader".
  The number is an intermediate; the *word* is the artefact that goes into a
  promotion case, a pay review and a performance-improvement plan. A system that
  only stores the number has thrown away the thing the process is for.
- **Reviewing a company one "New evaluation" click at a time does not scale.**
  A 200-person cycle is 200 clicks before any reviewing starts.
- **Nobody could see the shape of a cycle.** Rating inflation is invisible one
  scorecard at a time. Without the distribution on screen, "everyone is
  outstanding" is a fact the system holds and never says.

## Decision

**An appraisal is conducted against a tenant-defined framework, and the result is
reported in that tenant's own rating model.** Four concepts, three of them new:

- **Rating scale** (`rating_scales`) — a reusable measurement instrument: a
  numeric range with a step, a 0–100 percentage, or an ordered set of named
  levels each carrying a behavioural anchor. Defined once per tenant; referenced
  by criteria and by framework items. The inline scale columns that used to sit
  on `kpi_criteria` are folded into it, so a scale is defined in one place.
- **Criterion** (`kpi_criteria`) — now purely a **catalogue** entry: what is
  measured, on which scale, at what default weight. It no longer decides an
  appraisal on its own.
- **Appraisal framework** (`review_templates` + `review_template_items`) — how
  one population is reviewed: weighted **sections**, the weighted items inside
  them, an **eligibility rule** (everyone / departments / positions / employment
  types), and the **rating model**.
- **Rating model** — the ordered outcome bands a result is reported in, each a
  labelled cut of attainment on 0–100 with a semantic tone. This is the piece
  that makes "performance" mean what the company means by it.

### Scoring is two-level, and attainment is the canonical figure

`PerformanceScorer` reads each line on its own scale, weights it **within its
section**, then weights the sections against each other. The result is
**attainment on 0–100** (`overall_percent`) — the figure the rating model is read
from, and the only figure comparable across frameworks and across cycles.

`overall_score` (1–5) is kept as an affine projection of the same number, because
the ML forecast pipeline, the attrition features, the promotion mapper and the
awards nominator were all built on it. A flat, unsectioned 1–5 scorecard scores
**exactly** as it did before — the section fallback (a section with no declared
weight takes the weight of its own lines) makes the new formula degenerate to the
old one, which the unit tests pin.

### An appraisal snapshots the framework it was opened under

`performance_evaluations` carries `template_name`, `template_sections`,
`template_bands` and `result_display`; each score line carries its section (key,
name, weight), its own weight, its description and its full rating scale.
Retuning a framework, retiring a criterion or changing a scale therefore changes
the *next* appraisal and never a past one — and the scorer can rebuild a whole
result from the lines alone, with no configuration present.

### Frameworks resolve by specificity, and a cycle launches in one action

`TemplateResolver` picks the narrowest framework covering an employee (position
→ department → employment type → everyone), with the tenant's default breaking
ties. `EvaluationOpener` is the **one** path that seeds a scorecard, used
identically by the single-open action, the bulk cycle launch and the seeder.
A launch is idempotent: anyone already appraised in the cycle is skipped, so it
is safe to re-run as people join.

### The overview is a cycle, not a list

`/performance` scopes to one review cycle and answers three questions: coverage
(appraisals against active headcount), the **band distribution** across the
tenant's own model, and per-department calibration expressed as a deviation from
the cycle average. All of it is derived from the evaluations already loaded for
the page.

## Consequences

- **The rating model is data, not code.** A tenant running "A–F" and a tenant
  running five behavioural bands are the same code path. Nothing in the app
  hard-codes "out of 5" any more except the ML projection, which is documented
  as exactly that.
- **The 1–5 index survives as an index.** Every downstream ML and analytics
  consumer keeps working, unchanged, and gains a stable meaning of "overall"
  across frameworks that disagree about everything else.
- **Configuration got bigger.** `/setup/kpi` is now four surfaces (frameworks,
  scales, criteria, cycles) rather than two. That is the honest cost of the
  flexibility; the framework editor draws the rating ladder live so the
  configuration is legible while it is being written.
- **Existing tenants are migrated, not reset.** The migration folds each
  tenant's in-use scales into `rating_scales`, builds one "Standard Appraisal"
  framework from its criteria, and reads every past evaluation's 1–5 overall back
  onto 0–100 to stamp a band. Nothing needs re-scoring by hand.
- **Rounding drift on migrated rows.** A backfilled `overall_percent` is derived
  from a stored 1–5 value rounded to two decimals, so it can differ from a fresh
  computation by up to ~0.13 points. It never moves a result across a band
  boundary in practice, and no historical appraisal is re-derived.
- **Deliberately out of scope.** Self and peer review, 360 feedback, goal
  libraries with mid-cycle check-ins, forced distribution, and calibration
  *sessions* (as opposed to the calibration *view*) are not built. Each is a
  module, not a field.
