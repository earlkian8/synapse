# 0044 — The setup wizard carries every Company Setup screen

- **Status:** Accepted
- **Date:** 2026-09-25
- **Related:** [Company Setup Wizard module](../modules/company-setup-wizard.md),
  [0032 — Guided company setup before the first dashboard](./0032-guided-company-setup.md)
  (the wizard this widens, and the "offers, not defaults" rule it keeps),
  [0034 — A company writes its own setup](./0034-a-company-writes-its-own-setup.md)
  (the suggestion steps this leaves as they were),
  [0037](./0037-schedules-are-templates-assignments-are-dated-a-resolver-decides-the-day.md),
  [0040](./0040-punch-capture-geofences-device-ingestion-and-records-for-devices.md)
  (the roster, sites and devices that became steps).

## Context

ADR 0032 chose six screens that block day-one work, and named four others on the
finish screen. Since then Company Setup grew a Shift Roster, Locations and Devices,
and the wizard never learned about them: the finish screen still listed four, an owner
had no way to know the other three existed, and "Setup Guide" in the sidebar sat above
fourteen screens while walking six.

Within the six, each step was also narrower than its screen. The departments step could
create a flat list but not nest it or add positions; the attendance step made one
policy and one plain schedule but could not make one the default, edit it, or add a
night shift; the appraisal step built a framework, but a company could not add the
review cycle an appraisal needs to happen in. "Already configured" said what existed
and sent the owner to Company Setup to do anything about it — which, for a company
still owing setup, redirected straight back into the wizard.

## Decision

**Every Company Setup screen is a wizard step, and every step carries its screen
whole.** Thirteen steps, in three stretches — the company (profile, departments), its
time (attendance rules, schedules & holidays, leave, locations, devices, the roster),
and its people from hire to exit (hiring, onboarding, appraisals, awards,
offboarding). Where one step's options come from another — a site's default schedule,
a device's site, a roster's schedules — the other comes first.

1. **One source of truth per screen.** Each screen's props are built by a
   `SetupScreen` class (`app/Queries/Setup/*Screen`), and each screen's body is a
   feature-level *manager* component (`DepartmentsManager`, `ScheduleManager`, …).
   The Company Setup page renders `<Head>`, its title and the manager; the wizard step
   renders its own heading and the same manager with the same props. There is no
   second implementation of any editor, so the wizard cannot fall behind a screen.

2. **The view lives in the URL.** `GET /setup/wizard/{view}` renders the welcome, one
   step (with that step's screen as `screen`), or the send-off. The managers save the
   way they do on their screens — post, then `back()` — and land on the same step with
   the list refreshed. A roster week change is a partial reload of `screen` against the
   wizard URL. With no view named, the server opens where the company left off
   (`CompanySetup::initialView`).

3. **Suggestions are a tray, not the step.** Where a sensible place to start exists,
   it sits in a tray above the editor: the existing departments, leave, presets, hiring
   and framework offers (unchanged, ADR 0034), plus four new ones drawn from what the
   demo seeders already held — the Philippine holiday calendar (fixed dates recurring,
   Holy Week and National Heroes Day on their next date, Easter computed), the common
   award types, the standard onboarding checklist and the standard exit clearance
   (the provisioner's own list). The seeders now read the same lists. The tray is open
   while the module is empty and folds to one line once it is not. Adding from it stays
   on the step, so what was added can be shaped straight away in the editor below;
   the footer's "…and continue" does both at once.

4. **Continue is honest.** A step done with its editors rather than its suggestions is
   moved on from with `POST wizard/continue`, which records it as done only when its
   module holds something (`CompanySetup::configured`) and only for somebody holding
   that module's ability. A step with nothing in it is skipped, not completed.

5. **Locations, devices and the roster have no suggestions.** What is there depends on
   the company's buildings, hardware and people; an invented site or device would be a
   default in all but name. They are their editors, with a line on when to skip.

The company step also shows the join code ([ADR 0026](./0026-self-served-identity-and-workspace-join.md)) — managed exactly as on
Employees → Access — and the send-off can finish straight into Employees → Access,
because bringing people in is the one thing setup cannot do for the company.

**Email & Notifications** stays out: the sidebar links to `/setup/notifications`, but
no such screen exists yet. It becomes a step when it becomes a screen.

## Consequences

- Anything a company can do under Company Setup it can do in the wizard, and the two
  cannot drift: a feature added to a screen's manager appears in both.
- Organisations mid-setup gain seven pending steps; organisations that finished keep
  `setup_completed_at` and are never redirected. The send-off now counts thirteen.
- A step's full editor is heavier than a single decision, so every editor-backed step
  keeps the one-line hint of what to do and the footer's "skip for now".
- Company Setup controllers' `index` methods are one line each; their listing helpers
  moved into the screen classes.
