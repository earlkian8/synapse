# Company Setup Wizard

The guided walk-through a brand-new company is taken through **before its dashboard**.
It lives under **Company Setup** at `/setup/wizard` and covers the six things the rest
of the system reads from. Every step can be skipped, and a skip is remembered.

> Status: **Active** · Route prefix: `/setup/wizard`
> Sidebar: Company Setup → Setup Guide (gated by `setup.company.manage`)
> See [ADR 0032](../decisions/0032-guided-company-setup.md),
> [ADR 0034](../decisions/0034-a-company-writes-its-own-setup.md) and, for the
> Attendance step, [ADR 0038](../decisions/0038-attendance-policies-presets-and-typed-options-snapshotted-per-day.md).

## Why it exists

Registration provisions a whole tenant and nothing inside it (ADR 0005), and the
configuration-driven modules ship no defaults on purpose (ADR 0029). The owner's first
sign-in therefore landed on a dashboard of zeroes, behind which sat ten Company Setup
screens in no stated order. This walks the six that block day-one work and leaves the
other four to the finish screen.

## Surface

Its own full-screen chrome — no app shell, because a brand-new company has nothing for
the sidebar to link to yet. A deep-navy rail (the same field the sign-in screens and the
workspace picker use) carries the company, the progress bar and the step ladder; the
working pane beside it is the app's own light surface.

| Screen | What it asks |
| --- | --- |
| Welcome | What the six steps are, and that any of them can wait. |
| 1 · Company | Display name and time zone (both required — the zone starts from the browser's, and is the clock attendance is judged on, ADR 0036), legal name, logo, contact details, employer registration numbers (folded away — a company registering today may not have them). |
| 2 · Departments | Tick suggested functions, and name any the company has of its own. Nothing is pre-ticked; a department list is genuinely different at every company. |
| 3 · Leave | Tick kinds of leave and set the days each carries, and define any others whole. The statutory PH entitlements are pre-ticked at the number the law sets. |
| 4 · Attendance | Pick how days are judged — Philippines Labor Code / Standard 40-hour week / Flexible, no lateness / Shift work — each card listing the rules that make it different, or customise the chosen one in the same editor Company Setup uses, beside a worked example. Optionally write the hours most people work (a name, hours and working days). The policy becomes the company default, and the schedule the default schedule, when the company has none yet. |
| 5 · Hiring | Pick one process shape (Standard / Fast Track / Executive Search), or draw the company's own stages. Each card shows its actual stages. |
| 6 · Appraisals | Pick one framework (Balanced / Competency Review / Results & Conduct), or design one — sections, weights, criteria and the words a result is reported in. The selected card opens to show its sections and every criterion. |
| Finish | What landed where, with "Do it now" on anything still open, plus the four Company Setup screens the wizard leaves out. |

## Choose one, then make it yours

Every offer carries a **Customise** action, and every step a way to start from nothing
(ADR 0034). Customising lifts an offer out of the suggestion list and into the
company's own — prefilled, so nobody faces an empty form unless they ask to — and the
suggestion then reads "In your list" rather than staying on offer twice.

| Step | What the company can write | What stays the server's |
| --- | --- | --- |
| Departments | Name, code, description | The wording of a **ticked** suggestion |
| Leave | Name, code, description, colour, days, and the paid / half-day / approval flags | Everything about a **ticked** suggestion but its days — a statutory entitlement is not a request-body field |
| Attendance | The policy's name, and — when customised — every setting, held to `AttendancePolicyRequest`'s rules; the default schedule's name, hours and days | An **adopted** preset's settings, resolved from `AttendancePolicyPresets` rather than posted |
| Hiring | The process name, every stage name, and their order | A stage's `kind`, and the rule that a process has exactly one hired stage and at least one rejected one |
| Appraisals | The framework's name and description, its sections and weights, which criteria sit where and at what weight, the wording of a criterion it writes, and the rating bands | A **catalogue** criterion's wording (resolved from its key) and every rating scale, which is named from the shared library rather than described |

Anything the company writes is held to the rules its module's own screen enforces, so
the wizard cannot create something Company Setup would refuse to save. A criterion the
company writes here joins its **criteria catalogue** — unlike a one-off in the framework
editor, which stays inside the one framework: in the wizard there is no catalogue yet,
and writing a criterion is how it gets built.

Designing a **rating scale** is the one thing the wizard leaves out. A scale has bounds
and anchors the whole scoring apparatus reads; the six-instrument library is offered
instead, and Company Setup → Performance Framework is one click away.

The step ladder is free to move around — the steps are independent. A step whose module
the signed-in person may not configure is **shown, not hidden**, marked "No access", with
skipping as its forward action.

Each step confirms itself with a toast, saying what it created ("6 leave types added").
The wizard is the app's only surface with a pinned action bar bottom-right, where toasts
land, so it **lifts the toaster clear of its own footer** — see `toast-clearance.tsx` and
the two variables `components/ui/sonner.tsx` reads.

## The redirect

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
complete by the migration.

## Backend

- **`Setup\SetupWizardController`** — `show` (renders `setup/wizard` with the blueprints,
  the progress, what the company already has, and a per-step `can` map) plus one action
  per step, `skip` and `finish`.
- **`Support\Setup\CompanySetup`** — the step vocabulary (`STEPS`, `DONE`/`SKIPPED`/
  `PENDING`, `ABILITIES`) and the progress reads/writes. `resumeStep()` answers the first
  step still unanswered.
- **`Support\Setup\SetupBlueprints`** — every starting point the wizard offers:
  departments, leave types, pipelines, the criteria catalogue, the frameworks that draw
  on it, and the instrument library a bespoke framework measures on. Blueprints are
  resolved from here by **key**, never trusted as content.
- **`Support\Setup\SetupDefinition`** — where an adopted answer and a bespoke one
  become the same thing. A blueprint key is resolved; the company's own definition is
  taken as written, except for the parts the modules downstream read as meaning, which
  are resolved from `SetupBlueprints` either way.
- **`Support\Setup\SetupInstaller`** (was `BlueprintInstaller`) — writes a definition
  into the current tenant, without knowing which route it came by. Every method is
  idempotent against what the company already has, so a double submit (or a second owner
  walking the wizard) cannot produce two "Vacation Leave"s. Scales and criteria resolve
  **by name**, so a second framework that also measures "Communication" reuses the
  catalogue rather than splitting it.
- **`Support\Setup\CompanyProfileWriter`** — the shared profile write, called by both
  this wizard and `CompanyProfileController`.
- **FormRequests** in `Http/Requests/Setup/Wizard/` — one per step, plus `WizardSkipRequest`.
  The company step reuses `UpdateCompanyProfileRequest` unchanged. The attendance step
  (`WizardAttendanceRequest`) takes a preset key and, when customised, settings held to
  `AttendancePolicyRequest::settingsRules()`; writing its default schedule additionally
  needs `setup.schedule.manage`. The other four each validate two answers: a blueprint key, or the company's own definition held to the
  rules of the module's own request (`LeaveTypeRequest`,
  `StoreRecruitmentPipelineRequest`, `ReviewTemplateRequest`). A leave type or
  department left without a code gets one derived from its name — initials for a
  multi-word leave type ("Typhoon Leave" → `TL`), so a derived code looks like the ones
  the blueprints carry.
