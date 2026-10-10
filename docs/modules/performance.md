# Performance Management

Conduct appraisals against a tenant-defined **appraisal framework**: weighted
sections, criteria measured on their own **rating scales**, and a result reported
in the company's own **rating model** — its words, not a fixed 1–5. Everything is
tenant-scoped (ADR 0005). See
[ADR 0028](../decisions/0028-appraisal-frameworks-and-tenant-rating-models.md)
for the design and [ADR 0012](../decisions/0012-performance-management.md) for
the original cut. Reviews from the people around an appraisal and acknowledgement
by the employee are
[ADR 0072](../decisions/0072-appraisal-reviews-and-acknowledgement-by-the-employee.md);
goals, the goal library and calibration sessions are
[ADR 0073](../decisions/0073-goals-with-check-ins-and-calibration-sessions.md).

> Status: **Active** · Route prefix: `/performance` · Config: `/setup/kpi`
> Sidebar: Workforce → Performance Management (shown for `performance.view` *or*
> `performance.participate`); Company Setup → Performance Framework (gated by
> `setup.kpi.view`)

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

Every page header carries `PerformanceNav` (a `ModuleNav`) in two groups, each item
shown to whoever may open it:

- **My appraisals · My goals · Reviews** (`performance.participate`). Reviews shows
  the number waiting.
- **Appraisals · Goals · Calibration** (`performance.view`).

Someone who only takes part is sent from `/performance` to `/performance/me`. Page
titles are the section names, and the breadcrumb root is "Performance Management".

### What HR runs

- **`/performance`** (**Appraisals**) — the **cycle overview**, scoped to one review cycle
  (`?period=`, defaulting to the open one). Coverage against active headcount,
  in-progress and awaiting-sign-off counts, average attainment; the **result
  spread** across the tenant's own bands; **per-department calibration** as a
  deviation from the cycle average (both as compact tables); then the appraisals
  table (searchable, filterable by status, sortable by employee, framework, result
  and status, and paged), each row carrying its rating and a miniature of the ladder
  it sits on. The review-cycle picker sits in the page header. The page uses the
  shared Workforce table kit
  ([ADR 0047](../decisions/0047-workforce-list-pages-share-one-table-kit.md)). HR can **open one**
  appraisal or **launch the cycle** from the toolbar.
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
  locks the card once every criterion is rated, and shares it with the employee
  unless calibration holds it. An acknowledged appraisal is final.

  The scorecard also carries:
  - **notices**: the appraisal is your own (someone else rates it), or it is held by
    a calibration session;
  - the **calibration** block: the scored rating, the rating now, and every move with
    its reason;
  - the person's **goals** for the cycle and their attainment;
  - the **reviews** panel: who was asked and where each request stands (with
    **Remind** and **Withdraw**), **Ask for reviews**, and the comparison table;
  - who acknowledged the appraisal and how, with the employee's comment. **Record
    sign-off on their behalf** is for a sign-off given on paper.
- **`/performance/goals`** (**Goals**) — every goal in a cycle. Stat tiles for on
  track, at risk, off track and stale; search; filters by state and department.
  **Set a goal** for one or several people, from the library or written out. A goal
  opens in a dialog with its check-in timeline, where it can be checked in on,
  edited, closed (achieved, missed, dropped), reopened or deleted.
- **`/performance/calibration`** (**Calibration**) — a cycle's sessions, and **New
  session**.
- **`/performance/calibration/{session}`** — one session: the band spread before and
  after, and every appraisal in scope with its attainment, what the scorecard gave and
  what it is rated now. **Move** opens the band picker with a required reason.
  **Edit**, **Complete** and **Cancel session** sit in the header.

### What everyone taking part has

- **`/performance/me`** (**My appraisals**) — the person's own appraisals, newest
  first. An unshared one shows no result ("in progress" or "being calibrated"). A
  self-review still to write is linked from its appraisal.
- **`/performance/me/{evaluation}`** — one shared appraisal, read-only: the result on
  its ladder, every rating and its evidence beside their self-review, the remarks,
  the calibration note if moved, and **Acknowledge** with an optional comment.
  Anyone else's appraisal, or an unshared one, is a 404. `ai_insights` is never sent.
- **`/performance/me/goals`** (**My goals**) — their goals for a cycle, **Add a goal**,
  and **Check in**. `?goal=` opens one, which is where notifications link.
