# 0072 — Appraisals: self, peer and 360 reviews, and acknowledgement by the employee

- **Status:** Accepted
- **Date:** 2026-10-10
- **Related:**
  - [0012 — Performance management](./0012-performance-management.md) and
    [0028 — Appraisal frameworks and tenant rating models](./0028-appraisal-frameworks-and-tenant-rating-models.md)
    (the module this extends);
  - [0071 — Recognition](./0071-recognition-kudos-nominations-points-and-rewards.md)
    (the precedent for self-service: its own permission, one sidebar entry, `ModuleNav` sections);
  - [0073 — Goals with check-ins and calibration sessions](./0073-goals-with-check-ins-and-calibration-sessions.md)
    (built alongside; calibration decides when a result is shared);
  - module doc: [Performance Management](../modules/performance.md).

## Context

An appraisal was one person's view: the evaluator rated the scorecard and nobody else
was asked. The employee could not see their own appraisal. When they signed it off,
HR recorded the sign-off for them, because there was no screen where they could do it
themselves. Anyone with `performance.manage` could also rate, submit or discard their
own appraisal.

## Decision

### Taking part is its own permission

**`performance.participate`** is described as "See and acknowledge your own
appraisals, write the reviews you're asked for & check in on your goals
(self-service)". It is given to the built-in Staff, Department Head and HR Manager
roles, back-filled with `PermissionSyncer::grant()` the same way as
`awards.participate`. Running the programme stays `performance.view` and
`performance.manage`.

### A review is input to an appraisal, never its result

- `appraisal_reviews` has one row per appraisal and reviewer employee (unique).
- `appraisal_review_scores` has one row per scorecard line answered, with a score and
  remarks. Each score is checked against that line's own frozen scale, the same way
  the evaluator's ratings are.
- **The relationship is derived, never typed.** It comes from
  `employees.manager_id`:
  - the appraised person is **self**;
  - their manager is **manager**;
  - someone who reports to them is **direct report**;
  - anyone else is **peer**.

  HR picks the people, so the label can't be wrong.
- Statuses: `pending`, then `submitted`, `declined` or `cancelled`.

`ReviewWorkflow` holds the rules. Its refusals are `AppraisalException`s, so the
screens and the assistant refuse in the same words.

- **Who can be asked:**
  - Requests are made on a draft appraisal (`performance.manage`), or at cycle launch
    (a self-review from each person, a review from each person's manager).
  - A reviewer must be an active employee whose account holds
    `performance.participate`. Each person is checked on their own: asking five people
    where one can't answer still asks the other four, and names the fifth.
  - The appraisal's evaluator is never asked, because they rate the scorecard itself.
- **Answering:**
  - A **self-review** must rate every criterion.
  - Any other review needs at least one rating or one written answer. Leaving a
    criterion blank is how a reviewer says they can't judge it.
  - Anyone but the person themselves may **decline**, with a reason.
- **HR's tools:** HR may cancel a request, or remind a reviewer at most once a day.
- **Submitting the appraisal closes every pending request.** The reviewers aren't
  told, because nothing is left for them to do.

### Pooled so no single answer can be singled out

`FeedbackSummary` builds the comparison on the scorecard. It has one row per
criterion and these columns: the evaluator's own rating, then **Self**, **Manager**,
**Peers** and **Direct reports**.

- **Self and manager answers are attributed.** Each comes from one person whose
  identity the reviewer knows.
- **Peers and direct reports are pooled.** Their ratings are averaged and their
  comments are listed without names. A pool is shown only once **at least two** in it
  have answered (`POOL_MINIMUM`). Until then, the table says how many have answered.

The person reviewed never sees anyone else's answers, only their own self-review. The
reviewer's form never shows the evaluator's ratings or evidence.

### Shared, then acknowledged by the employee

`performance_evaluations` gains three columns:

- `shared_at`: when the employee could first read the result;
- `acknowledged_by`: the user who acknowledged it;
- `employee_comment`: what the employee said when they did.

Submitted appraisals already in the database are back-filled as shared at their
submission time.

- **Submitting shares the appraisal and notifies the employee.** The exception is an
  appraisal covered by an open calibration session (ADR 0073). It is shared when that
  session completes or is cancelled.
- **My appraisals** (`/performance/me`) lists a person's own appraisals.
  - An unshared one shows no result, only "in progress" or "being calibrated".
  - A shared one opens read-only. It shows the ladder, every rating and its evidence,
    the remarks, a note if calibration moved the rating, and the person's self-review
    beside the evaluator's ratings.
  - Anyone else's appraisal answers 404, and so does an unshared one.
- **Acknowledge**, with an optional comment of up to 2,000 characters.
  - Acknowledging says the person has read the appraisal, not that they agree. The
    comment is where they say so.
  - It is final, and the evaluator is notified.
- HR's **Record sign-off on their behalf** stays, for a sign-off given on paper or in
  person. It is refused while the appraisal is unshared. The scorecard says who
  acknowledged it and how.

### Nobody conducts their own appraisal

`AppraisalWorkflow::rate()`, `submit()` and `discard()` refuse when the appraisal is
about the person acting (`OWN_APPRAISAL`), on the screens and in chat. HR opening
their own appraisal sees a visible note in place of the controls, and nobody to ask
for reviews. Recording a sign-off on your own appraisal counts as your own
acknowledgement.

### One way in

The module has one sidebar entry, **Performance Management**. It shows for
`performance.view` *or* `performance.participate`. `PerformanceNav` (`ModuleNav`) puts
two groups of sections in each page header:

- **My appraisals · My goals · Reviews**, with the number of reviews waiting;
- **Appraisals · Goals · Calibration**.

Someone who only takes part is sent from `/performance` to `/performance/me`. The
Staff dashboard's *Your workspace* gains **My performance**.

## Consequences

- An appraisal can now draw on everyone around the person without any one reviewer
  being exposed. Two answers is a low bar for anonymity: in a pool of two, each
  person can work out the other's answer. It is the smallest pool that hides a single
  answer from the evaluator.
- Reviews never change the scored result. The evaluator still rates every criterion;
  the comparison sits beside their ratings.
- Asking for a review needs an account that can answer it. A colleague with no
  sign-in is named in the refusal rather than asked into a void.
- The assistant gains `request_reviews` (Confirm) for HR, and `find_my_appraisals`,
  `acknowledge_my_appraisal` (Confirm) and `find_my_reviews` for participants.
  `get_appraisal` adds the review status and the pooled comparison, under the same
  pooling rules. Writing a review stays on the screen.
- Not built: an employee choosing their own peer reviewers, and performance on the
  mobile app.
