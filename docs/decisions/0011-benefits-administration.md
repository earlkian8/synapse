# 0011 — Benefits Administration: plans + enrollments, not a contribution snapshot

- **Status:** Superseded — the Benefits module was **removed** as out of scope for HR
  management. See [ADR 0019](./0019-remove-payroll-and-benefits.md).
- **Date:** 2026-06-17 (superseded 2026-06-27)

> This ADR is retained as a tombstone so historical cross-references (e.g. ADRs 0012,
> 0013, 0014) still resolve. The decision it recorded no longer applies.

## What it decided (historical)

Benefits Administration was modelled as two complementary halves: `benefit_plans` (a
Company-Setup catalogue of HMO / insurance / retirement / wellness plans) plus
`benefit_enrollments` (the employee ↔ plan link), with monthly cost rollups **derived**
rather than snapshotted; statutory contributions (`benefit_contributions`) were generated
from the payroll run's statutory deductions. The split mirrored Payroll's
config + operational shape.

## Why it was removed

Payroll processing and benefits administration are downstream of core HR and were judged
outside the scope of this HR-management system. All Benefits and Payroll tables, code,
routes, permissions and UI were removed; employee compensation fields (`basic_salary`,
bank details, government-ID numbers) stay on the employee record as HR master data (and
keep the ML `salary` feature working). See
[ADR 0019](./0019-remove-payroll-and-benefits.md).
