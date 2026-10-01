# 0032 — Guided company setup before the first dashboard

- **Status:** Accepted
- **Date:** 2026-09-10
- **Related:** [Company Setup Wizard module](../modules/company-setup-wizard.md),
  [0005 — Multi-tenancy & the organisation as company profile](./0005-multi-tenancy.md)
  (registration provisions the empty tenant this fills),
  [0028 — Appraisal frameworks & tenant rating models](./0028-appraisal-frameworks-and-tenant-rating-models.md)
  (the framework a blueprint materialises),
  [0029 — Configurable recruitment pipelines](./0029-configurable-recruitment-pipelines.md)
  (the "no module defaults, honest empty state" convention this had to preserve).

## Context

Registration provisions a whole tenant: an organisation, its built-in roles, and the
registrant as its owner. It provisions nothing else, deliberately — the
configuration-driven modules ship **no defaults** so that every list in the system is
the company's own rather than a template's (ADR 0029).

The cost of that honesty landed entirely on the owner's first minute:

- **The dashboard was a wall of zeroes.** Nothing on it said what to do, because
  everything it counts is downstream of configuration that did not exist yet.
- **Company Setup is nine screens with no stated order.** Nothing distinguished the four
  that block day-one work (departments, leave types, a hiring pipeline, an appraisal
  framework) from the five that only make a module do more.
- **The heaviest screen was indistinguishable from the lightest.** Building a first
  appraisal framework means designing sections, weights, a criteria catalogue and the
  rating scales underneath it — an afternoon's work, presented with the same weight as
  adding a leave type.
- **Nothing recorded a decision to wait.** An owner who skipped setup had no way to say
  so, and nothing to come back to.

## Decision

**A brand-new company is taken through a five-step wizard before its dashboard, and
every step can be skipped.**

Three constraints shaped it.

**1. The wizard writes through the same doors as Company Setup.** It is not a parallel
configuration path. Its company step posts the same payload through the same
`UpdateCompanyProfileRequest`; the profile write itself moved into a shared
`CompanyProfileWriter` that both screens call, because the logo rules (remove before
replace, delete the file the old row pointed at) are exactly the kind of thing that
drifts when copied. Its recruitment step produces a pipeline the pipelines editor reads
back unchanged; its performance step produces a framework the framework editor reads back
unchanged. Every step is gated by the **permission of the module it configures**, not by
a new "can run the wizard" permission — the wizard is a route through Company Setup, not
a way around it.

**2. Blueprints are offers, not defaults.** The suggested departments, kinds of leave,
hiring processes and appraisal frameworks live in one server-side class
(`SetupBlueprints`). Nothing is written unless the owner picks it, so a company that
skips the wizard is exactly as empty as before — the "no module defaults" convention is
intact. Keeping them server-side is also what makes a posted choice safe: the client
sends a **key**, the server resolves it, and a statutory leave entitlement or an
open/won/lost stage kind is never something a request body gets to define.

**3. Progress belongs to the company, not the session.** `organizations.setup_steps` and
`setup_completed_at` record what happened to each step (`done` / `skipped`) and whether
setup is closed. It survives a sign-out, follows the company across devices, and reads
the same for every owner — a second HR Manager picks up where the first left off. A skip
is stored as an answer rather than an absence, so the wizard resumes past it and the
finish screen can say honestly what is still open.

**The redirect is narrow.** `RequireCompanySetup` acts only on a page navigation
(GET/HEAD, not JSON), only for somebody who holds `setup.company.manage`, and only
outside an exempt set (the wizard itself, signing out, switching company, and the
person's own account settings). A form post, an API call and a CSV download are never
bounced mid-flight; a Staff member who joins a half-configured company is never trapped
in a wizard they may not use.

**Companies that predate the wizard were back-filled as complete.** Nobody is sent
through setup for a company they have been running for months.

**Toasts stayed.** A step confirms itself the way every other save in the app does, and
says what it created. What changed instead is where the toast sits: the wizard is the
only surface with a pinned action bar bottom-right, so `components/ui/sonner.tsx` now
takes its bottom offset from a CSS variable (defaulting to sonner's own value) and the
wizard sets it. Moving the confirmation was the fix; removing it would have made the
wizard the one place in the app where a save says nothing.

## Consequences

- A new tenant reaches a working system in about five minutes, and the four heaviest
  configuration screens each become one decision.
- The wizard stays reachable from Company Setup → **Setup Guide** afterwards, so a step
  skipped on day one is not lost.
- `SetupBlueprints` is a second place that describes leave, hiring and appraisal
  vocabulary. It is data rather than logic, and it resolves against the catalogue
  (criteria and scales are matched **by name**, so adopting a blueprint alongside
  existing configuration reuses what is there instead of splitting the company's
  vocabulary in two) — but it does have to be kept honest when statutory entitlements
  change.
- Every factory-built organisation now stands for a company already in use
  (`setup_completed_at` set); the `newlyRegistered()` state describes the other case.
  A test that wants the redirect has to ask for it.

## Alternatives considered

- **A dashboard checklist instead of a wizard.** Cheaper, and it is what the finish
  screen ended up being — but it leaves the heaviest screens (a framework, a pipeline)
  exactly as heavy as they were. The wizard's value is that it turns those into a
  choice.
- **Seeding defaults at registration.** One migration, no UI. It would have made every
  company's leave and hiring vocabulary identical by accident, which is the thing ADR
  0029 deliberately walked away from.
- **A blocking wizard with no skip.** Rejected outright: a company that already knows it
  hires nobody should not be made to describe a hiring process to reach its dashboard.