- **`/performance/reviews`** (**Reviews**) — the reviews asked of them, the ones to
  write first.
- **`/performance/reviews/{review}`** — the review form: each criterion on its own
  scale, with an optional note, then *What went well* and *What you'd like to
  develop*. **Save**, **Hand in** and **Decline**. The evaluator's ratings and remarks
  are stripped from what the page is given. Anyone but the reviewer gets a 404.

The Staff dashboard's *Your workspace* links **My performance**.

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

## Reviews (ADR 0072)

`appraisal_reviews` holds one row per appraisal and reviewer employee, and
`appraisal_review_scores` one per line answered (each score checked against the line's
own frozen scale). The **relationship is derived** from `employees.manager_id` by
`ReviewWorkflow::relationshipOf()`: `self`, `manager`, `direct_report` or `peer`.
Statuses are `pending`, then `submitted`, `declined` or `cancelled`.

`App\Support\Performance\ReviewWorkflow` holds the rules, shared by the screens and the
assistant:

- Requests are made on a draft appraisal (`performance.manage`), or at cycle launch
  with **A self-review from each person** and **A review from each person's manager**.
- A reviewer must be active and hold `performance.participate` on an active account.
  The evaluator is never asked. Each person is checked on their own, and the refusals
  name who and why.
- A self-review must rate every criterion; any other needs at least one rating or one
  written answer. Anyone but the person may decline, with a reason.
- HR may cancel a pending request, or remind once per 24 hours.
- Submitting the appraisal cancels every pending request.
- The reviewer is told when asked and when reminded; the evaluator when a review is
  handed in or declined.

`App\Support\Performance\FeedbackSummary` builds the comparison: per criterion, the
evaluator's rating beside **Self**, **Manager**, **Peers** and **Direct reports**.
Self and manager are attributed. Peers and direct reports are averaged and their
comments listed without names, and a pool is shown only once two have answered
(`FeedbackSummary::POOL_MINIMUM`).

## Sharing and acknowledgement (ADR 0072)

- `shared_at` is when the employee could first read the result. `AppraisalSharing`
  shares on submit, unless an open calibration session covers the appraisal; then
  the session's completion or cancellation shares it. The employee is notified with a
  link to `/performance/me/{hashid}`.
- `acknowledged_by` and `employee_comment` record who acknowledged and what the
  employee said. `AppraisalWorkflow::acknowledge()` refuses an unshared appraisal and
  notifies the evaluator when the employee acknowledges it themselves.
- **Nobody conducts their own appraisal.** `rate()`, `submit()` and `discard()`
  refuse with `AppraisalWorkflow::OWN_APPRAISAL`.

## Goals (ADR 0073)

- `performance_goals`: employee, cycle, optional `goal_template_id`, title,
  description, `measure` (`percent` or `number`), start, target, current, unit,
  weight, due date, `status` (`active`, `achieved`, `missed`, `dropped`), `health`
  (`on_track`, `at_risk`, `off_track`), `last_check_in_at`, `created_by`,
  `closed_at`.
- `goal_check_ins`: append-only value, health and note, with the author.
- `GoalProgress`: progress is (current − start) ÷ (target − start), clamped to
  0–100%. Attainment is the weight-averaged progress; an achieved goal counts as 100
  and a dropped one is left out. It is shown on the scorecard and never sets a
  rating.
- A goal with no check-in for 30 days is **stale**.
- `GoalWorkflow` holds the rules: set (one or many people, from the library or
  written out), update, check in (owner or `performance.manage`, while active and the
  cycle isn't closed), close and reopen, delete (only without check-ins; the owner
  only their own). Goals can't be set in a closed cycle, and an employee adds their
  own only once it's open. The owner is told when someone else sets or checks in on
  their goal.

## Calibration sessions (ADR 0073)

- `calibration_sessions`: cycle, name, `scheduled_for`, `department_ids` (null for
  the whole cycle), `status` (`open`, `completed`, `cancelled`), notes,
  `facilitator_id`, `completed_at`. `calibration_participants` holds who takes part
  (notified when added). Calibrators are holders of `performance.view`.
- No two open sessions in a cycle overlap.
- `calibration_adjustments`: each move, from and to band, the reason and who made it.
  A move sets `result_band` / `result_label` and keeps the scored band in
  `scored_band` / `scored_label` with `calibrated_at`; moving back clears them.
  Attainment and the 1–5 index are never changed. Only a submitted, unacknowledged
  appraisal moves, and never by the person it is about.
