# Discipline

Incidents taken through **due process**: the twin-notice rule of the Labor Code and DOLE
D.O. 147-15, under the company's **code of conduct**, with each employee's **conduct
record**. The *why* is
[ADR 0105](../decisions/0105-discipline-taken-through-the-twin-notice-rule.md).

> Status: **Active** · Sidebar: Workforce → **Discipline** · HR: `/discipline/cases`,
> `/discipline/code`, `/discipline/people/{employee}` · Reporters: `/discipline/reported`
> · Employees: `/discipline/me` and the app's **Notices**

## Surfaces

- **`/discipline`** sends HR to the cases, a reporter to their reports, everyone else to
  their notices. `DisciplineNav`: *My notices* ‖ *Cases · Reported by me · Code of conduct*.
- **Cases** — tiles (reports to read, answers due, not answered in time, hearings to
  hold), tabs *Open · Decided · Closed* with counts, search (name, number, reference),
  25 a page; **Report an incident**.
- **A case** — the **next step** panel (what due process asks now; what blocks a
  decision; the actions: *Serve a notice to explain*, *Decide the case*, *Set / Move the
  hearing*, *Record the hearing*, *Record a paper explanation*, *Preventive suspension*,
  *Withdraw*, *Close*, and after a dismissal *Start their exit in Offboarding*); the
  incident, the notice, the explanation, the hearing, the decision; **Evidence** (upload,
  open, remove); beside it the employee, their standing and the **due-process
  timeline**. Notice and decision PDFs in the header.
- **Code of conduct** — offenses with gravity ticks, ground and period, and the ladder
  of penalties; *Add offense*, *Add the standard offenses*, edit, remove.
- **Conduct record** — standing, what still counts and until when, every case.
- **Reported by me** — the reports one filed and their status.
- **My notices** / a notice — standing; each notice's charge, facts, acknowledgement,
  the explanation form with *I ask for a hearing*, the hearing, the decision, and the
  same timeline. The app's `app/discipline/` screens do the same over `/api/discipline`.

## Data model

`conduct_offenses`, `disciplinary_cases`, `disciplinary_case_attachments`. See
[discipline tables](../database/discipline-tables.md).

## How it works

- `DisciplineWorkflow` — `report`, `update` (until served), `attach` / `detach`,
  `issueNotice` (≥ 5 calendar days), `acknowledge`, `explain` (employee or HR),
  `scheduleHearing`, `recordHearing`, `preventiveSuspension` (≤ 30 days), `suggestion`
  (occurrence and the ladder's step), `decide` (guards: `blockers()`, just cause for
  dismissal, 1–30 suspension days, not one's own case), `close`, `withdraw`,
  `saveOffense`, `removeOffense`. Logged under `discipline`; notifications as in ADR 0105.
- `ConductCode::STANDARD` / `addStandard()` — the fourteen starting offenses.
- `ConductRecord::for($employee)` — standing and what still counts.
- `DisciplineDocuments` — `notice-to-explain` and `notice-of-decision` PDFs.
- `DisciplineScreens` — every screen's props, shaped per audience.

## Permissions

`discipline.view`, `discipline.manage` (HR Manager); `discipline.report` (HR Manager,
Department Head); `discipline.respond` (every built-in role).

## Assistant

None. The system guide points to the screens.

## Tests

`tests/Feature/Discipline/DisciplineTest.php` — the report (who's told, what the
employee sees), the notice (five days, a specific charge, acknowledgement, the answer,
no witnesses or evidence for the employee), deciding (no notice, too early, waived,
hearing first, just cause, the ladder and its period, suspension limits, one's own
case, preventive suspension, the decision served and printed, withdrawal), the conduct
record, the code of conduct, the activity log, landings and tenancy.
