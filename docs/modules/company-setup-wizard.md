# Company Setup Wizard

The guided walk-through a brand-new company is taken through **before its dashboard**.
It lives under **Company Setup** at `/setup/wizard` and has a step for **every Company
Setup screen**, each carrying that screen's editors whole. Every step can be skipped,
and a skip is remembered.

> Status: **Active** · Route prefix: `/setup/wizard`
> Sidebar: Company Setup → Setup Guide (gated by `setup.company.manage`)
> See [ADR 0032](../decisions/0032-guided-company-setup.md),
> [ADR 0034](../decisions/0034-a-company-writes-its-own-setup.md),
> [ADR 0038](../decisions/0038-attendance-policies-presets-and-typed-options-snapshotted-per-day.md)
> (the Attendance step) and
> [ADR 0044](../decisions/0044-the-setup-wizard-carries-every-company-setup-screen.md)
> (a step per screen, the screen carried whole).

## Why it exists

Registration provisions a whole tenant and nothing inside it (ADR 0005), and the
configuration-driven modules ship no defaults on purpose (ADR 0029). The owner's first
sign-in therefore landed on a dashboard of zeroes, behind which sat every Company Setup
screen in no stated order. The wizard puts all of them in one order, offers a place to
start where a sensible one exists, and lets the owner do everything the screen itself
allows without leaving the walk-through.

## Surface

Its own full-screen chrome — no app shell, because a brand-new company has nothing for
the sidebar to link to yet. A deep-navy rail (the same field the sign-in screens and the
workspace picker use) carries the company, the progress bar and the step ladder in three
stretches; the working pane beside it is the app's own light surface.

| Stretch | Step | Company Setup screen it carries | Suggestions to start from |
| --- | --- | --- | --- |
| Your company | 1 · Company | Company Profile, plus the join code (as on Employees → Access) | — (a form, prefilled; the zone starts from the browser's) |
| | 2 · Departments | Departments — hierarchy, positions, heads, defaults | Common departments, customisable, or your own |
| Time & attendance | 3 · Attendance rules | Attendance Policies | The presets, customisable in the policy editor; an optional default schedule |
| | 4 · Schedules & holidays | Work Schedule & Holidays | The Philippine holiday calendar, ticked |
| | 5 · Leave | Leave Types | Statutory and common leave, customisable, or your own |
| | 6 · Locations | Locations — fences on the map, who is based where | — |
| | 7 · Shift roster | Shift Roster — the week, overrides, assignments | — |
| Your people, hire to exit | 8 · Hiring | Recruitment Pipelines | Three process shapes, customisable, or draw your own |
| | 9 · Onboarding | Onboarding Programs | The standard onboarding checklist |
| | 10 · Appraisals | Performance Framework — frameworks, scales, criteria, review cycles | Three frameworks, customisable, or design your own |
| | 11 · Awards | Award Types | The common award types |
| | 12 · Offboarding | Offboarding Programs | The standard exit clearance |

Around them: a **welcome** (the three stretches, and that any step can wait) and a
**send-off** (where each step landed, with *Review* or *Do it now* on each, and
"Finish, and bring your people in", which closes setup and opens Employees → Access).

**Email & Notifications** (in the sidebar) has no screen yet, so it has no step.

## A step

Top to bottom:

1. **Suggestions**, where there are any — a tray on its own muted surface. It is open
   while the step's module is empty and folds to one line ("4 common departments you
   haven't added") once it is not. Its button adds and *stays*, so what was added can be
   nested, renamed or extended straight away below.
2. **The Company Setup editor** — the screen's own manager component with the screen's
   own props, under a section heading. Every create, edit, default, archive, restore and
   delete the screen offers is here, posting to the screen's routes.
3. **The footer** — Back, *Skip this step*, and one forward action: "Add 5 leave types
   and continue" while suggestions are picked, otherwise **Continue**, which needs the
   module to hold something and says so when it doesn't. Only the button that started a
   save spins.

The Appraisals step also asks for a **review cycle** when a framework exists without
one — an appraisal can only happen inside a cycle.

A step whose module the signed-in person may not configure is **shown, not hidden**,
marked "No access", with skipping as its forward action and no screen data sent.

## Choose one, then make it yours

Every offer carries a **Customise** action where a step has one, and every step a way to
start from nothing (ADR 0034) — now also the editor under the tray.

| Step | What the company can write in the tray | What stays the server's |
| --- | --- | --- |
| Departments | Name, code, description | The wording of a **ticked** suggestion |
| Leave | Name, code, description, colour, days, and the paid / half-day / approval flags | Everything about a **ticked** suggestion but its days |
| Attendance | The policy's name, and — when customised — every setting, held to `AttendancePolicyRequest`'s rules; the default schedule's name, hours and days | An **adopted** preset's settings |
| Holidays | Which ones | Every holiday's **date**, type and recurrence — a key is posted, never a date |
| Hiring | The process name, every stage name, and their order | A stage's `kind` |
| Onboarding / Offboarding | The checklist's name | Its lines (changed afterwards in the program editor below) and, for a clearance, where each item is routed |
| Appraisals | Name, description, sections and weights, criteria, rating bands | A catalogue criterion's wording and every rating scale |
| Awards | Which ones | Name, meaning and colour of each |

Anything the company writes is held to the rules its module's own screen enforces, so
the wizard cannot create something Company Setup would refuse to save.

The holiday calendar offers the fixed regular and special non-working days as
**recurring**, and the movable ones — Maundy Thursday, Good Friday, Black Saturday
(Easter, computed) and National Heroes Day (last Monday of August) — on their **next**
date, not recurring. Days proclaimed each year (Eid'l Fitr, Eid'l Adha, Chinese New
Year) are left to the holiday editor below the tray.

An adopted **exit clearance** routes each item to the department carrying its code
(`IT`, `FIN`, `HR`) — the codes the suggested departments use — and `__own__` to the
leaver's own department; an item whose department does not exist yet stays unrouted.

## The URL, and the redirect

`GET /setup/wizard/{view}` — `view` is `intro`, a step key, or `done` — renders that
view, with the step's Company Setup screen as the `screen` prop. With no view named the
server opens where the company left off (`CompanySetup::initialView`): the welcome when
nothing is answered, the first pending step, or the send-off when everything is answered
or setup is finished. Because the view is in the URL, a step's editors can post to their
own routes and come `back()` to the same step; the roster's week, department and search
are query parameters on the step's URL, reloaded as a partial reload of `screen`.

`RequireCompanySetup` (in the `web` group, right after `SetCurrentOrganization`) sends an
owner to the wizard until setup is closed. It is deliberately narrow — it acts only on:

- a **page navigation** — GET/HEAD and not JSON, so a form post, an API call or a CSV
  download is never bounced mid-flight;
- by somebody holding **`setup.company.manage`**, so a Staff member joining a
  half-configured company is not trapped in a wizard they may not use;
- **outside the exempt set** — `setup.wizard.*`, `logout`, `workspaces`,
  `organization.switch`, the person's own account settings (`profile.*`, `security.*`,
  `appearance.*`, `password.*`, `two-factor.*`, `passkey.*`, `verification.*`) and the
  public surfaces (`home`, `careers.*`, `invite.*`).

