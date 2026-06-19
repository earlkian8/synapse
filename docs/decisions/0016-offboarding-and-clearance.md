# 0016 — Offboarding: structured employee exits with a clearance checklist

- **Status:** Accepted
- **Date:** 2026-06-19
- **Related:** [Offboarding module](../modules/offboarding.md),
  [offboarding tables](../database/offboarding-tables.md), [ERD](../database/erd.md),
  [0005 — Multi-tenancy](./0005-multi-tenancy.md),
  [0007 — Onboarding template bridge](./0007-onboarding-template-bridge.md) — the
  parent-case + checklist precedent this mirrors,
  [0004 — Employee/User separation](./0004-employee-user-separation.md) — the
  `employment_status` this module transitions

## Context

ERD §9 sketches `offboarding_cases` (an employee's exit: a `type`, the notice /
last-working-day dates, a reason, a `status` and a `clearance_status`) and
`clearance_items` (a sign-off owned by a department, `cleared`/`flagged` by a user).
The sidebar already carried an ungated **Offboarding** placeholder (`/offboarding`).

Structurally this is the **Onboarding** shape inverted: a per-employee **case** with a
**checklist**, a deliberate lifecycle, and a detail page worth navigating to. The only
real difference is what the checklist groups by — Onboarding groups *tasks* by
*category*; Offboarding groups *clearance sign-offs* by the *responsible department*.

## Decision

Build Offboarding by **mirroring Onboarding** (ADR 0007), reusing its exact layering:

- **`offboarding_cases`** — one case per employee (`unique(employee_id)`), addressed by
  hashid, with a deliberate lifecycle `initiated → clearance → completed` (plus
  `cancelled`). Started in-module by HR.
- **`clearance_items`** — the sign-offs, addressed by numeric id (like onboarding
  tasks), each routed to a responsible `department_id` and `cleared`/`flagged` by a
  user.
- **`App\Support\OffboardingProvisioner`** — the connective tissue (the
  `OnboardingProvisioner` analogue): `start()` opens a case and instantiates the
  standard clearance checklist, routing each item to the department that owns it
  (resolved by code — IT / Finance / HR — or the employee's own department).
- Two thin controllers (`OffboardingCaseController`, `ClearanceItemController`),
  `InitiateOffboardingRequest` + `StoreClearanceItemRequest`, and
  `OffboardingCaseResource` + `ClearanceItemResource` — the same shape as Onboarding.

### Justified refinements over the raw ERD (backward-compatible)

- **`clearance_status` is derived, not stored.** The ERD lists it as a column, but the
  codebase's firm norm is to *derive status from child records so it cannot drift*
  (onboarding progress, training/event status, leave balances, performance scores).
  `OffboardingProvisioner::clearanceStatus()` is the single source of truth, returning
  exactly the ERD's three states — `pending` (untouched) → `in_progress` → `cleared`
  (all signed off) — computed from the item counts.
- **`status` gains `cancelled`.** A withdrawn resignation is real; this gives the case
  the same complete / cancel / reopen lifecycle Onboarding already has.
- **`clearance_items` gains `remarks` + `sort_order`.** A `flagged` item needs to say
  *why* (e.g. "laptop not returned"); `sort_order` keeps the checklist stable, like
  onboarding tasks.
- **`offboarding_cases` gains `completed_at`.** The exit-finalised timestamp, matching
  onboarding's `completed_at` (and powering the "completed this month" stat).

### Bridge into the Employee core

Completing an exit **transitions the employee's `employment_status`** to match the exit
type (`resignation`/`retirement`/`end_of_contract` → `resigned`, `termination` →
`terminated`); reopening or cancelling returns them to `active`. This is the point of
offboarding — a finalised exit is the canonical way an employee leaves `active` — and
it reuses the existing `employees.employment_status` enum rather than adding state.
`OffboardingProvisioner` is idempotent per employee, and only `active` employees
without an existing case can be offboarded.

## Consequences

- A dedicated `offboarding.*` permission set (`view` / `manage`) gates the module;
  built-in **HR Manager** gets both, and the sidebar **Offboarding** item is now gated
  on `offboarding.view`.
- Mutations are activity-logged (`logName: 'offboarding'`); the clearance checklist
  auto-nudges a case from `initiated` to `clearance` on the first sign-off, exactly as
  onboarding nudges `pending → in_progress`.
- The Employee detail drawer gains a read-only **Offboarding** tab (alongside Benefits,
  Performance, Training, Awards and Events).
- **Out of scope (this cut):** an automatic exit-interview survey, document generation
  (clearance form / COE PDF), a self-service employee resignation request flow, final-pay
  computation from the case, and an assistant capability — matching the precedent of
  shipping the operational core first (Training, Awards, Events).
