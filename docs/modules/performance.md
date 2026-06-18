# Performance Management

Conduct employee appraisals against a set of **weighted KPI criteria** within a
**review period**. Each evaluation scores every criterion on a 1–5 scale; the
**overall score is derived** (weighted average) and follows a `draft → submitted →
acknowledged` lifecycle. Criteria and periods are configured in Company Setup. Data
model is ERD §8 (with the §2 config tables); everything is tenant-scoped (ADR 0005).
See [ADR 0012](../decisions/0012-performance-management.md) for the design.

> Status: **Active** · Route prefix: `/performance` · Config: `/setup/kpi`
> Sidebar: Workforce → Performance Management (gated by `performance.view`);
> Company Setup → KPI & Evaluation Criteria (gated by `setup.kpi.view`)

## Surfaces

- **`/performance`** — the **overview**: a KPI bar (total evaluations, in-progress
  drafts, awaiting sign-off, average score) and a filterable list of evaluations
  (search by employee, filter by period / status), each showing the employee, period,
  overall score and status. HR can **open a new evaluation**.
- **`/performance/{evaluation}`** — the **scorecard**: a header with the employee,
  period, evaluator, status and the overall score (with a rating label + bar), then a
  row per KPI criterion. While a **draft**, HR rates each criterion (1–5) and adds
  optional comments, with the overall score updating live; **Save** persists, **Submit**
  locks the card (every criterion must be scored), and a draft can be **Deleted**. Once
  **submitted**, HR can **Acknowledge** it. An acknowledged evaluation is final and
  read-only.

## The overall score

`App\Support\Performance\PerformanceScorer` is the single source of truth: the overall
is the **weighted average of the scored lines** on the 1–5 scale —
`Σ(score × weight) / Σ(weight)`, rounded to two decimals. Only scored lines contribute
(so a draft shows a live running score); if every weight is zero it falls back to a
simple mean. The score is recomputed on every save and on submit — it is never trusted
from the client. Each line snapshots the criterion's **label and weight** when the
evaluation is opened, so archiving a criterion later never changes a past appraisal.

## Configuration (`/setup/kpi`)

Company Setup → **KPI & Evaluation Criteria** manages two lists:

- **KPI criteria** — name, description, weight (%), active flag. The header shows the
  total active weight (a gentle nudge toward 100%). Full lifecycle: create / edit /
  archive (soft delete) / restore / permanent delete; each row shows how many
  evaluations use it. A criterion used by an evaluation cannot be permanently deleted.
- **Evaluation periods** — name, start / end dates, status (`draft | open | closed`).
  Evaluations can only be opened while a period is **open**. Same archive lifecycle; a
  period with evaluations cannot be permanently deleted.

## Permissions

`performance.view` (overview & scorecards), `performance.manage` (open / score /
submit / acknowledge / delete drafts); `setup.kpi.view` / `setup.kpi.manage` (the
configuration surface). Built-in **HR Manager** gets all of them.

## Out of scope (this cut)

Self / peer / 360 reviews, employee self-service acknowledgement, goal & competency
libraries, feeding scores into pay or promotions, Training & Development (a separate
module), and an assistant capability.
