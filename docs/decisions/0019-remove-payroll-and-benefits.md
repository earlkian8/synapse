# 0019 — Remove the Payroll and Benefits modules (out of HR-management scope)

- **Status:** Accepted
- **Date:** 2026-06-27
- **Supersedes:** [ADR 0011 — Benefits Administration](./0011-benefits-administration.md)
- **Related:** [Employees](../modules/employees.md),
  [ERD](../database/erd.md),
  [0005 — Multi-tenancy](./0005-multi-tenancy.md),
  Predictive Analytics ([ADR 0017](./0017-predictive-analytics-and-ml-inference.md),
  [ADR 0018](./0018-performance-forecasting.md)) — the ML `salary` feature reads the
  employee record, which is unaffected.

## Context

The system had grown a **Payroll** module (`payroll_periods`, `payslips`,
`payslip_earnings`, `payslip_deductions`, the `allowance_types` / `deduction_types`
Company-Setup config, and recurring per-employee `employee_allowances` /
`employee_deductions` pay items) and a **Benefits** module (`benefit_plans`,
`benefit_enrollments`, and statutory `benefit_contributions` derived from payroll).

On review, pay-run processing and benefits administration are **downstream of core HR**
and outside the intended scope of this HR-management product. They also carried the
system's only hard operational coupling (benefits → payroll → attendance).

## Decision

**Remove both modules entirely**, along with everything connected to them:

- **Payroll:** periods, payslips + earning/deduction lines, the `PayrollProcessor` /
  `PayrollCalculator`, the `allowance_types` / `deduction_types` config, and the
  recurring per-employee `employee_allowances` / `employee_deductions` pay items.
- **Benefits:** plans, enrollments, the statutory `benefit_contributions` +
  `BenefitContributionGenerator`.
- Their migrations, models, controllers, form requests, resources, routes, seeders,
  permissions (`payroll.*`, `benefits.*`, `setup.payroll.*`, `setup.benefits.*`),
  sidebar entries, Inertia pages and feature folders.

**Keep employee compensation as HR master data.** `employees.basic_salary`, bank details
and government-ID numbers (`tin`, `sss_no`, `philhealth_no`, `pagibig_no`) stay on the
employee record — they are standard 201-file data, and the predictive-analytics
**`salary` feature** ([ADR 0018](./0018-performance-forecasting.md)) reads
`basic_salary`. The employee form's *Compensation & Government IDs* sections and the
profile drawer's read-only compensation display remain; only the **pay-items**
(allowances/deductions) and **benefits** tabs were removed.

**Relocate shared code, don't delete it.** The generic `ConfirmDialog` (previously under
`features/payroll`) moved to `@/components/confirm-dialog`, since nine unrelated pages
imported it. No other module depended on payroll/benefits code.

## Consequences

- **Smaller, more focused product.** The operational coupling
  (benefits → payroll → attendance) is gone; Attendance remains as a standalone DTR
  module.
- **ML is unaffected.** The promotion/performance models still receive `salary` from the
  employee record; a `migrate:fresh --seed` + a forecaster smoke test confirmed scoring
  works post-removal.
- **History preserved.** [ADR 0011](./0011-benefits-administration.md) is kept as a
  tombstone so older cross-references resolve; the payroll/benefits changelog entries
  remain as historical record.
- **Permissions shrink.** Removing the permission groups means the `permissions` table
  re-syncs without them on the next provision/seed; built-in roles no longer grant them.
