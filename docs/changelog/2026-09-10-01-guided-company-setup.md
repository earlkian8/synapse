# A new company is set up before it is dropped into a dashboard

Registering creates a whole tenant and nothing inside it — deliberately, because the
configuration-driven modules ship no defaults so that every list in SYNAPSE is the
company's own rather than a template's. The bill for that honesty landed entirely on the
owner's first minute: a dashboard of zeroes, nine Company Setup screens behind it in no
stated order, and no way to say "not yet" and be remembered.

This adds the five-step walk-through that comes first, and the redirect that takes an
owner there once.

## Highlights

- **Five steps, and every one can be skipped.** The company's own identity, its
  departments, the leave it grants, how it hires, and how it appraises — the four things
  the rest of the system reads from, plus the profile. A skip is stored as an answer, so
  the wizard resumes past it and the finish screen says honestly what is still open.
- **The heaviest screens become one decision.** Building a first appraisal framework
  means sections, weights, a criteria catalogue and the scales underneath it. Here it is
  three cards; the selected one opens to show every section, every criterion and what it
  is measured on, and picking it writes the scales, the catalogue and the framework in
  one transaction. Hiring is the same shape — each card shows its actual stages, not a
  count.
- **Blueprints are offers, not defaults.** Nothing is written unless it is picked, so a
  company that skips the wizard is exactly as empty as before. The suggested departments,
  kinds of leave, pipelines and frameworks all live in one server-side class; the client
  posts a **key**, never content — a statutory leave entitlement or an open/won/lost
  stage kind is not something a request body gets to define.
- **It writes through the same doors as Company Setup.** Step one posts the same payload
  through the same FormRequest; the pipeline and the framework come back out of the
  existing editors unchanged. Each step is gated by the permission of the module it
  configures rather than a new "can run the wizard" one.
- **A step you may not do is shown, not hidden.** Marked "No access", with an explanation
  and skipping as its forward action, so an owner can see what is outstanding and hand it
  to somebody who can.
- **A company that already has some of this says so.** Coming back to the wizard, or
  reaching it after configuring a module by hand, each step names what is already there
  and adds only alongside it.
- **The redirect is narrow.** Page navigations only, only for somebody who can do setup,
  and never over the wizard itself, signing out, switching company or the person's own
  account settings.

## Backend

- **New** `Support\Setup\CompanySetup` — the step vocabulary and the progress
  reads/writes. `resumeStep()` answers the first step still unanswered.
- **New** `Support\Setup\SetupBlueprints` — every starting point on offer: six
  departments, nine kinds of PH leave (statutory ones at the entitlement the law sets),
  three hiring processes, eight catalogue criteria and three frameworks drawing on them.
  Resolved by key; never trusted as content.
- **New** `Support\Setup\BlueprintInstaller` — writes a chosen blueprint into the current
  tenant. Idempotent against what the company already has, so a double submit cannot
  produce two "Vacation Leave"s. Scales and criteria resolve **by name**, so adopting a
  blueprint next to existing configuration reuses the catalogue instead of splitting the
  company's vocabulary in two.
- **New** `Support\Setup\CompanyProfileWriter` — the profile write (including the logo
  rules: remove before replace, delete the file the old row pointed at) extracted out of
  `CompanyProfileController` so the wizard and the profile screen cannot drift.
- **New** `Setup\SetupWizardController`, five FormRequests under
  `Http/Requests/Setup/Wizard/`, and `Middleware\RequireCompanySetup`.
- **Migration** adds `organizations.setup_completed_at` and `setup_steps`, back-filling
  every existing organisation as complete (with the query builder, so a back-fill does
  not move `updated_at`). Neither column is `$fillable` — tenant state, like the join
  code.
- `OrganizationSeeder` marks a seeded tenant complete; `OrganizationFactory` defaults to
  a company already in use and gains `newlyRegistered()` for the other case.

## Frontend

- **New** `pages/setup/wizard.tsx` (layout-less, like the workspace picker) over
  `features/setup-wizard/` — a hook for navigation, a rail, six screens and a shared
  footer.
- `components/choice-card.tsx` — the wizard's main control. The real radio/checkbox stays
  in the DOM, visually hidden, so keyboard and screen readers behave natively and the
  card only styles `peer-checked`. A `bare` variant drops the chrome where the row is
  already the surface.
- The rail is SYNAPSE's deep-navy field — the same one the sign-in screens and the
  workspace picker use, because setup is the last stretch of the road that starts at
  registration. Its ladder is a record rather than decoration: a tick for done, a dash
  for skipped, the number otherwise.
- **The toaster moves out of the wizard's way.** Toasts land bottom-right, which
  everywhere else is empty space; the wizard is the one surface with a pinned action bar
  there, and a step's confirmation was landing on **Save and continue**.
  `components/ui/sonner.tsx` now takes its bottom offset from
  `--app-toast-offset-bottom` (defaulting to sonner's own value), and the wizard sets it
  — 27px of clearance at 1440px, 29px at 390px, every other page untouched.
- Company Setup → **Setup Guide** in the sidebar, so a step skipped on day one is not
  lost.

## Notes

- Pest: **686 tests, 685 passed** — 40 new ones covering the redirect's boundaries, each
  step's writes and refusals, idempotency, skip/resume, finishing twice, per-step
  permission gating and tenant isolation. The single failure is pre-existing and
  unrelated: `UserManagementTest`'s photo upload needs the GD extension, which is not
  installed on this machine. Pint, tsc, ESLint, Prettier and `npm run build` are green.
- Walked the real wizard in Chromium over CDP as a genuinely new registration: the
  redirect off `/dashboard`, all five steps, skipping, resuming, the restricted step
  under a role missing three of the abilities, the finish screen, and the dashboard
  afterwards — in light and dark, at 1440px and 390px. The framework and pipeline it
  created were then opened in the existing Company Setup editors to confirm they read
  back unchanged. The throwaway tenant was removed afterwards.
- Docs: [ADR 0032](../decisions/0032-guided-company-setup.md),
  [Company Setup Wizard](../modules/company-setup-wizard.md), and the two new columns in
  [organizations](../database/organizations-table.md).