Finishing the wizard — or "I'll set this up later", which is the same endpoint — clears
it for good.

## Data model

No new table. Two columns on `organizations` (see
[organizations table](../database/organizations-table.md)):

| Column | Notes |
| --- | --- |
| `setup_completed_at` | Null means "still show me the wizard". |
| `setup_steps` | `{step key: "done"｜"skipped"}`; anything absent reads as pending. |

Neither is `$fillable` — they are tenant state written only by `CompanySetup`, the same
reasoning as `join_code`. Organisations that predate the wizard were back-filled as
complete by the migration; organisations part-way through it gained the seven new steps
as pending.

## Backend

- **`Setup\SetupWizardController`** — `show` (renders `setup/wizard` with the view, the
  step's screen, the progress, what is configured, the blueprints, what the company
  already has, and a per-step `can` map) plus one action per suggestion set, `continue`,
  `skip` and `finish`.
- **`Queries\Setup\*Screen`** (implementing `SetupScreen`) — one class per Company Setup
  screen, building exactly the props that screen renders with. The screen's controller
  `index` and the wizard step both call it.
- **`Support\Setup\CompanySetup`** — the step vocabulary (`STEPS`, `ABILITIES`,
  `SCREENS`, `views()`, `DONE`/`SKIPPED`/`PENDING`), the progress reads/writes,
  `initialView()` and `configured()` (whether each step's module holds anything; the
  roster counts once there is a default schedule, an assignment or an override).
