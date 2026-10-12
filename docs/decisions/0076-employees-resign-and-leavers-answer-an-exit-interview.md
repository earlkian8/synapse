# 0076 — Employees file their own resignation; leavers answer an exit interview

- **Status:** Accepted
- **Date:** 2026-10-11
- **Related:**
  - [0048 — Talent acquisition and offboarding join the table kit](./0048-talent-acquisition-and-offboarding-join-the-table-kit.md)
    (the screens this extends);
  - [0051 — Assistant: offboarding, reports and workspace members](./0051-assistant-offboarding-reports-and-workspace-members.md)
    (the module that gains the new tools);
  - [0077 — An exit's papers are generated as PDFs](./0077-an-exits-papers-are-generated-as-pdfs.md)
    (built alongside);
  - module doc: [Offboarding](../modules/offboarding.md).

## Context

An exit started when HR typed it in. The employee who was leaving had no way to say
so in SYNAPSE: they wrote a letter, HR read it and started the exit by hand. Once it
started, the employee could not see their own clearance either: what each
department still needed from them reached them by word of mouth.

"Conduct exit interview" was a line on the default checklist. It was ticked when
somebody said they had talked, and what the leaver said was kept nowhere. No one
could ask why people leave.

## Decision

### A resignation is a request the employee files

New permission **`offboarding.resign`**, back-filled to the built-in Staff,
Department Head and HR Manager roles and added to their blueprints.

`resignation_requests` (hashid) holds what the employee filed: the **proposed last
day**, an optional **reason**, and a status `pending → accepted | declined |
withdrawn`. It also records who decided, when and with what note, and the exit it
became.

`App\Support\Offboarding\ResignationWorkflow` is the only write path. The web
screens, the mobile API and the assistant all call it:

- **file**: refused once the person has left, while their exit is under way, or
  while another of theirs is pending. The proposed day may not be in the past on the
  organisation's calendar. Less than **30 days' notice** (Labor Code Art. 300) is
  allowed: agreeing to it is HR's call. The notice to HR and every screen say so.
- **withdraw**: only while it is pending, by the employee.
- **accept** (`offboarding.manage`): HR sets the last working day agreed and,
  optionally, the clearance template. The request becomes the exit through
  `OffboardingWorkflow::start`, the same path the screens take. The type is
  *resignation* and the notice date is the day it was filed. An exit cancelled
  before is reopened, not duplicated.
- **decline**: needs a note, which the employee reads.

When HR starts an exit by hand for somebody whose resignation is waiting, the
request is settled as accepted (`settleFor`), so it does not wait for a decision
already made.

HR (`offboarding.manage`) is told when a resignation is filed or withdrawn. The
employee is told when it is decided. Every step is in the audit trail, and the
reason never appears there.

### The leaver's own view

`MyExit` builds one person's view of leaving, and both **My resignation** on the web
and **Resignation** in the app show it:

- their resignations and whether they may file one;
- their exit's dates and clearance, item by item, including a flagged item's
  reason, because that is what they have to sort out;
- their exit interview;
- whether their Certificate of Employment is ready.

HR's reason for the exit is not shown. The reason on their own resignation is,
because they wrote it.

`/offboarding` sends somebody who may only resign to `/offboarding/me`. The sidebar
entry shows for `offboarding.view` *or* `offboarding.resign`.

### The exit interview is a fixed survey, sent with the exit

`exit_interviews`, one per exit, holds:

- a status `pending | submitted | waived`;
- the **questions as they were asked** (JSON);
- the answers (JSON);
- the sent, reminded, submitted and waived stamps.

The questionnaire (`ExitInterviewQuestionnaire`) is fixed, so answers can be read
across every leaver:

- the main reason for leaving (one of 13);
- seven ratings from 1 to 5: the work, manager, team, growth, pay, balance and
  culture;
- would you recommend us, would you come back (yes / maybe / no);
- three open questions.

The insights read answers by key, so wording can change without losing a year of
answers.

- **Sent automatically** when an exit starts, except a termination. Asking someone
  who was let go to rate the company is HR's call, so HR sends one by hand.
- **Answering it signs off** any pending checklist item whose label mentions "exit
  interview", in the leaver's name.
- **Reminded once**: `offboarding:exit-interview-reminders` runs daily at 09:00 and
  reminds a survey that has waited 3 days while its exit is in progress.
- **Waived** by HR. The leaver can no longer answer it.

The answers are read by holders of `offboarding.view`, on the exit's *Exit
interview* tab and on **Exit interviews**. That page shows the response rate, the
reasons people leave, each rating's average and spread, and the would-recommend and
would-return tallies, over 3, 6 or 12 months or all time. The audit trail records
that a survey was sent or answered, never what it said.

### Screens

The Offboarding header gains sections **My resignation ‖ Exits · Resignations
(pending count) · Exit interviews**. Resignations and exit interviews are tables,
like the exits board. The exit's page gains underline tabs **Clearance · Exit
interview**.

## Consequences

- The employee starts their own exit, and HR decides it in one step.
- Nothing about the exit interview is configurable. A company that wants other
  questions cannot have them yet. That trade keeps the insights comparable.
- The assistant gains `find_resignations`, `decide_resignation`,
  `get_exit_interview` (audited as `viewed`), `exit_interview_summary`,
  `send_exit_interview` and `waive_exit_interview`. For the user's own exit it gains
  `get_my_exit`, `file_my_resignation` and `withdraw_my_resignation`. Deciding,
  waiving, filing and withdrawing wait for Confirm.
- Not built: answering the exit interview in chat (it is a form), a rehire-eligible
  flag fed by "would come back", and final pay.
