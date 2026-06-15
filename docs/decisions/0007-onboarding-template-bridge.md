# 0007 — Onboarding as a template-driven hire → productive bridge

- **Status:** Accepted
- **Date:** 2026-06-11
- **Related:** [Onboarding module](../modules/onboarding.md), [ERD §4](../database/erd.md),
  [0006 — Recruitment ATS & hire bridge](./0006-recruitment-ats-and-hire-bridge.md),
  [0004 — Employee ↔ User](./0004-employee-user-separation.md),
  [0005 — Multi-tenancy](./0005-multi-tenancy.md)

## Context

Recruitment ([ADR 0006](./0006-recruitment-ats-and-hire-bridge.md)) ends at **hired** —
it produces an `Employee`. But a new employee is not productive on day one: contracts
must be signed, equipment issued, accounts created, orientation done, compliance
enrolments filed. Left implicit, this work is ad-hoc and things slip through.

The sidebar models the life cycle as **Talent Acquisition → Workforce**, with
**Onboarding** sitting between Recruitment and the rest of the workforce. The ERD
sketched a single `onboarding_task` table hung off `employees` — enough to list tasks,
but too thin to be ERP-grade: no reuse across hires, no per-hire instantiation, no
case lifecycle, no sense of "this person's onboarding is 60% done".

## Decision

Build Onboarding as a small **template-driven** system and wire it to Recruitment with
an automatic handoff. **Four tenant-scoped tables** (ADR 0005):

- `onboarding_programs` — a reusable **template**, optionally targeted at a department
  and/or employment type, with one marked the tenant **default**.
- `onboarding_program_tasks` — a program's **blueprint** tasks. Each carries a
  **relative** `due_offset_days` (days after the hire starts) rather than an absolute
  date, so one template produces sensible deadlines for any hire.
- `onboarding_cases` — **one employee's** onboarding, with a lifecycle
  (`pending → in_progress → completed / cancelled`), a start/target date, and progress.
- `onboarding_tasks` — the **concrete** checklist instantiated onto a case: category,
  assignee, due date, completion.

**The handoff.** Hiring (the recruitment bridge) calls `OnboardingProvisioner::start()`
inside its transaction, so every new hire lands with a ready checklist. The provisioner
resolves the **best-matching active program** — department + type, then department, then
type, then the default — and copies its blueprint tasks onto a new case, dating each from
the hire date. Onboarding can also be **started manually** for employees created outside
Recruitment (data migration, bypass hires). It is idempotent: an employee already
onboarding is returned as-is (one case per employee).

**Lifecycle is explicit, progress is automatic.** A case is **completed** (or cancelled,
or reopened) by a deliberate HR action — an onboarding can be declared done with some
tasks deliberately *skipped*. But a `pending` case **auto-advances to `in_progress`** the
moment any task sees activity, so the board reflects reality without manual bookkeeping.

**Actors vs. subjects.** `assigned_to` / `completed_by` reference **users** (you act as an
account); `employee_id` references the **employee** being onboarded — consistent with the
ERD's actor/subject convention.

## Alternatives considered

- **The ERD's single employee-attached task table.** No template reuse, no case lifecycle,
  no instantiation from a blueprint. It makes onboarding a flat to-do list, not a process.
  Rejected — superseded by this ADR.
- **Auto-complete the case when every task is resolved.** Tempting, but onboarding is a
  judgement call (optional tasks, waived steps). We keep **completion** a human action and
  only automate the harmless `pending → in_progress` nudge.
- **A general workflow/BPM engine.** Overkill for a checklist; adds a dependency and
  concepts the rest of SYNAPSE doesn't use. Deferred.

## Consequences

- **Positive:** the life cycle is now wired end-to-end — *apply → hire → onboard →
  workforce*; templates make onboarding consistent and configurable per department/type;
  everything inherits tenant isolation; assignees get a targeted notification when work
  lands on them.
- **Negative / watch-outs:**
  - Blueprint tasks are **copied** onto a case at start time (like the résumé snapshot in
    ADR 0006). Editing a program later does **not** change in-flight cases — intended.
  - **One case per employee** (`unique(employee_id)`). Re-onboarding a re-hire means
    removing the old case first.
  - The single-**default** rule is enforced in the controller (saving a default unsets the
    others), not by a DB constraint.
  - Deleting a program is safe for running cases (their tasks already exist), but the
    case's `onboarding_program_id` becomes null.
