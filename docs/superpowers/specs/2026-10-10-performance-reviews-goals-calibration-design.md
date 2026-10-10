# Performance: self, peer and 360 reviews; acknowledgement by the employee; goals with check-ins; calibration sessions — design

- **Date:** 2026-10-10
- **Asked for:** from `docs/not-yet-built.md`, Performance:
  "No self, peer or 360 reviews. When an employee acknowledges an appraisal, HR records
  it; the employee can't do it themselves. No goal check-ins and no calibration
  sessions." The user said "go all out, implement with precision", with the UI kept
  consistent with the other modules.
- **Path:** architectural (new tables, a new permission, new screens, assistant
  tools). As with the events and recognition change, this spec records the decisions
  made on the user's behalf rather than waiting at each gate.
- **Precedent:** ADR 0070/0071 (self-service as its own permission, back-filled to the
  built-in roles; one sidebar entry per module; `ModuleNav` sections; underline tabs
  for in-page views; visible notes instead of disabled buttons for self-guards).

## 0. Vocabulary

| Word on screen | What it is | Table |
| --- | --- | --- |
| **Appraisal** | The official scorecard, conducted by an evaluator (unchanged). | `performance_evaluations` |
| **Review** (self / manager / peer / direct report) | Feedback on one appraisal's criteria from one person. Input to the appraisal, never its result. | `appraisal_reviews`, `appraisal_review_scores` |
| **Goal** | Something an employee commits to in a cycle, measured toward a target, with check-ins. | `performance_goals`, `goal_check_ins` |
| **Goal library** | Reusable goal wording and targets (Company Setup). | `goal_templates` |
| **Calibration session** | A meeting in which a cycle's submitted ratings are compared and moved, each move with a reason. | `calibration_sessions`, `calibration_participants`, `calibration_adjustments` |

## 1. Permission

**`performance.participate`** — "See and acknowledge your own appraisals, write the
reviews you're asked for & check in on your goals (self-service)". Back-filled to the
built-in Staff, Department Head and HR Manager roles with `PermissionSyncer::grant()`,
and given by `OrganizationProvisioner` to new companies. Running the programme stays
`performance.view` / `performance.manage`.

## 2. Reviews: self, manager, peer and direct report (360)

### 2.1 Shape
- One `appraisal_reviews` row per (appraisal, reviewer employee), unique.
- **The relationship is derived, never typed**: reviewer = the appraised person →
  `self`; reviewer = their manager (`employees.manager_id`) → `manager`; reviewer's
  manager = the appraised person → `direct_report`; anyone else → `peer`. HR picks
  people; the label cannot be wrong.
- `status`: `pending` → `submitted` | `declined` | `cancelled`. `due_on` (defaults to
  the cycle's end date), `strengths`, `improvements`, `decline_reason`, `requested_by`.
- `appraisal_review_scores`: one per appraisal line answered — `score` (nullable) and
  `remarks`. A rating is checked against **that line's own frozen scale**
  (`PerformanceScore::acceptsScore()`), as the appraisal's own ratings are.

### 2.2 Rules (`App\Support\Performance\ReviewWorkflow`, refusals as `AppraisalException`)
- Requests are made on a **draft** appraisal (`performance.manage`), or at cycle
  launch (2.4).
- The reviewer must be an **active employee with an active account** that holds
  `performance.participate` — someone who can actually answer. Refused otherwise, in
  words naming the person.
- The **evaluator** of the appraisal is never asked: they rate the scorecard itself.
- One request per person per appraisal. A declined or cancelled request may be asked
  again (it is re-opened).
- The reviewer answers while the appraisal is a draft and the request is pending:
  save (any part), then **submit**. A **self** review must rate every criterion; any
  other review needs at least one rating or one written answer ("can't judge" is
  leaving a criterion blank).
- Anyone but the person themselves may **decline** with a reason. HR may **cancel** a
  pending request, and **remind** (a notification; once per day per request).
- **Submitting the appraisal closes every pending request** (cancelled, with the
  reviewer told nothing — the work is over). A submitted review is kept.
- Notifications: the reviewer when asked (link to the form) and when reminded; the
  evaluator when a review is submitted or declined.

### 2.3 Who sees what (`App\Support\Performance\FeedbackSummary`)
- **HR (`performance.view`)** sees, on the scorecard, who was asked and where each
  request stands, and a **comparison table**: criterion rows × Self · Manager ·
  Peers · Direct reports, with the evaluator's own rating beside them.
- **Self and manager answers are attributed** (one person each, known to the
  reviewer). **Peers and direct reports are pooled**: their ratings are averaged and
  their written answers listed without names, and a pool is shown only once **at
  least two** in it have answered — so no single answer can be singled out. Below
  that, the table says how many have answered and that it opens at two.
- **The employee** sees their own self-review, side by side with the final ratings
  once the appraisal is shared. They never see peer, manager or direct-report
  answers.
- The reviewer sees their own answer (read-only once submitted).

### 2.4 Cycle launch
The launch dialog gains two options: **ask each person for a self-review** and **ask
each person's manager for a review** (skipped where the manager has no account, is
the evaluator, or is not active). The toast counts the requests made.

