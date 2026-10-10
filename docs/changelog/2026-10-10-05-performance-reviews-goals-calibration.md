# Performance: 360 reviews, acknowledgement by the employee, goals and calibration sessions

An appraisal used to be one person's view. The employee couldn't see it, and HR
recorded their sign-off for them. Goals and calibration existed only as a criterion's
name and a table of averages. Now the people around an appraisal take part in it. See
[ADR 0072](../decisions/0072-appraisal-reviews-and-acknowledgement-by-the-employee.md)
and [ADR 0073](../decisions/0073-goals-with-check-ins-and-calibration-sessions.md).

## Highlights

- **Reviews: self, manager, peer and direct report.**
  - HR asks people from the scorecard (**Ask for reviews**), or at cycle launch: a
    self-review from each person, and a review from each person's manager.
  - The relationship comes from the reporting line, so the label is never wrong.
  - Reviewers answer on the appraisal's own criteria and scales, then say what went
    well and what to develop. They can save, hand in, or decline with a reason.
  - The scorecard compares the evaluator's rating with Self, Manager, Peers and Direct
    reports. Peers and direct reports are averaged and shown without names, and only
    once two have answered.
  - Reviews never change the result.
- **The employee acknowledges their own appraisal.**
  - **My appraisals** lists their appraisals.
  - A submitted appraisal is shared with them and they're notified. They read it, with
    their self-review beside each rating, and **Acknowledge** it with an optional
    comment. The evaluator is notified.
  - HR's sign-off stays as **Record sign-off on their behalf**, for paper sign-offs,
    and the record shows who acknowledged it.
  - Nobody rates, submits or discards their own appraisal.
- **Goals with check-ins.**
  - A goal is either progress to 100%, or a number from a start to a target, such as
    120 → 20 tickets. It has a weight.
  - Check-ins record the value, a health (on track, at risk, off track) and a note. A
    goal with no check-in for 30 days shows as stale.
  - HR sets goals for one or several people. Employees add their own and check in
    from **My goals**.
  - The scorecard shows the person's goals and their attainment.
- **A goal library** on Company Setup → Performance Framework. A goal set from it
  copies the entry, so editing the library never moves a goal in flight.
- **Calibration sessions.**
  - A session covers the whole cycle or chosen departments, and holds back the results
    it covers until it ends.
  - In the session room, each appraisal shows its attainment, what the scorecard gave
    and the rating now. **Move** changes the rating to another band of its own model,
    with a required reason. Attainment never changes.
  - The band spread is shown before and after.
  - **Complete** shares everything the session held back.
- **One way in.** Performance Management is one sidebar entry for anyone who runs or
  takes part in appraisals. Its sections are **My appraisals · My goals · Reviews
  (count) ‖ Appraisals · Goals · Calibration**, and each shows only to people who may
  open it. The Staff dashboard gains **My performance**.

## Server

- New permission `performance.participate`, back-filled to the built-in Staff,
  Department Head and HR Manager roles.
- Migration `2026_10_10_020000_create_performance_self_service_tables`:
  - adds `appraisal_reviews`, `appraisal_review_scores`, `goal_templates`,
    `performance_goals`, `goal_check_ins`, `calibration_sessions`,
    `calibration_participants` and `calibration_adjustments`;
  - adds `shared_at`, `acknowledged_by`, `employee_comment`, `scored_band`,
    `scored_label` and `calibrated_at` to `performance_evaluations`;
  - back-fills `shared_at` for appraisals already submitted.
- `App\Support\Performance` gains:
  - `ReviewWorkflow` and `FeedbackSummary` for reviews;
  - `AppraisalSharing`, which decides when a result is shared;
  - `GoalWorkflow` and `GoalProgress` for goals;
  - `CalibrationWorkflow` and `CalibrationBoard` for calibration sessions.

  `AppraisalWorkflow` gains the self-guard, closes waiting reviews on submit, shares on
  submit, and acknowledges with who did it and their comment.
- Routes under `/performance`: `me`, `me/goals`, `reviews`, `goals` and `calibration`,
  all declared before the `{evaluation}` wildcard. Setup gains `setup/kpi/goals`.
  `/performance` sends someone who only takes part to `/performance/me`.
- Privacy:
  - The reviewer's form never carries the evaluator's ratings or evidence.
  - My appraisals answers 404 for anyone else's appraisal and for an unshared one, and
    never sends the AI insights.
- The assistant:
  - HR gains `request_reviews`† and `set_goal`†, plus `find_goals` and
    `find_calibration_sessions`.
  - Participants gain `find_my_appraisals`, `acknowledge_my_appraisal`†,
    `find_my_reviews`, `find_my_goals` and `check_in_goal`.
  - `get_appraisal` carries the pooled reviews, goal attainment and the calibration.
  - The router knows goals, reviews and calibration.

  († waits for Confirm.)
- `SystemGuide` gains My appraisals, My goals, Reviews, Goals and Calibration.
- Data Export covers the eight new tables. A new `calibration_sessions` scope confines
  the participants pivot.
- The seeder adds:
  - a goal library;
  - goals with check-ins (a few gone stale);
  - reviews on the open cycle;
  - an open calibration session holding the cycle's submitted results, with one move.

  The staff login (earlkian8) gets a shared FY 2025 appraisal to acknowledge, a
  self-review and a colleague's review to write, and three goals.

## Frontend

- New pages, all under `performance/`:
  - `me` and `my-appraisal`;
  - `reviews` and `review`;
  - `my-goals` and `goals`;
  - `calibration` and `calibration-session`.
- The scorecard gains notices, the calibration block, the goals panel, the reviews
  panel with its comparison table, and the acknowledgement details.
- The appraisals overview is titled **Appraisals**, and **Open one** and **Launch
  cycle** move into its toolbar.
- `PerformanceNav` is built on `ModuleNav`. Request-reviews, goal, check-in, session
  and move-rating dialogs use the shared modal kit.
- The launch dialog asks for self-reviews and manager reviews.
- The Performance Framework gains a **Goal library** tab.
- **Shared breadcrumbs:** on a phone, the top bar now shows only the current page, on
  one truncated line. Long trails used to wrap under the bar and over the page title,
  and they did on the existing scorecard too.

## Docs

- ADRs 0072 and 0073.
- `modules/performance.md`, including the surfaces, rules, the assistant and out of
  scope. Also `modules/assistant.md`, `modules/data-export.md` and
  `modules/help-center.md`.
- `database/performance-tables.md` (the eight tables and the new columns) and ERD §2b
  and §8.
- Help:
  - new: *Your appraisals and reviews*, *Goals and check-ins* and *Calibration
    sessions*;
  - *Performance appraisals* and *Performance framework* are updated.
- `not-yet-built.md`:
  - removed: reviews, self-acknowledgement, goal check-ins and calibration sessions;
  - still listed: forced distribution, feeding results into pay, scheduled check-in
    reminders, and employees choosing their own peer reviewers;
  - added under Mobile: performance on the mobile app.

## Notes

- **Verified:**
  - Pest (count in the commit message), Pint, tsc, ESLint, Prettier and the build;
  - the migration rolls back and re-applies on a scratch database, and its columns
    were checked against the table doc;
  - HR and Staff in headless Chromium at 1440 and 390 px. The flows worked: acknowledge
    with a comment, check in on a goal, move a rating with a reason, complete a
    session (5 appraisals shared), and the reviewer picker.
- Docker was not built or run. Gemini was never called: the key stays empty and the
  tests use the stub models.
- Demo colleagues mostly have no sign-in. Their seeded review answers are written
  straight to the table. In the app, asking someone for a review needs an account that
  can answer it.
