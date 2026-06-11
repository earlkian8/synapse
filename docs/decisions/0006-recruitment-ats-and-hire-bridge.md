# 0006 — Recruitment as an ATS, with a hire → employee bridge

- **Status:** Accepted
- **Date:** 2026-06-11
- **Related:** [Recruitment module](../modules/recruitment.md), [ERD §4](../database/erd.md), [0004 — Employee ↔ User](./0004-employee-user-separation.md), [0005 — Multi-tenancy](./0005-multi-tenancy.md)

## Context

STAFFA's sidebar models an employee's life cycle — **Talent Acquisition →
Workforce → Offboarding**. Until now an Employee could only be created by a manual
"New employee" form, which makes the Recruitment step decorative: if anyone can
conjure an employee, what is recruitment for?

We need recruitment to be the **primary, intended origin** of most employees: a
candidate applies, advances through a pipeline, and — when hired — *becomes* an
employee. Two modelling questions follow (ERD Open Question #6 among them): what is
a candidate, and how does a hire turn into an employee?

## Decision

Build Recruitment as a small **applicant tracking system (ATS)** and connect it to
the workforce with an explicit **hire bridge**.

- **Four entities**, all tenant-scoped (ADR 0005):
  - `job_postings` — a vacancy with a status lifecycle (`draft → open → closed /
    filled`) and an `openings` count.
  - `applicants` — a **standalone candidate pool**, *not* users or employees (ERD
    Open Question #6 resolved: standalone). One applicant can apply to many postings.
  - `job_applications` — an applicant on a posting, advancing through a **pipeline**
    (`applied → screening → interview → offer → hired / rejected`), with a rating and
    an optional `hired_employee_id`.
  - `interviews` — scheduled against an application, with a mode and a result.
- **The hire bridge.** Hiring is a deliberate action (`recruitment.hire`), never a
  plain stage edit. It creates an `Employee` from the applicant + posting in a
  transaction, copies the résumé into the new 201 file, sets the application to
  `hired` and links `hired_employee_id`, and **auto-fills the posting** once its
  openings are met. The stage endpoint explicitly refuses to set `hired`, so an
  employee is only ever produced through the bridge.
- **Manual "New employee" stays** — but as the exception (data migration, bypass
  hires), with recruitment as the front door. (See the module doc's lifecycle note.)
- **Actors vs. subjects.** `posted_by`, `interviewer_id` reference **users** (you
  must be an account to act); the produced `hired_employee_id` references **employees**
  — consistent with the ERD's actor/subject convention.

## Alternatives considered

- **Make applicants users or employees up front.** Pollutes auth/HR with people who
  may never be hired, and breaks the "candidate ≠ staff" reality. Rejected.
- **Let hiring be just another pipeline stage.** Then an employee could be created by
  a careless drag with no data wired up. Making it an explicit, transactional action
  keeps the employee record correct. Rejected.
- **Drag-and-drop Kanban with a DnD library.** Nice, but adds a dependency and edge
  cases; quick "move to" actions on each card achieve the same pipeline movement with
  less risk. Deferred.

## Consequences

- **Positive:** recruitment is now the real origin of employees; the lifecycle in the
  sidebar is wired end-to-end (apply → hire → workforce); the candidate pool is
  reusable across postings; everything inherits tenant isolation.
- **Negative / watch-outs:**
  - The résumé is **copied** (not referenced) into the 201 file at hire time, so later
    edits to the applicant's résumé do not propagate — intended (the 201 file is a
    snapshot).
  - Deleting a posting hard-deletes its applications (cascade); applicants in the pool
    survive. Postings are not soft-deleted — their status lifecycle is the archive.
  - The reverse bridge (an employee's **offboarding** closing the loop) is a separate,
    future module.
