# Performance Management

Conduct appraisals against a tenant-defined **appraisal framework**: weighted
sections, criteria measured on their own **rating scales**, and a result reported
in the company's own **rating model** — its words, not a fixed 1–5. Everything is
tenant-scoped (ADR 0005). See
[ADR 0028](../decisions/0028-appraisal-frameworks-and-tenant-rating-models.md)
for the design and [ADR 0012](../decisions/0012-performance-management.md) for
the original cut.

> Status: **Active** · Route prefix: `/performance` · Config: `/setup/kpi`
> Sidebar: Workforce → Performance Management (gated by `performance.view`);
> Company Setup → Performance Framework (gated by `setup.kpi.view`)

## The four concepts

| Concept | Table | What it decides |
| --- | --- | --- |
| **Rating scale** | `rating_scales` | *How* something is rated — a numeric range with a step, a 0–100 percentage, or ordered named levels with behavioural anchors. |
| **Criterion** | `kpi_criteria` | *What* can be measured — a catalogue entry naming a scale and a default weight. |
| **Framework** | `review_templates` + `review_template_items` | *Who* is reviewed, on *which* weighted sections and criteria, and *how the result is reported*. |
| **Rating model** | `review_templates.bands` | The ordered outcome bands a result is reported in — `{label, min_percent, description, tone}`, read top-down. |

A framework's **eligibility rule** (`all` / `department` / `position` /
`employment_type`) decides who it covers.
`App\Support\Performance\TemplateResolver` picks the **narrowest** match
(position → department → employment type → everyone), with the tenant's default
breaking ties. A resolved framework is a suggestion — HR can always pick another.

## Surfaces

- **`/performance`** — the **cycle overview**, scoped to one review cycle
  (`?period=`, defaulting to the open one). Coverage against active headcount,
  in-progress and awaiting-sign-off counts, average attainment; the **result
  spread** across the tenant's own bands; **per-department calibration** as a
  deviation from the cycle average; then the appraisal list, each row carrying
  its rating and a miniature of the ladder it sits on. HR can **open one**
  appraisal or **launch the cycle**.
- **`/performance/{evaluation}`** — the **scorecard**: who, which cycle, which
  framework, then the result — led with in whatever way the framework asks for —
  above the **rating ladder** showing the whole model with the result standing on
  it. Below: ML decision support, then one card per weighted section (its weight,
  its running attainment, how much of it is rated) holding the criteria. While a
  draft, each criterion is rated on its own scale — named levels show their
  anchor, goal attainment gets a slider — and the result moves live. **Submit**
  locks the card once every criterion is rated; a submitted appraisal can be
  **signed off**; an acknowledged one is final.

## The result

`App\Support\Performance\PerformanceScorer` is the single source of truth, and it
scores in **two levels**, because that is how frameworks are written:

1. Each line's raw rating is read as a position on **its own scale** (0–1).
2. Lines are weighted **within their section** → the section's attainment.
3. Sections are weighted **against each other** → the appraisal's attainment.

The result is **`overall_percent` — attainment on 0–100**, the canonical figure,
and the one the rating model is read from (`result_band` / `result_label`).
`overall_score` (1–5) is kept as an affine projection of the same figure, because
the ML forecast, attrition and promotion pipelines and the awards nominator are
all built on it. Only rated lines contribute, so a draft carries a live running
result; a section with nothing rated is left out entirely rather than dragging it
down. A section carrying no weight of its own falls back to the weight of its
lines — which makes a flat, unsectioned scorecard score **exactly** as it did
before frameworks existed.

The result is recomputed on every save and on submit, and is never trusted from
the client. A rating is checked against **its own line's snapshot scale** on the
way in: a level scale accepts only the values it defines.

## Snapshots

An appraisal freezes the framework it was opened under — its name, its sections
and its rating model — and each score line freezes its section (key, name,
weight), its own weight, its description and its full rating scale. Retuning a
framework, retiring a criterion or changing a scale therefore changes the *next*
appraisal, never a past one, and the whole result can be rebuilt from the lines
alone.

## Launching a cycle

`POST /performance/cycles` opens appraisals for a whole population at once —
everyone active, or the active staff of chosen departments — seeding each person
from the framework that covers them unless one is pinned for the launch. It is
**idempotent**: anyone already appraised in the cycle is skipped, so it is safe
to re-run as people join. The toast reports what was opened *and* who was left
out and why. Both this and the single-open action go through
`App\Support\Performance\EvaluationOpener`, so a scorecard is built the same way
however it was started.

## Configuration (`/setup/kpi`)

Company Setup → **Performance Framework**, four tabs:

- **Frameworks** — sections and their weights, the criteria inside them (each on
  its own scale), the eligibility rule, the rating model (drawn live as the
  ladder the scorecard will show), and which reading the scorecard leads with.
  Full archive lifecycle; a framework used for appraisals cannot be permanently
  deleted.
- **Rating scales** — the measurement instruments, one marked as the tenant's
  default. A scale still in use cannot be permanently deleted.
- **Criteria** — the catalogue: name, meaning, scale, default weight.
- **Review cycles** — name, start / end, status (`draft | open | closed`).
  Appraisals can only be opened while a cycle is **open**.

## Export

`GET /performance/export?period=` streams the shown cycle as CSV: employee,
department, position, cycle, **framework**, status, **rating** (the company's own
word), attainment (0–100), the 1–5 index, and the key dates.

## Permissions

`performance.view` (overview & scorecards), `performance.manage` (open, launch a
cycle, score, submit, sign off, delete drafts); `setup.kpi.view` /
`setup.kpi.manage` (the configuration surface). Built-in **HR Manager** gets all
of them.

## Out of scope (this cut)

Self / peer / 360 reviews, employee self-service acknowledgement, goal libraries
with mid-cycle check-ins, forced distribution, calibration *sessions* (as opposed
to the calibration view), feeding results into pay, and an assistant capability.
