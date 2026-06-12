# 0004 — Employee as a record separate from User

- **Status:** Accepted
- **Date:** 2026-06-10
- **Supersedes:** —
- **Related:** [Employees module](../modules/employees.md), [ERD](../database/erd.md), [0002 — RBAC](./0002-rbac-authorization.md)

## Context

NEXO needs an **employee** entity — the HR record at the centre of attendance,
leave, payroll, performance, etc. It already has a **user** entity for
authentication and RBAC. The pivotal modelling question (ERD open question #1):
are these the same row, or two linked rows?

Real HR scenarios pull them apart:

- A DTR-only field worker has an HR record but **no login**.
- An external IT admin or auditor has a **login but is not an employee**.
- Employee data (department, salary, government IDs, 201 file) is HR-owned and
  outlives any account; auth data (password, 2FA, roles) is security-owned.

## Decision

Keep them **separate**, linked 1:1 (optional):

- `employees.user_id` is a **nullable, unique** FK to `users`.
- An Employee carries its **own** name/contact fields (not derived from a user),
  so HR records exist independently of accounts.
- `employees.employee_no` (`EMP-NNNNN`) is the **canonical HR identifier**.
- **Actor** columns across the system (`*_by`, `approved_by`, `uploaded_by`)
  reference `users` (you must be an authenticated account to act); **subject /
  org-chart** columns (`manager_id`, `head_id`, `employee_id`) reference
  `employees`.

The existing `users.employee_id` string column is now redundant (superseded by
the real relationship) but is **left in place** for this change to avoid a
destructive migration; it can be dropped in a later cleanup.

## Alternatives considered

- **Collapse Employee into User.** Simpler joins, but forces every employee to
  have a login (wrong for field workers) and pollutes the security model with HR
  fields. Rejected.
- **Many-to-many User↔Employee.** Unnecessary — a person is at most one employee
  and at most one account. A unique nullable FK captures it exactly.

## Consequences

- **Positive:** clean separation of concerns; employees without logins and users
  without employee records are both first-class; the link is enforced unique so a
  user maps to at most one employee.
- **Negative / watch-outs:**
  - Two name sources can drift (a linked user and employee both have names). The
    employee record is authoritative for HR contexts.
  - Self-service (an employee viewing *their own* profile via their linked user)
    will need an explicit `users.employee` lookup — already wired as a hasOne.
  - `users.employee_id` remains as dead weight until a follow-up migration drops it.