## 3. Acknowledgement by the employee

- `performance_evaluations` gains `shared_at`, `acknowledged_by` (user) and
  `employee_comment`.
- **Shared** = the employee may see the result. Submitting shares the appraisal at
  once, **unless an open calibration session covers it** (4.3); then it is shared when
  the session completes or is cancelled. Existing submitted appraisals are back-filled
  as shared at their submission time.
- **My appraisals** (`/performance/me`, `performance.participate`) lists the person's
  own appraisals. An unshared one is listed without a result ("in progress" or "being
  calibrated"); a shared one opens read-only (`/performance/me/{hashid}`): the result
  on its ladder, every criterion's rating and evidence, the overall remarks, the
  calibration note if moved, and their self-review beside the ratings. Anyone else's
  appraisal is a 404.
- **Acknowledge**, with an optional comment (up to 2,000 characters). Acknowledging
  says they have seen it, not that they agree; the comment is where they say so. It is
  final. The evaluator is told, with whether a comment was left.
- HR's **Record sign-off** stays for a paper or verbal sign-off, now recorded as *on
  their behalf* (`acknowledged_by` = HR) and refused while the appraisal is unshared.
  The scorecard says who acknowledged and how.
- The employee is told when their appraisal is shared ("ready to read and
  acknowledge"), and when calibration moves a shared rating.
- **Nobody conducts their own appraisal**: rating, submitting and discarding one's own
  appraisal are refused ("Your self-review is where your view goes"), on the screens
  and in chat. Recording sign-off on one's own is the employee's own acknowledgement.

## 4. Goals with check-ins, and a goal library

### 4.1 Goals
- `performance_goals`: employee, cycle, optional library template, `title`,
  `description`, `measure` (`percent` — progress to 100 %; `number` — from a start
  value to a target, in a unit such as "deals" or "PHP"), `start_value`,
  `target_value`, `current_value`, `unit`, `weight` (relative, default 1), `due_on`,
  `status` (`active` → `achieved` | `missed` | `dropped`), `health` (`on_track` |
  `at_risk` | `off_track`, from the latest check-in), `last_check_in_at`,
  `created_by`, `closed_at`.
- **Progress** = (current − start) ÷ (target − start), clamped to 0–100 %
  (`GoalProgress`); a falling target (reduce defects from 40 to 10) works because the
  span is signed. A person's goal attainment for a cycle is the weight-averaged
  progress of their goals that are not dropped.
- **Check-ins** (`goal_check_ins`): a new current value, a health, and a note, by the
  goal's owner (self-service) or by HR/a manager (`performance.manage`). Only while the
  goal is active and the cycle is not closed. A check-in updates the goal's
  `current_value`, `health` and `last_check_in_at`; the history is append-only.
- A goal with no check-in in 30 days is marked **stale** on the lists.
- HR (`performance.manage`) sets goals for one or several people at once, from the
  library or written out; edits; closes (achieved / missed / dropped); deletes one that
  has no check-ins. An employee may add goals for themselves in an open cycle, and
  delete their own while they have no check-ins.
- Screens: **Goals** (`/performance/goals`, `performance.view`) — the cycle's goals,
  filterable by health, status, department, with progress and the last check-in, and a
  goal drawer with the timeline; **My goals** (`/performance/me/goals`) for the
  employee, with a check-in form. The appraisal scorecard shows the person's goals for
  that cycle and their attainment, as decision support (it does not set a rating).
- Notifications: the owner when HR sets a goal for them; the owner when someone else
  checks in on their goal.

### 4.2 Goal library
`goal_templates` (name, description, measure, target, unit, active, soft deletes),
managed on a fifth tab of **Company Setup → Performance Framework** (`setup.kpi.*`).
Setting a goal from the library copies its wording and target onto the goal (a
snapshot, like a scorecard line), so retuning the library never moves a goal in flight.

### 4.3 Calibration sessions
- `calibration_sessions`: cycle, name, `scheduled_for` (date), scope
  (`department_ids` JSON; null = the whole cycle), `status` (`open` → `completed` |
  `cancelled`), notes, `facilitator_id`, `completed_at`. Participants
  (`calibration_participants`, users) are told when invited.
- **No two open sessions overlap** in a cycle (the whole cycle overlaps everything; two
  department sets overlap if they share one). Refused naming the other session.
- **What is in a session** is decided live: the cycle's appraisals whose employee is in
  scope. Drafts are listed as not ready; acknowledged ones as final.
- **An adjustment** (`calibration_adjustments`) moves a **submitted, unacknowledged**
  appraisal to another band **of its own rating model**, with a required reason. It
  sets `result_band` / `result_label` (the official rating everyone reads) and keeps
  what the scorecard gave in `scored_band` / `scored_label`; moving it back clears
  those. Attainment (`overall_percent`) and the 1–5 index are not changed — the ML
  models keep reading what was scored. Every move is a row (history), and the
  activity log records it.
- **Holding**: while a session is open, appraisals submitted inside its scope are not
  shared. **Completing** the session shares every held appraisal in scope (each
  employee told), and locks its adjustments. **Cancelling** is allowed only before any
  adjustment, and releases the held appraisals the same way.
- Screens: **Calibration** (`/performance/calibration`) lists sessions by cycle; a
  session page shows the scope's band spread **before → after**, the per-department
  deviation table (the existing calibration view), and the appraisals with their
  scored and current band, a band picker and a reason (`performance.manage`, session
  open).

## 5. Navigation

- The sidebar entry **Performance Management** shows for `performance.view` *or*
  `performance.participate`; `/performance` sends someone who only takes part to
  `/performance/me`.
- `PerformanceNav` (`ModuleNav`) in each page header:
  **My appraisals · My goals · Reviews (count waiting)** ‖ **Appraisals · Goals ·
  Calibration**.
- Page titles are the section names; the breadcrumb root is "Performance Management".
  The overview's **Open one** and **Launch cycle** move into its toolbar.
- The Staff dashboard's *Your workspace* gains **My performance**.

## 6. The assistant

`PerformanceModule` is available with `performance.view` *or*
`performance.participate`.
- Participants: `find_my_appraisals` (own, shared results only), `acknowledge_my_appraisal`
  (Confirm), `find_my_reviews` (reviews asked of them), `find_my_goals`,
  `check_in_goal` (own goals).
- HR: `request_reviews` (Confirm — it notifies people), `find_goals`, `set_goal`
  (Confirm — it notifies the owner), `find_calibration_sessions`; `get_appraisal` adds
  the review status, the pooled comparison and goal attainment (same anonymity rules).
- Writing a review's ratings, calibrating and the goal library stay screen-only.

## 7. Elsewhere

- Help Center: new articles (*Your appraisals and reviews*, *Goals and check-ins*,
  *Calibration sessions*) and the performance article updated.
- SystemGuide, ToolRouter keywords, Data Export catalogue (new tables), demo seeder
  (reviews, goals with check-ins, an open calibration session, one appraisal for the
  demo staff login to acknowledge), the module doc, ADRs 0072 and 0073, the database
  docs and ERD, `not-yet-built.md`, a changelog entry and the commit text.
- **Not built** (stays in `not-yet-built.md`): forced distribution, feeding results
  into pay, scheduled check-in reminders, an employee choosing their own peer
  reviewers, and performance on the mobile app.

## 8. Build order

1. Migration, models, permission. 2. Workflows (reviews, sharing/acknowledgement,
goals, calibration) with Pest tests. 3. Controllers, requests, resources, routes.
4. Frontend: nav, overview toolbar, scorecard additions, My appraisals, review form,
Reviews, Goals, My goals, Calibration list and session, goal library tab, launch
options, dashboard. 5. Assistant. 6. Help, guide, export, seeder. 7. Docs. 8. Verify
(Pest, Pint, tsc, ESLint, Prettier, build; HR and Staff in the browser at 1440 and
390 px).