- While a session is open, it holds back the appraisals it covers. **Complete** shares
  them and keeps the moves; **Cancel** is allowed only before any move.
- `CalibrationWorkflow` holds the rules; `CalibrationBoard` builds the session page
  (rows, before/after spread, counts).

## Configuration (`/setup/kpi`)

Company Setup → **Performance Framework**, five tabs:

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
- **Goal library** — goals set often: name, what success looks like, and the
  measure (progress to 100%, or a number from a start to a target in a unit).
  Archive, restore and permanent delete, through `PerformanceFrameworkWorkflow`.
  Setting a goal from an entry copies it, so editing the library never moves a goal
  already set.

## Export

`GET /performance/export?period=` streams the shown cycle as CSV: employee,
department, position, cycle, **framework**, status, **rating** (the company's own
word), attainment (0–100), the 1–5 index, and the key dates.

## Permissions

- `performance.view`: the overview, scorecards, goals and calibration sessions.
- `performance.manage`: open, launch a cycle, score, submit, record sign-off, delete
  drafts; ask for, cancel and remind reviews; set and manage goals; open, run and
  close calibration sessions.
- `performance.participate`: see and acknowledge your own appraisals, write the
  reviews you're asked for, and add and check in on your goals. Built-in **Staff**,
  **Department Head** and **HR Manager** have it, back-filled by the migration.
- `setup.kpi.view` / `setup.kpi.manage`: the configuration surface, the goal library
  included.

Built-in **HR Manager** gets all of them.

## The assistant

`App\Services\Assistant\Modules\PerformanceModule` puts appraisals in the chat
assistant ([ADR 0049](../decisions/0049-assistant-prompt-injection-defences.md)). It is
available with `performance.view` *or* `performance.participate`.

- **Reads** (`performance.view`):
  - `find_appraisals` — by person, cycle and status;
  - `get_appraisal` — one scorecard in full: result, band, every criterion's rating,
    the evaluator and remarks, the reviews asked for and the pooled comparison (under
    the same pooling rule as the screen), goal attainment, the calibration, and the
    employee's comment. Reading a named person's appraisal is written to the audit
    trail as `viewed`;
  - `performance_summary` — a cycle as the overview reads it (coverage, statuses,
    average, the band spread, and the departments rating furthest from the cycle
    average), through `PerformanceCalibration`;
  - `list_review_cycles`;
  - `find_goals` — a cycle's goals, by person, health or status;
  - `find_calibration_sessions`.
- **Writes** (`performance.manage`), all through `AppraisalWorkflow`, the class the
  screens now use too:
  - `open_appraisal`;
  - `rate_appraisal` — a rating is given by criterion name, as a number on that
    criterion's own scale or one of its level names ("Proficient"). Off-scale ratings
    are refused before anything is written;
  - `submit_appraisal`, `acknowledge_appraisal` (a sign-off recorded on the
    employee's behalf), `delete_draft_appraisal` and `launch_review_cycle` (with
    `self_reviews` and `manager_reviews`) always wait for the user's **Confirm** in the
    chat;
  - `request_reviews` and `set_goal` also wait for Confirm, because they notify other
    people.
  - Rating, submitting or discarding the user's own appraisal is refused.
- **Self-service** (`performance.participate`):
  - `find_my_appraisals` — the user's own appraisals, with results only once shared;
  - `acknowledge_my_appraisal`, with an optional comment, waits for Confirm;
  - `find_my_reviews` — the reviews asked of the user, the waiting ones first;
  - `find_my_goals` and `check_in_goal` (the user's own goals).
- **Screen-only:** writing a review, moving a rating in calibration, and the goal
  library.
- **Names resolve to exactly one person** (or an employee number) before any write.
  "Maria", when there are two, is refused rather than guessed.
- **Retrieval:**
  - a question about a person carries their last four appraisals and how their
    attainment moved;
  - their latest ML forecast is added only for someone with
    `analytics.performance.view`;
  - a question about the cycle that names nobody ("how is the review cycle going?")
    carries the cycle summary.
- **Self-service is the user's own only.** `find_my_appraisals` never returns an
  unshared result, and nothing in the participant tools reaches anyone else's
  appraisal or anyone's review answers.

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

Forced distribution, feeding results into pay, scheduled check-in reminders, an
employee choosing their own peer reviewers, and performance on the mobile app.