- Mutations are activity-logged (`logName: 'company-setup'`, and `'recruitment'` for the
  pipeline), described as happening "during company setup".

## Frontend

`resources/js/pages/setup/wizard.tsx` (registered as a layout-less page in `app.tsx`)
over `features/setup-wizard/`:

- `use-setup-wizard.ts` — which screen is on show, the ladder navigation, `skip` and
  `finish`. Advancing is the client's decision, taken once the server confirms the step;
  what is *recorded* stays the server's, re-read from props after every post.
- `components/choice-card.tsx` — the wizard's main control. The real radio/checkbox stays
  in the DOM, visually hidden, so keyboard and screen readers behave natively and the
  card only styles `peer-checked`. A `bare` variant drops the card chrome where the row
  is already the surface (the leave table); an `action` slot carries **Customise**,
  swallowing its own click so pressing it never also ticks the box.
- `components/custom-section.tsx`, `leave-type-fields.tsx`, `stage-editor.tsx` and
  `framework-editor.tsx` — what the company writes for itself. Everything bespoke sits
  on a **dashed hairline**, the same signal the framework editor already uses for a
  criterion written by hand: the difference from an offer is authorship, not importance,
  so it is drawn without a second accent colour.
- `components/attendance-step.tsx` — reuses `features/attendance-policy-config`'s
  `PolicySections` and `WorkedExample` when a preset is customised, so the wizard edits a
  policy exactly as Company Setup does.
- `components/wizard-rail.tsx`, `step-body.tsx`, `step-footer.tsx`,
  `already-configured.tsx`, `toast-clearance.tsx`, and one component per screen.
- `features/kpi-config/components/weight-controls.tsx` — the percent box, the running
  tally and the split-evenly arithmetic, shared with the framework editor under Company
  Setup so a weight reads and computes the same in both.
- `components/ui/sonner.tsx` takes its bottom offset from
  `--app-toast-offset-bottom` / `--app-toast-offset-bottom-mobile`, defaulting to
  sonner's own values. The app mounts one `<Toaster>` globally, so that pair of
  variables is how a page with a pinned action bar asks it to move; every other page
  is exactly where it was.
- `constants.ts` carries the step copy and the four Company Setup screens the wizard
  leaves out; `routes.ts` mirrors the named routes.

## Permissions

Reaching the wizard is `setup.company.manage`. Each step is gated by the ability of the
module it configures:

| Step | Ability |
| --- | --- |
| Company | `setup.company.manage` |
| Departments | `setup.departments.manage` |
| Leave | `setup.leave-types.manage` |
| Attendance | `setup.attendance-policies.manage` (and `setup.schedule.manage` to write the default schedule) |
| Hiring | `recruitment.configure-pipelines` |
| Appraisals | `setup.kpi.manage` |

The Attendance step's ability arrived with ADR 0038. Built-in **HR Manager** (the owner)
holds all of them.

## Integrations

- **Company Profile** — step 1 is the same payload, validation and writer.
- **Departments / Leave Types / Attendance Policies / Recruitment Pipelines / Performance
  Framework** — each
  step creates records those screens read back and edit as normal, whether the company
  adopted an offer or wrote its own.
- **Seeding** — `OrganizationSeeder` marks a seeded tenant complete, so the demo account
  lands on the dashboard rather than the wizard.
- **Tests** — `OrganizationFactory` defaults to a company already in use;
  `newlyRegistered()` is the state that owes setup.

## Out of scope (this cut)

Other schedules (night shifts, split shifts, rotations), holidays, onboarding and
offboarding programs, and award types — all named on the finish screen, none of them
blocking day-one work. Inviting people is left
to the Employees module, which already has an invitation flow (ADR 0026).
