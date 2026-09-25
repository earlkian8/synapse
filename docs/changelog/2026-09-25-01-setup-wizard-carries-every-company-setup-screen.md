# The setup wizard carries every Company Setup screen

The setup wizard walked six of Company Setup's screens and only part of each. It now
has a step for every screen — thirteen, in three stretches — and every step carries its
screen whole: the same editors, the same actions, posting to the same routes. Where a
sensible place to start exists it sits above the editor as a tray of suggestions. See
[ADR 0044](../decisions/0044-the-setup-wizard-carries-every-company-setup-screen.md).

## Highlights

- **Everything Company Setup can do, the wizard can do.** Nest departments and add
  positions; add night, split and rotating schedules and pick the company default; edit
  a policy, star the default, archive and restore; draw sites on the map and say who is
  based there; register devices, replace keys, import a scanner's CSV; override a day on
  the roster or put people on a schedule; add rating scales, criteria and review cycles;
  edit onboarding and exit checklists — without leaving setup.
- **Seven new steps.** Schedules & holidays, Locations, Devices, Shift roster,
  Onboarding, Awards and Offboarding, grouped with the original six into *Your company*,
  *Time & attendance* and *Your people, hire to exit*.
- **New places to start.** The Philippine holiday calendar (fixed days recurring; Holy
  Week — Easter computed — and National Heroes Day on their next date), the common award
  types, the standard onboarding checklist, and the standard exit clearance routed to
  the IT, Finance and HR departments by code.
- **Add, then shape.** Adding suggestions stays on the step, so what was added appears
  in the editor right below, and the tray folds to one line. "…and continue" in the
  footer does both at once.
- **Honest progress.** *Continue* records a step done only when its module holds
  something; otherwise the way on is *Skip*. The Appraisals step asks for a review cycle
  when a framework has none, since nothing can be appraised without one.
- **How people join, and what comes next.** The company step shows the join code with
  its switch and *New code*; the send-off can finish straight into Employees → Access.

## Backend

- `app/Queries/Setup/*Screen` (`SetupScreen`) — one class per Company Setup screen,
  building the props that screen renders. The screens' `index` methods are now one line;
  the listing helpers moved with them.
- `CompanySetup` — thirteen `STEPS`, their `ABILITIES` and `SCREENS`, `views()`,
  `initialView()` and `configured()`.
- `SetupWizardController::show(?string $view)` — `GET /setup/wizard/{view}` renders the
  welcome, a step (with its screen as `screen`), or the send-off; with no view, where the
  company left off. New actions: `holidays`, `awardTypes`, `onboarding`, `offboarding`,
  `continue`; `finish` can hand over to `employees.access`.
- `SetupBlueprints` / `SetupDefinition` / `SetupInstaller` — holidays, award types,
  onboarding and offboarding checklists, idempotent by name; the first checklist of each
  kind is the default.
- FormRequests `WizardHolidaysRequest`, `WizardAwardTypesRequest`,
  `WizardProgramRequest`, `WizardContinueRequest`.
- `HolidaySeeder`, `AwardSeeder` and `OnboardingSeeder` read the same suggestion lists,
  so the demo tenant and a new company start from the same content.

## Frontend

- A manager component per Company Setup screen (`DepartmentsManager`,
  `PoliciesManager`, `ScheduleManager`, `LeaveTypesManager`, `LocationsManager`,
  `DevicesManager`, `RosterManager`, `PipelinesManager`, `OnboardingProgramsManager`,
  `KpiManager`, `AwardTypesManager`, `OffboardingProgramsManager`). Each Company Setup
  page is now its title plus its manager — unchanged on screen.
- The wizard: URL-driven navigation, a grouped rail that scrolls on short windows, the
  suggestions tray, an editor section under each tray, a footer whose one forward action
  says what it will do, and only the pressed button spinning. The phone layout of the
  leave table was reworked to fit.
- `JoinCodeCard` takes the hint it shows, so it reads right outside Employees → Access.
- `already-configured.tsx` is gone — the editor under each tray shows what exists.

## Notes

- **Email & Notifications** is still a sidebar link with no screen behind it
  (`/setup/notifications` has no route), so it has no step.
- Companies part-way through setup gain the seven new steps as pending; companies that
  finished are unaffected.
- Tests: `SetupWizardStepsTest` (new) checks every step's `screen` against the props of
  its Company Setup page, and covers the new steps, `continue`, the URL and the
  hand-over; `SetupWizardTest` updated for the wider step list.