- **`Support\Setup\SetupBlueprints`** — every starting point: departments, leave types,
  holidays (with the Easter computation), pipelines, onboarding checklists, the criteria
  catalogue and frameworks, instruments, award types and exit clearances (the
  provisioner's `STANDARD_ITEMS`). Resolved by **key**, never trusted as content. The
  holiday, award and onboarding seeders read the same lists.
- **`Support\Setup\SetupDefinition`** — where an adopted answer and a bespoke one
  become the same thing.
- **`Support\Setup\SetupInstaller`** — writes a definition into the current tenant.
  Idempotent against what the company already has: a code or a name that exists is
  passed over, so a double submit cannot produce two "Vacation Leave"s, two "Christmas
  Day"s or two "Standard Onboarding"s. The first onboarding checklist and the first exit
  clearance become the default.
- **`Support\Setup\CompanyProfileWriter`** — the shared profile write, called by both
  this wizard and `CompanyProfileController`.
- **FormRequests** in `Http/Requests/Setup/Wizard/` — one per suggestion set
  (`WizardHolidaysRequest`, `WizardAwardTypesRequest`, `WizardProgramRequest` for both
  checklists), `WizardContinueRequest` (the step's own ability, and a configured module)
  and `WizardSkipRequest`.
- Mutations are activity-logged (`logName: 'company-setup'`, and `'recruitment'`,
  `'onboarding'`, `'offboarding'` for those modules), described as happening "during
  company setup".

## Frontend

`resources/js/pages/setup/wizard.tsx` (registered as a layout-less page in `app.tsx`)
over `features/setup-wizard/`:

- `use-setup-wizard.ts` — navigation as visits to `/setup/wizard/{view}`, `skip`,
  `advance` (continue), `finish`, and which of them is in flight.
- `components/suggestions-panel.tsx` — the tray; `section-heading.tsx` — the heading
  over an editor; `editor-step.tsx` — a step that is only its editor (locations,
  roster); `checklist-step.tsx` — onboarding and offboarding; one component per
  other step.
- The editors are the Company Setup **managers**: `DepartmentsManager`,
  `PoliciesManager`, `ScheduleManager`, `LeaveTypesManager`, `LocationsManager`,
  `RosterManager`, `PipelinesManager`, `OnboardingProgramsManager`,
  `KpiManager`, `AwardTypesManager`, `OffboardingProgramsManager` — each in its own
  feature folder, each rendered by its Company Setup page too. `RosterManager` takes the
  URL its filters reload against.
- `components/choice-card.tsx`, `custom-section.tsx`, `leave-type-fields.tsx`,
  `stage-editor.tsx`, `framework-editor.tsx` — the offers and what a company writes for
  itself, unchanged (ADR 0034).
- A step's forward action is a plain button, never a form submit: the editors' own
  dialogs and forms must not set it off.
- `components/ui/sonner.tsx` takes its bottom offset from `--app-toast-offset-bottom`,
  which `toast-clearance.tsx` sets so toasts clear the wizard's footer.

## Permissions

Reaching the wizard is `setup.company.manage`. Each step is gated by the ability of the
module it configures, and carries its screen only for somebody holding it:

| Step | Ability |
| --- | --- |
| Company | `setup.company.manage` |
| Departments | `setup.departments.manage` |
| Attendance rules | `setup.attendance-policies.manage` (and `setup.schedule.manage` to write the default schedule) |
| Schedules & holidays | `setup.schedule.manage` |
| Leave | `setup.leave-types.manage` |
| Locations | `setup.locations.manage` |
| Shift roster | `setup.roster.manage` |
| Hiring | `recruitment.configure-pipelines` |
| Onboarding | `onboarding.manage-programs` |
| Appraisals | `setup.kpi.manage` |
| Awards | `setup.award-types.manage` |
| Offboarding | `offboarding.manage-programs` |

"Finish, and bring your people in" goes to Employees → Access only for somebody holding
`employees.invite`; anybody else lands on the dashboard. Built-in **HR Manager** (the
owner) holds all of them.

## Integrations

- **Every Company Setup screen** — a step renders the screen's manager with the
  screen's props, so the two are the same editor.
- **Employees → Access** — the join code card, and the send-off's hand-over.
- **Seeding** — `OrganizationSeeder` marks a seeded tenant complete, so the demo account
  lands on the dashboard rather than the wizard; the holiday, award and onboarding
  seeders read the wizard's suggestion lists.
- **Tests** — `OrganizationFactory` defaults to a company already in use;
  `newlyRegistered()` is the state that owes setup. `SetupWizardTest` covers the
  original steps; `SetupWizardStepsTest` checks every step against its screen's props
  and covers the new steps, `continue`, and the URL.
