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
  deviation from the cycle average (both as compact tables); then the appraisals
  table (searchable, filterable by status, sortable by employee, framework, result
  and status, and paged), each row carrying its rating and a miniature of the ladder
  it sits on. The review-cycle picker sits in the page header. The page uses the
  shared Workforce table kit
  ([ADR 0047](../decisions/0047-workforce-list-pages-share-one-table-kit.md)). HR can **open one**
  appraisal or **launch the cycle**.
- **`/performance/{evaluation}`** — the **scorecard**: who, which cycle, which
  framework, then the result — led with in whatever way the framework asks for —
  above the **rating ladder** showing the whole model with the result standing on
  it. The header carries the cycle, the framework and the evaluator in one line.
  Below: ML decision support, then the **scorecard table**. Each weighted section
  is a header row (its weight, its running attainment, how much of it is rated),
  followed by a row per criterion: its weight within the section, the rating, and
  the evidence. While a
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
the ML forecast and promotion pipelines and the awards nominator are all built on
it. Only rated lines contribute, so a draft carries a live running
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
however it was started. Every change to an appraisal (open, rate, submit, sign off,
discard, launch) is `App\Support\Performance\AppraisalWorkflow`, shared by the
screens and the assistant, so both refuse for the same reasons in the same words.

## Configuration (`/setup/kpi`)

Company Setup → **Performance Framework**, four tabs:

- **Frameworks** — sections and their weights, the criteria inside them (each on
  its own scale), the eligibility rule, the rating model (drawn live as the
  ladder the scorecard will show), and which reading the scorecard leads with.
  Full archive lifecycle; a framework used for appraisals cannot be permanently
  deleted.

  A criterion is **chosen, not typed**. The editor offers the catalogue, and a
  line that names one takes the catalogue's wording and meaning — at save, when
  the editor reads it back, and when a scorecard is opened — so retitling a
  criterion once reaches every framework drawing on it. The same criterion cannot
  be measured twice in one framework. A line that is genuinely local to a
  framework is written as an explicit **one-off** (`kpi_criterion_id` null): it
  keeps its own words, stays out of the catalogue, and no other framework can
  reuse it.

  Weights are relative twice over — a section's share of the appraisal, a line's
  share of its section — so the editor states each line's resulting share of the
  whole, keeps a running total per section and across sections, and can split
  either evenly.
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

## The assistant

`App\Services\Assistant\Modules\PerformanceModule` puts appraisals in the chat
assistant ([ADR 0049](../decisions/0049-assistant-prompt-injection-defences.md)).

- **Reads** (`performance.view`):
  - `find_appraisals` — by person, cycle and status;
  - `get_appraisal` — one scorecard in full: result, band, every criterion's rating,
    the evaluator and remarks. Reading a named person's appraisal is written to the
    audit trail as `viewed`;
  - `performance_summary` — a cycle as the overview reads it (coverage, statuses,
    average, the band spread, and the departments rating furthest from the cycle
    average), through `PerformanceCalibration`;
  - `list_review_cycles`.
- **Writes** (`performance.manage`), all through `AppraisalWorkflow`, the class the
  screens now use too:
  - `open_appraisal`;
  - `rate_appraisal` — a rating is given by criterion name, as a number on that
    criterion's own scale or one of its level names ("Proficient"). Off-scale ratings
    are refused before anything is written;
  - `submit_appraisal`, `acknowledge_appraisal`, `delete_draft_appraisal` and
    `launch_review_cycle` always wait for the user's **Confirm** in the chat.
- **Names resolve to exactly one person** (or an employee number) before any write.
  "Maria", when there are two, is refused rather than guessed.
- **Retrieval:**
  - a question about a person carries their last four appraisals and how their
    attainment moved;
  - their latest ML forecast is added only for someone with
    `analytics.performance.view`;
  - a question about the cycle that names nobody ("how is the review cycle going?")
    carries the cycle summary.
- **No self-service.** As on the screens, there is no "my appraisal" view: an
  appraisal, including one's own, needs `performance.view`.

### The framework in the assistant

`App\Services\Assistant\Modules\PerformanceFrameworkModule`
([ADR 0055](../decisions/0055-assistant-locations-leave-and-award-types-and-performance-framework.md)).
Every write on `/setup/kpi` — frameworks, scales, criteria, cycles — goes through
**`App\Support\Setup\PerformanceFrameworkWorkflow`**, which the four controllers use too
(`PerformanceFrameworkException` for the in-use delete guards). A framework is rebuilt
whole and validated by the editor's own request (`ReviewTemplateRequest::normalise()`,
`documentRules()`, `validateDocument()`).

- **Reads** (`setup.kpi.view`):
  - `find_frameworks`;
  - `get_framework`: section by section with each item's weight and scale, the bands,
    and who it **covers today**, asked of `TemplateResolver`;
  - `find_kpi_criteria`, `find_rating_scales`;
  - `find_review_cycles`, only for users without `performance.view`, whose
    `list_review_cycles` already answers.
- **Writes** (`setup.kpi.manage`):
  - `create_framework`, by copying one or from catalogue criteria. It applies to
    everyone and is not the default, so it reaches only people no framework covers until
    it is re-targeted;
  - `add_kpi_criterion`, `create_rating_scale` (numeric, percentage, or levels from
    labels), `set_default_rating_scale`, `create_review_cycle` (a draft);
  - **these always wait for Confirm**, their cards saying whom the framework covers or
    what uses the record:
    - `update_framework` (name, description, who it applies to by names, active,
      result display);
    - `set_framework_item` and `remove_framework_item`;
    - `set_framework_section` (rename, re-weight, or add);
    - `set_default_framework` and `archive_framework`;
    - `update_kpi_criterion` and `archive_kpi_criterion` (frameworks take a criterion's
      wording and scale when a scorecard opens);
    - `update_review_cycle` (an open cycle takes new appraisals).
- **Screen-only:** the rating bands, editing or archiving a scale, removing a section,
  restoring, and permanent deletion.
- **Retrieval:** "what does our appraisal framework measure?" carries the frameworks,
  whom each applies to, and how the one for a person is chosen.
- **`TemplateResolver` ranks explicitly**: the most specific rule, then the default,
  then the oldest. Its old `sortBy()` of key closures did not.

## Out of scope (this cut)

Self / peer / 360 reviews, employee self-service acknowledgement, goal libraries
with mid-cycle check-ins, forced distribution, calibration *sessions* (as opposed
to the calibration view), and feeding results into pay.
