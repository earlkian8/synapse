# 0011 — Benefits Administration: program enrollments + derived statutory contributions

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

Build **both halves** — they answer different questions and don't conflict:

**1. Program administration** — `benefit_plans` + `benefit_enrollments`:
- **`benefit_plans`** — a Company-Setup catalogue (category, provider, employee /
  employer cost, frequency, active flag), mirroring how `allowance_types` /
  `deduction_types` back payroll.
- **`benefit_enrollments`** — the employee ↔ plan link with a status, member
  reference and coverage dates. One row per employee per plan.
- Cost reporting is **derived** from active enrollments × each plan's
  monthly-equivalent cost (store the source, derive the rollup — ADR 0009).

**2. Statutory remittance** — `benefit_contributions` (the ERD's table, kept):
- One row per employee per pay period per government benefit (SSS / PhilHealth /
  Pag-IBIG), holding the **employee and employer** shares.
- **Generated from each processed payroll run**: the employee share is the statutory
  deduction already on the payslip; the **employer share** — the company counterpart
  the payslip does *not* carry — is computed from an `employer` block on the statutory
  deduction type's `computation`, on the same gross. This is the real gap the table
  fills: nothing else in the system computes the employer's mandatory contribution.

### Why keep both rather than pick one

The original ERD scoped "Benefits" to `benefit_contributions` only. Building just
that would be a thin report over data payroll mostly has; building only
plans/enrollments would leave the employer-share + remittance gap unfilled and drop
the ERD's table. The contributions table is the higher-value, compliance-critical
half; the plan/enrollment module is complementary program admin. Together they cover
both the legal remittance need and the HR benefits-program need.

## Consequences

- Statutory figures still have **one source of truth**: the payslip deductions.
  `benefit_contributions` is *derived* from them (regenerated whenever a run is
  processed / re-processed / a payslip is adjusted), never hand-entered — so it can't
  drift from payroll.
- Benefit-plan costs are **not** auto-pushed into payroll deductions in this cut; if an
  employee's share should hit their pay, HR adds a per-employee deduction in Payroll.
- A dedicated `benefits.*` / `setup.benefits.*` permission set gates the module and its
  configuration; the built-in HR Manager role gets both.
- Dependents / beneficiaries and employee self-service are out of scope for now.
