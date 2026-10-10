# 0073 — Goals with check-ins, a goal library, and calibration sessions

- **Status:** Accepted
- **Date:** 2026-10-10
- **Related:**
  - [0028 — Appraisal frameworks and tenant rating models](./0028-appraisal-frameworks-and-tenant-rating-models.md)
    (the rating model a calibration moves within; scorecard snapshots);
  - [0072 — Self, peer and 360 reviews, and acknowledgement by the employee](./0072-appraisal-reviews-and-acknowledgement-by-the-employee.md)
    (built alongside; sharing a result is decided there);
  - module doc: [Performance Management](../modules/performance.md).

## Context

A cycle had no goals: the "Goal attainment" criterion was rated from memory at the
end. The overview's department calibration table showed which departments rated
generously, but nothing could be done about it. Ratings could not be moved with a
reason on record, and a result reached the employee before anyone had compared it
with the rest.

## Decision

### Goals are measured toward a target and checked in on

`performance_goals` records the employee, the cycle, an optional library entry, a
title and a description, and the following:

- **how it's measured** (`measure`):
  - `percent`: progress to 100%;
  - `number`: a start, a target and a unit, such as 120 tickets → 20 tickets;
- **weight**, relative, default 1;
- **due date**;
- **status**: `active`, then `achieved`, `missed` or `dropped`;
- **health**: `on_track`, `at_risk` or `off_track`, taken from the latest check-in;
- the **current value** and the **time of the last check-in**.

**Progress** is (current − start) ÷ (target − start), clamped to 0–100% by
`GoalProgress`. The span is signed, so a goal to reduce a number works the same way as
a goal to raise one.

**Attainment** is a person's weight-averaged progress over a cycle:

- An achieved goal counts as 100%.
- A dropped goal is left out.

Attainment appears on the scorecard as decision support. It never sets a rating.

**Check-ins** (`goal_check_ins`) are append-only. Each records a value, a health and a
note.

- **Who checks in:** the goal's owner, or anyone with `performance.manage`.
- **When:** only while the goal is active and its cycle isn't closed.
- **What changes:** a check-in updates the goal's `current_value`, `health` and
  `last_check_in_at`.
- **Who is notified:** the owner hears when someone else checks in on their goal, or
  sets one for them.

A goal with no check-in for 30 days is marked **stale** (`PerformanceGoal::STALE_AFTER_DAYS`).

**Who sets goals:**

- **HR** sets goals for one or several people at once, edits them, and closes them as
  achieved, missed or dropped (and can reopen them). HR deletes a goal only if nobody
  has checked in on it; a goal with history is dropped instead.
- **The employee** may add goals of their own once the cycle is open. They may delete
  one they added while it has no check-ins.

`GoalWorkflow` holds these rules for the screens and the assistant.

### A goal library, copied from

`goal_templates` holds goal entries: name, description, measure, start, target, unit,
active, and soft deletes. They are managed on a fifth tab of **Company Setup →
Performance Framework** (`setup.kpi.*`, through `PerformanceFrameworkWorkflow`).

Setting a goal from the library copies the entry's wording and target onto the goal,
the same way a scorecard line snapshots its criterion. Editing the library later never
moves a goal in flight.

### Calibration sessions move ratings, with a reason, before anyone reads them

`calibration_sessions` records:

- the cycle and a name;
- a `scheduled_for` date;
- `department_ids`, where null means the whole cycle;
- the status: `open`, then `completed` or `cancelled`;
- notes and a facilitator.

The people taking part are kept in `calibration_participants`, and are notified when
they're added.

- **No two open sessions in a cycle overlap.** The whole cycle overlaps everything.
  Two sets of departments overlap if they share one. A refusal names the other
  session.
- **What's in a session is decided live**, from the cycle's appraisals whose employee
  is in scope:
  - drafts are listed as not ready;
  - acknowledged appraisals are listed as final.
- **An adjustment** (`calibration_adjustments`) moves a submitted, unacknowledged
  appraisal to another band of **its own** rating model, with a required reason.
  - It sets `result_band` and `result_label`, the rating everyone reads.
  - It keeps what the scorecard gave in `scored_band` and `scored_label`, and stamps
    `calibrated_at`.
  - Moving the rating back to what the scorecard gave clears all three.
  - **Attainment and the 1–5 index are never changed**, so the ML forecast and
    promotion models keep learning from what was scored, not what was negotiated.
  - Every move is kept as a row, with who made it and why.
  - Nobody calibrates their own rating.
- **Holding.** While a session is open, an appraisal submitted in its scope is not
  shared with its employee (ADR 0072).
  - **Completing** the session shares every appraisal it held and notifies each
    employee. Its moves stand.
  - **Cancelling** is allowed only before anything has been moved. It releases the
    held appraisals the same way.
  - A move to a result that is already shared notifies the employee.

`CalibrationWorkflow` holds the rules. `CalibrationBoard` builds the session page:

- the rows, each with its scored and current band;
- the band spread **before** and **after**;
- the departments, the average and the counts.

## Consequences

- A rating can now differ from its attainment, and the record says by whom, when and
  why. Reports that read `result_label` show the calibrated rating. Anything that reads
  attainment or the 1–5 index is unchanged.
- An open session holds results back, so a session left open delays everyone in its
  scope. The holding is shown wherever it applies: the employee's list, the scorecard
  and the submit toast.
- There is no forced distribution. The before/after spread informs the room; it never
  constrains a move.
- The assistant gains:
  - for HR: `find_goals`, `set_goal` (Confirm) and `find_calibration_sessions`;
  - for participants: `find_my_goals` and `check_in_goal`.

  Moving a rating and the goal library stay on the screen.
- Not built: scheduled check-in reminders, feeding results into pay, and goals and
  calibration on the mobile app.
