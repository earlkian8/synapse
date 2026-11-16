# 0105 — Discipline, taken through the twin-notice rule, under a code of conduct

- **Status:** Accepted
- **Date:** 2026-11-16
- **Related:**
  - [0087 — Uploads are private to their workspace](./0087-uploads-are-private-to-their-workspace.md)
    (the evidence);
  - [0077 — An exit's papers are generated as PDFs](./0077-an-exits-papers-are-generated-as-pdfs.md)
    (the letterhead renderer, extracted from it as `DocumentRenderer`);
  - module docs: [Discipline](../modules/discipline.md),
    [discipline tables](../database/discipline-tables.md).

## Context

SYNAPSE had nowhere to keep an employee's conduct. Incident reports, notices to explain
and decisions lived in Word files and email, and a dismissal could fail for want of
procedure even when the cause was good: Article 292(b) of the Labor Code and DOLE
Department Order No. 147-15 require the **twin-notice rule** for a just-cause case:

1. a **first written notice** naming the specific charge and narrating the facts in
   detail (a general description won't do), with **at least five calendar days** to
   answer in writing;
2. **ample opportunity to be heard**: the written answer, and a hearing or conference
   when the employee asks for one in writing, the facts are in dispute, or company
   practice requires it;
3. a **second written notice** stating that every circumstance was considered and
   giving the decision.

A dismissal for a just cause without this is upheld but the employer pays nominal
damages. Dismissal itself rests only on the just causes of Article 297: serious
misconduct, willful disobedience, gross and habitual neglect, fraud or willful breach of
trust, a crime against the employer, and analogous causes. A preventive suspension
while a case is heard runs at most 30 days. Philippine HRIS products keep a "violations
profile" of each employee for the same reason.

## Decision

### The case, and the order it goes in

`disciplinary_cases`, one incident about one employee, referenced `DC-<year>-<n>`, moved
only by `DisciplineWorkflow`, which **won't let a step be skipped**:

| Status | What happened | Who is told |
|---|---|---|
| `reported` | An incident report: when, where, what, who saw it. HR's alone. | holders of `discipline.manage` |
| `notice_issued` | The notice to explain: the charge, the facts (the report's narrative), an answer due ≥ 5 calendar days away. | the employee |
| `explained` | Their written answer, from the app or recorded from paper by HR; whether they ask for a hearing. | HR, when the employee sends it |
| `hearing` | A hearing set (when, where), then recorded (minutes; attended or not). | the employee, when it's set |
| `decided` | The notice of decision: upheld or not, the penalty and its dates, the reasons. | the employee |
| `closed` / `withdrawn` | Closed after a decision; withdrawn (with why) any time before one. | the employee, if they were served |

A decision is **refused** until a notice has been served; until the answer is in **or**
its due date has passed (the right is then waived); while a hearing asked for in
writing hasn't been set and recorded; and without reasons (30 characters at the least).
**Dismissal** is refused unless the case is charged under an offense resting on a just
cause. A suspension runs 1–30 days; a preventive suspension at most 30, only once a
notice is served, and never as a penalty. **Nobody serves or decides a case about
themselves.** The report can be corrected only until the notice is served.

The employee's **acknowledgement** of each notice (*I've received it*) is kept with its
time as proof of service; each notice also prints (Dompdf, the letterhead) with a line to
sign. Acknowledging is not agreeing, and the screens say so.

### The code of conduct, and progressive penalties

`conduct_offenses`: a code, a title, a gravity (minor, less serious, serious, grave),
the ground (one of the Article 297 just causes, or a company rule), a ladder of penalties
for the first, second, … time (the last repeating), and a reckoning period. When a case
is decided, its **occurrence** is the employee's upheld cases of the same offense decided
within the period before the incident, plus one; the ladder's step for it is offered
first and kept beside the penalty chosen, so a deviation shows. A ladder can't reach
dismissal on a company-rule ground. **Add the standard offenses** seeds fourteen common
ones (tardiness, AWOL, abandonment, falsifying attendance, insubordination, violence,
harassment, intoxication, negligence, safety, dress code, theft, confidentiality) to edit.

### The conduct record

`ConductRecord` gives each employee's standing today: dismissed, serving a suspension,
on preventive suspension, on a final warning, on a warning, a case being heard, or in
good standing; and the upheld decisions still counting against them, each until its
offense's period runs out. HR reads it beside every case and on the employee's record
page; the employee reads their own on **My notices**.

### Who sees what

- `discipline.view` / `.manage` (HR Manager): every case, whole, including witnesses and
  evidence; the code of conduct.
- `discipline.report` (HR Manager, Department Head): file an incident and follow the
  reports they filed, **status only**: never the answer or the reasons.
- `discipline.respond` (every built-in role): the cases **served** on oneself: the
  charge, the facts, the dates, the hearing, the decision. A case still at its report is
  invisible to them; witnesses and evidence stay with HR. Anything else is a 404.
- Evidence files go to the private `discipline/` folder of the uploads disk (ADR 0087),
  readable only with `discipline.view`.
- Every step is in the activity log under `discipline`. The Data Export includes the
  tables (with the evidence files) for `discipline.view`. **The assistant has no
  discipline tools**: cases are personal and consequential; the system guide points to
  the screens.

## Consequences

- **A case that reaches a decision in SYNAPSE has had due process**, dated step by step,
  with proof of service, which is what a labor arbiter asks for.
- **The penalty for a repeat is consistent** across supervisors, and a departure from the
  code is visible.
- **The system can't judge the merits.** Whether misconduct is "serious" or neglect
  "habitual" is HR's call; the system only holds the procedure and the record.
- **Authorized-cause terminations** (redundancy, retrenchment, closure: Article 298),
  with their 30-day notices to the employee and to DOLE, are not cases here; they belong
  to Offboarding and remain to be built.
- **Ending employment** after a dismissal is started in Offboarding; the case links to
  it rather than starting it.
