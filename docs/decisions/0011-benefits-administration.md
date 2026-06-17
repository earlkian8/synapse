# 0011 — Benefits Administration: plans + enrollments, not a contribution snapshot

- **Status:** Accepted
- **Date:** 2026-06-17
- **Related:** [Benefits module](../modules/benefits.md),
  [benefits tables](../database/benefits-tables.md), [ERD](../database/erd.md),
  [0005 — Multi-tenancy](./0005-multi-tenancy.md),
  Payroll ([module](../modules/payroll.md))

## Context

The ERD §7 originally sketched a single `benefit_contributions` table — one row per
employee per month per statutory benefit (`sss | philhealth | pagibig`) holding the
employee / employer share. That answers "what did each person contribute to SSS in
June?" — but the statutory contributions are **already computed and itemised as
payslip deductions** by the payroll engine, so a parallel monthly snapshot table
would duplicate that data and need its own generation step.

What the system actually lacked was **benefits administration**: a record of the
benefit *plans* the company offers (HMO, life insurance, retirement, wellness) and
*who is enrolled* — the HMO member list, the insurance roster, the cost of the
program. None of that is derivable from payslip deductions.

## Decision

Model **plans + enrollments**, not contribution snapshots:

- **`benefit_plans`** — a Company-Setup catalogue (category, provider, employee /
  employer cost, frequency, active flag). The configuration layer, mirroring how
  `allowance_types` / `deduction_types` back payroll.
- **`benefit_enrollments`** — the employee ↔ plan link with a status, member
  reference and coverage dates. One row per employee per plan.

Cost reporting is **derived** from active enrollments × each plan's monthly-equivalent
cost, rather than stored per period — the same "store the source, derive the rollup"
principle the leave balances use (ADR 0009).

Statutory contributions stay where they already are — **payslip deductions** computed
by the payroll engine — so there is no second source of truth for SSS / PhilHealth /
Pag-IBIG figures. `benefit_contributions` is dropped from the build.

## Consequences

- The Benefits module is about **coverage and cost**, complementary to payroll, not a
  re-derivation of statutory math.
- Benefit costs are **not** auto-pushed into payroll deductions in this cut; if an
  employee's share should hit their pay, HR adds a per-employee deduction in Payroll.
  A future enhancement could bridge an enrollment's `employee_cost` into a payslip
  line automatically.
- A dedicated `benefits.*` / `setup.benefits.*` permission set gates the module and
  its configuration; the built-in HR Manager role gets both.
- Dependents / beneficiaries and employee self-service are out of scope for now.
